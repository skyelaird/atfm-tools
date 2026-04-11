<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Short-retention position history row. Feeds the ROT state machine and
 * trajectory-based computations. Purged by bin/cleanup.php after 48 h.
 *
 * Not for analytical long-term use — derived milestones live on the
 * flights row itself.
 */
final class PositionScratch extends Model
{
    protected $table = 'position_scratch';

    public $timestamps = false;

    protected $fillable = [
        'flight_id',
        'lat',
        'lon',
        'altitude_ft',
        'groundspeed_kts',
        'heading_deg',
        'observed_at',
    ];

    protected $casts = [
        'flight_id'       => 'int',
        'lat'             => 'float',
        'lon'             => 'float',
        'altitude_ft'     => 'int',
        'groundspeed_kts' => 'int',
        'heading_deg'     => 'int',
        'observed_at'     => 'datetime',
    ];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }
}
