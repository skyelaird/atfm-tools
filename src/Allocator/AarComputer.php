<?php

declare(strict_types=1);

namespace Atfm\Allocator;

use Atfm\Models\AarCalculation;
use Atfm\Models\Airport;
use Atfm\Models\RotObservation;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Daily Airport Arrival Rate derivation — v0.4.
 *
 * Walks the rot_observations table for ARR events in a rolling window
 * (default 24 h) per airport, groups by runway, and computes:
 *
 *   • inter_arrival_seconds[]  — gap between consecutive ARR threshold_at
 *                                 timestamps on the same runway
 *   • mean_threshold_gs_kts    — mean of threshold_gs_kts across the window
 *   • mean_spacing_nm          — derived as
 *                                 (mean_gs / 3600) × mean_inter_arrival_sec
 *
 * Then applies the ICAO 9971 Part II Appendix II-B formula:
 *
 *     AAR = ⌊ mean_threshold_GS_kts / mean_spacing_NM ⌋
 *
 * which (algebraically) equals 3600 / mean_inter_arrival_sec — i.e. the
 * familiar "arrivals per hour" you'd compute by counting events in the
 * window. Storing both forms preserves traceability to the ICAO formula
 * and lets us inspect spacing trends independently of arrival counts.
 *
 * Outliers (gaps > 30 min) are dropped before averaging — those represent
 * lulls in the arrival flow rather than honest separation. Gaps < 30 s
 * are also dropped (data error / re-export from the same flight).
 *
 * Sample-size gating
 * -------------------
 * Per-runway calculations with fewer than `minSamples` data points are
 * stored but flagged with a low `confidence_pct`. The aggregate per-airport
 * AAR (max across runways) is promoted into airports.observed_arrival_rate
 * only when sample_count ≥ MIN_SAMPLES_FOR_PROMOTION.
 *
 * See docs/ARCHITECTURE.md §9 (AAR derivation) and
 *     docs/GLOSSARY.md §3 (AAR).
 */
final class AarComputer
{
    private const WINDOW_HOURS = 24;
    private const DROP_GAP_OVER_SEC  = 1800; // 30 min — flow lull, not spacing
    private const DROP_GAP_UNDER_SEC = 30;   // duplicate / data error
    private const MIN_SAMPLES_FOR_PROMOTION = 20;

    public function run(): array
    {
        $now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start  = $now->modify('-' . self::WINDOW_HOURS . ' hours');
        $startStr = $start->format('Y-m-d H:i:s');

        $stats = [
            'airports'        => 0,
            'runways'         => 0,
            'rows_written'    => 0,
            'promotions'      => 0,
            'samples_total'   => 0,
        ];

        $airports = Airport::orderBy('icao')->get();
        foreach ($airports as $airport) {
            $stats['airports']++;

            $rows = RotObservation::where('airport_icao', $airport->icao)
                ->where('event_type', RotObservation::EVENT_ARR)
                ->where('threshold_at', '>=', $startStr)
                ->orderBy('runway_ident')
                ->orderBy('threshold_at')
                ->get(['runway_ident', 'threshold_at', 'threshold_gs_kts']);

            // Group by runway in PHP — small enough that pulling once is cheaper than N queries.
            $byRunway = [];
            foreach ($rows as $r) {
                $byRunway[$r->runway_ident][] = $r;
            }

            $bestAar = 0;
            $bestSampleCount = 0;

            foreach ($byRunway as $runwayIdent => $events) {
                if (count($events) < 2) {
                    continue;
                }
                $stats['runways']++;

                // Inter-arrival gaps
                $gaps = [];
                $gsValues = [];
                $prevT = null;
                foreach ($events as $e) {
                    $t = $e->threshold_at->getTimestamp();
                    if ($e->threshold_gs_kts !== null) {
                        $gsValues[] = (int) $e->threshold_gs_kts;
                    }
                    if ($prevT !== null) {
                        $gap = $t - $prevT;
                        if ($gap >= self::DROP_GAP_UNDER_SEC && $gap <= self::DROP_GAP_OVER_SEC) {
                            $gaps[] = $gap;
                        }
                    }
                    $prevT = $t;
                }

                if (empty($gaps)) {
                    continue;
                }

                $meanGap   = array_sum($gaps) / count($gaps);
                $meanGs    = !empty($gsValues)
                    ? (int) round(array_sum($gsValues) / count($gsValues))
                    : 130; // crude default; better than null
                $spacingNm = ($meanGs / 3600.0) * $meanGap;
                $aar       = $spacingNm > 0
                    ? (int) floor($meanGs / $spacingNm)
                    : 0;

                // Confidence: simple linear ramp from 0 to 100 over MIN..MIN*5
                $sampleCount = count($gaps);
                $confidence  = (int) round(min(100, ($sampleCount / (self::MIN_SAMPLES_FOR_PROMOTION * 5)) * 100));

                AarCalculation::create([
                    'airport_icao'          => $airport->icao,
                    'runway_ident'          => $runwayIdent,
                    'window_start'          => $start,
                    'window_end'            => $now,
                    'mean_threshold_gs_kts' => $meanGs,
                    'mean_spacing_nm'       => round($spacingNm, 3),
                    'computed_aar'          => $aar,
                    'sample_count'          => $sampleCount,
                    'confidence_pct'        => $confidence,
                ]);
                $stats['rows_written']++;
                $stats['samples_total'] += $sampleCount;

                if ($sampleCount > $bestSampleCount) {
                    $bestSampleCount = $sampleCount;
                    $bestAar = $aar;
                }
            }

            // Promote into airports.observed_arrival_rate when we have
            // enough samples on at least one runway.
            if ($bestSampleCount >= self::MIN_SAMPLES_FOR_PROMOTION && $bestAar > 0) {
                $airport->observed_arrival_rate  = $bestAar;
                $airport->observed_rate_sample_n = $bestSampleCount;
                $airport->save();
                $stats['promotions']++;
            }
        }

        return $stats;
    }
}
