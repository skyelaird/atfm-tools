<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-flight runway timing observation, written by bin/rot-tracker.php.
 *
 * Holds:
 *   - threshold_at: refined ATOT (departures) or ALDT (arrivals), ideally
 *     interpolated between two position_scratch samples that bracket the
 *     runway-threshold polygon. When only one sample is available we fall
 *     back to the milestone timestamp from the flights row.
 *   - clear_at: best-effort time the runway was vacated. With our 5-min
 *     ingest cadence this is necessarily approximate.
 *   - rot_seconds: clear_at − threshold_at, or NULL when we couldn't
 *     estimate it (single sample, no clear_at observation, etc.)
 *   - threshold_gs_kts: groundspeed at threshold, used by compute-aar.php
 *     in the ICAO 9971 Part II AAR = GS / spacing formula.
 *   - source: 'I' interpolated, 'A' single-sample approximation, 'F' fallback.
 *
 * Unique on (flight_id, event_type) so re-running the tracker is idempotent.
 *
 * See docs/ARCHITECTURE.md §7 (ROT cascade) and §9 (AAR derivation).
 */
final class RotObservation extends Model
{
    public const EVENT_DEP = 'DEP';
    public const EVENT_ARR = 'ARR';

    public const SOURCE_INTERPOLATED = 'I';
    public const SOURCE_APPROX       = 'A';
    public const SOURCE_FALLBACK     = 'F';

    protected $table = 'rot_observations';

    protected $fillable = [
        'flight_id',
        'airport_icao',
        'runway_ident',
        'event_type',
        'threshold_at',
        'clear_at',
        'rot_seconds',
        'threshold_gs_kts',
        'source',
    ];

    protected $casts = [
        'flight_id'        => 'int',
        'threshold_at'     => 'datetime',
        'clear_at'         => 'datetime',
        'rot_seconds'      => 'int',
        'threshold_gs_kts' => 'int',
    ];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }
}
