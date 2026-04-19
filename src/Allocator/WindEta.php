<?php

declare(strict_types=1);

namespace Atfm\Allocator;

use Atfm\Models\Airport;
use Atfm\Models\Flight;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Multi-level GRIB wind-corrected ELDT computation.
 *
 * Downloads GFS U/V winds at 250mb, 300mb, and 500mb from NOAA NOMADS
 * (1° grid, cached 6h). Selects the pressure level closest to the
 * flight's cruise altitude and integrates wind along the filed route
 * per grid cell.
 *
 * Pressure level → approximate FL mapping:
 *   250mb ≈ FL340  (jets at high cruise)
 *   300mb ≈ FL300  (medium-haul jets, regional jets)
 *   500mb ≈ FL180  (turboprops, low-altitude jets)
 *
 * Reuses Geo::parseRouteCoordinates() for 4-layer route resolution and
 * AircraftTas for TAS sanity gating. Authoritative in the ETA cascade
 * as WIND_GRIB (conf 92) — feeds directly into eldt.
 */
final class WindEta
{
    // Grid bounds (1-degree) — covers departure points for all our inbound
    // long-haul traffic AND the entire enroute portion.
    //   LAT 15-70°N : Canadian FIRs, CONUS, Caribbean (CYVR/CYYZ sun-dest),
    //                 Europe down to N. Africa, polar routes
    //   LON -170 to +30 : trans-Pacific east of dateline through CONUS,
    //                 NAT tracks, Europe west of Urals
    // Trans-Pacific flights west of the dateline (e.g. ACA64 at RKSI+1500nm,
    // lon 178°E) enter the grid ~30min after crossing into negative lon —
    // plenty of GRIB coverage before freeze at T-90min.
    private const LAT_MIN = 15;
    private const LAT_MAX = 70;
    private const LON_MIN = -170;
    private const LON_MAX = 30;

    private const GFS_CYCLES = [0, 6, 12, 18];
    private const CACHE_TTL_SEC = 21600; // 6 hours

    // Pressure levels to fetch (mb) and their approximate FL equivalents
    private const LEVELS_MB = [250, 300, 500];
    private const LEVEL_FL = [
        250 => 34000,  // FL340
        300 => 30000,  // FL300
        500 => 18000,  // FL180
    ];

    // Eligible airborne phases
    private const ELIGIBLE_PHASES = [
        Flight::PHASE_ENROUTE,
        Flight::PHASE_DEPARTED,
        Flight::PHASE_ARRIVING,
        Flight::PHASE_DESCENT,
    ];

    /** @var array<int, array{lats: float[], lons: float[], u: float[][], v: float[][]}>|null */
    private static ?array $windGrids = null;

    /**
     * Run wind computation for all eligible flights. Returns count of updated records.
     */
    public static function computeAll(): array
    {
        $grids = self::loadWindGrids();
        if ($grids === null) {
            return ['computed' => 0, 'updated' => 0, 'error' => 'GRIB fetch/parse failed'];
        }

        $airports = [];
        foreach (Airport::all() as $apt) {
            if ($apt->latitude && $apt->longitude) {
                $airports[$apt->icao] = [
                    'lat' => (float) $apt->latitude,
                    'lon' => (float) $apt->longitude,
                    'elev' => (int) ($apt->elevation_ft ?? 0),
                ];
            }
        }

        $flights = Flight::whereIn('phase', self::ELIGIBLE_PHASES)
            ->whereNotNull('last_lat')
            ->whereNotNull('last_lon')
            ->whereNull('aldt')
            ->get();

        $computed = 0;
        $updated = 0;

        foreach ($flights as $f) {
            if (!isset($airports[$f->ades])) {
                continue;
            }
            $windEldt = self::computeForFlight($f, $grids, $airports[$f->ades]);
            if ($windEldt !== null) {
                $computed++;
                $n = Flight::where('id', $f->id)
                    ->whereNull('aldt')
                    ->update(['eldt_wind' => $windEldt->format('Y-m-d H:i:s')]);
                $updated += $n;
            }
        }

        return ['computed' => $computed, 'updated' => $updated];
    }

