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
    // Filters are stored in the array-of-{type,value} shape that Roger's
    // CDM plugin (and ECFMP/flow's PluginApiController) parse. The CDM
    // plugin filters out any measure whose type isn't minimum_departure_interval
    // or per_hour, so we use minimum_departure_interval here (value in seconds).
    FlowMeasure::updateOrCreate(
        ['identifier' => 'EGTT-01A'],
        [
            'fir_id'     => $london->id,
            'reason'     => 'Demo MDI for testing atfm-tools CDM-compatibility.',
            'type'       => 'minimum_departure_interval',
            'value'      => 120,
            'filters'    => [
                ['type' => 'ADEP', 'value' => ['EGLL', 'EGKK']],
            ],
            'start_time' => (new DateTime('-1 hour'))->format('Y-m-d H:i:s'),
            'end_time'   => (new DateTime('+2 hours'))->format('Y-m-d H:i:s'),
        ],
    );
    echo "✓ seeded sample flow measure (MDI 120s, ADEP EGLL/EGKK)\n";
}

echo "done.\n";
