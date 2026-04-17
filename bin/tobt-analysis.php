<?php

/**
 * TOBT Proxy Analysis — spawn-to-movement stats.
 *
 * Answers: "On VATSIM, how long after EOBT does the pilot actually push back?"
 * This informs the TOBT proxy: instead of TOBT = EOBT (garbage), we can set
 * TOBT = first_seen + expected_dwell, or TOBT = EOBT + observed_median_bias.
 *
 * Run: composer exec tobt-analysis  (or php bin/tobt-analysis.php)
 * Read-only. Outputs analysis to stdout as JSON.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

use Illuminate\Database\Capsule\Manager as DB;

$hours = (int) ($argv[1] ?? 168); // default 7 days

echo "TOBT Analysis — last {$hours} hours\n";
echo str_repeat('=', 60) . "\n\n";

// 1. EOBT vs AOBT (actual pushback) — the core question
echo "1. EOBT ERROR (AOBT - EOBT) by departure airport\n";
echo "   Positive = pilot pushed back later than EOBT\n";
echo "   Negative = pilot pushed back earlier than EOBT\n\n";

$rows = DB::select("
    SELECT
        adep,
        COUNT(*) as n,
        ROUND(AVG(TIMESTAMPDIFF(SECOND, eobt, aobt)) / 60, 1) as avg_min,
        ROUND(STDDEV(TIMESTAMPDIFF(SECOND, eobt, aobt)) / 60, 1) as std_min
    FROM flights
    WHERE aobt IS NOT NULL
      AND eobt IS NOT NULL
      AND aobt >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
      AND ABS(TIMESTAMPDIFF(SECOND, eobt, aobt)) < 14400
    GROUP BY adep
    HAVING n >= 3
    ORDER BY n DESC
");

printf("%-6s %5s %8s %8s\n", 'ADEP', 'n', 'avg', 'std');
printf("%-6s %5s %8s %8s\n", '----', '---', '------', '------');
foreach ($rows as $r) {
    printf("%-6s %5d %+8.1f %8.1f\n", $r->adep, $r->n, $r->avg_min, $r->std_min);
}

// 2. Distribution of EOBT error in buckets
echo "\n2. EOBT ERROR DISTRIBUTION (all departures from scope airports)\n\n";

$bucketRows = DB::select("
    SELECT
        CASE
            WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) < -3600 THEN 'a. < -60m'
            WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) < -900  THEN 'b. -60..-15m'
            WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) <= 300   THEN 'c. -15..+5m'
            WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) <= 900   THEN 'd. +5..+15m'
            WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) <= 3600  THEN 'e. +15..+60m'
            WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) <= 7200  THEN 'f. +60..+120m'
            ELSE 'g. > +120m'
        END as bucket,
        COUNT(*) as n
    FROM flights
    WHERE aobt IS NOT NULL
      AND eobt IS NOT NULL
      AND aobt >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
      AND ABS(TIMESTAMPDIFF(SECOND, eobt, aobt)) < 14400
      AND adep IN ('CYHZ','CYOW','CYUL','CYVR','CYWG','CYYC','CYYZ')
    GROUP BY bucket
    ORDER BY bucket
");

$totalBucket = array_sum(array_map(fn($r) => $r->n, $bucketRows));
foreach ($bucketRows as $r) {
    $pct = $totalBucket > 0 ? round(100 * $r->n / $totalBucket, 1) : 0;
    $bar = str_repeat('#', (int) ($pct / 2));
    printf("  %-15s %5d (%5.1f%%) %s\n", $r->bucket, $r->n, $pct, $bar);
}

// 3. Spawn-to-pushback: first_seen vs AOBT
echo "\n3. SPAWN-TO-PUSHBACK (AOBT - created_at) — dwell time on ground\n";
echo "   How long between first appearance in feed and actual pushback?\n\n";

$dwellRows = DB::select("
    SELECT
        adep,
        COUNT(*) as n,
        ROUND(AVG(TIMESTAMPDIFF(SECOND, created_at, aobt)) / 60, 1) as avg_dwell_min,
        ROUND(MIN(TIMESTAMPDIFF(SECOND, created_at, aobt)) / 60, 1) as min_dwell,
        ROUND(MAX(TIMESTAMPDIFF(SECOND, created_at, aobt)) / 60, 1) as max_dwell
    FROM flights
    WHERE aobt IS NOT NULL
      AND created_at IS NOT NULL
      AND aobt >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
      AND TIMESTAMPDIFF(SECOND, created_at, aobt) > 0
      AND TIMESTAMPDIFF(SECOND, created_at, aobt) < 14400
      AND adep IN ('CYHZ','CYOW','CYUL','CYVR','CYWG','CYYC','CYYZ')
    GROUP BY adep
    HAVING n >= 3
    ORDER BY n DESC
");

printf("%-6s %5s %10s %8s %8s\n", 'ADEP', 'n', 'avg_dwell', 'min', 'max');
printf("%-6s %5s %10s %8s %8s\n", '----', '---', '--------', '------', '------');
foreach ($dwellRows as $r) {
    printf("%-6s %5d %+10.1f %8.1f %8.1f\n", $r->adep, $r->n, $r->avg_dwell_min, $r->min_dwell, $r->max_dwell);
}

// 4. EOBT vs first_seen — do pilots file EOBT way before they spawn?
echo "\n4. EOBT vs FIRST_SEEN (created_at - eobt)\n";
echo "   Positive = pilot spawned after their EOBT (late spawn)\n";
echo "   Negative = pilot spawned before their EOBT (pre-spawn / prefiled)\n\n";

$spawnRows = DB::select("
    SELECT
        adep,
        COUNT(*) as n,
        ROUND(AVG(TIMESTAMPDIFF(SECOND, eobt, created_at)) / 60, 1) as avg_spawn_vs_eobt
    FROM flights
    WHERE created_at IS NOT NULL
      AND eobt IS NOT NULL
      AND aobt IS NOT NULL
      AND aobt >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
      AND ABS(TIMESTAMPDIFF(SECOND, eobt, created_at)) < 14400
      AND adep IN ('CYHZ','CYOW','CYUL','CYVR','CYWG','CYYC','CYYZ')
    GROUP BY adep
    HAVING n >= 3
    ORDER BY n DESC
");

printf("%-6s %5s %12s\n", 'ADEP', 'n', 'avg_min');
printf("%-6s %5s %12s\n", '----', '---', '----------');
foreach ($spawnRows as $r) {
    printf("%-6s %5d %+12.1f\n", $r->adep, $r->n, $r->avg_spawn_vs_eobt);
}

// 5. Percentile analysis of AOBT - EOBT for scope airports combined
echo "\n5. EOBT ERROR PERCENTILES (scope airports combined)\n\n";

$allErrors = DB::select("
    SELECT TIMESTAMPDIFF(SECOND, eobt, aobt) / 60 as err_min
    FROM flights
    WHERE aobt IS NOT NULL
      AND eobt IS NOT NULL
      AND aobt >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
      AND ABS(TIMESTAMPDIFF(SECOND, eobt, aobt)) < 14400
      AND adep IN ('CYHZ','CYOW','CYUL','CYVR','CYWG','CYYC','CYYZ')
    ORDER BY err_min
");

$vals = array_map(fn($r) => (float) $r->err_min, $allErrors);
$n = count($vals);
if ($n > 0) {
    $pcts = [5, 10, 25, 50, 75, 90, 95];
    foreach ($pcts as $p) {
        $idx = (int) floor($n * $p / 100);
        $idx = max(0, min($n - 1, $idx));
        printf("  P%02d: %+.1f min\n", $p, $vals[$idx]);
    }
    printf("\n  Total: %d flights\n", $n);
    printf("  Mean: %+.1f min\n", array_sum($vals) / $n);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Analysis complete.\n";
