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
}
