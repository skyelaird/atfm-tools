<?php

declare(strict_types=1);

// Seed the 7 Canadian airports and their runway thresholds.
// Idempotent: safe to re-run; uses updateOrCreate.
//
// Values:
//   - base_arrival_rate from vIFF (user's CY/CZ admin scope)
//     except CYOW which is manual (28) pending observation calibration
//   - coordinates from operator-supplied NAV CANADA AIP data
//   - taxi defaults from ICAO 9971 Part III A-CDM guidance

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

use Atfm\Models\Airport;
use Atfm\Models\RunwayThreshold;

//
// Airports
//
$airports = [
    // icao, name, lat, lon, elev, base_arr, base_dep, exot, exit, cdm
    // Names are plain ASCII to avoid charset issues on shared MySQL hosts
    // where the column charset may not honor the Eloquent connection charset.
    ['CYHZ', 'Halifax Stanfield International',        44.8808,  -63.5086,  477, 24, 24, 10, 6, false],
    ['CYOW', 'Ottawa Macdonald-Cartier International', 45.3225,  -75.6692,  374, 28, 28, 12, 8, false],
    ['CYUL', 'Montreal Pierre Elliott Trudeau',        45.4706,  -73.7408,  118, 40, 40, 15, 10, false],
    ['CYVR', 'Vancouver International',                49.1939, -123.1844,   14, 50, 50, 10, 8, false],
    ['CYWG', 'Winnipeg James Armstrong Richardson',    49.9100,  -97.2399,  783, 36, 36,  8, 6, false],
    ['CYYC', 'Calgary International',                  51.1139, -114.0203, 3557, 32, 32, 10, 8, false],
    ['CYYZ', 'Toronto Lester B. Pearson International', 43.6772,  -79.6306,  569, 66, 66, 20, 12, false],
];

$count = 0;
foreach ($airports as $row) {
    Airport::updateOrCreate(
        ['icao' => $row[0]],
        [
            'name'                => $row[1],
            'latitude'            => $row[2],
            'longitude'           => $row[3],
            'elevation_ft'        => $row[4],
            'base_arrival_rate'   => $row[5],
            'base_departure_rate' => $row[6],
            'default_exot_min'    => $row[7],
            'default_exit_min'    => $row[8],
            'is_cdm_airport'      => $row[9],
            'arrived_geofence_nm' => 5,
            'final_threshold_nm'  => 10,
        ],
    );
    $count++;
}
echo "✓ seeded {$count} airports\n";

//
// Runway thresholds
//
// Parsed from operator-supplied NAV CANADA data, DMS → decimal degrees.
// Each physical strip produces TWO rows, one per landing direction.
// Width defaults to 200 ft for commercial runways; can be overridden later.
//
// Source format per line: "dir1 dir2 hdg1 hdg2 lat1 lon1 lat2 lon2 icao"
// Line 1's lat/lon pair = landing end for direction 1 (where dir1 aircraft touch down)
// Line 2's lat/lon pair = landing end for direction 2
//

/** Parse DMS string like "N044.51.56.318" → decimal degrees. */
$dms = static function (string $s): float {
    if (! preg_match('/^([NSEW])(\d{2,3})\.(\d{2})\.(\d{2})\.(\d{3})$/', $s, $m)) {
        throw new InvalidArgumentException("Bad DMS: $s");
    }
    [, $hemi, $deg, $min, $sec, $ms] = $m;
    $v = (int) $deg + ((int) $min) / 60 + ((int) $sec + ((int) $ms) / 1000) / 3600;
    return ($hemi === 'S' || $hemi === 'W') ? -$v : $v;
};

/** Parse one input line into two threshold dicts. */
$parseLine = static function (string $line) use ($dms): array {
    $parts = preg_split('/\s+/', trim($line));
    if (count($parts) !== 9) {
        throw new InvalidArgumentException("Expected 9 fields, got " . count($parts) . ": $line");
    }
    return [
        'icao'      => $parts[8],
        'direction_1' => [
            'runway_ident' => $parts[0],
            'heading_deg'  => (int) $parts[2],
            'threshold_lat' => $dms($parts[4]),
            'threshold_lon' => $dms($parts[5]),
            'opposite_threshold_lat' => $dms($parts[6]),
            'opposite_threshold_lon' => $dms($parts[7]),
        ],
        'direction_2' => [
            'runway_ident' => $parts[1],
            'heading_deg'  => (int) $parts[3],
            'threshold_lat' => $dms($parts[6]),
            'threshold_lon' => $dms($parts[7]),
            'opposite_threshold_lat' => $dms($parts[4]),
            'opposite_threshold_lon' => $dms($parts[5]),
        ],
    ];
};

