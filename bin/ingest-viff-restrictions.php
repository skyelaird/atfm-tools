<?php

declare(strict_types=1);

// Cron entry-point: mirror vIFF's active ARR restrictions into our own
// restriction table so the allocator can issue CTOTs against a constraint a
// human authored in vIFF. Runs every 2 min, alongside the allocator.
//
// No-ops unless VIFF_RESTRICTIONS_ENABLED=true. See docs/VIFF-INTEGRATION.md.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

$start = microtime(true);
echo "[ingest-viff] start " . gmdate('Y-m-d H:i:s') . "Z\n";

try {
    $result = (new \Atfm\Ingestion\ViffRestrictionIngestor())->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "[ingest-viff] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

if (! $result['enabled']) {
    echo "[ingest-viff] disabled (VIFF_RESTRICTIONS_ENABLED not set) — no-op\n";
    exit(0);
}

printf(
    "[ingest-viff] fetched=%d in_scope=%d created=%d updated=%d released=%d errors=%d elapsed_ms=%d\n",
    $result['fetched'],
    $result['in_scope'],
    $result['created'],
    $result['updated'],
    $result['released'],
    $result['errors'],
    (int) round((microtime(true) - $start) * 1000)
);
