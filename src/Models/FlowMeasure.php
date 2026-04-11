<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Flow Measure.
 *
 * Trimmed version of atfm-flow's flow_measures table: no user, no event,
 * no mandatory_route. Filters stay as JSON so you can evolve the shape
 * without schema churn.
 */
final class FlowMeasure extends Model
{
    use SoftDeletes;

    protected $table = 'flow_measures';

    protected $fillable = [
        'identifier',
        'fir_id',
        'reason',
        'type',
        'value',
        'filters',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'filters'    => 'array',
        'value'      => 'int',
        'start_time' => 'datetime',
        'end_time'   => 'datetime',
    ];

    public function fir(): BelongsTo
    {
        return $this->belongsTo(Fir::class, 'fir_id');
    }

    public function isActiveAt(\DateTimeInterface $when): bool
    {
        return $this->start_time <= $when && $this->end_time >= $when;
    }
}
