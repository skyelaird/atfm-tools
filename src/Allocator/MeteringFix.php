<?php

declare(strict_types=1);

namespace Atfm\Allocator;

use Atfm\Models\Airport;
use Atfm\Models\Flight;

/**
 * Resolve the STAR and arrival metering fix for a flight from its filed route.
 *
 * Uses the catalog in data/star-catalog.json — a hand-built index of the
 * 57 STARs across our 7 scope airports, with their entry fixes, transitions,
 * and the metering fix where Miles-in-Trail spacing is typically applied.
 *
 * The resolver is pure and derived: it reads only the flight's stored
 * fp_route and destination ICAO. No DB writes, no schema changes. This
 * makes historical attribution automatic — any completed flight with a
 * filed route resolves the same way as a live one.
 *
 * Typical output for an inbound to CYYZ:
 *   ['star' => 'NUBER6', 'entry_fix' => 'NUBER',
 *    'transitions' => ['YZEMN', 'MONEE'], 'metering_fix' => 'ERBUS']
 *
 * The STAR is matched by name (usually the last or second-to-last token in
 * the route) against the per-airport catalog. Transition fixes in the route
 * (the token preceding the STAR name) are captured for MIT traceability.
 */
final class MeteringFix
{
    private static ?array $catalog = null;
    /** @var array<string, array{lat: float, lon: float}>|null  ICAO => coords */
    private static ?array $airportCoords = null;

    /**
     * @return array{star:?string,entry_fix:?string,transitions:array<int,string>,metering_fix:?string,filed_transition:?string,inferred:bool}|null
     */
    public static function resolve(Flight $f): ?array
    {
        if (!$f->ades) return null;

        // Primary: STAR name in filed route
        if ($f->fp_route) {
            $r = self::resolveFromRoute($f->ades, (string) $f->fp_route);
            if ($r) {
                $r['inferred'] = false;
                return $r;
            }
        }

        // Fallback: infer from geometry. Pilots who file direct-to
        // without a STAR token still cross a metering fix corridor —
        // the fix closest in bearing to their approach direction is
        // what ATC would have them over. Assign that fix so the demand
        // distribution reflects real sector load.
        return self::inferByBearing($f);
    }

    /**
     * @return array{star:string,entry_fix:?string,transitions:array<int,string>,metering_fix:?string,filed_transition:?string}|null
     */
    public static function resolveFromRoute(string $ades, string $route): ?array
    {
        $ades = strtoupper($ades);
        $catalog = self::loadCatalog();
        $aptStars = $catalog['stars'][$ades] ?? null;
        if (!$aptStars) {
            return null;
        }

        // Tokenise route; strip speed/alt suffixes like "/N0460F370"
        $tokens = [];
        foreach (preg_split('/\s+/', trim($route)) as $t) {
            if ($t === '') continue;
            $t = strtoupper($t);
            if (str_contains($t, '/')) $t = explode('/', $t)[0];
            $tokens[] = $t;
        }

        // Scan tokens right-to-left for a STAR name present in the catalog.
        // Routes typically end with ...[TRANSITION] [STAR] [DESTINATION?].
        $starName = null;
        $starIndex = null;
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            if (isset($aptStars[$tokens[$i]])) {
                $starName = $tokens[$i];
                $starIndex = $i;
                break;
            }
        }
        if ($starName === null) {
            return null;
        }

        $star = $aptStars[$starName];
        $filedTransition = null;
        $previousToken = ($starIndex > 0) ? $tokens[$starIndex - 1] : null;

        // If the token immediately before the STAR matches a known transition
        // for this STAR, attribute it. Otherwise leave null — the STAR was
        // joined via a direct-to or a non-standard entry.
        if ($previousToken !== null && !empty($star['transitions'])
            && in_array($previousToken, $star['transitions'], true)) {
            $filedTransition = $previousToken;
        } elseif ($previousToken !== null && $previousToken === ($star['entry_fix'] ?? null)) {
            // Pilot filed entry fix directly (no transition)
            $filedTransition = $previousToken;
        }

