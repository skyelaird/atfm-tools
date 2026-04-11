<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Flight Information Region.
 *
 * Mirrors atfm-flow's flight_information_regions schema (trimmed).
 */
final class Fir extends Model
{
    protected $table = 'firs';

    protected $fillable = ['identifier', 'name'];

    public function flowMeasures(): HasMany
    {
        return $this->hasMany(FlowMeasure::class, 'fir_id');
    }
}
