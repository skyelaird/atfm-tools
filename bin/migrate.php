<?php

declare(strict_types=1);

// One-shot schema creator. Run with:
//   composer migrate
// or
//   php bin/migrate.php
//
// Idempotent: creates tables only if they don't already exist.

require __DIR__ . '/../vendor/autoload.php';

\Atfm\Bootstrap::boot(__DIR__ . '/..');

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

$schema = Capsule::schema();

if (! $schema->hasTable('firs')) {
    $schema->create('firs', function (Blueprint $t) {
        $t->id();
        $t->string('identifier', 4)->unique()->comment('ICAO FIR id, e.g. EGTT');
        $t->string('name')->comment('Human name, e.g. London');
        $t->timestamps();
    });
    echo "✓ created firs\n";
} else {
    echo "• firs already exists\n";
}

if (! $schema->hasTable('flow_measures')) {
    $schema->create('flow_measures', function (Blueprint $t) {
        $t->id();
        $t->string('identifier')->unique()->comment('Human identifier for the flow measure');
        $t->foreignId('fir_id')->constrained('firs');
        $t->text('reason');
        $t->string('type')->comment('e.g. ground_stop, minimum_departure_interval, ...');
        $t->unsignedInteger('value')->nullable();
        $t->json('filters')->nullable()->comment('Filter config as JSON blob');
        $t->dateTime('start_time');
        $t->dateTime('end_time');
        $t->timestamps();
        $t->softDeletes();
        $t->index(['start_time', 'end_time']);
    });
    echo "✓ created flow_measures\n";
} else {
    echo "• flow_measures already exists\n";
}

echo "done.\n";
