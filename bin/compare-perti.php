<?php

declare(strict_types=1);

// PERTI cross-validation — compares our flight data against PERTI's ADL
// for the 7 monitored Canadian airports. Read-only, no writes.
//
// Usage:
//   php bin/compare-perti.php              # full comparison
//   php bin/compare-perti.php --eta-only   # just ETA comparison
//   php bin/compare-perti.php --phases     # just phase comparison
//   php bin/compare-perti.php --tmi        # check for active TMIs
//
// Requires PERTI SWIM key in .env or passed via PERTI_SWIM_KEY env var.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

use Atfm\Models\Flight;
use Atfm\Models\Airport;

$swimKey = getenv('PERTI_SWIM_KEY') ?: 'swim_pub_7783b37a28c167af41788599954e3e39';
$pertiBase = 'https://perti.vatcscc.org/api';
$airports = ['CYHZ', 'CYOW', 'CYUL', 'CYVR', 'CYWG', 'CYYC', 'CYYZ'];

$etaOnly = in_array('--eta-only', $argv, true);
$phasesOnly = in_array('--phases', $argv, true);
$tmiOnly = in_array('--tmi', $argv, true);

$ts = gmdate('Y-m-d H:i:s');
echo "[perti-compare] {$ts}Z\n";

// ---------- Fetch PERTI ADL ----------

echo "[perti] fetching ADL...\n";
$ctx = stream_context_create([
    'http' => [
        'header' => "Authorization: Bearer {$swimKey}\r\n",
        'timeout' => 15,
    ],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
]);

$raw = @file_get_contents("{$pertiBase}/adl/current", false, $ctx);
if ($raw === false) {
    echo "[perti] ERROR: failed to fetch ADL\n";
    exit(1);
}

$adl = json_decode($raw, true);
if (!$adl || !isset($adl['flights'])) {
    echo "[perti] ERROR: invalid ADL response\n";
    exit(1);
}

$pertiFlights = $adl['flights'];
echo "[perti] {$adl['snapshot_utc']} — " . count($pertiFlights) . " flights total\n";

// Index PERTI flights by flight_key for fast lookup
$pertiByKey = [];
foreach ($pertiFlights as $pf) {
    $key = $pf['flight_key'] ?? null;
    if ($key !== null) {
        $pertiByKey[$key] = $pf;
    }
}

// Also index by callsign for fallback matching
$pertiByCsAdes = [];
foreach ($pertiFlights as $pf) {
    $cs = $pf['callsign'] ?? '';
    $ades = $pf['fp_dest_icao'] ?? '';
    if ($cs && $ades) {
        $pertiByCsAdes[$cs . '|' . $ades] = $pf;
    }
}

echo "[perti] indexed " . count($pertiByKey) . " by flight_key, "
     . count($pertiByCsAdes) . " by callsign|ades\n\n";

// ---------- Load our flights ----------

$ourFlights = Flight::whereIn('ades', $airports)
    ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED])
    ->get();

echo "[ours] " . count($ourFlights) . " active inbound flights across " . implode(', ', $airports) . "\n\n";

$matched = 0;
$unmatched = 0;
$etaComparisons = [];
$phaseComparisons = [];

foreach ($ourFlights as $f) {
    // Try flight_key match first, then callsign|ades fallback
    $pf = $pertiByKey[$f->flight_key] ?? $pertiByCsAdes[$f->callsign . '|' . $f->ades] ?? null;

    if ($pf === null) {
        $unmatched++;
        continue;
    }
    $matched++;

    // ---------- Phase comparison ----------
    if (!$etaOnly && !$tmiOnly) {
        $ourPhase = $f->phase;
        $pertiPhase = $pf['phase'] ?? '?';
        // Normalize PERTI phases to ours for comparison
        $pertiNorm = match($pertiPhase) {
            'prefiled', 'filed' => 'FILED',
            'taxiing_out', 'taxi_out' => 'TAXI_OUT',
            'departed', 'climbing' => 'DEPARTED',
            'enroute', 'cruising' => 'ENROUTE',
            'descending' => 'ENROUTE', // we don't have a DB DESCENT phase
            'arriving', 'approach' => 'ARRIVING',
            'taxiing_in', 'taxi_in' => 'TAXI_IN',
            'arrived', 'landed' => 'ARRIVED',
            default => strtoupper($pertiPhase),
        };

        if ($ourPhase !== $pertiNorm) {
            $phaseComparisons[] = [
                'cs' => $f->callsign,
                'ades' => $f->ades,
                'ours' => $ourPhase,
                'perti' => $pertiPhase,
                'perti_norm' => $pertiNorm,
            ];
        }
    }

    // ---------- ETA comparison ----------
    if (!$phasesOnly && !$tmiOnly) {
        $ourEldt = $f->eldt ? $f->eldt->getTimestamp() : null;
        $pertiEta = isset($pf['eta_utc']) && $pf['eta_utc']
            ? strtotime($pf['eta_utc'])
            : null;

        if ($ourEldt !== null && $pertiEta !== null && $pertiEta > 0) {
            $deltaSec = $ourEldt - $pertiEta;
            $deltaMin = round($deltaSec / 60);
            $etaComparisons[] = [
                'cs' => $f->callsign,
                'type' => $f->aircraft_type,
                'ades' => $f->ades,
                'our_eldt' => gmdate('H:i', $ourEldt),
                'perti_eta' => gmdate('H:i', $pertiEta),
                'delta_min' => $deltaMin,
                'our_src' => $f->eldt_locked ? 'FROZEN' : 'LIVE',
                'perti_src' => $pf['arr_time_source'] ?? '?',
            ];
        }
    }
}

