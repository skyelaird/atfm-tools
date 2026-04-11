<?php

declare(strict_types=1);

// Cron entry-point for VATCAN event bookings ingestion.
// Runs every 5 min. See docs/ARCHITECTURE.md §6.2.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

$start = microtime(true);
$ts = gmdate('Y-m-d H:i:s');
echo "[ingest-events] start {$ts}Z\n";

try {
    $result = (new \Atfm\Ingestion\VatcanEventIngestor())->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "[ingest-events] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

$elapsedMs = (int) round((microtime(true) - $start) * 1000);
printf(
    "[ingest-events] events=%d slots=%d errors=%d elapsed_ms=%d\n",
    $result['events_polled'], $result['slots_updated'], $result['errors'], $elapsedMs
);
