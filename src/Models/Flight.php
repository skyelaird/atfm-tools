<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A flight record — the analytical core of atfm-tools.
 *
 * One row per flight, mutated in place every 5-min ingest cycle. When a
 * flight reaches a terminal state (ARRIVED or WITHDRAWN) `finalized_at`
 * is set and the row is effectively frozen (no more mutations from the
 * ingest loop).
 *
 * Column list is enormous but intentional — see docs/ARCHITECTURE.md §4.4.
 * We mirror PERTI's adl_flights_* vocabulary where practical and the
 * ICAO A-CDM milestone vocabulary everywhere else.
 *
 * Terminal states: ARRIVED, WITHDRAWN.
 * Transient non-terminal state: DISCONNECTED (can re-animate if the pilot
 * returns to the VATSIM feed with the same flight_key).
 */
final class Flight extends Model
{
    protected $table = 'flights';

    // Phase constants — keep in sync with ARCHITECTURE.md §7
    public const PHASE_PREFILE      = 'PREFILE';
    public const PHASE_FILED        = 'FILED';
    public const PHASE_TAXI_OUT     = 'TAXI_OUT';
    public const PHASE_DEPARTED     = 'DEPARTED';
    public const PHASE_ENROUTE      = 'ENROUTE';
    public const PHASE_ARRIVING     = 'ARRIVING';
    public const PHASE_FINAL        = 'FINAL';
    public const PHASE_ON_RUNWAY    = 'ON_RUNWAY';
    public const PHASE_VACATED      = 'VACATED';
    public const PHASE_TAXI_IN      = 'TAXI_IN';
    public const PHASE_ARRIVED      = 'ARRIVED';
    public const PHASE_GO_AROUND    = 'GO_AROUND';
    public const PHASE_DISCONNECTED = 'DISCONNECTED';
    public const PHASE_WITHDRAWN    = 'WITHDRAWN';

    public const TERMINAL_PHASES = [self::PHASE_ARRIVED, self::PHASE_WITHDRAWN];

    public const DELAY_ON_TIME            = 'ON_TIME';
    public const DELAY_DELAYED            = 'DELAYED';
    public const DELAY_COMPLIANT_DEPARTED = 'COMPLIANT_DEPARTED';
    public const DELAY_NON_COMPLIANT      = 'NON_COMPLIANT';
    public const DELAY_WITHDRAWN          = 'WITHDRAWN';
    public const DELAY_EXEMPT             = 'EXEMPT';

    protected $fillable = [
        'flight_key',
        'callsign',
        'cid',
        'first_seen_at',
        'last_updated_at',
        'finalized_at',
        'aircraft_type',
        'aircraft_faa',
        'wake_category',
        'flight_rules',
        'airline_icao',
        'adep',
        'ades',
        'alt_icao',
        'fp_route',
        'fp_altitude_ft',
        'fp_cruise_tas',
        'fp_enroute_time_min',
        'departure_runway',
        'arrival_runway',
        'departure_gate',
        'arrival_gate',
        // A-CDM milestones (EUROCONTROL Airport CDM Implementation Manual,
        // 5.0, 31 March 2017 — see docs/GLOSSARY.md for canonical defs).
        // E* = estimated, T* = target, A* = actual, C* = calculated.
        'eobt', 'tobt', 'tsat', 'ttot', 'ctot',
        'asat', 'aobt', 'atot',
        'eldt', 'cta', 'aldt', 'aibt',
        // *_exot_min: Estimated Taxi-Out Time (EXOT). Per the manual:
        // "the estimated taxi time between off-block and take off."
        // *_exit_min: Estimated Taxi-In Time (EXIT). Per the manual:
        // "the estimated taxi time between landing and in-block."
        // The columns named "actual_*" actually store AXOT/AXIT
        // (Actual Taxi-Out / Actual Taxi-In), the canonical metrics
        // ATOT − AOBT and AIBT − ALDT respectively. Schema column names
        // are kept for backwards compat; new code should treat them as
        // synonyms for AXOT/AXIT.
        'planned_exot_min', 'actual_exot_min', // ≡ EXOT (planned) / AXOT (observed)
        'planned_exit_min', 'actual_exit_min', // ≡ EXIT (planned) / AXIT (observed)
        'ctl_type', 'ctl_element', 'ctl_restriction_id',
        'delay_minutes', 'delay_status',
        'phase', 'phase_updated_at',
        'last_lat', 'last_lon', 'last_altitude_ft',
        'last_groundspeed_kts', 'last_heading_deg', 'last_position_at',
        'first_disconnect_at', 'reconnect_count',
    ];

    protected $casts = [
        'cid'                  => 'int',
        'first_seen_at'        => 'datetime',
        'last_updated_at'      => 'datetime',
        'finalized_at'         => 'datetime',
        'fp_altitude_ft'       => 'int',
        'fp_cruise_tas'        => 'int',
        'fp_enroute_time_min'  => 'int',
        'eobt'                 => 'datetime',
        'tobt'                 => 'datetime',
        'tsat'                 => 'datetime',
        'ttot'                 => 'datetime',
        'ctot'                 => 'datetime',
        'asat'                 => 'datetime',
        'aobt'                 => 'datetime',
        'atot'                 => 'datetime',
        'eldt'                 => 'datetime',
        'cta'                  => 'datetime',
        'aldt'                 => 'datetime',
        'aibt'                 => 'datetime',
        'planned_exot_min'     => 'int',
        'actual_exot_min'      => 'int',
        'planned_exit_min'     => 'int',
        'actual_exit_min'      => 'int',
        'delay_minutes'        => 'int',
        'phase_updated_at'     => 'datetime',
        'last_lat'             => 'float',
        'last_lon'             => 'float',
        'last_altitude_ft'     => 'int',
        'last_groundspeed_kts' => 'int',
        'last_heading_deg'     => 'int',
        'last_position_at'     => 'datetime',
        'first_disconnect_at'  => 'datetime',
        'reconnect_count'      => 'int',
    ];

    public function positions(): HasMany
    {
        return $this->hasMany(PositionScratch::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->phase, self::TERMINAL_PHASES, true);
    }

    public function isAirborne(): bool
    {
        return in_array($this->phase, [
            self::PHASE_DEPARTED,
            self::PHASE_ENROUTE,
            self::PHASE_ARRIVING,
            self::PHASE_FINAL,
            self::PHASE_GO_AROUND,
        ], true);
    }

    public function isOnGround(): bool
    {
        return in_array($this->phase, [
            self::PHASE_PREFILE,
            self::PHASE_FILED,
            self::PHASE_TAXI_OUT,
            self::PHASE_ON_RUNWAY,
            self::PHASE_VACATED,
            self::PHASE_TAXI_IN,
        ], true);
    }
}
