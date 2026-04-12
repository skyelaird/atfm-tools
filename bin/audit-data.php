<?php

declare(strict_types=1);

// One-shot data audit — reports contamination counts and outliers
// without modifying anything. Safe to run anytime.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

use Illuminate\Database\Capsule\Manager as DB;

echo "=== FLIGHT COUNTS ===\n";
echo "Total flights: " . DB::table('flights')->count() . "\n";
echo "With ALDT: " . DB::table('flights')->whereNotNull('aldt')->count() . "\n";
echo "With AIBT: " . DB::table('flights')->whereNotNull('aibt')->count() . "\n";
echo "With AOBT: " . DB::table('flights')->whereNotNull('aobt')->count() . "\n";
echo "With ATOT: " . DB::table('flights')->whereNotNull('atot')->count() . "\n";
echo "With ASAT (should be 0): " . DB::table('flights')->whereNotNull('asat')->count() . "\n";
echo "With eldt_locked: " . DB::table('flights')->whereNotNull('eldt_locked')->count() . "\n";
echo "With TLDT: " . DB::table('flights')->whereNotNull('tldt')->count() . "\n";
echo "With actual_exit_min: " . DB::table('flights')->whereNotNull('actual_exit_min')->count() . "\n";
echo "With actual_exot_min: " . DB::table('flights')->whereNotNull('actual_exot_min')->count() . "\n";

echo "\n=== CONTAMINATION CHECK ===\n";
echo "AIBT = ALDT (same second): " . DB::table('flights')->whereNotNull('aibt')->whereNotNull('aldt')->whereColumn('aibt', '=', 'aldt')->count() . "\n";
echo "AOBT > ATOT (impossible): " . DB::table('flights')->whereNotNull('aobt')->whereNotNull('atot')->whereColumn('aobt', '>', 'atot')->count() . "\n";
echo "AOBT > 4h before EOBT: " . DB::table('flights')->whereNotNull('aobt')->whereNotNull('eobt')->whereRaw('TIMESTAMPDIFF(MINUTE, aobt, eobt) > 240')->count() . "\n";
echo "actual_exot_min > 60: " . DB::table('flights')->where('actual_exot_min', '>', 60)->count() . "\n";
echo "actual_exit_min > 60: " . DB::table('flights')->where('actual_exit_min', '>', 60)->count() . "\n";
echo "actual_exot_min = 0: " . DB::table('flights')->where('actual_exot_min', 0)->count() . "\n";
echo "actual_exit_min = 0: " . DB::table('flights')->where('actual_exit_min', 0)->count() . "\n";
echo "delay_minutes without ctot: " . DB::table('flights')->whereNull('ctot')->whereNotNull('delay_minutes')->count() . "\n";
echo "ASAT not null (should be 0): " . DB::table('flights')->whereNotNull('asat')->count() . "\n";

echo "\n=== EOBT OUTLIERS ===\n";
echo "EOBT > now+48h (future garbage): " . DB::table('flights')->whereNotNull('eobt')->whereRaw('eobt > DATE_ADD(NOW(), INTERVAL 48 HOUR)')->count() . "\n";
echo "EOBT < now-48h on non-terminal: " . DB::table('flights')->whereNotNull('eobt')->whereRaw('eobt < DATE_SUB(NOW(), INTERVAL 48 HOUR)')->whereNotIn('phase', ['ARRIVED', 'WITHDRAWN'])->count() . "\n";

echo "\n=== dEOBT OUTLIERS (|AOBT - EOBT| > 2h) ===\n";
$bad = DB::table('flights')
    ->whereNotNull('aobt')
    ->whereNotNull('eobt')
    ->selectRaw('callsign, adep, ades, eobt, aobt, TIMESTAMPDIFF(MINUTE, eobt, aobt) as delta_min')
    ->havingRaw('ABS(delta_min) > 120')
    ->orderByRaw('ABS(delta_min) DESC')
    ->limit(15)
    ->get();
if ($bad->isEmpty()) {
    echo "  None found.\n";
} else {
    foreach ($bad as $r) {
        printf("  %-10s %s->%s  EOBT=%s  AOBT=%s  delta=%+dm\n",
            $r->callsign, $r->adep, $r->ades, $r->eobt, $r->aobt, $r->delta_min);
    }
}

echo "\n=== AXOT DISTRIBUTION ===\n";
$axotDist = DB::table('flights')
    ->whereNotNull('actual_exot_min')
    ->selectRaw('actual_exot_min as val, COUNT(*) as n')
    ->groupBy('actual_exot_min')
    ->orderBy('actual_exot_min')
    ->get();
foreach ($axotDist as $r) {
    printf("  %3d min: %d flights\n", $r->val, $r->n);
}

echo "\n=== AXIT DISTRIBUTION ===\n";
$axitDist = DB::table('flights')
    ->whereNotNull('actual_exit_min')
    ->selectRaw('actual_exit_min as val, COUNT(*) as n')
    ->groupBy('actual_exit_min')
    ->orderBy('actual_exit_min')
    ->get();
foreach ($axitDist as $r) {
    printf("  %3d min: %d flights\n", $r->val, $r->n);
}

echo "\ndone.\n";