    /**
     * Classify why WIND_GRIB can't/didn't produce a value for a flight.
     * Returns a short code suitable for UI display (or null if eligible).
     * Pure function — no DB / no network.
     *
     * @param array{lat: float, lon: float, elev: int}|null $dest
     */
    public static function classifyMissing(Flight $f, ?array $dest = null): ?string
    {
        // Not airborne → ground-tier ELDT applies, GRIB not expected
        if (!$f->isAirborne()) {
            return 'ground';
        }
        if ($f->last_lat === null || $f->last_lon === null) {
            return 'no_position';
        }
        $lat = (float) $f->last_lat;
        $lon = (float) $f->last_lon;

        // Grid bounds
        if ($lat < self::LAT_MIN || $lat > self::LAT_MAX
            || $lon < self::LON_MIN || $lon > self::LON_MAX) {
            return 'outside_grid';
        }

        // Still climbing — GRIB would use cruise-altitude winds for a
        // flight that isn't there yet; skip it.
        $curAlt = (int) ($f->last_altitude_ft ?? 0);
        $filedAlt = (int) ($f->fp_altitude_ft ?? 35000);
        if ($curAlt > 0 && $curAlt < $filedAlt - 5000) {
            return 'climbing';
        }

        if ($dest !== null) {
            $routeCoords = Geo::parseRouteCoordinates($f->fp_route ?? '');
            $legs = self::alongRouteLegs(
                $lat, $lon,
                (float) $dest['lat'], (float) $dest['lon'],
                $routeCoords
            );
            $distNm = 0.0;
            foreach ($legs as [$fLat, $fLon, $tLat, $tLon]) {
                $distNm += Geo::distanceNm($fLat, $fLon, $tLat, $tLon);
            }
            if ($distNm < 20.0) return 'too_close';
            if ($distNm > 4000.0) return 'too_far';
        }

        // Should have been eligible — if we got here but eldt_wind is still
        // null, the most likely cause is GRIB data not loaded this cycle.
        return 'grib_unavailable';
    }

    /**
     * Classify why a landed flight has no eldt_wind. Post-hoc: we can't
     * recheck grid bounds or position because the flight is gone, but we
     * can infer from the stored data shape.
     */
    public static function classifyHistoricalMissing(Flight $f): string
    {
        if (!$f->fp_route || strlen($f->fp_route) < 5) {
            return 'no_route';
        }
        if ($f->atot === null) {
            return 'no_takeoff';
        }
        $scope = ['CYHZ','CYOW','CYUL','CYVR','CYWG','CYYC','CYYZ'];
        if (!in_array($f->ades, $scope, true)) {
            return 'unknown_ades';
        }
        // Short-haul: if total flight duration was under ~60 min it was
        // mostly climb+descent, GRIB has little cruise to integrate.
        if ($f->aldt && $f->atot) {
            $durMin = ($f->aldt->getTimestamp() - $f->atot->getTimestamp()) / 60;
            if ($durMin < 45) return 'short_haul';
        }
        // All data looked computable — most likely the wind cron missed a
        // window or the NOAA fetch failed when this flight was at cruise.
        return 'grib_missed';
    }

