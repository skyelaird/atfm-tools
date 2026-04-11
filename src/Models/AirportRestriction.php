<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A time-windowed rate reduction on a specific airport.
 *
 * Fields mirror vIFF's restriction model. HHMM time-of-day windows, max
 * 5h enforced at the application layer, auto-expired after 24h past
 * expires_at. See docs/ARCHITECTURE.md §4.3.
 */
final class AirportRestriction extends Model
{
    use SoftDeletes;

    protected $table = 'airport_restrictions';

    // PERTI TMU OpLevel taxonomy (docs/GLOSSARY.md)
    public const OP_LEVEL_STEADY_STATE = 1;
    public const OP_LEVEL_LOCALIZED    = 2;
    public const OP_LEVEL_REGIONAL     = 3;
    public const OP_LEVEL_NAS_WIDE     = 4;

    public const OP_LEVEL_LABELS = [
        1 => 'Steady State',
        2 => 'Localized Impact',
        3 => 'Regional Impact',
        4 => 'NAS-Wide Impact',
    ];

    protected $fillable = [
        'restriction_id',
        'airport_id',
        'runway_config',
        'capacity',
        'reason',
        'op_level',
        'type',
        'runway',
        'tier_minutes',
        'compliance_window_early_min',
        'compliance_window_late_min',
        'start_utc',
        'end_utc',
        'active_from',
        'expires_at',
    ];

    protected $casts = [
        'capacity'                    => 'int',
        'op_level'                    => 'int',
        'tier_minutes'                => 'int',
        'compliance_window_early_min' => 'int',
        'compliance_window_late_min'  => 'int',
        'active_from'                 => 'datetime',
        'expires_at'                  => 'datetime',
    ];

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    /**
     * Generate a restriction_id like "CYHZ11VP" — airport ICAO + 4 random
     * hex chars uppercased. Matches vIFF's format.
     */
    public static function generateId(string $icao): string
    {
        return strtoupper(trim($icao)) . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    }

    /**
     * Is this restriction currently in effect (time-of-day window active +
     * active_from reached + not expired)?
     */
    public function isActiveAt(\DateTimeInterface $now): bool
    {
        if ($this->active_from && $this->active_from > $now) {
            return false;
        }
        if ($this->expires_at && $this->expires_at < $now) {
            return false;
        }
        return self::hhmmInWindow($now->format('Hi'), $this->start_utc, $this->end_utc);
    }

    /**
     * HHMM window check, handling midnight wrap (start=2200, end=0200).
     */
    private static function hhmmInWindow(string $now, string $start, string $end): bool
    {
        $n = (int) $now;
        $s = (int) $start;
        $e = (int) $end;
        return $s <= $e ? ($n >= $s && $n <= $e) : ($n >= $s || $n <= $e);
    }
}
