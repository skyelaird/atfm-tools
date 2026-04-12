<?php

declare(strict_types=1);

namespace Atfm\Allocator;

use Atfm\Models\Airport;
use Atfm\Models\Flight;
use Atfm\Models\PositionScratch;
use Atfm\Models\RotObservation;
use Atfm\Models\RunwayThreshold;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Adaptive runway-occupancy tracker — v0.4.
 *
 * What this is and what it isn't
 * --------------------------------
 *
 * This is the atfm-tools answer to "where did this flight cross the
 * runway threshold and how long was it on the runway?" — but at a 5-min
 * ingest cadence, where typical ROT is 30–90 s, we cannot directly
 * measure ROT to the second. We do the best we can with what we have:
 *
 *   1. For each flight that has just departed (ATOT set, no DEP
 *      rot_observation yet) or just landed (ALDT set, no ARR
 *      rot_observation yet), pull its position_scratch trail in the
 *      window around the milestone.
 *   2. Find the two consecutive scratch samples that bracket the runway
 *      threshold polygon (one inside the airport bbox / on ground, one
 *      airborne for departures; reverse for arrivals). Linearly
 *      interpolate the threshold-crossing time → refined ATOT/ALDT.
 *      Source = 'I' (interpolated).
 *   3. If only a single sample is in range, fall back to using the
 *      milestone time as-is. Source = 'A' or 'F'.
 *   4. For arrivals, scan forward in scratch history to find the first
 *      sample with groundspeed < 30 kt as a proxy for "off the runway".
 *      Source = 'A' on a 5-min cadence.
 *   5. Pick the most plausible runway by matching the flight's heading
 *      against each candidate threshold's `heading_deg` (smallest
 *      circular delta wins). On a tie, prefer the threshold whose lat/lon
 *      is closest to the bracketing samples.
 *
 * The output table `rot_observations` is consumed by bin/compute-aar.php
 * which derives an Airport Arrival Rate from inter-arrival spacing and
 * the AAR = GS / spacing formula (ICAO 9971 Part II App II-B).
 *
 * Idempotent: re-running the tracker on a flight that already has a
 * rot_observation row for an event type is a no-op.
 */
final class RotTracker
{
    /** Look back window for which flights to consider. */
    private const LOOKBACK_MIN = 30;

    /** How far around the milestone to scan position_scratch. */
    private const SCRATCH_WINDOW_MIN = 6;

    /** GS threshold (kt) for "off the runway, taxiing" in arrival case. */
    private const TAXI_GS_KT = 30;

    /** Maximum NM the bracketing samples may be from the threshold. */
    private const BRACKET_MAX_NM = 4.0;

    public function run(): array
    {
        $now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->modify('-' . self::LOOKBACK_MIN . ' minutes')->format('Y-m-d H:i:s');

        $stats = [
            'departures_processed' => 0,
            'arrivals_processed'   => 0,
            'interpolated'         => 0,
            'approximated'         => 0,
            'fallback'             => 0,
            'skipped_no_scratch'   => 0,
            'skipped_no_runway'    => 0,
        ];

        // Build a map: airport_icao → array<RunwayThreshold>.
        $thresholdsByAirport = [];
        foreach (RunwayThreshold::all() as $rt) {
            $thresholdsByAirport[$rt->airport_icao][] = $rt;
        }

        // ----- Departures -----
        $deps = Flight::query()
            ->whereNotNull('atot')
            ->where('atot', '>=', $cutoff)
            ->whereNotNull('adep')
            ->whereDoesntHave('rotObservations', function ($q) {
                $q->where('event_type', RotObservation::EVENT_DEP);
            })
            ->limit(500)
            ->get();

        foreach ($deps as $flight) {
            $airport = $thresholdsByAirport[$flight->adep] ?? null;
            if ($airport === null) {
                $stats['skipped_no_runway']++;
                continue;
            }
            $obs = $this->observeDeparture($flight, $airport);
            if ($obs === null) {
                $stats['skipped_no_scratch']++;
                continue;
            }
            RotObservation::create($obs);
            $stats['departures_processed']++;
            $stats[match ($obs['source']) {
                RotObservation::SOURCE_INTERPOLATED => 'interpolated',
                RotObservation::SOURCE_APPROX       => 'approximated',
                default                             => 'fallback',
            }]++;
        }

        // ----- Arrivals -----
        $arrs = Flight::query()
            ->whereNotNull('aldt')
            ->where('aldt', '>=', $cutoff)
            ->whereNotNull('ades')
            ->whereDoesntHave('rotObservations', function ($q) {
                $q->where('event_type', RotObservation::EVENT_ARR);
            })
            ->limit(500)
            ->get();

        foreach ($arrs as $flight) {
            $airport = $thresholdsByAirport[$flight->ades] ?? null;
            if ($airport === null) {
                $stats['skipped_no_runway']++;
                continue;
            }
            $obs = $this->observeArrival($flight, $airport);
            if ($obs === null) {
                $stats['skipped_no_scratch']++;
                continue;
            }
            RotObservation::create($obs);
            $stats['arrivals_processed']++;
            $stats[match ($obs['source']) {
                RotObservation::SOURCE_INTERPOLATED => 'interpolated',
                RotObservation::SOURCE_APPROX       => 'approximated',
                default                             => 'fallback',
            }]++;
        }

        return $stats;
    }

