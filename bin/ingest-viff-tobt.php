<?php

declare(strict_types=1);

// Cron entry-point: adopt pilot-declared TOBT from vIFF's VDGS, so a pilot who
// sets their ready time on vats.im/vdgs gets a slot computed from it rather
// than from our spawn+20 proxy. Runs every 2 min, before the allocator.
//
// No-ops unless VIFF_PILOT_TOBT_ENABLED=true. See docs/VIFF-INTEGRATION.md.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

$start = microtime(true);
echo "[ingest-viff-tobt] start " . gmdate('Y-m-d H:i:s') . "Z\n";

try {
    $result = (new \Atfm\Ingestion\ViffPilotTobtIngestor())->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "[ingest-viff-tobt] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

if (! $result['enabled']) {
    echo "[ingest-viff-tobt] disabled (VIFF_PILOT_TOBT_ENABLED not set) — no-op\n";
    exit(0);
}

printf(
    "[ingest-viff-tobt] airports=%d rows=%d declared=%d adopted=%d unchanged=%d unmatched=%d errors=%d elapsed_ms=%d\n",
    $result['airports'],
    $result['rows'],
    $result['declared'],
    $result['adopted'],
    $result['unchanged'],
    $result['unmatched'],
    $result['errors'],
    (int) round((microtime(true) - $start) * 1000)
);
