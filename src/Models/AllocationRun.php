<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per allocator cycle. Cheap audit trail for "why did this flight
 * get this CTOT at this time?" debugging. See ARCHITECTURE.md §4.6.
 */
final class AllocationRun extends Model
{
    protected $table = 'allocation_runs';

    public $timestamps = false;

    protected $fillable = [
        'run_uuid',
        'started_at',
        'finished_at',
        'airports_considered',
        'restrictions_active',
        'flights_evaluated',
        'ctots_frozen_kept',
        'ctots_issued',
        'ctots_released',
        'ctots_reissued',
        'elapsed_ms',
        'notes',
    ];

    protected $casts = [
        'started_at'          => 'datetime',
        'finished_at'         => 'datetime',
        'airports_considered' => 'int',
        'restrictions_active' => 'int',
        'flights_evaluated'   => 'int',
        'ctots_frozen_kept'   => 'int',
        'ctots_issued'        => 'int',
        'ctots_released'      => 'int',
        'ctots_reissued'      => 'int',
        'elapsed_ms'          => 'int',
    ];
}
