<?php

declare(strict_types=1);

namespace Atfm\Allocator;

/**
 * Great-circle and along-route distance helpers. No winds, no
 * atmosphere model. Uses filed route waypoints (both coordinate
 * and named fixes from data/waypoints.json) when available to
 * correct for routing deviations (NAT tracks, jet-stream avoidance,
 * domestic routes via named fixes) that inflate actual distance
 * vs great-circle.
 *
 * See docs/ARCHITECTURE.md §2 for why we deliberately don't do more.
 */
final class Geo
{
    public const EARTH_RADIUS_NM = 3440.065;
    public const DEFAULT_CRUISE_KT = 450;

    public static function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_NM * $c;
    }

    /**
     * Cruise flight time in minutes, rounded to nearest whole minute.
     * Uses filed TAS if sensible, else 450 kt default.
     */
    public static function flightMinutes(float $distanceNm, ?int $filedTas = null): int
    {
        $tas = ($filedTas !== null && $filedTas >= 120 && $filedTas <= 650)
            ? $filedTas
            : self::DEFAULT_CRUISE_KT;
        return (int) round(($distanceNm / $tas) * 60);
    }

    /**
     * For airborne flights, estimate ETA from current position + current
     * groundspeed. Fall back to 450 kt if groundspeed is missing/zero.
     *
     * @deprecated Use etaMinutesWithDescent() for descent-aware estimates.
     */
    public static function etaMinutesFromPosition(
        float $curLat, float $curLon,
        float $destLat, float $destLon,
        ?int $groundspeed
    ): int {
        $nm = self::distanceNm($curLat, $curLon, $destLat, $destLon);
        $gs = ($groundspeed !== null && $groundspeed > 100) ? $groundspeed : self::DEFAULT_CRUISE_KT;
        return (int) round(($nm / $gs) * 60);
    }

    /**
     * Descent-aware ETA estimate.
     *
     * Uses a standard 3° descent profile with published speed constraints:
     *
     *   Segment          Distance       Speed
     *   ─────────────────────────────────────────
     *   0-2 nm           2 nm           Vref (~140 kt)
     *   2-5 nm           3 nm           decel ~180 kt avg
     *   5-10 nm          5 nm           ~220 kt (3000 AGL at 10nm)
     *   10-20 nm         10 nm          220 kt approach limit
     *   20 nm-FL100      varies         250 kt (mandatory below FL100)
     *   FL100-TOD        varies         cruise GS (decelerating toward 250)
     *   TOD-current pos  varies         cruise GS
     *
     * 3° glidepath = ~318 ft/nm. TOD is computed from current altitude (or
     * filed cruise altitude for ground flights) and airport elevation.
     *
     * For flights already in descent, the method detects this from the
     * altitude relative to the TOD point and adjusts accordingly.
     *
     * @param float $distNm         Great-circle distance from current position (or ADEP) to ADES
     * @param int   $cruiseKt       Cruise GS or TAS to use for the cruise segment
     * @param int   $cruiseAltFt    Current altitude (airborne) or filed cruise altitude (ground)
     * @param int   $airportElevFt  ADES field elevation
     * @return float Time in minutes (not rounded — caller rounds as needed)
     */
    /**
     * @param int $descentIasHigh IAS (kt) for the descent segment between
     *                           crossover altitude and FL100 (type-specific;
     *                           e.g. 310 for B77W, 280 for B738). Use
     *                           AircraftTas::descentIasHigh() to look it up.
     */
    public static function etaMinutesWithDescent(
        float $distNm,
        int $cruiseKt,
        int $cruiseAltFt,
        int $airportElevFt,
        int $descentIasHigh = 280
    ): float {
        // Distance from airport where the aircraft is on a 3° slope
        // at its current altitude. This tells us how much of the
        // remaining distance is "descent segment" vs "cruise segment".
        $altAboveAirport = max(0, $cruiseAltFt - $airportElevFt);
        $todDistNm = $altAboveAirport / 318.0;

        // If the aircraft is closer than its TOD distance, it's already
        // in descent (or on approach). The entire remaining distance is
        // descent. If it's farther, the difference is cruise.
        if ($distNm <= $todDistNm) {
            // Already in descent — the observed GS is the current
            // descent/approach speed. Simple distance/GS is the most
            // honest estimate, because the speed schedule segments
            // ahead are at speeds similar to or slower than current GS.
            // Adding the schedule on top would double-count the slowdown.
            return ($cruiseKt > 0) ? ($distNm / $cruiseKt) * 60.0 : 0.0;
        }

        // Still in cruise — split into cruise + descent.
        $fl100AGL = max(0, 10000 - $airportElevFt);
        $fl100DistNm = $fl100AGL / 318.0;

        $descentMin = self::descentSegmentMinutes(
            $todDistNm,
            $fl100DistNm,
            $descentIasHigh
        );

        $cruiseNm = $distNm - $todDistNm;
        $cruiseMin = ($cruiseKt > 0) ? ($cruiseNm / $cruiseKt) * 60.0 : 0.0;

        return $cruiseMin + $descentMin;
    }

    /**
     * Time in minutes for the approach/descent segment, working from the
     * airport outward. Uses the standard 3° glidepath speed schedule:
     *
     *   0-2 nm:              Vref ~140 kt (final)
     *   2-5 nm:              decel ~180 kt avg
     *   5-10 nm:             ~220 kt (3000 AGL at 10nm on 3°)
     *   10-20 nm:            220 kt approach limit
     *   20 nm - FL100 dist:  250 KIAS (regulatory below FL100)
     *   FL100 dist - TOD:    IAS_high (type-specific: 280-310 KIAS)
     *
     * @param float $distNm        Total descent distance (TOD to airport)
     * @param float $fl100DistNm   Distance from airport where FL100 intersects the 3° slope
     * @param int   $iasHighKt     Type-specific descent IAS above FL100 (e.g. 310 for B77W)
     */
    /** @var array<string,array{float,float}>|null Lazy-loaded waypoint DB */
    private static ?array $waypointDb = null;

    /** @var array<string,array<string,array>>|null Lazy-loaded airway graph */
    private static ?array $airwayDb = null;

    /**
     * Load the waypoint database (data/waypoints.json) once per process.
     *
     * @return array<string,array{float,float}>
     */
    private static function loadWaypoints(): array
    {
        if (self::$waypointDb !== null) {
            return self::$waypointDb;
        }
        $file = __DIR__ . '/../../data/waypoints.json';
        if (file_exists($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            self::$waypointDb = is_array($data) ? $data : [];
        } else {
            self::$waypointDb = [];
        }
        return self::$waypointDb;
    }

    /**
     * Load the airway adjacency graph (data/airways.json) once per process.
     *
     * Format: { "J501": { "TONNY": [lat, lon, "next_fix"|null, "prev_fix"|null], ... }, ... }
     *
     * @return array<string,array<string,array>>
     */
    private static function loadAirways(): array
    {
        if (self::$airwayDb !== null) {
            return self::$airwayDb;
        }
        $file = __DIR__ . '/../../data/airways.json';
        if (file_exists($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            self::$airwayDb = is_array($data) ? $data : [];
        } else {
            self::$airwayDb = [];
        }
        return self::$airwayDb;
    }

    /**
     * Expand an airway segment between two fixes into intermediate waypoints.
     *
     * Walks the airway's linked list (next/prev pointers) from $fromFix to
     * $toFix, returning the intermediate fixes (excluding $fromFix, including
     * $toFix). Returns null if the segment can't be resolved.
     *
     * @return array<array{float,float}>|null  Intermediate fix coords, or null
     */
    public static function expandAirwaySegment(string $airwayId, string $fromFix, string $toFix): ?array
    {
        $airways = self::loadAirways();
        if (!isset($airways[$airwayId])) {
            return null;
        }
        $awy = $airways[$airwayId];
        if (!isset($awy[$fromFix]) || !isset($awy[$toFix])) {
            return null;
        }

        // Try both directions (next and prev pointers)
        foreach (['next' => 2, 'prev' => 3] as $dir => $idx) {
            $path = [];
            $cur = $fromFix;
            $visited = [$fromFix => true];
            while (true) {
                $node = $awy[$cur] ?? null;
                if ($node === null) {
                    break;
                }
                $nxt = $node[$idx] ?? null;
                if ($nxt === null || isset($visited[$nxt])) {
                    break;
                }
                if ($nxt === $toFix) {
                    $path[] = [$awy[$toFix][0], $awy[$toFix][1]];
                    return $path;
                }
                $visited[$nxt] = true;
                if (isset($awy[$nxt])) {
                    $path[] = [$awy[$nxt][0], $awy[$nxt][1]];
                }
                $cur = $nxt;
            }
        }

        return null;
    }

    /**
     * Parse coordinate, named-fix, and airway-segment waypoints from a
     * filed route string.
     *
     * Handles coordinate formats:
     *   - NAT integer degrees: 49N050W, 54N020W, 60N020W
     *   - ICAO DDMM format:    3303S15639E, 5530N02030W
     *
     * Resolves named fixes (TONNY, YEE, FIORD, etc.) against
     * data/waypoints.json.
     *
     * Expands airway segments: when "FIX_A J501 FIX_B" is encountered,
     * walks the airway graph (data/airways.json) to insert all
     * intermediate fixes between FIX_A and FIX_B.
     *
     * Returns an array of [lat, lon] pairs in decimal degrees,
     * in the order they appear in the route string.
     *
     * @return array<array{float,float}>
     */
    public static function parseRouteCoordinates(string $route): array
    {
        $waypoints = self::loadWaypoints();
        $coords = [];
        $tokens = preg_split('/\s+/', trim($route));
        if ($tokens === false) {
            return [];
        }
        // Re-index after filtering empty tokens
        $tokens = array_values(array_filter($tokens, fn($t) => trim($t) !== ''));
        $count = count($tokens);
        $lastFixName = null; // track last resolved fix name for airway expansion

        for ($i = 0; $i < $count; $i++) {
            $token = trim($tokens[$i]);

            // NAT format: DDN/SDDDW/E (2-digit lat, 3-digit lon)
            if (preg_match('/^(\d{2})(N|S)(\d{3})(W|E)$/', $token, $m)) {
                $lat = (float) $m[1] * ($m[2] === 'S' ? -1 : 1);
                $lon = (float) $m[3] * ($m[4] === 'W' ? -1 : 1);
                $coords[] = [$lat, $lon];
                $lastFixName = null; // coordinate waypoint, no name for airway lookup
                continue;
            }

            // ICAO DDMM format: DDMMN/SDDDMME/W (4-digit lat, 5-digit lon)
            if (preg_match('/^(\d{4})(N|S)(\d{5})(W|E)$/', $token, $m)) {
                $latDeg = (int) substr($m[1], 0, 2);
                $latMin = (int) substr($m[1], 2, 2);
                $lat = ($latDeg + $latMin / 60.0) * ($m[2] === 'S' ? -1 : 1);
                $lonDeg = (int) substr($m[3], 0, 3);
                $lonMin = (int) substr($m[3], 3, 2);
                $lon = ($lonDeg + $lonMin / 60.0) * ($m[4] === 'W' ? -1 : 1);
                $coords[] = [$lat, $lon];
                $lastFixName = null;
                continue;
            }

            // Skip speed/level groups (N0450F350, M082F390, K0900S1200)
            if (preg_match('/^[NMK]\d{4}[FSAM]\d{3,4}$/', $token)) {
                continue;
            }

            // Skip DCT (direct-to)
            if ($token === 'DCT') {
                continue;
            }

            // Airway identifier: 1-2 letters + 1-4 digits (J501, V300, T720, Q42, UN601, UL9)
            // When we see one, look at prev fix and next token to expand the segment
            if (preg_match('/^[A-Z]{1,2}\d{1,4}$/', $token)) {
                $airwayId = $token;
                // Next token should be the exit fix
                if ($lastFixName !== null && $i + 1 < $count) {
                    $nextToken = trim($tokens[$i + 1]);
                    // Resolve the exit fix name
                    $exitFix = null;
                    if (preg_match('/^[A-Z]{2,5}$/', $nextToken)) {
                        $exitFix = $nextToken;
                    }
                    if ($exitFix !== null) {
                        $expanded = self::expandAirwaySegment($airwayId, $lastFixName, $exitFix);
                        if ($expanded !== null) {
                            // Add intermediate + exit fix coords; skip next token (we consumed it)
                            foreach ($expanded as $coord) {
                                $coords[] = $coord;
                            }
                            $lastFixName = $exitFix;
                            $i++; // skip exit fix token
                            continue;
                        }
                    }
                }
                // Couldn't expand — skip the airway token
                continue;
            }

            // Named fix lookup — must be 2-5 uppercase alpha chars
            if (preg_match('/^[A-Z]{2,5}$/', $token) && isset($waypoints[$token])) {
                $coords[] = [$waypoints[$token][0], $waypoints[$token][1]];
                $lastFixName = $token;
                continue;
            }
        }

        return $coords;
    }

    /**
     * Along-route distance from current position to destination,
     * using parsed route coordinate waypoints for the intervening path.
     *
     * Strategy: determine which waypoints are still AHEAD of the
     * aircraft by comparing each waypoint's distance-to-dest against
     * the aircraft's distance-to-dest. Waypoints closer to dest than
     * the aircraft is are "ahead" — the aircraft must still fly through
     * them. Sum: cur → first_ahead → remaining_ahead → dest.
     *
     * This corrects the systematic distance underestimate for NAT
     * westbound flights that route 10-15% longer than great-circle
     * to avoid jet-stream headwinds.
     *
     * @param array<array{float,float}> $routeCoords parsed waypoints
     */
    public static function alongRouteDistanceNm(
        float $curLat, float $curLon,
        float $destLat, float $destLon,
        array $routeCoords
    ): float {
        $directDist = self::distanceNm($curLat, $curLon, $destLat, $destLon);
        if (empty($routeCoords)) {
            return $directDist;
        }

        // Compute each waypoint's distance to destination.
        $wptDistToDest = [];
        foreach ($routeCoords as [$wLat, $wLon]) {
            $wptDistToDest[] = self::distanceNm($wLat, $wLon, $destLat, $destLon);
        }

        // Keep only waypoints that are AHEAD: closer to destination
        // than the aircraft currently is. This filters out waypoints
        // the aircraft has already passed.
        $ahead = [];
        foreach ($routeCoords as $i => [$wLat, $wLon]) {
            if ($wptDistToDest[$i] < $directDist) {
                $ahead[] = [$wLat, $wLon];
            }
        }

        if (empty($ahead)) {
            // All waypoints are behind us — direct to dest.
            return $directDist;
        }

        // Sum: cur → first_ahead → subsequent_ahead → dest.
        $dist = self::distanceNm($curLat, $curLon, $ahead[0][0], $ahead[0][1]);
        for ($i = 1; $i < count($ahead); $i++) {
            $dist += self::distanceNm(
                $ahead[$i - 1][0], $ahead[$i - 1][1],
                $ahead[$i][0], $ahead[$i][1]
            );
        }
        $lastAhead = $ahead[count($ahead) - 1];
        $dist += self::distanceNm($lastAhead[0], $lastAhead[1], $destLat, $destLon);

        // Sanity bounds: never shorter than direct, never more than
        // 1.3x direct (catches garbage waypoints or route mismatches).
        return max($directDist, min($dist, $directDist * 1.30));
    }

    private static function descentSegmentMinutes(float $distNm, float $fl100DistNm = 31.0, int $iasHighKt = 280): float
    {
        if ($distNm <= 0) {
            return 0.0;
        }
        $time = 0.0;
        $remaining = $distNm;

        // 0-2 nm at Vref (~140 kt)
        $seg = min(2.0, $remaining);
        $time += ($seg / 140.0) * 60.0;
        $remaining -= $seg;
        if ($remaining <= 0) return $time;

        // 2-5 nm decel segment (~180 kt avg)
        $seg = min(3.0, $remaining);
        $time += ($seg / 180.0) * 60.0;
        $remaining -= $seg;
        if ($remaining <= 0) return $time;

        // 5-10 nm at ~220 kt (3000 AGL at 10nm)
        $seg = min(5.0, $remaining);
        $time += ($seg / 220.0) * 60.0;
        $remaining -= $seg;
        if ($remaining <= 0) return $time;

        // 10-20 nm at 220 kt approach limit
        $seg = min(10.0, $remaining);
        $time += ($seg / 220.0) * 60.0;
        $remaining -= $seg;
        if ($remaining <= 0) return $time;

        // 20 nm to FL100 distance at 250 KIAS (regulatory)
        $belowFl100Nm = max(0.0, $fl100DistNm - 20.0);
        $seg = min($belowFl100Nm, $remaining);
        $time += ($seg / 250.0) * 60.0;
        $remaining -= $seg;
        if ($remaining <= 0) return $time;

        // FL100 to TOD at type-specific IAS_high (e.g. 310 for B77W).
        // IAS-to-TAS correction at the average altitude of this segment
        // (~FL200): at FL200 the density ratio gives TAS/IAS ~ 1.3.
        // This is conservative (at FL300 it's ~1.45) but avoids being
        // too optimistic for the lower portion of the segment.
        $gsHighKt = (int) round($iasHighKt * 1.3);
        $time += ($remaining / $gsHighKt) * 60.0;

        return $time;
    }
}
