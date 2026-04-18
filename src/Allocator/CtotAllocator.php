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

        // Release any previously-issued allocations whose restriction is no
        // longer active (deleted, expired, or window closed). Without this,
        // allocations from yesterday's programs would persist forever on
        // flight records. v0.5.0: also clears TLDT, not just CTOT.
        $activeRestrictionIds = $restrictions->pluck('restriction_id')->all();
        $stale = Flight::query()
            ->where(function ($q) {
                $q->whereNotNull('ctot')->orWhereNotNull('tldt');
            })
            ->whereNotNull('ctl_restriction_id')
            ->whereNotIn('ctl_restriction_id', $activeRestrictionIds ?: [''])
            ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])
            ->get();
        foreach ($stale as $flight) {
            $flight->tldt               = null;
            $flight->tldt_assigned_at   = null;
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
     * v0.5.0 — TLDT-primary model. The allocator now writes a TLDT
     * (Target Landing Time, per EUROCONTROL Airport CDM Manual) for
     * every inbound flight within the restriction's tier window. CTOT
     * is derived for ground-bound flights as the back-calculated
     * departure time that would achieve the assigned TLDT — i.e.
     * CTOT enforces TLDT, not the other way around. Airborne flights
     * receive a TLDT but no CTOT (the controller has to slow them
     * down or vector to meet it; we don't issue post-departure
     * regulations).
     *
     * Order of operations:
     *   1. Validate / carry forward existing TLDTs from prior runs
     *      (frozen-slot semantics across cron ticks)
     *   2. Honour imported CTOTs (vATCSCC events, etc.) as fixed
     *      slot reservations
     *   3. Pack remaining inbounds into the rate ladder, sorted by ELDT
     *   4. Derive CTOT for ground flights whose TLDT requires non-zero
     *      delay
     *
     * @param array $stats Passed by reference to accumulate counters.
     */
    private function allocateAirport(
        Airport $airport,
        AirportRestriction $restriction,
        DateTimeImmutable $now,
        array &$stats
    ): void {
        $capacity   = max(1, (int) $restriction->capacity);
        $slotSec    = (int) round(3600 / $capacity);
        $tierSec    = ((int) $restriction->tier_minutes) * 60;
        $tierCutoff = $now->getTimestamp() + $tierSec;
        $earlyMin   = (int) $restriction->compliance_window_early_min;
        $lateMin    = (int) $restriction->compliance_window_late_min;

        $inbound = Flight::query()
            ->where('ades', $airport->icao)
            ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])
            ->get();

        $stats['flights_evaluated'] += $inbound->count();

        $takenSlots = []; // map<int slot_epoch, flight_id>

        // ---- Step 1: Validate existing TLDTs and carry them forward ----
        foreach ($inbound as $flight) {
            if ($flight->tldt === null) {
                continue;
            }

            // Disconnected: release the slot entirely.
            if ($flight->phase === Flight::PHASE_DISCONNECTED) {
                $this->clearAllocation($flight);
                $flight->delay_status = Flight::DELAY_WITHDRAWN;
                $flight->save();
                $stats['ctots_released']++;
                continue;
            }

            // Already departed: compute CTOT compliance, keep the slot
            // reserved (the inbound is still coming).
            if ($flight->atot !== null && $flight->ctot !== null) {
                $drift = $flight->atot->getTimestamp() - $flight->ctot->getTimestamp();
                $flight->delay_status = ($drift >= -$earlyMin * 60 && $drift <= $lateMin * 60)
                    ? Flight::DELAY_COMPLIANT_DEPARTED
                    : Flight::DELAY_NON_COMPLIANT;
                $flight->save();
            }

            // Ground flight that missed its CTOT compliance window:
            // release CTOT and TLDT for re-allocation this cycle.
            if ($flight->ctot !== null
                && $flight->atot === null
                && $now->getTimestamp() > $flight->ctot->getTimestamp() + $lateMin * 60
            ) {
                $this->clearAllocation($flight);
                $flight->delay_status = Flight::DELAY_NON_COMPLIANT;
                $flight->save();
                $stats['ctots_reissued']++;
                continue;
            }

            // Slot is still valid — reserve it.
            $slotStart = $this->snapSlot($flight->tldt->getTimestamp(), $slotSec);
            $takenSlots[$slotStart] = $flight->id;
            $stats['ctots_frozen_kept']++;
        }

        // ---- Step 2: Imported (event/file) CTOTs as fixed reservations ----
        // An imported source declares "this callsign must depart at
        // CTOT_imp"; we honour it as the truth and derive a TLDT from it
        // by adding the flight's expected enroute time to land at the
        // destination.
        $importedMap = $this->loadImportedCtots($inbound, $now);
        foreach ($inbound as $flight) {
            $hit = $this->matchImported($flight, $importedMap);
            if ($hit === null) {
                continue;
            }
            // Skip airborne flights — a departure CTOT is meaningless once
            // the aircraft has taken off.
            if ($flight->atot !== null) {
                continue;
            }
            // Skip if we already wrote this exact CTOT in a prior run.
            if ($flight->ctot !== null
                && $flight->ctot->getTimestamp() === $hit->ctot->getTimestamp()
            ) {
                continue;
            }

            $ctotImpEpoch = $hit->ctot->getTimestamp();
            $eteMin       = (int) ($flight->fp_enroute_time_min ?? 0);
            $exotMin      = (int) ($flight->planned_exot_min ?? $airport->default_exot_min ?? 10);
            $impliedTldt  = $ctotImpEpoch + (($eteMin + $exotMin) * 60);

            $slotStart = $this->snapSlot($impliedTldt, $slotSec);

            $flight->ctot               = $hit->ctot;
            $flight->tldt               = (new DateTimeImmutable('@' . $impliedTldt))
                ->setTimezone(new DateTimeZone('UTC'));
            $flight->tldt_assigned_at   = $now;
            $flight->ctl_type           = (str_starts_with($hit->source_file ?? '', 'vatcan:'))
                ? 'EVENT_BOOKED'
                : 'IMPORTED_CTOT';
            $flight->ctl_element        = $airport->icao;
            $flight->ctl_restriction_id = $restriction->restriction_id;
            $delay = (int) round(
                ($hit->ctot->getTimestamp() - ($flight->ttot?->getTimestamp() ?? $hit->ctot->getTimestamp())) / 60
            );
            $flight->delay_minutes  = max(0, $delay);
            $flight->delay_status   = $delay > 0 ? Flight::DELAY_DELAYED : Flight::DELAY_ON_TIME;
            $flight->save();

            $takenSlots[$slotStart] = $flight->id;
            $stats['ctots_issued']++;
        }

        // ---- Step 3: Pack remaining inbounds into the rate ladder ----
        // Build candidates with their estimated landing times, sort by
        // ELDT (closest first), then walk the ladder.
        $candidates = [];
        foreach ($inbound as $flight) {
            if ($flight->tldt !== null) {
                continue; // already allocated above
            }
            if ($flight->phase === Flight::PHASE_DISCONNECTED) {
                continue;
            }
            $etaEpoch = $this->estimateArrivalEpoch($flight, $airport, $now);
            if ($etaEpoch === null) {
                continue;
            }
            if ($etaEpoch > $tierCutoff) {
                continue;
            }
            $candidates[] = ['flight' => $flight, 'eldt' => $etaEpoch];
        }
        usort($candidates, fn ($a, $b) => $a['eldt'] <=> $b['eldt']);

        foreach ($candidates as $cand) {
            $flight   = $cand['flight'];
            $etaEpoch = $cand['eldt'];

            $slotEpoch = $this->nextFreeSlot($takenSlots, $etaEpoch, $slotSec);
            $takenSlots[$slotEpoch] = $flight->id;

            $delaySec = $slotEpoch - $etaEpoch;

            $flight->tldt               = (new DateTimeImmutable('@' . $slotEpoch))
                ->setTimezone(new DateTimeZone('UTC'));
            $flight->tldt_assigned_at   = $now;
            $flight->ctl_type           = 'AIRPORT_ARR_RATE';
            $flight->ctl_element        = $airport->icao;
            $flight->ctl_restriction_id = $restriction->restriction_id;
            $flight->delay_minutes      = (int) round($delaySec / 60);

            // ---- Step 4: Derive CTOT for ground-bound flights ----
            // Only ground flights get a CTOT — and only if the assigned
            // delay exceeds the 5-minute floor (below that, the pilot
            // can absorb it in taxi-out without issuing a regulation).
            if ($flight->isOnGround() && $delaySec >= 5 * 60) {
                // CTOT = TTOT + delay (algebraically equivalent to
                // TLDT − ETE − EXOT for our non-CDM scope where we
                // don't have a separate ETE field stored).
                $ttotEpoch    = $flight->ttot?->getTimestamp() ?? $now->getTimestamp();
                $newCtotEpoch = $ttotEpoch + $delaySec;
                $flight->ctot = (new DateTimeImmutable('@' . $newCtotEpoch))
                    ->setTimezone(new DateTimeZone('UTC'));
                $flight->delay_status = Flight::DELAY_DELAYED;
                $stats['ctots_issued']++;
            } elseif ($delaySec >= 5 * 60) {
                // Airborne with non-trivial delay — flag as delayed but
                // no CTOT (controller must slow / vector to meet TLDT).
                $flight->delay_status = Flight::DELAY_DELAYED;
            } else {
                $flight->delay_status = Flight::DELAY_ON_TIME;
            }

            $flight->save();
        }
    }

    /**
     * Wipe an existing allocation off a flight (used by Step 1's
     * release branches). Doesn't touch state we should never override
     * here, like phase or ALDT.
     */
    private function clearAllocation(Flight $flight): void
    {
        $flight->tldt               = null;
        $flight->tldt_assigned_at   = null;
        $flight->ctot               = null;
        $flight->ctl_type           = null;
        $flight->ctl_element        = null;
        $flight->ctl_restriction_id = null;
        $flight->delay_minutes      = null;
        $flight->delay_status       = null;
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
