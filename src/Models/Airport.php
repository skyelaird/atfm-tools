<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Airport with a base arrival rate and optional observed-rate override.
 *
 * Mirrors vIFF's airport record at the operator-config level, plus columns
 * for ICAO 9971 Part II App II-B dynamic AAR derivation (see
 * docs/ARCHITECTURE.md §9).
 */
final class Airport extends Model
{
    protected $table = 'airports';

    protected $fillable = [
        'icao',
        'name',
        'latitude',
        'longitude',
        'elevation_ft',
        'base_arrival_rate',
        'observed_arrival_rate',
        'observed_rate_sample_n',
        'observed_rate_updated_at',
        'base_departure_rate',
        'default_exot_min',
        'default_exit_min',
        'is_cdm_airport',
        'arrived_geofence_nm',
        'final_threshold_nm',
        'active_config_name',
        'active_arr_rate',
        'active_dep_rate',
        'active_config_set_at',
    ];

    protected $casts = [
        'latitude'                 => 'float',
        'longitude'                => 'float',
        'elevation_ft'             => 'int',
        'base_arrival_rate'        => 'int',
        'observed_arrival_rate'    => 'int',
        'observed_rate_sample_n'   => 'int',
        'observed_rate_updated_at' => 'datetime',
        'base_departure_rate'      => 'int',
        'default_exot_min'         => 'int',
        'default_exit_min'         => 'int',
        'is_cdm_airport'           => 'bool',
        'arrived_geofence_nm'      => 'int',
        'final_threshold_nm'       => 'int',
        'active_arr_rate'          => 'int',
        'active_dep_rate'          => 'int',
    ];

    public function thresholds(): HasMany
    {
        return $this->hasMany(RunwayThreshold::class, 'airport_icao', 'icao');
    }

    public function restrictions(): HasMany
    {
        return $this->hasMany(AirportRestriction::class);
    }

    /**
     * Effective arrival rate: active (FMP-set) > observed > base.
     * Single source of truth — same value everywhere.
     */
    public function effectiveArrivalRate(int $minSamples = 100): int
    {
        if ($this->active_arr_rate !== null) {
            return $this->active_arr_rate;
        }
        if ($this->observed_arrival_rate !== null && $this->observed_rate_sample_n >= $minSamples) {
            return $this->observed_arrival_rate;
        }
        return $this->base_arrival_rate;
    }
}
