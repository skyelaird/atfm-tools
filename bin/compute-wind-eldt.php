<?php

declare(strict_types=1);

// Cron entry-point for wind-corrected ELDT computation.
//
// Downloads GFS 250mb winds from NOAA NOMADS (cached 6h), computes
// wind-corrected ETA for all eligible airborne flights, and writes
// eldt_wind directly to the flights table.
//
// Designed to run every 2-5 minutes alongside other cron scripts.
// Pure PHP — no Python, no numpy, no external dependencies.
//
// Cron line:
//   */5 * * * * cd ~/atfm.momentaryshutter.com && php bin/compute-wind-eldt.php >> logs/wind.log 2>&1

require __DIR__ . '/../vendor/autoload.php';
\Atfm\Bootstrap::boot(__DIR__ . '/..');

$start = microtime(true);
$ts = gmdate('Y-m-d H:i:s');
echo "[wind-eldt] start {$ts}Z\n";

try {
    $result = \Atfm\Allocator\WindEta::computeAll();
} catch (\Throwable $e) {
    fwrite(STDERR, "[wind-eldt] ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

$elapsed = (int) round((microtime(true) - $start) * 1000);

if (isset($result['error'])) {
    fwrite(STDERR, "[wind-eldt] FAILED: {$result['error']}\n");
    exit(1);
}

printf(
    "[wind-eldt] computed=%d updated=%d elapsed_ms=%d\n",
    $result['computed'],
    $result['updated'],
    $elapsed
);
