<?php

declare(strict_types=1);

namespace Atfm\Allocator;

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

    /**
     * @return array{star:string,entry_fix:?string,transitions:array<int,string>,metering_fix:?string,filed_transition:?string}|null
     */
    public static function resolve(Flight $f): ?array
    {
        if (!$f->ades || !$f->fp_route) {
            return null;
        }
        return self::resolveFromRoute($f->ades, (string) $f->fp_route);
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
        ];
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