    /**
     * @param list<RunwayThreshold> $thresholds
     * @return array<string, mixed>|null
     */
    private function observeDeparture(Flight $flight, array $thresholds): ?array
    {
        // Pull scratch samples around ATOT.
        $samples = $this->scratchAround($flight, $flight->atot);
        if (empty($samples)) {
            return null;
        }

        // Pick the runway by matching heading at the airborne sample.
        $runway = $this->pickRunway($thresholds, $samples, $flight, /*departure*/ true);
        if ($runway === null) {
            return null;
        }

        // Interpolate threshold crossing: find two consecutive samples
        // bracketing "on ground low GS / accelerating" → "airborne".
        // Heuristic: alt_ft transitions from runway elevation +200 ft.
        $thresholdAlt = ($runway->elevation_ft ?? 0) + 200;
        $crossingAt = null;
        $thresholdGs = null;
        $source = RotObservation::SOURCE_FALLBACK;

        for ($i = 0; $i + 1 < count($samples); $i++) {
            $a = $samples[$i];
            $b = $samples[$i + 1];
            $aAlt = (int) ($a->altitude_ft ?? 0);
            $bAlt = (int) ($b->altitude_ft ?? 0);
            if ($aAlt < $thresholdAlt && $bAlt >= $thresholdAlt) {
                // Linear interpolation in altitude → time
                $span = $bAlt - $aAlt;
                $frac = $span > 0 ? max(0.0, min(1.0, ($thresholdAlt - $aAlt) / $span)) : 0.5;
                $tA = $a->observed_at->getTimestamp();
                $tB = $b->observed_at->getTimestamp();
                $crossingAt = (new DateTimeImmutable('@' . (int) round($tA + $frac * ($tB - $tA))))
                    ->setTimezone(new DateTimeZone('UTC'));
                $thresholdGs = (int) round((($a->groundspeed_kts ?? 0) + ($b->groundspeed_kts ?? 0)) / 2);
                $source = RotObservation::SOURCE_INTERPOLATED;
                break;
            }
        }

        if ($crossingAt === null) {
            // Single-sample fallback: use ATOT and the closest sample's GS.
            $closest = $this->closestSample($samples, $flight->atot);
            $crossingAt = $flight->atot;
            $thresholdGs = $closest?->groundspeed_kts;
            $source = $closest !== null ? RotObservation::SOURCE_APPROX : RotObservation::SOURCE_FALLBACK;
        }

        // Departures don't get a clear_at — runway is vacated by the act
        // of becoming airborne, which is the threshold crossing itself.
        return [
            'flight_id'        => $flight->id,
            'airport_icao'     => $flight->adep,
            'runway_ident'     => $runway->runway_ident,
            'event_type'       => RotObservation::EVENT_DEP,
            'threshold_at'     => $crossingAt,
            'clear_at'         => null,
            'rot_seconds'      => null,
            'threshold_gs_kts' => $thresholdGs,
            'source'           => $source,
        ];
    }

