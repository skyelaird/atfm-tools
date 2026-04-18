<?php

declare(strict_types=1);

namespace Atfm\Allocator;

use Atfm\Models\Airport;
use Atfm\Models\Flight;
use DateTimeImmutable;
use DateTimeZone;

/**
 * GRIB-based wind-corrected ELDT computation.
 *
 * Downloads GFS 250mb U/V winds from NOAA NOMADS (1° grid, cached 6h),
 * integrates wind along the filed route per grid cell, and produces a
 * wind-corrected ETA for each eligible airborne flight.
 *
 * Reuses Geo::parseRouteCoordinates() for 4-layer route resolution and
 * AircraftTas for TAS sanity gating. Writes eldt_wind directly to the
 * flights table (no API round-trip).
 *
 * The wind grid covers LAT 40–65, LON -130 to -30 — all Canadian FIRs
 * plus NAT tracks and European departure corridors.
 */
final class WindEta
{
    // Grid bounds (1-degree) — covers Canadian FIRs + NAT + European departures
    private const LAT_MIN = 40;
    private const LAT_MAX = 65;
    private const LON_MIN = -130;
    private const LON_MAX = -30;

    private const GFS_CYCLES = [0, 6, 12, 18];
    private const CACHE_TTL_SEC = 21600; // 6 hours
    private const DESCENT_FPM = 318; // ft per nm on 3° glidepath

    // Eligible airborne phases
    private const ELIGIBLE_PHASES = [
        Flight::PHASE_ENROUTE,
        Flight::PHASE_DEPARTED,
        Flight::PHASE_ARRIVING,
        Flight::PHASE_DESCENT,
    ];

    /** @var array{lats: float[], lons: float[], u: float[][], v: float[][]}|null */
    private static ?array $windGrid = null;