// Runway data (all 7 airports).
$runwayLines = [
    // CYHZ
    '05  23  053 233 N044.51.56.318 W063.31.38.110 N044.53.18.250 W063.30.17.200 CYHZ',
    '14  32  143 323 N044.53.37.388 W063.31.02.769 N044.52.53.691 W063.29.35.278 CYHZ',

    // CYUL
    '06R 24L 057 237 N045.27.27.568 W073.44.29.011 N045.28.37.268 W073.42.57.628 CYUL',
    '06L 24R 057 237 N045.27.39.859 W073.45.53.978 N045.28.59.768 W073.44.09.358 CYUL',

    // CYOW
    '14  32  140 320 N045.19.37.311 W075.41.10.460 N045.18.38.811 W075.39.17.650 CYOW',
    '07  25  071 251 N045.18.47.750 W075.40.16.769 N045.19.30.219 W075.38.42.381 CYOW',
    '04  22  039 219 N045.19.36.030 W075.41.20.461 N045.20.03.210 W075.41.02.180 CYOW',

    // CYYZ
    '15R 33L 147 327 N043.41.05.578 W079.39.04.240 N043.40.08.860 W079.37.50.149 CYYZ',
    '15L 33R 147 327 N043.41.34.108 W079.38.35.858 N043.40.14.869 W079.36.52.379 CYYZ',
    '06R 24L 057 237 N043.39.29.879 W079.37.18.940 N043.40.31.040 W079.35.50.049 CYYZ',
    '06L 24R 057 237 N043.39.39.780 W079.37.24.340 N043.40.44.320 W079.35.50.528 CYYZ',
    '05  23  057 237 N043.40.27.818 W079.39.49.359 N043.41.37.831 W079.38.07.688 CYYZ',

    // CYYC
    '17R 35L 165 345 N051.07.52.921 W114.01.16.759 N051.05.47.918 W114.01.16.881 CYYC',
    '17L 35R 165 345 N051.08.54.898 W113.59.25.051 N051.06.36.809 W113.59.25.270 CYYC',
    '11  29  105 285 N051.07.36.170 W114.02.11.061 N051.06.56.678 W114.00.22.550 CYYC',

    // CYVR
    '13  31  125 305 N049.11.59.690 W123.12.03.229 N049.11.03.051 W123.10.55.340 CYVR',
    '08R 26L 083 263 N049.11.23.161 W123.12.17.740 N049.11.04.891 W123.09.37.530 CYVR',
    '08L 26R 083 263 N049.12.18.759 W123.12.03.959 N049.12.01.951 W123.09.36.489 CYVR',

    // CYWG (operator-supplied, v0.4)
    '18  36  185 005 N049.55.29.701 W097.14.05.899 N049.53.42.388 W097.14.30.771 CYWG',
    '13  31  135 315 N049.54.46.900 W097.15.08.369 N049.53.42.619 W097.13.40.321 CYWG',
];

$thresholdCount = 0;
foreach ($runwayLines as $line) {
    $parsed = $parseLine($line);
    foreach (['direction_1', 'direction_2'] as $dirKey) {
        $dir = $parsed[$dirKey];
        RunwayThreshold::updateOrCreate(
            [
                'airport_icao' => $parsed['icao'],
                'runway_ident' => $dir['runway_ident'],
            ],
            [
                'heading_deg'            => $dir['heading_deg'],
                'threshold_lat'          => $dir['threshold_lat'],
                'threshold_lon'          => $dir['threshold_lon'],
                'opposite_threshold_lat' => $dir['opposite_threshold_lat'],
                'opposite_threshold_lon' => $dir['opposite_threshold_lon'],
                'width_ft'               => 200,
            ],
        );
        $thresholdCount++;
    }
}
echo "✓ seeded {$thresholdCount} runway thresholds (all 7 airports)\n";

echo "done.\n";