    /**
     * Compute wind-corrected ELDT for a single flight.
     *
     * @param array<int, array{lats: float[], lons: float[], u: float[][], v: float[][]}> $grids keyed by mb
     * @param array{lat: float, lon: float, elev: int} $dest
     */
    public static function computeForFlight(Flight $f, array $grids, array $dest): ?DateTimeImmutable
    {
        $lat = $f->last_lat;
        $lon = $f->last_lon;
        if ($lat === null || $lon === null) {
            return null;
        }
        $lat = (float) $lat;
        $lon = (float) $lon;

        // Reject positions outside the GRIB grid
        if ($lat < self::LAT_MIN || $lat > self::LAT_MAX
            || $lon < self::LON_MIN || $lon > self::LON_MAX) {
            return null;
        }

        $destLat = $dest['lat'];
        $destLon = $dest['lon'];
        $destElev = $dest['elev'];

        $cruiseKt = self::selectTas($f);

        $cruiseAlt = ($f->last_altitude_ft && $f->last_altitude_ft > 10000)
            ? (int) $f->last_altitude_ft
            : (int) ($f->fp_altitude_ft ?? 35000);

        // Select wind level closest to cruise altitude
        $grid = self::selectLevel($grids, $cruiseAlt);
        if ($grid === null) {
            return null;
        }

        // Route resolution + along-route legs
        $routeCoords = Geo::parseRouteCoordinates($f->fp_route ?? '');
        $legs = self::alongRouteLegs($lat, $lon, $destLat, $destLon, $routeCoords);
        $distNm = 0.0;
        foreach ($legs as [$fromLat, $fromLon, $toLat, $toLon]) {
            $distNm += Geo::distanceNm($fromLat, $fromLon, $toLat, $toLon);
        }

        if ($distNm < 20.0 || $distNm > 4000.0) {
            return null;
        }

        // Wind-corrected ETA: integrate wind per grid cell along route
        $altAbove = max(0, $cruiseAlt - $destElev);
        $todDist = $altAbove / 318.0;
        $cruiseRemaining = max(0.0, $distNm - $todDist);

        $windCruiseMin = 0.0;
        $distAccum = 0.0;

        foreach ($legs as [$fromLat, $fromLon, $toLat, $toLon]) {
            $cells = self::gridCellSegments($fromLat, $fromLon, $toLat, $toLon);
            foreach ($cells as [$cLat1, $cLon1, $cLat2, $cLon2]) {
                $cellNm = Geo::distanceNm($cLat1, $cLon1, $cLat2, $cLon2);
                if ($cellNm < 0.1) {
                    continue;
                }
                $cruiseInCell = min($cellNm, max(0.0, $cruiseRemaining - $distAccum));
                if ($cruiseInCell <= 0) {
                    break;
                }

                $midLat = ($cLat1 + $cLat2) / 2;
                $midLon = ($cLon1 + $cLon2) / 2;
                [$uKt, $vKt] = self::interpolateWind($grid, $midLat, $midLon);
                $cellBearing = self::bearingDeg($cLat1, $cLon1, $cLat2, $cLon2);
                $wAlong = self::windAlongTrack($uKt, $vKt, $cellBearing);

                $effKt = max(150.0, min($cruiseKt + $wAlong, 700.0));
                $windCruiseMin += ($cruiseInCell / $effKt) * 60.0;
                $distAccum += $cellNm;
            }

            if ($distAccum >= $cruiseRemaining) {
                break;
            }
        }

        // Descent (no wind correction — low-level wind not representative)
        $descentIasHigh = AircraftTas::descentIasHigh($f->aircraft_type ?? '');
        $fl100Agl = max(0, 10000 - $destElev);
        $fl100Dist = $fl100Agl / 318.0;
        $descentMin = self::descentSegmentMinutes($todDist, $fl100Dist, $descentIasHigh);

        $totalMin = $windCruiseMin + $descentMin;
        $epochSec = time() + (int) round($totalMin * 60);

        return new DateTimeImmutable('@' . $epochSec);
    }

    // ------------------------------------------------------------------
    //  Level selection
    // ------------------------------------------------------------------

