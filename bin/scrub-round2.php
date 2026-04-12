<?php

declare(strict_types=1);

// Second-pass data scrub — targets contamination that survived the
// v0.4.4 scrub or was created between the scrub and the code fixes.
//
// Usage:
//   php bin/scrub-round2.php --dry-run   # report only
//   php bin/scrub-round2.php             # scrub

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

use Illuminate\Database\Capsule\Manager as Capsule;

$dryRun = in_array('--dry-run', $argv, true);
$mode   = $dryRun ? 'DRY RUN' : 'EXECUTE';
echo "[scrub-r2] mode={$mode}\n";

$totalRows = 0;

// Rule 1: AIBT = ALDT same-second (9 remaining)
$q1 = Capsule::table('flights')
    ->whereNotNull('aibt')
    ->whereNotNull('aldt')
    ->whereColumn('aibt', '=', 'aldt');
$n1 = $q1->count();
echo "  AIBT = ALDT (same second): {$n1} rows\n";
if ($n1 > 0 && !$dryRun) {
    Capsule::table('flights')
        ->whereNotNull('aibt')
        ->whereNotNull('aldt')
        ->whereColumn('aibt', '=', 'aldt')
        ->update(['aibt' => null, 'actual_exit_min' => null]);
    echo "    -> scrubbed\n";
}
$totalRows += $n1;

// Rule 2: AOBT > 4h before EOBT (1 remaining — FDX7811)
$q2 = Capsule::table('flights')
    ->whereNotNull('aobt')
    ->whereNotNull('eobt')
    ->whereRaw('TIMESTAMPDIFF(MINUTE, aobt, eobt) > 240');
$n2 = $q2->count();
echo "  AOBT > 4h before EOBT: {$n2} rows\n";
if ($n2 > 0 && !$dryRun) {
    Capsule::table('flights')
        ->whereNotNull('aobt')
        ->whereNotNull('eobt')
        ->whereRaw('TIMESTAMPDIFF(MINUTE, aobt, eobt) > 240')
        ->update(['aobt' => null, 'actual_exot_min' => null]);
    echo "    -> scrubbed\n";
}
$totalRows += $n2;

// Rule 3: AOBT with |dEOBT| > 2h — these are mid-cruise backfills.
// The old ratchet stamped AOBT to "now" on first sighting regardless
// of phase. A genuine pushback has dEOBT typically -30..+60 min.
// Anything beyond 2h is contamination.
$q3 = Capsule::table('flights')
    ->whereNotNull('aobt')
    ->whereNotNull('eobt')
    ->whereRaw('ABS(TIMESTAMPDIFF(MINUTE, eobt, aobt)) > 120');
$n3 = $q3->count();
echo "  |AOBT - EOBT| > 2h: {$n3} rows\n";
if ($n3 > 0 && !$dryRun) {
    Capsule::table('flights')
        ->whereNotNull('aobt')
        ->whereNotNull('eobt')
        ->whereRaw('ABS(TIMESTAMPDIFF(MINUTE, eobt, aobt)) > 120')
        ->update(['aobt' => null, 'actual_exot_min' => null]);
    echo "    -> scrubbed\n";
}
$totalRows += $n3;

// Rule 4: AXOT values that are exactly 5 min from old 5-min cadence
// and where AOBT and ATOT differ by exactly 300s (one old cycle).
// These are "probably real but low confidence" — leave them for now
// but report the count. Once 2-min cadence data accumulates, the
// 5-min values will age out of the 24h window naturally.
$n4 = Capsule::table('flights')
    ->where('actual_exot_min', 5)
    ->count();
echo "  AXOT = exactly 5 min (old cadence floor, not scrubbed): {$n4} rows\n";

// Rule 5: AXIT values that are exactly 5 min (same reason)
$n5 = Capsule::table('flights')
    ->where('actual_exit_min', 5)
    ->count();
echo "  AXIT = exactly 5 min (old cadence floor, not scrubbed): {$n5} rows\n";

echo "\n[scrub-r2] {$totalRows} rows affected (mode={$mode})\n";
if ($dryRun && $totalRows > 0) {
    echo "[scrub-r2] re-run without --dry-run to apply\n";
}
