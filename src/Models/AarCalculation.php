<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rolling AAR derivations per airport per runway per window.
 * Populated by bin/compute-aar.php using the ICAO 9971 Part II App II-B
 * formula: AAR = round_down(mean_threshold_GS / mean_spacing_NM).
 *
 * When sample_count exceeds a threshold (default 100), the latest row for
 * an (airport, runway) pair promotes into airports.observed_arrival_rate.
 */
final class AarCalculation extends Model
{
    protected $table = 'aar_calculations';

    protected $fillable = [
        'airport_icao',
        'runway_ident',
        'window_start',
        'window_end',
        'mean_threshold_gs_kts',
        'mean_spacing_nm',
        'computed_aar',
        'sample_count',
        'confidence_pct',
        'preceding_wake',
        'follower_wake',
    ];

    protected $casts = [
        'window_start'          => 'datetime',
        'window_end'            => 'datetime',
        'mean_threshold_gs_kts' => 'int',
        'mean_spacing_nm'       => 'float',
        'computed_aar'          => 'int',
        'sample_count'          => 'int',
        'confidence_pct'        => 'int',
    ];
}
