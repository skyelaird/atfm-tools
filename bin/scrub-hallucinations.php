<?php

declare(strict_types=1);

// One-shot data cleanup script — scrubs hallucinated A-CDM milestone
// values that were written by buggy ingestor logic in v0.3.4 and earlier.
//
// The bugs that wrote the bad data have all been fixed (v0.3.5 stopped
// stamping ASAT, added the previousPhase guard for AOBT, capped EXOT/EXIT
// at 1-60 min; v0.4.2 relaxed AIBT and switched ETA-error to ATOT-based).
// But the old rows are still in the database polluting reports — the
// CYYZ "AVG EOBT Δ -327", "AVG ETA ERR -527" etc. The user's term for
// this was "fake news"; this script removes it.
//
// Usage:
//   php bin/scrub-hallucinations.php --dry-run   # report counts only
//   php bin/scrub-hallucinations.php             # actually scrub
//
// Idempotent: re-running on a clean database is a no-op.
// Reversible: only NULLs columns; doesn't delete rows.
//
// What gets scrubbed (each rule reports the count it touched):
//
//   1. asat → NULL everywhere.
//      v0.3.4 stamped ASAT from any phase ≥ TAXI_OUT as a synonym for
//      AOBT. ASAT is a controller event we have no signal for from
//      VATSIM. There is no current upstream feed populating this column.
//      Every existing value is fake.
//
//   2. aobt → NULL where aobt > atot.
//      Off-block after takeoff is physically impossible. This catches
//      the WJA1034-style mid-cruise first sightings where the ingestor
//      stamped AOBT = "now" while the pilot was already over Florida.
//
//   3. aobt → NULL where eobt is set and aobt is more than 4 hours
//      before EOBT. AOBT-before-EOBT-by-hours is also impossible —
//      catches the negative ΔEOBT outliers like CYYZ -180, CYOW -327.
//
//   4. actual_exot_min → NULL outside 1..60 min.
//      The 60-min sanity cap was added in v0.3.5; pre-existing values
//      need to be retroactively trimmed.
//
//   5. actual_exit_min → NULL outside 1..60 min. Same reason.
//
//   6. aibt → NULL where aibt = aldt to the second.
//      Pre-v0.3.5 the ratchet stamped AIBT and ALDT in the same ingest
//      cycle, producing degenerate zero-second deltas.
//
//   7. delay_minutes / delay_status → NULL where ctot is null.
//      Orphans from a previous restriction's CTOT issuance that got
//      cleaned up but left the delay annotation behind.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

use Atfm\Models\Flight;
use Illuminate\Database\Capsule\Manager as Capsule;

$dryRun = in_array('--dry-run', $argv, true);
$mode   = $dryRun ? 'DRY RUN' : 'EXECUTE';
$ts     = gmdate('Y-m-d H:i:s');
echo "[scrub] start {$ts}Z mode={$mode}\n";

$totals = ['rules' => 0, 'rows_touched' => 0];

/**
 * Helper: count matching rows, then update unless dry-run.
 *
 * @param string $rule  short label for log output
 * @param array  $where assoc array of conditions OR a closure to apply to a query builder
 * @param array  $set   columns to NULL/update
 */
$apply = function (string $rule, callable $whereClause, array $set) use (&$totals, $dryRun) {
    $countQuery = Capsule::table('flights');
    $whereClause($countQuery);
    $count = $countQuery->count();

    if ($count === 0) {
        printf("  %-42s 0 rows (clean)\n", $rule);
        return;
    }

    if ($dryRun) {
        printf("  %-42s %d rows  [dry-run, not modified]\n", $rule, $count);
    } else {
        $updateQuery = Capsule::table('flights');
        $whereClause($updateQuery);
        $updateQuery->update($set);
        printf("  %-42s %d rows  ✓ scrubbed\n", $rule, $count);
    }

    $totals['rules']++;
    $totals['rows_touched'] += $count;
};

// Rule 1: ASAT — NULL everywhere
$apply(
    'asat (no signal, never legitimately set)',
    fn ($q) => $q->whereNotNull('asat'),
    ['asat' => null]
);

// Rule 2: AOBT > ATOT (physically impossible)
$apply(
    'aobt > atot (off-block after takeoff)',
    fn ($q) => $q->whereNotNull('aobt')
                 ->whereNotNull('atot')
                 ->whereColumn('aobt', '>', 'atot'),
    ['aobt' => null, 'actual_exot_min' => null]
);

// Rule 3: AOBT more than 4 hours before EOBT
$apply(
    'aobt > 4h before eobt (impossible)',
    fn ($q) => $q->whereNotNull('aobt')
                 ->whereNotNull('eobt')
                 ->whereRaw('TIMESTAMPDIFF(MINUTE, aobt, eobt) > 240'),
    ['aobt' => null, 'actual_exot_min' => null]
);

// Rule 4: actual_exot_min outside 1..60
$apply(
    'actual_exot_min outside 1..60 min',
    fn ($q) => $q->whereNotNull('actual_exot_min')
                 ->where(function ($qq) {
                     $qq->where('actual_exot_min', '<', 1)
                        ->orWhere('actual_exot_min', '>', 60);
                 }),
    ['actual_exot_min' => null]
);

// Rule 5: actual_exit_min outside 1..60
$apply(
    'actual_exit_min outside 1..60 min',
    fn ($q) => $q->whereNotNull('actual_exit_min')
                 ->where(function ($qq) {
                     $qq->where('actual_exit_min', '<', 1)
                        ->orWhere('actual_exit_min', '>', 60);
                 }),
    ['actual_exit_min' => null]
);

// Rule 6: aibt = aldt (same-second degenerate stamp)
$apply(
    'aibt = aldt (same-cycle stamp)',
    fn ($q) => $q->whereNotNull('aibt')
                 ->whereNotNull('aldt')
                 ->whereColumn('aibt', '=', 'aldt'),
    ['aibt' => null, 'actual_exit_min' => null]
);

// Rule 7: orphan delay annotations
$apply(
    'delay_minutes set without ctot',
    fn ($q) => $q->whereNull('ctot')
                 ->whereNotNull('delay_minutes'),
    ['delay_minutes' => null, 'delay_status' => null]
);

echo "\n";
printf(
    "[scrub] done — %d rules touched, %d rows affected (mode=%s)\n",
    $totals['rules'],
    $totals['rows_touched'],
    $mode
);

if ($dryRun && $totals['rows_touched'] > 0) {
    echo "[scrub] re-run without --dry-run to apply\n";
}