    /**
     * Select the wind grid closest to the flight's cruise altitude.
     *
     * 250mb ≈ FL340 — jets at high cruise
     * 300mb ≈ FL300 — medium jets, regional jets
     * 500mb ≈ FL180 — turboprops, low-altitude
     *
     * @param array<int, array> $grids keyed by pressure level (mb)
     */
    private static function selectLevel(array $grids, int $altFt): ?array
    {
        $bestMb = null;
        $bestDiff = PHP_INT_MAX;
        foreach (self::LEVEL_FL as $mb => $fl) {
            if (!isset($grids[$mb])) {
                continue;
            }
            $diff = abs($altFt - $fl);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestMb = $mb;
            }
        }
        return $bestMb !== null ? $grids[$bestMb] : null;
    }

    // ------------------------------------------------------------------
    //  TAS selection (mirrors EtaEstimator sanity gate)
    // ------------------------------------------------------------------

    private static function selectTas(Flight $f): int
    {
        $actype = $f->aircraft_type ?? '';
        $filedTas = $f->fp_cruise_tas;

        if ($filedTas !== null && ($filedTas < 120 || $filedTas > 650)) {
            $filedTas = null;
        }

        if ($filedTas !== null && AircraftTas::known($actype)) {
            $typeTas = AircraftTas::typicalTas($actype);
            if (abs($filedTas - $typeTas) > $typeTas * 0.30) {
                $filedTas = null;
            }
        }

        if ($filedTas !== null) {
            return (int) $filedTas;
        }
        if (AircraftTas::known($actype)) {
            return AircraftTas::typicalTas($actype);
        }
        $gs = $f->last_groundspeed_kts;
        if ($gs !== null && $gs > 100) {
            return (int) $gs;
        }
        return 450;
    }

    // ------------------------------------------------------------------
    //  Route legs
    // ------------------------------------------------------------------

    /**
     * @param array<array{float,float}> $routeCoords
     * @return array<array{float,float,float,float}>
     */
    private static function alongRouteLegs(
        float $curLat, float $curLon,
        float $destLat, float $destLon,
        array $routeCoords
    ): array {
        if (empty($routeCoords)) {
            return [[$curLat, $curLon, $destLat, $destLon]];
        }

        $directDist = Geo::distanceNm($curLat, $curLon, $destLat, $destLon);
        $ahead = [];
        foreach ($routeCoords as [$wLat, $wLon]) {
            if (Geo::distanceNm($wLat, $wLon, $destLat, $destLon) < $directDist) {
                $ahead[] = [$wLat, $wLon];
            }
        }

        if (empty($ahead)) {
            return [[$curLat, $curLon, $destLat, $destLon]];
        }

        $legs = [[$curLat, $curLon, $ahead[0][0], $ahead[0][1]]];
        for ($j = 1; $j < count($ahead); $j++) {
            $legs[] = [$ahead[$j - 1][0], $ahead[$j - 1][1], $ahead[$j][0], $ahead[$j][1]];
        }
        $last = $ahead[count($ahead) - 1];
        $legs[] = [$last[0], $last[1], $destLat, $destLon];

        $total = 0.0;
        foreach ($legs as [$fLat, $fLon, $tLat, $tLon]) {
            $total += Geo::distanceNm($fLat, $fLon, $tLat, $tLon);
        }
        if ($total < $directDist || $total > $directDist * 1.30) {
            return [[$curLat, $curLon, $destLat, $destLon]];
        }

        return $legs;
    }

    // ------------------------------------------------------------------
    //  Grid cell segmentation
    // ------------------------------------------------------------------

    /** @return array<array{float,float,float,float}> */
    private static function gridCellSegments(
        float $lat1, float $lon1,
        float $lat2, float $lon2
    ): array {
        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;
        $crossings = [0.0, 1.0];

        if (abs($dlon) > 1e-9) {
            $lo = min($lon1, $lon2);
            $hi = max($lon1, $lon2);
            for ($lonInt = (int) ceil($lo); $lonInt <= (int) floor($hi); $lonInt++) {
                $frac = ($lonInt - $lon1) / $dlon;
                if ($frac > 0 && $frac < 1) {
                    $crossings[] = $frac;
                }
            }
        }

        if (abs($dlat) > 1e-9) {
            $lo = min($lat1, $lat2);
            $hi = max($lat1, $lat2);
            for ($latInt = (int) ceil($lo); $latInt <= (int) floor($hi); $latInt++) {
                $frac = ($latInt - $lat1) / $dlat;
                if ($frac > 0 && $frac < 1) {
                    $crossings[] = $frac;
                }
            }
        }

        sort($crossings);

        $segments = [];
        for ($k = 0; $k < count($crossings) - 1; $k++) {
            $f1 = $crossings[$k];
            $f2 = $crossings[$k + 1];
            if ($f2 - $f1 < 1e-12) {
                continue;
            }
            $segments[] = [
                $lat1 + $f1 * $dlat,
                $lon1 + $f1 * $dlon,
                $lat1 + $f2 * $dlat,
                $lon1 + $f2 * $dlon,
            ];
        }

        return $segments ?: [[$lat1, $lon1, $lat2, $lon2]];
    }

    // ------------------------------------------------------------------
    //  Bearing + wind projection
    // ------------------------------------------------------------------

    private static function bearingDeg(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1r = deg2rad($lat1);
        $lat2r = deg2rad($lat2);
        $dlon = deg2rad($lon2 - $lon1);
        $x = sin($dlon) * cos($lat2r);
        $y = cos($lat1r) * sin($lat2r) - sin($lat1r) * cos($lat2r) * cos($dlon);
        return fmod(rad2deg(atan2($x, $y)) + 360, 360);
    }

    private static function windAlongTrack(float $uKt, float $vKt, float $headingDeg): float
    {
        $hdgRad = deg2rad($headingDeg);
        return $uKt * sin($hdgRad) + $vKt * cos($hdgRad);
    }

    // ------------------------------------------------------------------
    //  Descent
    // ------------------------------------------------------------------

    private static function descentSegmentMinutes(float $distNm, float $fl100DistNm, int $iasHighKt = 280): float
    {
        if ($distNm <= 0) {
            return 0.0;
        }
        $time = 0.0;
        $remaining = $distNm;

        foreach ([[2.0, 140], [3.0, 180], [5.0, 220], [10.0, 220]] as [$seg, $spd]) {
            $s = min($seg, $remaining);
            $time += ($s / $spd) * 60;
            $remaining -= $s;
            if ($remaining <= 0) return $time;
        }

        $belowFl100 = max(0.0, $fl100DistNm - 20.0);
        $s = min($belowFl100, $remaining);
        $time += ($s / 250.0) * 60;
        $remaining -= $s;
        if ($remaining <= 0) return $time;

        $gsHighKt = (int) round($iasHighKt * 1.3);
        $time += ($remaining / $gsHighKt) * 60;
        return $time;
    }

    // ------------------------------------------------------------------
    //  Wind interpolation
    // ------------------------------------------------------------------

    /** @return array{float, float} [u_kt, v_kt] */
    private static function interpolateWind(array $grid, float $lat, float $lon): array
    {
        $lats = $grid['lats'];
        $lons = $grid['lons'];
        $nLat = count($lats);
        $nLon = count($lons);

        $lat = max($lats[0], min($lats[$nLat - 1], $lat));
        $lon = max($lons[0], min($lons[$nLon - 1], $lon));

        $latIdx = self::searchSorted($lats, $lat);
        $latIdx = max(0, min($latIdx - 1, $nLat - 2));
        $lonIdx = self::searchSorted($lons, $lon);
        $lonIdx = max(0, min($lonIdx - 1, $nLon - 2));

        $latFrac = ($lats[$latIdx + 1] - $lats[$latIdx]) != 0
            ? ($lat - $lats[$latIdx]) / ($lats[$latIdx + 1] - $lats[$latIdx])
            : 0.0;
        $lonFrac = ($lons[$lonIdx + 1] - $lons[$lonIdx]) != 0
            ? ($lon - $lons[$lonIdx]) / ($lons[$lonIdx + 1] - $lons[$lonIdx])
            : 0.0;

        $bilerp = function (array $d) use ($latIdx, $lonIdx, $latFrac, $lonFrac): float {
            $c0 = $d[$latIdx][$lonIdx] * (1 - $lonFrac) + $d[$latIdx][$lonIdx + 1] * $lonFrac;
            $c1 = $d[$latIdx + 1][$lonIdx] * (1 - $lonFrac) + $d[$latIdx + 1][$lonIdx + 1] * $lonFrac;
            return $c0 * (1 - $latFrac) + $c1 * $latFrac;
        };

        $msToKt = 1.94384;
        return [$bilerp($grid['u']) * $msToKt, $bilerp($grid['v']) * $msToKt];
    }

    private static function searchSorted(array $arr, float $val): int
    {
        $lo = 0;
        $hi = count($arr);
        while ($lo < $hi) {
            $mid = (int) (($lo + $hi) / 2);
            if ($arr[$mid] < $val) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    // ------------------------------------------------------------------
    //  GRIB2 fetching and parsing (multi-level)
    // ------------------------------------------------------------------

    /**
     * Load multi-level wind grids, fetching GRIB if needed.
     *
     * @return array<int, array{lats: float[], lons: float[], u: float[][], v: float[][]}>|null
     *         Keyed by pressure level in mb (250, 300, 500).
     */
    public static function loadWindGrids(): ?array
    {
        if (self::$windGrids !== null) {
            return self::$windGrids;
        }

        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            [$dateStr, $cycle] = self::latestGfsCycle($now);
            $gribData = self::fetchGrib($dateStr, $cycle);
            if ($gribData === null) {
                return null;
            }
            self::$windGrids = self::parseMultiLevelWindGrid($gribData);
            return self::$windGrids;
        } catch (\Throwable $e) {
            error_log("[wind-eta] GRIB load failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Backwards-compatible single-grid loader. Returns the 250mb grid
     * or whichever level is available. Used by EtaEstimator integration.
     */
    public static function loadWindGrid(): ?array
    {
        $grids = self::loadWindGrids();
        return $grids[250] ?? ($grids ? reset($grids) : null);
    }

    private static function latestGfsCycle(DateTimeImmutable $now): array
    {
        $adjusted = $now->modify('-4 hours');
        $hour = (int) $adjusted->format('G');
        $cycleHour = 0;
        foreach (self::GFS_CYCLES as $h) {
            if ($h <= $hour) {
                $cycleHour = $h;
            }
        }
        return [$adjusted->format('Ymd'), sprintf('%02d', $cycleHour)];
    }

    /**
     * Fetch GFS multi-level GRIB2 data (250mb + 300mb + 500mb).
     */
    private static function fetchGrib(string $dateStr, string $cycle): ?string
    {
        $cacheDir = sys_get_temp_dir() . '/atfm-grib-cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        // Include grid bounds in cache key so an extension (e.g. LON_MIN
        // westward expansion) doesn't serve stale narrower-region data.
        $bounds = self::LAT_MIN . 'x' . self::LAT_MAX . '_' . self::LON_MIN . 'x' . self::LON_MAX;
        $cacheFile = $cacheDir . "/gfs_{$dateStr}_{$cycle}z_multilevel_{$bounds}.grib2";

        if (file_exists($cacheFile)) {
            $age = time() - filemtime($cacheFile);
            if ($age < self::CACHE_TTL_SEC) {
                return file_get_contents($cacheFile);
            }
        }

        // Request all three levels in one call
        $levParams = implode('', array_map(fn($mb) => "&lev_{$mb}_mb=on", self::LEVELS_MB));
        $url = "https://nomads.ncep.noaa.gov/cgi-bin/filter_gfs_1p00.pl?"
            . "dir=%2Fgfs.{$dateStr}%2F{$cycle}%2Fatmos"
            . "&file=gfs.t{$cycle}z.pgrb2.1p00.anl"
            . "&var_UGRD=on&var_VGRD=on" . $levParams
            . "&subregion=&toplat=" . self::LAT_MAX . "&leftlon=" . self::LON_MIN
            . "&rightlon=" . self::LON_MAX . "&bottomlat=" . self::LAT_MIN;

        $ctx = stream_context_create([
            'http' => ['timeout' => 30],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || strlen($data) < 100) {
            error_log("[wind-eta] GRIB download failed for {$dateStr}/{$cycle}z");
            if (file_exists($cacheFile)) {
                return file_get_contents($cacheFile);
            }
            // Try single-level fallback (old cache file)
            $oldCache = $cacheDir . "/gfs_{$dateStr}_{$cycle}z_250mb.grib2";
            if (file_exists($oldCache)) {
                return file_get_contents($oldCache);
            }
            return null;
        }

        @file_put_contents($cacheFile, $data);
        return $data;
    }

    /**
     * Parse multi-level GRIB2 into per-level wind grids.
     *
     * @return array<int, array{lats: float[], lons: float[], u: float[][], v: float[][]}>
     */
    private static function parseMultiLevelWindGrid(string $data): array
    {
        $messages = self::parseGrib2Messages($data);

        // Group by level: messages have 'level_mb', 'param' (u/v)
        $byLevel = [];
        foreach ($messages as $msg) {
            $mb = $msg['level_mb'] ?? 0;
            $param = $msg['param'];
            if ($param !== 'u' && $param !== 'v') {
                continue;
            }
            $byLevel[$mb][$param] = $msg;
        }

        $grids = [];
        foreach ($byLevel as $mb => $params) {
            if (!isset($params['u']) || !isset($params['v'])) {
                continue;
            }
            $grids[$mb] = self::buildGridFromMessages($params['u'], $params['v']);
        }

        if (empty($grids)) {
            throw new \RuntimeException('GRIB2: no valid wind levels found');
        }

        return $grids;
    }

    /**
     * Build a normalized grid (lats ascending, lons in [-180,180]) from U/V messages.
     */
    private static function buildGridFromMessages(array $uMsg, array $vMsg): array
    {
        $nj = $uMsg['nj'];
        $ni = $uMsg['ni'];

        $lats = [];
        for ($j = 0; $j < $nj; $j++) {
            $lats[] = $uMsg['lat1'] + $j * ($uMsg['lat2'] - $uMsg['lat1']) / max(1, $nj - 1);
        }
        $lons = [];
        for ($i = 0; $i < $ni; $i++) {
            $lons[] = $uMsg['lon1'] + $i * ($uMsg['lon2'] - $uMsg['lon1']) / max(1, $ni - 1);
        }

        $needsSort = false;
        foreach ($lons as &$l) {
            if ($l > 180) {
                $l -= 360;
                $needsSort = true;
            }
        }
        unset($l);

        $u = $uMsg['values'];
        $v = $vMsg['values'];

        if ($needsSort) {
            $idx = range(0, count($lons) - 1);
            array_multisort($lons, SORT_ASC, $idx);
            $uSorted = $vSorted = [];
            for ($j = 0; $j < $nj; $j++) {
                $uRow = $vRow = [];
                foreach ($idx as $i) {
                    $uRow[] = $u[$j][$i];
                    $vRow[] = $v[$j][$i];
                }
                $uSorted[] = $uRow;
                $vSorted[] = $vRow;
            }
            $u = $uSorted;
            $v = $vSorted;
        }

        if ($lats[0] > $lats[$nj - 1]) {
            $lats = array_reverse($lats);
            $u = array_reverse($u);
            $v = array_reverse($v);
        }

        return ['lats' => $lats, 'lons' => $lons, 'u' => $u, 'v' => $v];
    }

    /**
     * Parse GRIB2 messages with pressure level extraction.
     *
     * Section 4 (product definition) contains the pressure level in the
     * "first fixed surface" fields: type=100 (isobaric), scale+value.
     */
    private static function parseGrib2Messages(string $data): array
    {
        $messages = [];
        $len = strlen($data);
        $pos = 0;

        while ($pos < $len - 4) {
            if (substr($data, $pos, 4) !== 'GRIB') {
                $pos++;
                continue;
            }

            $edition = ord($data[$pos + 7]);
            if ($edition !== 2) {
                throw new \RuntimeException("Expected GRIB2, got edition {$edition}");
            }
            $discipline = ord($data[$pos + 6]);

            $totalLen = self::unpackUint64($data, $pos + 8);
            $msgEnd = $pos + $totalLen;

            $secPos = $pos + 16;
            $ni = $nj = 0;
            $lat1 = $lon1 = $lat2 = $lon2 = 0.0;
            $paramCat = $paramNum = 0;
            $levelMb = 0;
            $refVal = 0.0;
            $binScale = $decScale = $nbits = 0;
            $packedData = '';

            while ($secPos < $msgEnd - 4) {
                $secLen = unpack('N', substr($data, $secPos, 4))[1];
                $secNum = ord($data[$secPos + 4]);
                if ($secLen === 0) break;

                if ($secNum === 3) {
                    $ni = unpack('N', substr($data, $secPos + 30, 4))[1];
                    $nj = unpack('N', substr($data, $secPos + 34, 4))[1];
                    $lat1 = self::unpackSint32($data, $secPos + 46) / 1e6;
                    $lon1 = self::unpackSint32($data, $secPos + 50) / 1e6;
                    $lat2 = self::unpackSint32($data, $secPos + 55) / 1e6;
                    $lon2 = self::unpackSint32($data, $secPos + 59) / 1e6;
                } elseif ($secNum === 4) {
                    $paramCat = ord($data[$secPos + 9]);
                    $paramNum = ord($data[$secPos + 10]);
                    // Extract pressure level from first fixed surface
                    // Offset 22: type of first fixed surface (100 = isobaric in Pa)
                    // Offset 23: scale factor
                    // Offset 24-27: scaled value
                    if ($secLen > 27) {
                        $surfType = ord($data[$secPos + 22]);
                        if ($surfType === 100) { // isobaric surface
                            $scaleFactor = ord($data[$secPos + 23]);
                            $scaledValue = unpack('N', substr($data, $secPos + 24, 4))[1];
                            // Value is in Pa, convert to mb (hPa)
                            $levelPa = $scaledValue / (10 ** $scaleFactor);
                            $levelMb = (int) round($levelPa / 100);
                        }
                    }
                } elseif ($secNum === 5) {
                    $refVal = unpack('G', substr($data, $secPos + 11, 4))[1];
                    $binScale = self::unpackSint16($data, $secPos + 15);
                    $decScale = self::unpackSint16($data, $secPos + 17);
                    $nbits = ord($data[$secPos + 19]);
                } elseif ($secNum === 7) {
                    $packedData = substr($data, $secPos + 5, $secLen - 5);
                }

                $secPos += $secLen;
            }

            $npts = $ni * $nj;
            if ($nbits > 0 && strlen($packedData) > 0) {
                $vals = self::unpackSimplePacking($packedData, $npts, $nbits, $refVal, $binScale, $decScale);
            } else {
                $fillVal = $refVal / (10.0 ** $decScale);
                $vals = array_fill(0, $npts, $fillVal);
            }

            $values2d = [];
            for ($j = 0; $j < $nj; $j++) {
                $values2d[] = array_slice($vals, $j * $ni, $ni);
            }

            $param = 'unknown';
            if ($discipline === 0 && $paramCat === 2) {
                $param = ($paramNum === 2) ? 'u' : (($paramNum === 3) ? 'v' : "c{$paramCat}n{$paramNum}");
            }

            $messages[] = [
                'param' => $param,
                'level_mb' => $levelMb,
                'values' => $values2d,
                'ni' => $ni,
                'nj' => $nj,
                'lat1' => $lat1,
                'lon1' => $lon1,
                'lat2' => $lat2,
                'lon2' => $lon2,
            ];

            $pos = $msgEnd;
        }

        return $messages;
    }

    /** @return float[] */
    private static function unpackSimplePacking(
        string $packed, int $npts, int $nbits,
        float $refVal, int $binScale, int $decScale
    ): array {
        $vals = [];
        $binFactor = 2.0 ** $binScale;
        $decFactor = 10.0 ** $decScale;

        $byteLen = strlen($packed);
        $bitBuf = 0;
        $bitsInBuf = 0;
        $bytePos = 0;

        for ($i = 0; $i < $npts; $i++) {
            while ($bitsInBuf < $nbits && $bytePos < $byteLen) {
                $bitBuf = ($bitBuf << 8) | ord($packed[$bytePos]);
                $bitsInBuf += 8;
                $bytePos++;
            }

            $shift = $bitsInBuf - $nbits;
            $raw = ($shift >= 0) ? ($bitBuf >> $shift) & ((1 << $nbits) - 1) : 0;
            $bitsInBuf -= $nbits;
            if ($bitsInBuf > 0) {
                $bitBuf &= (1 << $bitsInBuf) - 1;
            } else {
                $bitBuf = 0;
            }

            $vals[] = ($refVal + $raw * $binFactor) / $decFactor;
        }

        return $vals;
    }

    // ------------------------------------------------------------------
    //  Binary helpers
    // ------------------------------------------------------------------

    private static function unpackUint64(string $data, int $offset): int
    {
        $hi = unpack('N', substr($data, $offset, 4))[1];
        $lo = unpack('N', substr($data, $offset + 4, 4))[1];
        return ($hi << 32) | $lo;
    }

    private static function unpackSint32(string $data, int $offset): int
    {
        $val = unpack('N', substr($data, $offset, 4))[1];
        if ($val >= 0x80000000) {
            $val -= 0x100000000;
        }
        return $val;
    }

    private static function unpackSint16(string $data, int $offset): int
    {
        $val = unpack('n', substr($data, $offset, 2))[1];
        if ($val >= 0x8000) {
            $val -= 0x10000;
        }
        return $val;
    }
}
