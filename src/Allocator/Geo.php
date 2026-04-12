<?php

declare(strict_types=1);

namespace Atfm\Allocator;

/**
 * Tiny great-circle helper. No winds. No climb/descent profile.
 * 450 kt average cruise when filed TAS is missing.
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
    public static function etaMinutesWithDescent(
        float $distNm,
        int $cruiseKt,
        int $cruiseAltFt,
        int $airportElevFt
    ): float {
        // Descent distance from 3° glidepath: 318 ft per nm
        $altAboveAirport = max(0, $cruiseAltFt - $airportElevFt);
        $todDistNm = $altAboveAirport / 318.0;

        // Compute time for the approach/descent segment (from airport outward)
        $descentMin = self::descentSegmentMinutes(min($todDistNm, $distNm));

        // Cruise segment: whatever remains beyond the TOD point
        $cruiseNm = max(0.0, $distNm - $todDistNm);
        $cruiseMin = ($cruiseKt > 0) ? ($cruiseNm / $cruiseKt) * 60.0 : 0.0;

        return $cruiseMin + $descentMin;
    }

    /**
     * Time in minutes for the approach/descent segment, working from the
     * airport outward. Applies standard speed constraints per ICAO/FAA:
     *
     *   0-2 nm:   Vref ~140 kt
     *   2-5 nm:   decel segment ~180 kt avg
     *   5-10 nm:  ~220 kt (3000ft AGL at 10nm on 3° slope)
     *   10-20 nm: 220 kt approach speed limit
     *   20+ nm:   250 kt (below FL100, mandatory)
     *
     * @param float $distNm Distance from airport for this descent segment
     */
    private static function descentSegmentMinutes(float $distNm): float
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

        // 20+ nm at 250 kt (below FL100)
        $time += ($remaining / 250.0) * 60.0;

        return $time;
    }
}
