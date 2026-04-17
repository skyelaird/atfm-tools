#!/usr/bin/env php
<?php
/**
 * Import navigation waypoint data from Navigraph AIRAC files.
 *
 * Reads ISEC.txt (fixes) and AIRWAY.txt (VORs/NDBs/waypoints) from the
 * Navigraph navdata directory, filters to our operational area (North
 * Atlantic + North America), deduplicates, and writes a compact JSON
 * lookup file for route resolution.
 *
 * Usage:
 *   php bin/import-navdata.php [navdata-dir]
 *
 * Default navdata dir: D:\navdata\Bin
 * Output: data/waypoints.json
 *
 * The output file maps fix names to [lat, lon]:
 *   { "TONNY": [44.185, -79.723], "YEE": [44.582, -79.793], ... }
 *
 * For fixes with duplicate names worldwide, we keep the one closest to
 * our operational centroid (50°N, 85°W — roughly central Canada).
 */

declare(strict_types=1);

$navDir = $argv[1] ?? 'D:\\navdata\\Bin';
$outFile = __DIR__ . '/../data/waypoints.json';

// Operational centroid for duplicate resolution
$centLat = 50.0;
$centLon = -85.0;

// Bounding box: generous to cover NAT tracks, US origins, Caribbean
$latMin = 10;
$latMax = 80;
$lonMin = -180;
$lonMax = 10;

function haversineNm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 3440.065; // Earth radius in nm
    $dlat = deg2rad($lat2 - $lat1);
    $dlon = deg2rad($lon2 - $lon1);
    $a = sin($dlat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlon / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// ---- Parse ISEC.txt (fixes) ----
$isecFile = $navDir . DIRECTORY_SEPARATOR . 'ISEC.txt';
if (!file_exists($isecFile)) {
    echo "ERROR: {$isecFile} not found\n";
    exit(1);
}

$waypoints = []; // name => [lat, lon, distToCentroid]
$skipped = 0;
$total = 0;

$fh = fopen($isecFile, 'r');
while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '' || $line[0] === ';') continue;
    $parts = preg_split('/\t+/', $line);
    if (count($parts) < 3) continue;

    $name = trim($parts[0]);
    $lat = (float) trim($parts[1]);
    $lon = (float) trim($parts[2]);
    $total++;

    // Filter to operational bounding box
    if ($lat < $latMin || $lat > $latMax || $lon < $lonMin || $lon > $lonMax) {
        $skipped++;
        continue;
    }

    $dist = haversineNm($lat, $lon, $centLat, $centLon);

    if (!isset($waypoints[$name]) || $dist < $waypoints[$name][2]) {
        $waypoints[$name] = [$lat, $lon, $dist];
    }
}
fclose($fh);
echo "ISEC: {$total} total, {$skipped} outside bbox, " . count($waypoints) . " kept\n";

// ---- Parse AIRWAY.txt (VORs/NDBs/waypoints on airways) ----
$awyFile = $navDir . DIRECTORY_SEPARATOR . 'AIRWAY.txt';
$awyAdded = 0;
if (file_exists($awyFile)) {
    $fh = fopen($awyFile, 'r');
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';') continue;
        $parts = preg_split('/\t+/', $line);
        if (count($parts) < 3) continue;

        // Each AIRWAY line has up to 3 waypoints:
        // cols 1-3: primary waypoint (name, lat, lon)
        // cols 7-9: next waypoint
        // cols 12-14: previous waypoint
        $candidates = [
            [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? ''],
        ];
        if (count($parts) >= 9 && $parts[6] !== '') {
            $candidates[] = [$parts[6], $parts[7], $parts[8]];
        }
        if (count($parts) >= 14 && $parts[11] !== '') {
            $candidates[] = [$parts[11], $parts[12], $parts[13]];
        }

        foreach ($candidates as [$name, $latStr, $lonStr]) {
            $name = trim($name);
            if ($name === '' || strlen($name) < 2) continue;
            $lat = (float) trim($latStr);
            $lon = (float) trim($lonStr);
            if ($lat == 0 && $lon == 0) continue;

            if ($lat < $latMin || $lat > $latMax || $lon < $lonMin || $lon > $lonMax) continue;

            $dist = haversineNm($lat, $lon, $centLat, $centLon);
            if (!isset($waypoints[$name]) || $dist < $waypoints[$name][2]) {
                $waypoints[$name] = [$lat, $lon, $dist];
                $awyAdded++;
            }
        }
    }
    fclose($fh);
    echo "AIRWAY: {$awyAdded} new/updated waypoints\n";
}

// ---- Write waypoints output ----
// Strip the distance field, round coords to 6 decimal places
$output = [];
foreach ($waypoints as $name => [$lat, $lon, $dist]) {
    $output[$name] = [round($lat, 6), round($lon, 6)];
}

ksort($output);
$json = json_encode($output, JSON_UNESCAPED_UNICODE);

file_put_contents($outFile, $json);
$sizeMb = round(strlen($json) / 1024 / 1024, 2);
echo "Wrote " . count($output) . " waypoints to {$outFile} ({$sizeMb} MB)\n";

// ---- Build airway adjacency graph ----
$awyOutFile = __DIR__ . '/../data/airways.json';
$airways = []; // airway_id => { fix_name => [lat, lon, next, prev] }

if (file_exists($awyFile)) {
    $fh = fopen($awyFile, 'r');
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';') continue;
        $parts = preg_split('/\t+/', $line);
        if (count($parts) < 5) continue;

        $name = trim($parts[0]);
        $lat = (float) trim($parts[1]);
        $lon = (float) trim($parts[2]);
        $awyId = trim($parts[4]);

        if ($lat < $latMin || $lat > $latMax || $lon < $lonMin || $lon > $lonMax) continue;

        $nextFix = (count($parts) > 6 && trim($parts[6]) !== '') ? trim($parts[6]) : null;
        $prevFix = (count($parts) > 11 && trim($parts[11]) !== '') ? trim($parts[11]) : null;

        if (!isset($airways[$awyId])) $airways[$awyId] = [];
        $airways[$awyId][$name] = [round($lat, 6), round($lon, 6), $nextFix, $prevFix];
    }
    fclose($fh);

    $awyJson = json_encode($airways, JSON_UNESCAPED_UNICODE);
    file_put_contents($awyOutFile, $awyJson);
    $awySizeMb = round(strlen($awyJson) / 1024 / 1024, 2);
    $totalFixes = array_sum(array_map('count', $airways));
    echo "Wrote " . count($airways) . " airways ({$totalFixes} fix entries) to {$awyOutFile} ({$awySizeMb} MB)\n";
}

// ---- Verify some known Canadian fixes ----
$check = ['TONNY', 'IKLEN', 'FIORD', 'YEE', 'DOTTY', 'SCROD', 'LOBST', 'RAGID', 'CYMON'];
echo "\nVerification:\n";
foreach ($check as $fix) {
    if (isset($output[$fix])) {
        echo "  {$fix}: {$output[$fix][0]}, {$output[$fix][1]}\n";
    } else {
        echo "  {$fix}: NOT FOUND\n";
    }
}
