<?php

declare(strict_types=1);

// Cron entry-point for runway-occupancy tracking (v0.4).
// Suggested cadence: every 5 min, immediately after ingest.
// See src/Allocator/RotTracker.php for the algorithm.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

$start = microtime(true);
$ts = gmdate('Y-m-d H:i:s');
echo "[rot-tracker] start {$ts}Z\n";

try {
    $stats = (new \Atfm\Allocator\RotTracker())->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "[rot-tracker] ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

$elapsedMs = (int) round((microtime(true) - $start) * 1000);
printf(
    "[rot-tracker] dep=%d arr=%d interp=%d approx=%d fallback=%d skip_no_scratch=%d skip_no_runway=%d elapsed_ms=%d\n",
    $stats['departures_processed'],
    $stats['arrivals_processed'],
    $stats['interpolated'],
    $stats['approximated'],
    $stats['fallback'],
    $stats['skipped_no_scratch'],
    $stats['skipped_no_runway'],
    $elapsedMs
);