// ---------- Report ----------

echo "=== MATCHING ===\n";
echo "  Matched: {$matched}  Unmatched (ours not in PERTI): {$unmatched}\n\n";

if (!$etaOnly && !$tmiOnly && count($phaseComparisons) > 0) {
    echo "=== PHASE DISCREPANCIES ===\n";
    printf("  %-10s %-4s %-15s %-15s %-15s\n", 'Callsign', 'ADES', 'Ours', 'PERTI', 'PERTI (raw)');
    foreach ($phaseComparisons as $pc) {
        printf("  %-10s %-4s %-15s %-15s %-15s\n",
            $pc['cs'], $pc['ades'], $pc['ours'], $pc['perti_norm'], $pc['perti']);
    }
    echo "\n";
} elseif (!$etaOnly && !$tmiOnly) {
    echo "=== PHASE DISCREPANCIES ===\n  None — all phases agree.\n\n";
}

if (!$phasesOnly && !$tmiOnly && count($etaComparisons) > 0) {
    echo "=== ETA COMPARISON (our ELDT vs PERTI eta_utc) ===\n";
    printf("  %-10s %-4s %-4s %-6s %-6s %7s %-7s %-5s\n",
        'Callsign', 'Type', 'ADES', 'Ours', 'PERTI', 'Δ min', 'Our src', 'P src');
    $totalDelta = 0;
    foreach ($etaComparisons as $ec) {
        printf("  %-10s %-4s %-4s %-6s %-6s %+6.0f  %-7s %-5s\n",
            $ec['cs'], $ec['type'], $ec['ades'],
            $ec['our_eldt'], $ec['perti_eta'],
            $ec['delta_min'], $ec['our_src'], $ec['perti_src']);
        $totalDelta += abs($ec['delta_min']);
    }
    $n = count($etaComparisons);
    $avg = $n > 0 ? round($totalDelta / $n) : 0;
    echo "  --- {$n} comparisons, avg |Δ| = {$avg} min\n\n";
} elseif (!$phasesOnly && !$tmiOnly) {
    echo "=== ETA COMPARISON ===\n  No flights with both our ELDT and PERTI eta_utc set.\n\n";
}

// ---------- TMI check ----------
if (!$etaOnly && !$phasesOnly) {
    echo "=== TMI CHECK (GDP/GS on our airports) ===\n";
    $gsCount = 0;
    foreach ($pertiFlights as $pf) {
        $dest = $pf['fp_dest_icao'] ?? '';
        if (in_array($dest, $airports, true) && ($pf['gs_flag'] ?? 0)) {
            $gsCount++;
            if ($gsCount <= 10) {
                printf("  %-10s %-4s→%-4s gs_flag=%d edct=%s ctd=%s\n",
                    $pf['callsign'] ?? '?',
                    $pf['fp_dept_icao'] ?? '?',
                    $dest,
                    $pf['gs_flag'],
                    $pf['edct_utc'] ?? '—',
                    $pf['ctd_utc'] ?? '—');
            }
        }
    }
    if ($gsCount === 0) {
        echo "  No active GDP/GS affecting our airports.\n";
    } elseif ($gsCount > 10) {
        echo "  ... and " . ($gsCount - 10) . " more.\n";
    }
    echo "\n";

    // Check for EDCT/CTA on flights to our airports
    $edctCount = 0;
    foreach ($pertiFlights as $pf) {
        $dest = $pf['fp_dest_icao'] ?? '';
        if (in_array($dest, $airports, true) && !empty($pf['edct_utc'])) {
            $edctCount++;
            if ($edctCount <= 10) {
                printf("  EDCT: %-10s %-4s→%-4s edct=%s\n",
                    $pf['callsign'] ?? '?',
                    $pf['fp_dept_icao'] ?? '?',
                    $dest,
                    $pf['edct_utc']);
            }
        }
    }
    if ($edctCount === 0) {
        echo "  No EDCTs (CTOTs) active for our airports.\n";
    }
}

echo "\n[perti-compare] done.\n";