        return [
            'star'            => $starName,
            'entry_fix'       => $star['entry_fix'] ?? null,
            'transitions'     => $star['transitions'] ?? [],
            'metering_fix'    => $star['metering_fix'] ?? null,
            'filed_transition'=> $filedTransition,
            'inferred'        => false,
        ];
    }

    /**
     * Infer metering fix for direct-to filers by geometry.
     *
     * Picks the metering fix whose bearing from the destination matches
     * most closely the bearing of the flight's origin point (current
     * airborne position, or ADEP coords for ground flights). Flights
     * approaching from the northwest get attributed to the northwest
     * corridor fix, etc.
     *
     * @return array{star:?string,entry_fix:?string,transitions:array<int,string>,metering_fix:?string,filed_transition:?string,inferred:bool}|null
     */
    private static function inferByBearing(Flight $f): ?array
    {
        $ades = strtoupper((string) $f->ades);
        $catalog = self::loadCatalog();
        $fixes = $catalog['metering_fixes'][$ades] ?? null;
        if (!$fixes || count($fixes) === 0) return null;

        // Destination coords
        $destCoords = self::loadAirportCoords()[$ades] ?? null;
        if (!$destCoords) return null;

        // Origin point: live position if airborne, else ADEP coords
        $fromLat = null; $fromLon = null;
        if ($f->last_lat !== null && $f->last_lon !== null) {
            $fromLat = (float) $f->last_lat;
            $fromLon = (float) $f->last_lon;
        } elseif ($f->adep) {
            $adep = self::loadAirportCoords()[strtoupper((string) $f->adep)] ?? null;
            if ($adep) {
                $fromLat = $adep['lat'];
                $fromLon = $adep['lon'];
            }
        }
        if ($fromLat === null) return null;

        // If origin is very close to dest (< 20 nm), bearing is noisy — skip
        if (self::distanceNm($fromLat, $fromLon, $destCoords['lat'], $destCoords['lon']) < 20) {
            return null;
        }

        // Bearing from ADES toward origin (direction flight is coming from)
        $originBearing = self::bearingDeg($destCoords['lat'], $destCoords['lon'], $fromLat, $fromLon);

        $bestFix = null;
        $bestDiff = 360.0;
        foreach ($fixes as $fixEntry) {
            $fc = $fixEntry['coords'] ?? null;
            if (!$fc) continue;
            $fixBearing = self::bearingDeg($destCoords['lat'], $destCoords['lon'], $fc['lat'], $fc['lon']);
            $diff = self::bearingDiffDeg($originBearing, $fixBearing);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestFix = $fixEntry;
            }
        }
        // Only accept if the origin bearing is reasonably aligned with a
        // corridor — > 75° off means the flight is coming from a direction
        // no corridor covers (e.g. overflying from an unexpected vector).
        if (!$bestFix || $bestDiff > 75) return null;

        $stars = $bestFix['stars'] ?? [];
        return [
            'star'             => $stars[0] ?? null, // most-common STAR at this fix
            'entry_fix'        => null,
            'transitions'      => [],
            'metering_fix'     => $bestFix['fix'],
            'filed_transition' => null,
            'inferred'         => true,
        ];
    }

    /** @return array<string, array{lat: float, lon: float}> */
    private static function loadAirportCoords(): array
    {
        if (self::$airportCoords !== null) return self::$airportCoords;
        self::$airportCoords = [];
        try {
            foreach (Airport::all(['icao', 'latitude', 'longitude']) as $a) {
                self::$airportCoords[(string) $a->icao] = [
                    'lat' => (float) $a->latitude,
                    'lon' => (float) $a->longitude,
                ];
            }
        } catch (\Throwable $e) {
            // DB not available (tests, etc.) — leave empty
        }
        return self::$airportCoords;
    }

    private static function bearingDeg(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $φ1 = deg2rad($lat1); $φ2 = deg2rad($lat2);
        $Δλ = deg2rad($lon2 - $lon1);
        $y = sin($Δλ) * cos($φ2);
        $x = cos($φ1) * sin($φ2) - sin($φ1) * cos($φ2) * cos($Δλ);
        $θ = rad2deg(atan2($y, $x));
        return fmod($θ + 360.0, 360.0);
    }

    private static function bearingDiffDeg(float $a, float $b): float
    {
        $d = abs($a - $b);
        return $d > 180 ? 360 - $d : $d;
    }

    private static function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 3440.065; // Earth radius in nm
        $φ1 = deg2rad($lat1); $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1); $Δλ = deg2rad($lon2 - $lon1);
        $a = sin($Δφ/2)**2 + cos($φ1) * cos($φ2) * sin($Δλ/2)**2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @return array<string, array<string, int>>  icao => fix => count
     */
    public static function loadCatalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        $path = dirname(__DIR__, 2) . '/data/star-catalog.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            self::$catalog = ['stars' => [], 'metering_fixes' => []];
            return self::$catalog;
        }
        $data = json_decode($raw, true);
        self::$catalog = is_array($data) ? $data : ['stars' => [], 'metering_fixes' => []];
        return self::$catalog;
    }

    /**
     * Summary of metering-fix load for an airport (or all airports).
     * Returns counts and callsigns grouped by metering fix — used by the
     * reports page to show MIT pressure per fix.
     *
     * @param array<int,Flight> $flights
     * @return array<string, array<string, array{count:int,callsigns:array<int,string>}>>  icao => fix => {count, callsigns}
     */
    public static function summarize(iterable $flights): array
    {
        $out = [];
        foreach ($flights as $f) {
            $r = self::resolve($f);
            if (!$r || !$r['metering_fix']) continue;
            $apt = $f->ades;
            $fix = $r['metering_fix'];
            $out[$apt] ??= [];
            $out[$apt][$fix] ??= ['count' => 0, 'callsigns' => []];
            $out[$apt][$fix]['count']++;
            $out[$apt][$fix]['callsigns'][] = $f->callsign;
        }
        return $out;
    }
}