    /**
     * @param list<RunwayThreshold> $thresholds
     * @return array<string, mixed>|null
     */
    private function observeArrival(Flight $flight, array $thresholds): ?array
    {
        $samples = $this->scratchAround($flight, $flight->aldt);
        if (empty($samples)) {
            return null;
        }

        $runway = $this->pickRunway($thresholds, $samples, $flight, /*departure*/ false);
        if ($runway === null) {
            return null;
        }

        $thresholdAlt = ($runway->elevation_ft ?? 0) + 200;
        $crossingAt = null;
        $thresholdGs = null;
        $source = RotObservation::SOURCE_FALLBACK;

        // Bracketing transition: airborne above threshold → on/below threshold
        for ($i = 0; $i + 1 < count($samples); $i++) {
            $a = $samples[$i];
            $b = $samples[$i + 1];
            $aAlt = (int) ($a->altitude_ft ?? 0);
            $bAlt = (int) ($b->altitude_ft ?? 0);
            if ($aAlt >= $thresholdAlt && $bAlt < $thresholdAlt) {
                $span = $aAlt - $bAlt;
                $frac = $span > 0 ? max(0.0, min(1.0, ($aAlt - $thresholdAlt) / $span)) : 0.5;
                $tA = $a->observed_at->getTimestamp();
                $tB = $b->observed_at->getTimestamp();
                $crossingAt = (new DateTimeImmutable('@' . (int) round($tA + $frac * ($tB - $tA))))
                    ->setTimezone(new DateTimeZone('UTC'));
                $thresholdGs = (int) round((($a->groundspeed_kts ?? 0) + ($b->groundspeed_kts ?? 0)) / 2);
                $source = RotObservation::SOURCE_INTERPOLATED;
                break;
            }
        }

        if ($crossingAt === null) {
            $closest = $this->closestSample($samples, $flight->aldt);
            $crossingAt = $flight->aldt;
            $thresholdGs = $closest?->groundspeed_kts;
            $source = $closest !== null ? RotObservation::SOURCE_APPROX : RotObservation::SOURCE_FALLBACK;
        }

        // Find clear_at: first sample after threshold crossing where GS
        // dropped below taxi threshold (proxy for "off the runway").
        $clearAt = null;
        $rotSec  = null;
        foreach ($samples as $s) {
            if ($s->observed_at <= $crossingAt) {
                continue;
            }
            if (($s->groundspeed_kts ?? 999) < self::TAXI_GS_KT) {
                $clearAt = $s->observed_at;
                $rotSec  = $clearAt->getTimestamp() - $crossingAt->getTimestamp();
                break;
            }
        }

        return [
            'flight_id'        => $flight->id,
            'airport_icao'     => $flight->ades,
            'runway_ident'     => $runway->runway_ident,
            'event_type'       => RotObservation::EVENT_ARR,
            'threshold_at'     => $crossingAt,
            'clear_at'         => $clearAt,
            'rot_seconds'      => $rotSec,
            'threshold_gs_kts' => $thresholdGs,
            'source'           => $source,
        ];
    }

    /**
     * @return list<PositionScratch>
     */
    private function scratchAround(Flight $flight, ?DateTimeImmutable $anchor): array
    {
        if ($anchor === null) {
            return [];
        }
        $start = $anchor->modify('-' . self::SCRATCH_WINDOW_MIN . ' minutes')->format('Y-m-d H:i:s');
        $end   = $anchor->modify('+' . self::SCRATCH_WINDOW_MIN . ' minutes')->format('Y-m-d H:i:s');
        return PositionScratch::where('flight_id', $flight->id)
            ->whereBetween('observed_at', [$start, $end])
            ->orderBy('observed_at')
            ->get()
            ->all();
    }

    private function closestSample(array $samples, ?DateTimeImmutable $anchor): ?PositionScratch
    {
        if ($anchor === null || empty($samples)) {
            return null;
        }
        $best = null;
        $bestDiff = PHP_INT_MAX;
        foreach ($samples as $s) {
            $d = abs($s->observed_at->getTimestamp() - $anchor->getTimestamp());
            if ($d < $bestDiff) {
                $bestDiff = $d;
                $best = $s;
            }
        }
        return $best;
    }

    /**
     * Pick the most plausible runway by matching the flight's heading at
     * the threshold sample against each candidate's `heading_deg`. The
     * smallest circular delta wins. We also gate by distance — if no
     * threshold is within BRACKET_MAX_NM of any sample, return null.
     *
     * @param list<RunwayThreshold> $candidates
     * @param list<PositionScratch> $samples
     */
    private function pickRunway(array $candidates, array $samples, Flight $flight, bool $departure): ?RunwayThreshold
    {
        if (empty($candidates) || empty($samples)) {
            return null;
        }
        // Reference sample: closest to threshold polygon (cheap proxy: lowest altitude).
        $ref = $samples[0];
        $refAlt = PHP_INT_MAX;
        foreach ($samples as $s) {
            $alt = (int) ($s->altitude_ft ?? PHP_INT_MAX);
            if ($alt < $refAlt) {
                $refAlt = $alt;
                $ref = $s;
            }
        }

        $bestRunway = null;
        $bestScore  = PHP_INT_MAX;
        foreach ($candidates as $rt) {
            $distNm = Geo::distanceNm(
                (float) $ref->lat, (float) $ref->lon,
                (float) $rt->threshold_lat, (float) $rt->threshold_lon
            );
            if ($distNm > self::BRACKET_MAX_NM) {
                continue;
            }
            $hdgDelta = $this->circularDelta(
                (int) ($ref->heading_deg ?? $rt->heading_deg),
                (int) $rt->heading_deg
            );
            // Composite: heading delta dominates, distance tiebreaks.
            $score = ($hdgDelta * 100) + (int) round($distNm * 10);
            if ($score < $bestScore) {
                $bestScore  = $score;
                $bestRunway = $rt;
            }
        }
        return $bestRunway;
    }

    private function circularDelta(int $a, int $b): int
    {
        $d = abs($a - $b) % 360;
        return $d > 180 ? 360 - $d : $d;
    }
}
