<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configured VATCAN event code to poll for slot bookings.
 *
 * Typically 0 rows — atfm-tools admin adds a row when an event organizer
 * publishes an event code (e.g. 'xXpFB' for a CTP). See
 * docs/ARCHITECTURE.md §4.7.
 */
final class EventSource extends Model
{
    protected $table = 'event_sources';

    protected $fillable = [
        'event_code',
        'label',
        'start_utc',
        'end_utc',
        'active',
    ];

    protected $casts = [
        'start_utc' => 'datetime',
        'end_utc'   => 'datetime',
        'active'    => 'bool',
    ];

    /**
     * Is this event source currently "on" — either explicitly active or
     * within its time window?
     */
    public function isLive(\DateTimeInterface $now): bool
    {
        if (! $this->active) {
            return false;
        }
        if ($this->start_utc && $this->start_utc > $now) {
            return false;
        }
        if ($this->end_utc && $this->end_utc < $now) {
            return false;
        }
        return true;
    }

    public function apiUrl(): string
    {
        return 'https://bookings.vatcan.ca/api/event/' . rawurlencode($this->event_code);
    }
}
