<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A runway landing threshold. Each physical runway strip has two rows in
 * this table — one per landing direction.
 *
 * Seeded from NAV CANADA AIP / CFS data via bin/seed-airports.php.
 */
final class RunwayThreshold extends Model
{
    protected $table = 'runway_thresholds';

    protected $fillable = [
        'airport_icao',
        'runway_ident',
        'heading_deg',
        'threshold_lat',
        'threshold_lon',
        'opposite_threshold_lat',
        'opposite_threshold_lon',
        'width_ft',
        'elevation_ft',
        'displaced_threshold_ft',
    ];

    protected $casts = [
        'heading_deg'            => 'int',
        'threshold_lat'          => 'float',
        'threshold_lon'          => 'float',
        'opposite_threshold_lat' => 'float',
        'opposite_threshold_lon' => 'float',
        'width_ft'               => 'int',
        'elevation_ft'           => 'int',
        'displaced_threshold_ft' => 'int',
    ];

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'airport_icao', 'icao');
    }
}
