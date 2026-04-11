<?php

declare(strict_types=1);

namespace Atfm\Allocator;

/**
 * ICAO aircraft type → typical cruise TAS (kt) lookup.
 *
 * Used as the Tier 4 fallback in EtaEstimator when a flight has no filed
 * enroute_time, no filed cruise_tas, but we do know the aircraft type.
 *
 * Values are conservative typical cruise TAS at typical cruise altitude.
 * Not a substitute for real performance models — but good enough to get
 * a rough ETA for the allocator when the pilot files nothing but a type.
 *
 * Covers ~60 common ICAO type designators seen in VATSIM North-American
 * traffic. For unknown types, EtaEstimator falls back to DEFAULT_TAS_KT.
 */
final class AircraftTas
{
    public const DEFAULT_TAS_KT = 430;

    /** @var array<string, int> */
    private const TABLE = [
        // Narrowbody
        'A319' => 447, 'A320' => 447, 'A321' => 447,
        'A20N' => 447, 'A21N' => 447,
        'B737' => 460, 'B738' => 460, 'B739' => 460,
        'B37M' => 460, 'B38M' => 460, 'B39M' => 460, 'B3XM' => 460,
        'B752' => 470, 'B753' => 470,

        // Widebody
        'A332' => 480, 'A333' => 480, 'A338' => 480, 'A339' => 480,
        'A359' => 488, 'A35K' => 488,
        'A388' => 488,
        'B762' => 480, 'B763' => 480, 'B764' => 480,
        'B772' => 490, 'B77L' => 490, 'B77W' => 490,
        'B744' => 480, 'B748' => 490,
        'B788' => 490, 'B789' => 490, 'B78X' => 490,
        'MD11' => 490,

        // Regional jet
        'CRJ1' => 425, 'CRJ2' => 425, 'CRJ7' => 430, 'CRJ9' => 430, 'CRJX' => 430,
        'E135' => 430, 'E145' => 430,
        'E170' => 450, 'E75L' => 450, 'E75S' => 450, 'E175' => 450,
        'E190' => 450, 'E195' => 450,
        'E290' => 460, 'E295' => 460,

        // Turboprop regional
        'DH8A' => 280, 'DH8B' => 290, 'DH8C' => 290, 'DH8D' => 310,
        'AT72' => 275, 'AT75' => 275, 'AT76' => 275,
        'SB20' => 330, 'F50'  => 270,

        // Business jets
        'C25A' => 400, 'C25B' => 420, 'C25C' => 430,
        'C56X' => 410, 'C680' => 450, 'C68A' => 450, 'C700' => 485, 'C750' => 480,
        'GLF4' => 470, 'GLF5' => 488, 'GLF6' => 488, 'GL5T' => 488, 'GL6T' => 488,
        'BE40' => 430, 'LJ35' => 440, 'LJ40' => 440, 'LJ45' => 440, 'LJ60' => 460,
        'E50P' => 350, 'E55P' => 390,
        'CL30' => 460, 'CL35' => 470, 'CL60' => 460, 'CL65' => 465,

        // Props / trainers / GA
        'C172' => 110, 'C182' => 140, 'C208' => 170,
        'C402' => 215, 'C414' => 200, 'C421' => 230,
        'BE20' => 265, 'BE35' => 170, 'BE36' => 170, 'BE58' => 190,
        'BE99' => 240, 'B350' => 280,
        'PC12' => 270, 'PC24' => 440, 'P180' => 320,
        'SF50' => 300,
        'DA40' => 140, 'DA42' => 175, 'DA62' => 190,
        'SR20' => 160, 'SR22' => 175,
        'PA28' => 130, 'PA44' => 165, 'PA46' => 200,
        'L410' => 220,

        // Other common
        'MD88' => 470, 'MD90' => 470,
        'F70'  => 420, 'F100' => 440,
    ];

    public static function typicalTas(string $icaoType): int
    {
        $key = strtoupper(trim($icaoType));
        return self::TABLE[$key] ?? self::DEFAULT_TAS_KT;
    }

    public static function known(string $icaoType): bool
    {
        return isset(self::TABLE[strtoupper(trim($icaoType))]);
    }
}
