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

    /**
     * Descent speed schedule: Mach / IAS_high / IAS_low (250 always).
     *
     * Sourced from PMDG, iniBuilds, and similar study-level sim aircraft
     * performance data. The IAS_high is the speed from crossover altitude
     * (~FL280-300) down to FL100; IAS_low is always 250 kt (regulatory).
     *
     * Format: [descent_mach_x100, ias_high_kt]
     * IAS_low is always 250, so not stored.
     *
     * For types not in this table, DEFAULT_DESCENT applies.
     */
    public const DEFAULT_DESCENT_IAS_HIGH = 280;

    /** @var array<string, array{int, int}> [mach_x100, ias_high] */
    private const DESCENT = [
        // Narrowbody (from PMDG/Zibo B738, default A320 profiles)
        'A319' => [78, 300], 'A320' => [78, 300], 'A321' => [78, 300],
        'A20N' => [78, 300], 'A21N' => [78, 300],
        'B737' => [78, 280], 'B738' => [78, 280], 'B739' => [78, 280],
        'B37M' => [78, 280], 'B38M' => [78, 280], 'B39M' => [78, 280], 'B3XM' => [78, 280],
        'B752' => [80, 290], 'B753' => [80, 290],

        // Widebody
        'A332' => [82, 300], 'A333' => [82, 300], 'A338' => [82, 300], 'A339' => [82, 300],
        'A359' => [85, 300], 'A35K' => [85, 300],
        'A388' => [85, 310],
        'B762' => [80, 290], 'B763' => [80, 300], 'B764' => [80, 300],
        'B772' => [84, 310], 'B77L' => [84, 310], 'B77W' => [84, 310],
        'B744' => [84, 310], 'B748' => [84, 310],
        'B788' => [84, 310], 'B789' => [84, 310], 'B78X' => [84, 310],
        'MD11' => [83, 300],

        // Regional jets — lower descent speeds
        'CRJ7' => [74, 270], 'CRJ9' => [74, 270], 'CRJX' => [74, 270],
        'E170' => [75, 270], 'E75L' => [75, 270], 'E175' => [75, 270],
        'E190' => [78, 280], 'E195' => [78, 280],
        'E290' => [78, 290], 'E295' => [78, 290],
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

    /**
     * Descent IAS (kt) for the segment from crossover altitude to FL100.
     * Returns the type-specific IAS_high from the DESCENT table, or
     * DEFAULT_DESCENT_IAS_HIGH (280 kt) for unknown types.
     */
    public static function descentIasHigh(string $icaoType): int
    {
        $key = strtoupper(trim($icaoType));
        return (self::DESCENT[$key] ?? [0, self::DEFAULT_DESCENT_IAS_HIGH])[1];
    }
}
