<?php

declare(strict_types=1);

// Seed a handful of FIRs and one sample flow measure so the map has
// something to show. Safe to re-run.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

use Atfm\Models\Fir;
use Atfm\Models\FlowMeasure;

$firs = [
    ['identifier' => 'EGTT', 'name' => 'London'],
    ['identifier' => 'EGPX', 'name' => 'Scottish'],
    ['identifier' => 'LFFF', 'name' => 'Paris'],
    ['identifier' => 'EDUU', 'name' => 'Rhein UAC'],
    ['identifier' => 'CZQM', 'name' => 'Moncton'],
];

foreach ($firs as $row) {
    Fir::updateOrCreate(['identifier' => $row['identifier']], $row);
}
echo "✓ seeded " . count($firs) . " FIRs\n";

$london = Fir::where('identifier', 'EGTT')->first();
if ($london !== null) {
    FlowMeasure::updateOrCreate(
        ['identifier' => 'EGTT-01A'],
        [
            'fir_id'     => $london->id,
            'reason'     => 'Demo ground stop for testing the atfm-tools scaffold.',
            'type'       => 'ground_stop',
            'value'      => null,
            'filters'    => ['adep' => ['EGLL', 'EGKK']],
            'start_time' => (new DateTime('-1 hour'))->format('Y-m-d H:i:s'),
            'end_time'   => (new DateTime('+2 hours'))->format('Y-m-d H:i:s'),
        ],
    );
    echo "✓ seeded sample flow measure\n";
}

echo "done.\n";
