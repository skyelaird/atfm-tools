<?php

declare(strict_types=1);

// Cron entry-point for the CTOT allocator. Runs every 5 min.
// See docs/ARCHITECTURE.md §8 and §12.2.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

$start = microtime(true);
$ts = gmdate('Y-m-d H:i:s');
echo "[compute-ctots] start {$ts}Z\n";

try {
    $result = (new \Atfm\Allocator\CtotAllocator())->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "[compute-ctots] ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

printf(
    "[compute-ctots] airports=%d restrictions=%d flights=%d frozen=%d issued=%d released=%d reissued=%d elapsed_ms=%d run=%s\n",
    $result['airports_considered'],
    $result['restrictions_active'],
    $result['flights_evaluated'],
    $result['ctots_frozen_kept'],
    $result['ctots_issued'],
    $result['ctots_released'],
    $result['ctots_reissued'],
    $result['elapsed_ms'],
    $result['run_uuid']
);
