<?php

declare(strict_types=1);

// Cron entry-point for the CTOT allocator. Runs every 5 min.
// See docs/ARCHITECTURE.md §8 and §12.2.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

// --shadow flag: run in dry-run mode (no DB writes, log what WOULD happen)
$shadowMode = in_array('--shadow', $argv ?? [], true);

$start = microtime(true);
$ts = gmdate('Y-m-d H:i:s');
$modeLabel = $shadowMode ? 'SHADOW' : 'LIVE';
echo "[compute-ctots] start {$ts}Z mode={$modeLabel}\n";

try {
    $result = (new \Atfm\Allocator\CtotAllocator())->run($shadowMode);
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
    $result['run_uuid'] ?? 'shadow'
);

// In shadow mode, dump the action log
if ($shadowMode && !empty($result['shadow_log'])) {
    echo "\n=== SHADOW LOG (" . count($result['shadow_log']) . " actions) ===\n";
    foreach ($result['shadow_log'] as $entry) {
        $cs = $entry['callsign'] ?? '???';
        $action = $entry['action'] ?? '???';
        $ctot = $entry['ctot'] ?? '-';
        $delay = isset($entry['delay_min']) ? $entry['delay_min'] . 'm' : '-';
        $ctx = '';
        if (!empty($entry['context'])) {
            $parts = [];
            foreach ($entry['context'] as $k => $v) {
                $parts[] = "{$k}={$v}";
            }
            $ctx = ' ' . implode(' ', $parts);
        }
        echo "  [{$action}] {$cs} ctot={$ctot} delay={$delay}{$ctx}\n";
    }
    echo "=== END SHADOW LOG ===\n";
} elseif ($shadowMode) {
    echo "\n=== SHADOW LOG (0 actions — no restrictions active) ===\n";
}
