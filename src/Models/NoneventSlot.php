<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Non-event CTOT slot.
 *
 * Issued by the open flow-management layer to pilots whose flights
 * cross CTP-active Atlantic corridors but aren't booked into the
 * event slot system. 4 slots/hour per ADES, clock-aligned
 * (:00/:15/:30/:45), reverse-computed from an allocated ELDT.
 *
 * Filters (enforced by the allocator, not at the DB layer):
 *   - ADEP / ADES must NOT be a CTP event airport
 *   - ADEP / ADES must NOT be one of our 7 Canadian airports
 *     (CYHZ/CYOW/CYUL/CYVR/CYWG/CYYC/CYYZ — those are covered by
 *     the main atfm-tools allocator against AAR)
 *   - Filed route must not violate an ECFMP MANDATORY_ROUTE or
 *     PROHIBIT measure
 *
 * The slot is *frozen* at assignment — subsequent wind-forecast
 * updates do not shift the CTOT. The pilot sees a stable number
 * until the slot expires (ctot + 15 min no-show) or is voluntarily
 * released.
 */
final class NoneventSlot extends Model
{
    protected $table = 'nonevent_slots';

    protected $fillable = [
        'cid',
        'callsign',
        'adep',
        'ades',
        'eobt',
        'ctot',
        'eldt',
        'filed_route',
        'aircraft_type',
        'filed_fl',
        'submitted_by',
        'expires_at',
        'released_at',
        'release_reason',
    ];

    protected $casts = [
        'cid'         => 'integer',
        'filed_fl'    => 'integer',
        'eobt'        => 'datetime',
        'ctot'        => 'datetime',
        'eldt'        => 'datetime',
        'expires_at'  => 'datetime',
        'released_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        if ($this->released_at !== null) {
            return false;
        }
        return $this->expires_at > new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public function release(string $reason): void
    {
        $this->released_at = new \DateTime('now', new \DateTimeZone('UTC'));
        $this->release_reason = $reason;
        $this->save();
    }
}
