<?php

declare(strict_types=1);

// Daily rollup of metering-fix demand distribution across the 7 scope
// airports. Writes data/cache/demand-history.json with per-day, per-
// airport, per-fix counts for the trailing 30 days.
//
// Rationale: the reports page's Demand Distribution panel over 7d/all
// windows would otherwise scan thousands of flights on every page load.
// Computing daily and caching lets those queries serve from a small
// JSON file; today's partial day is always computed live by the API.
//
// Cron: run once per day, e.g. 02:00 UTC.
//   0 2 * * * cd ~/atfm.momentaryshutter.com && php bin/compute-demand-history.php >> logs/demand-history.log 2>&1

require __DIR__ . '/../vendor/autoload.php';
\Atfm\Bootstrap::boot(__DIR__ . '/..');

use Atfm\Allocator\MeteringFix;
use Atfm\Models\Flight;
use DateTimeImmutable;
use DateTimeZone;

$start = microtime(true);
$tsStart = gmdate('Y-m-d H:i:s');
echo "[demand-history] start {$tsStart}Z\n";

$scope = ['CYVR','CYYC','CYWG','CYYZ','CYOW','CYUL','CYHZ'];
$lookbackDays = 30;

$catalog = MeteringFix::loadCatalog();

$today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(0, 0, 0);
$days = [];

for ($i = $lookbackDays; $i >= 1; $i--) {
    $dayStart = $today->modify("-{$i} days");
    $dayEnd   = $dayStart->modify('+1 day');
    $dateKey  = $dayStart->format('Y-m-d');

    $flights = Flight::whereIn('ades', $scope)
        ->whereNotNull('aldt')
        ->where('aldt', '>=', $dayStart->format('Y-m-d H:i:s'))
        ->where('aldt', '<', $dayEnd->format('Y-m-d H:i:s'))
        ->get(['id', 'callsign', 'adep', 'ades', 'fp_route', 'last_lat', 'last_lon', 'aldt']);

    $dayOut = [];
    foreach ($scope as $apt) {
        $dayOut[$apt] = [
            'total'      => 0,
            'unresolved' => 0,
            'fixes'      => [],
        ];
        // Seed with all catalog fixes so zero-demand fixes appear
        foreach (($catalog['metering_fixes'][$apt] ?? []) as $fixEntry) {
            $dayOut[$apt]['fixes'][$fixEntry['fix']] = 0;
        }
    }

    foreach ($flights as $f) {
        $apt = $f->ades;
        if (!isset($dayOut[$apt])) continue;
        $dayOut[$apt]['total']++;
        $r = MeteringFix::resolve($f);
        if (!$r || !$r['metering_fix']) {
            $dayOut[$apt]['unresolved']++;
            continue;
        }
        $fix = $r['metering_fix'];
        $dayOut[$apt]['fixes'][$fix] = ($dayOut[$apt]['fixes'][$fix] ?? 0) + 1;
    }

    $days[$dateKey] = $dayOut;
    $n = array_sum(array_map(fn($a) => $a['total'], $dayOut));
    echo "[demand-history]   {$dateKey}: {$n} arrivals\n";
}

$cacheDir = __DIR__ . '/../data/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cacheFile = $cacheDir . '/demand-history.json';

$payload = [
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
    'lookback_days' => $lookbackDays,
    'scope'        => $scope,
    'days'         => $days,
];

file_put_contents($cacheFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$elapsed = (int) round((microtime(true) - $start) * 1000);
$totalArr = 0;
foreach ($days as $d) foreach ($d as $a) $totalArr += $a['total'];
echo "[demand-history] done days={$lookbackDays} arrivals={$totalArr} file={$cacheFile} elapsed_ms={$elapsed}\n";