    /**
     * Run wind computation for all eligible flights. Returns count of updated records.
     */
    public static function computeAll(): array
    {
        $grid = self::loadWindGrid();
        if ($grid === null) {
            return ['computed' => 0, 'updated' => 0, 'error' => 'GRIB fetch/parse failed'];
        }

        // Load airport coords indexed by ICAO
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

        // Fetch eligible flights
        $flights = Flight::whereIn('phase', self::ELIGIBLE_PHASES)
            ->whereNotNull('last_lat')
            ->whereNotNull('last_lon')
            ->whereNull('aldt')
            ->get();

        $computed = 0;
        $updated = 0;

        foreach ($flights as $f) {
            $ades = $f->ades;
            if (!isset($airports[$ades])) {
                continue;
            }

            $windEldt = self::computeForFlight($f, $grid, $airports[$ades]);
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
     * Compute wind-corrected ELDT for a single flight.
     *
     * @param array{lat: float, lon: float, elev: int} $dest
     */
    public static function computeForFlight(Flight $f, array $grid, array $dest): ?DateTimeImmutable
    {
        $lat = $f->last_lat;
        $lon = $f->last_lon;
        if ($lat === null || $lon === null) {
            return null;
        }
        $lat = (float) $lat;
        $lon = (float) $lon;

        $destLat = $dest['lat'];
        $destLon = $dest['lon'];
        $destElev = $dest['elev'];

        // TAS selection — mirrors EtaEstimator sanity gate
        $cruiseKt = self::selectTas($f);

        $cruiseAlt = ($f->last_altitude_ft && $f->last_altitude_ft > 10000)
            ? (int) $f->last_altitude_ft
            : (int) ($f->fp_altitude_ft ?? 35000);

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

        // Descent (no wind correction — 250mb wind not representative below FL100)
        $descentIasHigh = AircraftTas::descentIasHigh($f->aircraft_type ?? '');
        $fl100Agl = max(0, 10000 - $destElev);
        $fl100Dist = $fl100Agl / 318.0;
        $descentMin = self::descentSegmentMinutes($todDist, $fl100Dist, $descentIasHigh);

        $totalMin = $windCruiseMin + $descentMin;
        $epochSec = time() + (int) round($totalMin * 60);

        return new DateTimeImmutable('@' . $epochSec);
    }

    // ------------------------------------------------------------------
    //  TAS selection (mirrors Python + PHP EtaEstimator sanity gate)
    // ------------------------------------------------------------------

    private static function selectTas(Flight $f): int
    {
        $actype = $f->aircraft_type ?? '';
        $filedTas = $f->fp_cruise_tas;

        // Basic range check
        if ($filedTas !== null && ($filedTas < 120 || $filedTas > 650)) {
            $filedTas = null;
        }

        // Sanity gate: reject if >30% off type table
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
    //  Route legs (mirrors Python along_route_legs)
    // ------------------------------------------------------------------

    /**
     * Build legs from aircraft through ahead-waypoints to destination.
     *
     * @param array<array{float,float}> $routeCoords
     * @return array<array{float,float,float,float}> legs as [fromLat, fromLon, toLat, toLon]
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

        // Keep waypoints that are ahead (closer to dest than we are)
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

        // Sanity: total shouldn't exceed 1.3x direct
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

    /**
     * Break a leg into sub-segments at 1° grid boundaries for per-cell
     * wind interpolation.
     *
     * @return array<array{float,float,float,float}>
     */
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
    //  Descent (mirrors Python + Geo::descentSegmentMinutes)
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

    /**
     * Bilinear interpolation of U/V wind at a point.
     *
     * @return array{float, float} [u_kt, v_kt]
     */
    private static function interpolateWind(array $grid, float $lat, float $lon): array
    {
        $lats = $grid['lats'];
        $lons = $grid['lons'];
        $nLat = count($lats);
        $nLon = count($lons);

        $lat = max($lats[0], min($lats[$nLat - 1], $lat));
        $lon = max($lons[0], min($lons[$nLon - 1], $lon));

        // Find bounding indices
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

    /**
     * Binary search: find index where $val would be inserted in sorted $arr.
     */
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
    //  GRIB2 fetching and parsing (pure PHP, no extensions needed)
    // ------------------------------------------------------------------

    /**
     * Load wind grid, fetching GRIB if needed. Returns null on failure.
     *
     * @return array{lats: float[], lons: float[], u: float[][], v: float[][]}|null
     */
    public static function loadWindGrid(): ?array
    {
        if (self::$windGrid !== null) {
            return self::$windGrid;
        }

        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            [$dateStr, $cycle] = self::latestGfsCycle($now);
            $gribData = self::fetchGrib($dateStr, $cycle);
            if ($gribData === null) {
                return null;
            }
            self::$windGrid = self::parseWindGrid($gribData);
            return self::$windGrid;
        } catch (\Throwable $e) {
            error_log("[wind-eta] GRIB load failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Determine most recent GFS cycle (accounting for ~4h publication lag).
     *
     * @return array{string, string} [YYYYMMDD, HH]
     */
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
     * Fetch GFS 250mb GRIB2 data, cached to temp dir.
     */
    private static function fetchGrib(string $dateStr, string $cycle): ?string
    {
        $cacheDir = sys_get_temp_dir() . '/atfm-grib-cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . "/gfs_{$dateStr}_{$cycle}z_250mb.grib2";

        // Check cache
        if (file_exists($cacheFile)) {
            $age = time() - filemtime($cacheFile);
            if ($age < self::CACHE_TTL_SEC) {
                return file_get_contents($cacheFile);
            }
        }

        $url = "https://nomads.ncep.noaa.gov/cgi-bin/filter_gfs_1p00.pl?"
            . "dir=%2Fgfs.{$dateStr}%2F{$cycle}%2Fatmos"
            . "&file=gfs.t{$cycle}z.pgrb2.1p00.anl"
            . "&var_UGRD=on&var_VGRD=on&lev_250_mb=on"
            . "&subregion=&toplat=" . self::LAT_MAX . "&leftlon=" . self::LON_MIN
            . "&rightlon=" . self::LON_MAX . "&bottomlat=" . self::LAT_MIN;

        $ctx = stream_context_create([
            'http' => ['timeout' => 30],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || strlen($data) < 100) {
            error_log("[wind-eta] GRIB download failed for {$dateStr}/{$cycle}z");
            // Fall back to cached file even if stale
            if (file_exists($cacheFile)) {
                return file_get_contents($cacheFile);
            }
            return null;
        }

        @file_put_contents($cacheFile, $data);
        return $data;
    }

    /**
     * Parse GFS 1° subregion GRIB2 into U/V wind arrays.
     *
     * Handles simple packing (template 5.0) on a regular lat/lon grid.
     * Returns structured array with lats, lons, u, v (2D arrays indexed
     * [lat_idx][lon_idx]).
     *
     * @return array{lats: float[], lons: float[], u: float[][], v: float[][]}
     */
    private static function parseWindGrid(string $data): array
    {
        $messages = self::parseGrib2Messages($data);

        $uMsg = $vMsg = null;
        foreach ($messages as $msg) {
            if ($msg['param'] === 'u') $uMsg = $msg;
            if ($msg['param'] === 'v') $vMsg = $msg;
        }
        if ($uMsg === null || $vMsg === null) {
            throw new \RuntimeException('GRIB2 missing U or V wind component');
        }

        // Build lat/lon axes
        $lats = [];
        $nj = $uMsg['nj'];
        $ni = $uMsg['ni'];
        $lat1 = $uMsg['lat1'];
        $lat2 = $uMsg['lat2'];
        $lon1 = $uMsg['lon1'];
        $lon2 = $uMsg['lon2'];

        for ($j = 0; $j < $nj; $j++) {
            $lats[] = $lat1 + $j * ($lat2 - $lat1) / max(1, $nj - 1);
        }
        $lons = [];
        for ($i = 0; $i < $ni; $i++) {
            $lons[] = $lon1 + $i * ($lon2 - $lon1) / max(1, $ni - 1);
        }

        // Normalize longitudes to [-180, 180]
        $needsSort = false;
        foreach ($lons as &$l) {
            if ($l > 180) {
                $l -= 360;
                $needsSort = true;
            }
        }
        unset($l);

        // Sort lons and rearrange U/V columns if needed
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

        // Ensure lats ascending
        if ($lats[0] > $lats[$nj - 1]) {
            $lats = array_reverse($lats);
            $u = array_reverse($u);
            $v = array_reverse($v);
        }

        return ['lats' => $lats, 'lons' => $lons, 'u' => $u, 'v' => $v];
    }

    /**
     * Parse GRIB2 messages from raw binary data.
     *
     * Handles section 3 (grid definition), section 4 (product definition),
     * section 5 (data representation), and section 7 (data).
     *
     * @return array<array{param: string, values: float[][], ni: int, nj: int, lat1: float, lon1: float, lat2: float, lon2: float}>
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

            // Total message length (8 bytes at offset 8)
            $totalLen = self::unpackUint64($data, $pos + 8);
            $msgEnd = $pos + $totalLen;

            $secPos = $pos + 16;
            $ni = $nj = 0;
            $lat1 = $lon1 = $lat2 = $lon2 = 0.0;
            $paramCat = $paramNum = 0;
            $refVal = 0.0;
            $binScale = $decScale = $nbits = 0;
            $packedData = '';

            while ($secPos < $msgEnd - 4) {
                $secLen = unpack('N', substr($data, $secPos, 4))[1];
                $secNum = ord($data[$secPos + 4]);
                if ($secLen === 0) break;

                if ($secNum === 3) {
                    // Grid definition section
                    $ni = unpack('N', substr($data, $secPos + 30, 4))[1];
                    $nj = unpack('N', substr($data, $secPos + 34, 4))[1];
                    $lat1 = self::unpackSint32($data, $secPos + 46) / 1e6;
                    $lon1 = self::unpackSint32($data, $secPos + 50) / 1e6;
                    $lat2 = self::unpackSint32($data, $secPos + 55) / 1e6;
                    $lon2 = self::unpackSint32($data, $secPos + 59) / 1e6;
                } elseif ($secNum === 4) {
                    // Product definition section
                    $paramCat = ord($data[$secPos + 9]);
                    $paramNum = ord($data[$secPos + 10]);
                } elseif ($secNum === 5) {
                    // Data representation section
                    $refVal = unpack('G', substr($data, $secPos + 11, 4))[1]; // IEEE 754 float big-endian
                    $binScale = self::unpackSint16($data, $secPos + 15);
                    $decScale = self::unpackSint16($data, $secPos + 17);
                    $nbits = ord($data[$secPos + 19]);
                } elseif ($secNum === 7) {
                    // Data section
                    $packedData = substr($data, $secPos + 5, $secLen - 5);
                }

                $secPos += $secLen;
            }

            // Decode values
            $npts = $ni * $nj;
            $vals = [];

            if ($nbits > 0 && strlen($packedData) > 0) {
                $vals = self::unpackSimplePacking($packedData, $npts, $nbits, $refVal, $binScale, $decScale);
            } else {
                $fillVal = $refVal / (10.0 ** $decScale);
                $vals = array_fill(0, $npts, $fillVal);
            }

            // Reshape 1D → 2D [nj][ni]
            $values2d = [];
            for ($j = 0; $j < $nj; $j++) {
                $values2d[] = array_slice($vals, $j * $ni, $ni);
            }

            // Determine parameter name
            $param = 'unknown';
            if ($discipline === 0 && $paramCat === 2) {
                $param = ($paramNum === 2) ? 'u' : (($paramNum === 3) ? 'v' : "c{$paramCat}n{$paramNum}");
            }

            $messages[] = [
                'param' => $param,
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

    /**
     * Unpack simple packing (GRIB2 template 5.0) using bit manipulation.
     *
     * Each value = (refVal + raw * 2^binScale) / 10^decScale
     *
     * @return float[]
     */
    private static function unpackSimplePacking(
        string $packed, int $npts, int $nbits,
        float $refVal, int $binScale, int $decScale
    ): array {
        $vals = [];
        $binFactor = 2.0 ** $binScale;
        $decFactor = 10.0 ** $decScale;

        // Convert packed bytes to an array of integers for bit extraction
        $byteLen = strlen($packed);
        $bitBuf = 0;
        $bitsInBuf = 0;
        $bytePos = 0;

        for ($i = 0; $i < $npts; $i++) {
            // Ensure we have enough bits
            while ($bitsInBuf < $nbits && $bytePos < $byteLen) {
                $bitBuf = ($bitBuf << 8) | ord($packed[$bytePos]);
                $bitsInBuf += 8;
                $bytePos++;
            }

            $shift = $bitsInBuf - $nbits;
            $raw = ($shift >= 0) ? ($bitBuf >> $shift) & ((1 << $nbits) - 1) : 0;
            $bitsInBuf -= $nbits;
            // Clear consumed bits
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
        // PHP unpack doesn't have unsigned 64-bit big-endian natively.
        // For GRIB2 message lengths, the value fits in a regular int.
        $hi = unpack('N', substr($data, $offset, 4))[1];
        $lo = unpack('N', substr($data, $offset + 4, 4))[1];
        return ($hi << 32) | $lo;
    }

    private static function unpackSint32(string $data, int $offset): int
    {
        $val = unpack('N', substr($data, $offset, 4))[1];
        // Sign extension for 32-bit signed
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
