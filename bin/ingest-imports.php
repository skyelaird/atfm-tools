<?php

declare(strict_types=1);

// Cron entry-point for file-based CTOT imports.
// Runs every 5 min. See docs/ARCHITECTURE.md §6.3.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

$start = microtime(true);
$ts = gmdate('Y-m-d H:i:s');
echo "[ingest-imports] start {$ts}Z\n";

try {
    $result = (new \Atfm\Ingestion\FileImportIngestor(__DIR__ . '/..'))->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "[ingest-imports] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

$elapsedMs = (int) round((microtime(true) - $start) * 1000);
printf(
    "[ingest-imports] scanned=%d processed=%d rows=%d errors=%d elapsed_ms=%d\n",
    $result['files_scanned'], $result['files_processed'], $result['rows_imported'], $result['errors'], $elapsedMs
);
