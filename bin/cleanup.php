<?php

declare(strict_types=1);

// Daily cleanup job:
//   - Purge position_scratch older than 48 h
//   - Mark DISCONNECTED flights WITHDRAWN after 10 h
//   - Purge ImportedCtot rows past valid_until by > 24 h
//
// See docs/ARCHITECTURE.md §4.5, §7 (withdraw logic), §4.8.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

use Atfm\Models\Flight;
use Atfm\Models\ImportedCtot;
use Atfm\Models\PositionScratch;
use DateTimeImmutable;
use DateTimeZone;

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$ts  = $now->format('Y-m-d H:i:s');
echo "[cleanup] start {$ts}Z\n";

// 1. Purge position_scratch older than 48h
$cutoff48h = $now->modify('-48 hours')->format('Y-m-d H:i:s');
$purgedPositions = PositionScratch::where('observed_at', '<', $cutoff48h)->delete();
echo "• purged {$purgedPositions} position_scratch rows (>48h)\n";

// 2. Mark long-disconnected flights as WITHDRAWN
$cutoff10h = $now->modify('-10 hours')->format('Y-m-d H:i:s');
$withdrawn = Flight::where('phase', Flight::PHASE_DISCONNECTED)
    ->where('first_disconnect_at', '<', $cutoff10h)
    ->whereNull('finalized_at')
    ->get();

$withdrawnCount = 0;
foreach ($withdrawn as $flight) {
    $flight->phase          = Flight::PHASE_WITHDRAWN;
    $flight->delay_status   = Flight::DELAY_WITHDRAWN;
    $flight->finalized_at   = $now;
    $flight->phase_updated_at = $now;
    $flight->save();
    $withdrawnCount++;
}
echo "• withdrew {$withdrawnCount} disconnected flights (>10h)\n";

// 3. Purge ImportedCtot past valid_until by > 24h
$cutoffCtot = $now->modify('-24 hours')->format('Y-m-d H:i:s');
$purgedImports = ImportedCtot::where('valid_until', '<', $cutoffCtot)->delete();
echo "• purged {$purgedImports} imported_ctots (expired >24h)\n";

echo "done.\n";
