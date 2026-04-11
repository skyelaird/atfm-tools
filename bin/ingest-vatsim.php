<?php

declare(strict_types=1);

// Cron entry-point for VATSIM data ingestion.
// Runs every 5 minutes. See docs/ARCHITECTURE.md §6.1.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

$start = microtime(true);
$ts = gmdate('Y-m-d H:i:s');
echo "[ingest-vatsim] start {$ts}Z\n";

try {
    $result = (new \Atfm\Ingestion\VatsimIngestor())->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "[ingest-vatsim] ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

printf(
    "[ingest-vatsim] fetched=%d kept=%d disconnected=%d elapsed_ms=%d\n",
    $result['fetched'], $result['kept'], $result['disconnected'], $result['elapsed_ms']
);
