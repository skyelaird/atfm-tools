<?php

declare(strict_types=1);

// Cron entry-point for daily AAR derivation (v0.4).
// Suggested cadence: once per day, off-peak (e.g. 04:07Z).
// Reads rot_observations from the last 24h, writes aar_calculations,
// and promotes the best per-airport AAR into airports.observed_arrival_rate
// when sample size warrants. See src/Allocator/AarComputer.php.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

$start = microtime(true);
$ts = gmdate('Y-m-d H:i:s');
echo "[compute-aar] start {$ts}Z\n";

try {
    $stats = (new \Atfm\Allocator\AarComputer())->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "[compute-aar] ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

$elapsedMs = (int) round((microtime(true) - $start) * 1000);
printf(
    "[compute-aar] airports=%d runways=%d rows=%d promotions=%d samples=%d elapsed_ms=%d\n",
    $stats['airports'],
    $stats['runways'],
    $stats['rows_written'],
    $stats['promotions'],
    $stats['samples_total'],
    $elapsedMs
);
