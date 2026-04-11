<?php

declare(strict_types=1);

namespace Atfm\Allocator;

use Atfm\Models\Airport;
use Atfm\Models\AirportRestriction;
use Atfm\Models\AllocationRun;
use Atfm\Models\Flight;
use Atfm\Models\ImportedCtot;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Rate-based tactical CTOT allocator — NOT a classical GDP.
 *
 * Implements the CASA-light priority ladder described in
 * docs/ARCHITECTURE.md §8.1.
 *
 * Stateless across runs except for the set of *frozen CTOTs* carried from
 * prior cycles (live in the `flights.ctot` column). Every invocation is a
 * fresh allocation that may keep, release, reissue, or newly issue CTOTs.
 *
 * Responsibilities:
 *   1. Load active restrictions (matching now's HHMM window and active_from/expires_at)
 *   2. For each airport with an active restriction:
 *        a. Validate and carry frozen CTOTs (or release non-compliant ones)
 *        b. Pre-consume capacity by airborne inbound (no CTOT, just bucket fill)
 *        c. Overlay imported/event CTOTs (priority-ordered)
 *        d. CASA-sort ground inbound within tier, issue new CTOTs >= 5 min delay
 *        e. Skip flights beyond tier
 *   3. Write audit row to `allocation_runs`
 */
final class CtotAllocator
{
    public function run(): array
    {
        $start   = microtime(true);
        $runUuid = self::generateUuid();
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $stats = [
            'airports_considered' => 0,
            'restrictions_active' => 0,
            'flights_evaluated'   => 0,
            'ctots_frozen_kept'   => 0,
            'ctots_issued'        => 0,
            'ctots_released'      => 0,
            'ctots_reissued'      => 0,
        ];

        // Load all restrictions whose window is currently active.
        $restrictions = AirportRestriction::query()
            ->whereNull('deleted_at')
            ->where('active_from', '<=', $now->format('Y-m-d H:i:s'))
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>=', $now->format('Y-m-d H:i:s'));
            })
            ->with('airport')
            ->get()
            ->filter(fn (AirportRestriction $r) => $r->isActiveAt($now));

        $stats['restrictions_active'] = $restrictions->count();

        // Release any previously-issued CTOTs whose restriction is no longer
        // active (deleted, expired, or window closed). Without this, CTOTs
        // from yesterday's programs would persist forever on flight records.
        $activeRestrictionIds = $restrictions->pluck('restriction_id')->all();
        $stale = Flight::query()
            ->whereNotNull('ctot')
            ->whereNotNull('ctl_restriction_id')
            ->whereNotIn('ctl_restriction_id', $activeRestrictionIds ?: [''])
            ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])
            ->get();
        foreach ($stale as $flight) {
            $flight->ctot               = null;
            $flight->ctl_type           = null;
            $flight->ctl_element        = null;
            $flight->ctl_restriction_id = null;
            $flight->delay_minutes      = null;
            $flight->delay_status       = null;
            $flight->save();
            $stats['ctots_released']++;
        }

        foreach ($restrictions as $restriction) {
            $airport = $restriction->airport;
            if (! $airport) {
                continue;
            }
            $stats['airports_considered']++;
            $this->allocateAirport($airport, $restriction, $now, $stats);
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);
        AllocationRun::create([
            'run_uuid'            => $runUuid,
            'started_at'          => $now,
            'finished_at'         => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'airports_considered' => $stats['airports_considered'],
            'restrictions_active' => $stats['restrictions_active'],
            'flights_evaluated'   => $stats['flights_evaluated'],
            'ctots_frozen_kept'   => $stats['ctots_frozen_kept'],
            'ctots_issued'        => $stats['ctots_issued'],
            'ctots_released'      => $stats['ctots_released'],
            'ctots_reissued'      => $stats['ctots_reissued'],
            'elapsed_ms'          => $elapsedMs,
        ]);

        return [...$stats, 'elapsed_ms' => $elapsedMs, 'run_uuid' => $runUuid];
    }

    /**
     * Allocate one airport's arrival slots.
     *
     * @param array $stats Passed by reference to accumulate counters.
     */
    private function allocateAirport(
        Airport $airport,
        AirportRestriction $restriction,
        DateTimeImmutable $now,
        array &$stats
    ): void {
        $capacity = max(1, (int) $restriction->capacity);
        $slotSec  = (int) round(3600 / $capacity);
        $tierSec  = ((int) $restriction->tier_minutes) * 60;
        $tierCutoff = $now->getTimestamp() + $tierSec;

        // Candidates: inbound flights to this airport, not terminal.
        $inbound = Flight::query()
            ->where('ades', $airport->icao)
            ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])
            ->orderBy('eobt')
            ->get();

        $stats['flights_evaluated'] += $inbound->count();

        // ---- Step 0 / 1: validate existing frozen CTOTs ----
        $takenSlots = []; // map<int slot_epoch, true>

        foreach ($inbound as $flight) {
            if ($flight->ctot === null) {
                continue;
            }
            $ctotEpoch = $flight->ctot->getTimestamp();
            $earlyMin  = (int) $restriction->compliance_window_early_min;
            $lateMin   = (int) $restriction->compliance_window_late_min;

            if ($flight->phase === Flight::PHASE_DISCONNECTED) {
                // Flight disappeared from feed — release its slot.
                $flight->ctot = null;
                $flight->ctl_type = null;
                $flight->ctl_element = null;
                $flight->ctl_restriction_id = null;
                $flight->delay_status = Flight::DELAY_WITHDRAWN;
                $flight->save();
                $stats['ctots_released']++;
                continue;
            }

            if ($flight->atot !== null) {
                // Flight already departed — compute compliance.
                $actualEpoch = $flight->atot->getTimestamp();
                $drift = $actualEpoch - $ctotEpoch;
                if ($drift >= -$earlyMin * 60 && $drift <= $lateMin * 60) {
                    $flight->delay_status = Flight::DELAY_COMPLIANT_DEPARTED;
                } else {
                    $flight->delay_status = Flight::DELAY_NON_COMPLIANT;
                }
                $flight->save();
                // Slot is released to downstream
                $stats['ctots_released']++;
                continue;
            }

            if ($now->getTimestamp() > $ctotEpoch + $lateMin * 60) {
                // Missed the window on the ground. Release + reissue candidate.
                $flight->ctot = null;
                $flight->delay_status = Flight::DELAY_NON_COMPLIANT;
                $flight->save();
                $stats['ctots_reissued']++;
                continue;
            }

            // Still valid — keep the slot.
            $slotStart = $this->snapSlot($ctotEpoch, $slotSec);
            $takenSlots[$slotStart] = true;
            $stats['ctots_frozen_kept']++;
        }

        // ---- Step 2: Airborne inbound pre-consume capacity ----
        foreach ($inbound as $flight) {
            if (! $flight->isAirborne()) {
                continue;
            }
            if ($flight->ctot !== null) {
                continue; // handled in step 0
            }
            $etaEpoch = $this->estimateArrivalEpoch($flight, $airport, $now);
            if ($etaEpoch === null) {
                continue;
            }
            if ($etaEpoch > $tierCutoff) {
                continue; // beyond tier
            }
            $slot = $this->nextFreeSlot($takenSlots, $etaEpoch, $slotSec);
            $takenSlots[$slot] = true;
            // Airborne flights don't get a CTOT — just consume the bucket.
        }

        // ---- Step 3: Imported / event CTOTs (priority-ordered) ----
        $importedMap = $this->loadImportedCtots($inbound, $now);
        foreach ($inbound as $flight) {
            $hit = $this->matchImported($flight, $importedMap);
            if ($hit === null) {
                continue;
            }
            if ($flight->ctot !== null && $flight->ctot->getTimestamp() === $hit->ctot->getTimestamp()) {
                continue; // already frozen at the imported time
            }
            $slotStart = $this->snapSlot($hit->ctot->getTimestamp(), $slotSec);
            if (isset($takenSlots[$slotStart])) {
                // Imported source says this slot — if it collides, we still
                // honor the import; allocator's later issuances will avoid it.
            }
            $flight->ctot            = $hit->ctot;
            $flight->ctl_type        = (str_starts_with($hit->source_file ?? '', 'vatcan:'))
                ? 'EVENT_BOOKED'
                : 'IMPORTED_CTOT';
            $flight->ctl_element     = $airport->icao;
            $flight->ctl_restriction_id = $restriction->restriction_id;
            $delay = (int) round(
                ($hit->ctot->getTimestamp() - ($flight->ttot?->getTimestamp() ?? $hit->ctot->getTimestamp())) / 60
            );
            $flight->delay_minutes  = max(0, $delay);
            $flight->delay_status   = $delay > 0 ? Flight::DELAY_DELAYED : Flight::DELAY_ON_TIME;
            $flight->save();
            $takenSlots[$slotStart] = true;
            $stats['ctots_issued']++;
        }

        // ---- Step 4: Ground CASA for remaining inbound, within tier ----
        $ground = $inbound
            ->filter(fn (Flight $f) => $f->isOnGround() && $f->ctot === null)
            ->sortBy(fn (Flight $f) => $f->eobt?->getTimestamp() ?? PHP_INT_MAX);

        foreach ($ground as $flight) {
            $etaEpoch = $this->estimateArrivalEpoch($flight, $airport, $now);
            if ($etaEpoch === null) {
                continue;
            }
            if ($etaEpoch > $tierCutoff) {
                continue;
            }
            $assignedSlot = $this->nextFreeSlot($takenSlots, $etaEpoch, $slotSec);
            $takenSlots[$assignedSlot] = true;

            $delaySec = $assignedSlot - $etaEpoch;
            if ($delaySec < 5 * 60) {
                // Delay below threshold — no CTOT needed.
                continue;
            }

            // Issue CTOT = TTOT + delay. If TTOT is unknown, use now + delay as fallback.
            $ttotEpoch = $flight->ttot?->getTimestamp() ?? $now->getTimestamp();
            $newCtotEpoch = $ttotEpoch + $delaySec;

            $flight->ctot = (new DateTimeImmutable('@' . $newCtotEpoch))->setTimezone(new DateTimeZone('UTC'));
            $flight->ctl_type           = 'AIRPORT_ARR_RATE';
            $flight->ctl_element        = $airport->icao;
            $flight->ctl_restriction_id = $restriction->restriction_id;
            $flight->delay_minutes      = (int) round($delaySec / 60);
            $flight->delay_status       = Flight::DELAY_DELAYED;
            $flight->save();
            $stats['ctots_issued']++;
        }
    }

    /**
     * Estimate arrival epoch for a flight heading to `airport`, or null if
     * unknown. Delegates to EtaEstimator which implements the tiered
     * cascade (filed → observed position → filed TAS → type table → default).
     */
    private function estimateArrivalEpoch(Flight $flight, Airport $airport, DateTimeImmutable $now): ?int
    {
        $est = EtaEstimator::estimate($flight, $airport, $now);
        return $est['epoch'];
    }

    /**
     * Load imported CTOTs currently valid, keyed by cid and callsign.
     *
     * @return array{by_cid: array<int, ImportedCtot>, by_callsign: array<string, ImportedCtot>}
     */
    private function loadImportedCtots($inboundFlights, DateTimeImmutable $now): array
    {
        $callsigns = $inboundFlights->pluck('callsign')->filter()->unique()->values()->all();
        $cids      = $inboundFlights->pluck('cid')->filter()->unique()->values()->all();

        $rows = ImportedCtot::query()
            ->where('active', true)
            ->where('valid_from', '<=', $now->format('Y-m-d H:i:s'))
            ->where('valid_until', '>=', $now->format('Y-m-d H:i:s'))
            ->where(function ($q) use ($callsigns, $cids) {
                if (! empty($callsigns)) {
                    $q->whereIn('callsign', $callsigns);
                }
                if (! empty($cids)) {
                    $q->orWhereIn('cid', $cids);
                }
            })
            ->orderBy('priority') // lowest number wins
            ->get();

        $byCid = [];
        $byCs  = [];
        foreach ($rows as $row) {
            if ($row->cid !== null && ! isset($byCid[$row->cid])) {
                $byCid[$row->cid] = $row;
            }
            if ($row->callsign !== null && ! isset($byCs[$row->callsign])) {
                $byCs[$row->callsign] = $row;
            }
        }
        return ['by_cid' => $byCid, 'by_callsign' => $byCs];
    }

    private function matchImported(Flight $flight, array $importedMap): ?ImportedCtot
    {
        if ($flight->cid && isset($importedMap['by_cid'][$flight->cid])) {
            return $importedMap['by_cid'][$flight->cid];
        }
        if ($flight->callsign && isset($importedMap['by_callsign'][$flight->callsign])) {
            return $importedMap['by_callsign'][$flight->callsign];
        }
        return null;
    }

    /** Snap an epoch time to the start of its slot bucket. */
    private function snapSlot(int $epoch, int $slotSec): int
    {
        return (int) (floor($epoch / $slotSec) * $slotSec);
    }

    /** Find the next free slot at or after a target epoch. */
    private function nextFreeSlot(array &$taken, int $target, int $slotSec): int
    {
        $slot = (int) (ceil($target / $slotSec) * $slotSec);
        while (isset($taken[$slot])) {
            $slot += $slotSec;
        }
        return $slot;
    }

    /** Generate a v4 UUID without pulling in ramsey/uuid. */
    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
