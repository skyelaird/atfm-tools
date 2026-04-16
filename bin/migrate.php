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
    echo "✓ created flow_measures (legacy compat)\n";
} else {
    echo "• flow_measures already exists\n";
}

//
// === v0.3 schema ===
// Per docs/ARCHITECTURE.md §4. Rate-based tactical CTOT allocator with
// A-CDM milestone tracking. Schema-compatible with PERTI's adl_flights_*
// where practical.
//

if (! $schema->hasTable('airports')) {
    $schema->create('airports', function (Blueprint $t) {
        $t->id();
        $t->string('icao', 4)->unique()->comment('ICAO code, e.g. CYHZ');
        $t->string('name');
        $t->double('latitude');
        $t->double('longitude');
        $t->integer('elevation_ft')->default(0);
        $t->unsignedInteger('base_arrival_rate');
        $t->unsignedInteger('observed_arrival_rate')->nullable();
        $t->unsignedInteger('observed_rate_sample_n')->default(0);
        $t->dateTime('observed_rate_updated_at')->nullable();
        $t->unsignedInteger('base_departure_rate');
        $t->unsignedInteger('default_exot_min')->default(10)
            ->comment('ICAO EXOT: startup+pushback+taxi+queue');
        $t->unsignedInteger('default_exit_min')->default(6);
        $t->boolean('is_cdm_airport')->default(false);
        $t->unsignedInteger('arrived_geofence_nm')->default(5);
        $t->unsignedInteger('final_threshold_nm')->default(10);
        $t->timestamps();
    });
    echo "✓ created airports (v0.3)\n";
} else {
    echo "• airports already exists\n";
}

if (! $schema->hasTable('runway_thresholds')) {
    $schema->create('runway_thresholds', function (Blueprint $t) {
        $t->id();
        $t->string('airport_icao', 4);
        $t->string('runway_ident', 4)->comment("e.g. '05', '23L', '33R'");
        $t->unsignedInteger('heading_deg')->comment('magnetic, e.g. 053 for RWY 05');
        $t->double('threshold_lat')->comment('landing end of this direction');
        $t->double('threshold_lon');
        $t->double('opposite_threshold_lat')->comment('other end of the strip');
        $t->double('opposite_threshold_lon');
        $t->unsignedInteger('width_ft')->default(200);
        $t->integer('elevation_ft')->nullable();
        $t->unsignedInteger('displaced_threshold_ft')->default(0);
        $t->timestamps();
        $t->unique(['airport_icao', 'runway_ident']);
        $t->index('airport_icao');
    });
    echo "✓ created runway_thresholds\n";
} else {
    echo "• runway_thresholds already exists\n";
}

if (! $schema->hasTable('airport_restrictions')) {
    $schema->create('airport_restrictions', function (Blueprint $t) {
        $t->id();
        $t->string('restriction_id', 16)->unique()
            ->comment('Auto-generated, e.g. CYHZ11VP');
        $t->foreignId('airport_id')->constrained('airports')->cascadeOnDelete();
        $t->string('runway_config', 16)->nullable()->comment('e.g. 05 or 05+14');
        $t->unsignedInteger('capacity')->comment('Reduced rate (mvts/hr)');
        $t->string('reason', 32)->default('ATC_CAPACITY');
        $t->string('type', 8)->default('ARR')->comment('ARR | DEP | BOTH');
        $t->string('runway', 4)->nullable()->comment('Required if type=DEP');
        $t->unsignedInteger('tier_minutes')->default(120);
        $t->unsignedInteger('compliance_window_early_min')->default(5);
        $t->unsignedInteger('compliance_window_late_min')->default(5);
        $t->string('start_utc', 4)->comment('HHMM');
        $t->string('end_utc', 4)->comment('HHMM');
        $t->dateTime('active_from')->useCurrent();
        $t->dateTime('expires_at')->nullable();
        $t->timestamps();
        $t->softDeletes();
        $t->index(['airport_id', 'type']);
        $t->index('expires_at');
    });
    echo "✓ created airport_restrictions (v0.3)\n";
} else {
    echo "• airport_restrictions already exists\n";
}

if (! $schema->hasTable('flights')) {
    $schema->create('flights', function (Blueprint $t) {
        $t->id();

        // Identity
        $t->string('flight_key', 80)->unique()
            ->comment('Composite: cid|callsign|adep|ades|deptime');
        $t->string('callsign', 16)->index();
        $t->unsignedInteger('cid')->index();
        $t->dateTime('first_seen_at');
        $t->dateTime('last_updated_at');
        $t->dateTime('finalized_at')->nullable()->index();

        // Aircraft / flight plan
        $t->string('aircraft_type', 8)->nullable();
        $t->string('aircraft_faa', 32)->nullable();
        $t->char('wake_category', 1)->nullable();
        $t->char('flight_rules', 1)->nullable()->comment('I|V|Y|Z');
        $t->char('airline_icao', 3)->nullable();

        // Route
        $t->char('adep', 4)->nullable();
        $t->char('ades', 4)->nullable();
        $t->char('alt_icao', 4)->nullable();
        $t->text('fp_route')->nullable();
        $t->integer('fp_altitude_ft')->nullable();
        $t->unsignedInteger('fp_cruise_tas')->nullable();

        // Runway / gate (FIXM-aligned)
        $t->string('departure_runway', 4)->nullable();
        $t->string('arrival_runway', 4)->nullable();
        $t->string('departure_gate', 10)->nullable();
        $t->string('arrival_gate', 10)->nullable();

        // A-CDM milestones
        $t->dateTime('eobt')->nullable();
        $t->dateTime('tobt')->nullable();
        $t->dateTime('tsat')->nullable();
        $t->dateTime('ttot')->nullable();
        $t->dateTime('ctot')->nullable()->comment('Allocator output');
        $t->dateTime('asat')->nullable();
        $t->dateTime('aobt')->nullable();
        $t->dateTime('atot')->nullable();
        $t->dateTime('eldt')->nullable();
        $t->dateTime('cta')->nullable();
        $t->dateTime('aldt')->nullable();
        $t->dateTime('aibt')->nullable();

        // Planned vs actual taxi
        $t->unsignedInteger('planned_exot_min')->nullable();
        $t->unsignedInteger('actual_exot_min')->nullable();
        $t->unsignedInteger('planned_exit_min')->nullable();
        $t->unsignedInteger('actual_exit_min')->nullable();

        // Regulation state
        $t->string('ctl_type', 32)->nullable()
            ->comment('AIRPORT_ARR_RATE|AIRPORT_DEP_RATE|EVENT_BOOKED|IMPORTED_CTOT|NONE');
        $t->string('ctl_element', 16)->nullable();
        $t->string('ctl_restriction_id', 16)->nullable();
        $t->integer('delay_minutes')->nullable();
        $t->string('delay_status', 16)->nullable();

        // State machine
        $t->string('phase', 16)->nullable();
        $t->dateTime('phase_updated_at')->nullable();

        // Last known position
        $t->double('last_lat')->nullable();
        $t->double('last_lon')->nullable();
        $t->integer('last_altitude_ft')->nullable();
        $t->integer('last_groundspeed_kts')->nullable();
        $t->unsignedInteger('last_heading_deg')->nullable();
        $t->dateTime('last_position_at')->nullable();

        // Disconnect handling
        $t->dateTime('first_disconnect_at')->nullable();
        $t->unsignedInteger('reconnect_count')->default(0);

        $t->timestamps();

        $t->index(['adep', 'phase']);
        $t->index(['ades', 'phase']);
        $t->index(['phase', 'last_updated_at']);
    });
    echo "✓ created flights (v0.3)\n";
} else {
    echo "• flights already exists\n";
}

if (! $schema->hasTable('position_scratch')) {
    $schema->create('position_scratch', function (Blueprint $t) {
        $t->id();
        $t->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
        $t->double('lat');
        $t->double('lon');
        $t->integer('altitude_ft')->nullable();
        $t->integer('groundspeed_kts')->nullable();
        $t->unsignedInteger('heading_deg')->nullable();
        $t->dateTime('observed_at');
        $t->index(['flight_id', 'observed_at']);
        $t->index('observed_at');
    });
    echo "✓ created position_scratch\n";
} else {
    echo "• position_scratch already exists\n";
}

if (! $schema->hasTable('allocation_runs')) {
    $schema->create('allocation_runs', function (Blueprint $t) {
        $t->id();
        $t->uuid('run_uuid')->unique();
        $t->dateTime('started_at')->index();
        $t->dateTime('finished_at')->nullable();
        $t->unsignedInteger('airports_considered')->default(0);
        $t->unsignedInteger('restrictions_active')->default(0);
        $t->unsignedInteger('flights_evaluated')->default(0);
        $t->unsignedInteger('ctots_frozen_kept')->default(0);
        $t->unsignedInteger('ctots_issued')->default(0);
        $t->unsignedInteger('ctots_released')->default(0);
        $t->unsignedInteger('ctots_reissued')->default(0);
        $t->unsignedInteger('elapsed_ms')->nullable();
        $t->text('notes')->nullable();
    });
    echo "✓ created allocation_runs\n";
} else {
    echo "• allocation_runs already exists\n";
}

if (! $schema->hasTable('event_sources')) {
    $schema->create('event_sources', function (Blueprint $t) {
        $t->id();
        $t->string('event_code', 16)->unique();
        $t->string('label', 64);
        $t->dateTime('start_utc')->nullable();
        $t->dateTime('end_utc')->nullable();
        $t->boolean('active')->default(true);
        $t->timestamps();
    });
    echo "✓ created event_sources\n";
} else {
    echo "• event_sources already exists\n";
}

if (! $schema->hasTable('imported_ctots')) {
    $schema->create('imported_ctots', function (Blueprint $t) {
        $t->id();
        $t->string('source_file', 255);
        $t->string('source_label', 64)->nullable();
        $t->dateTime('source_uploaded_at');
        $t->string('callsign', 16)->nullable();
        $t->unsignedInteger('cid')->nullable();
        $t->dateTime('ctot');
        $t->string('most_penalizing_airspace', 64)->nullable();
        $t->unsignedInteger('priority')->default(100);
        $t->dateTime('valid_from');
        $t->dateTime('valid_until');
        $t->boolean('active')->default(true);
        $t->timestamps();
        $t->index(['callsign', 'valid_from']);
        $t->index(['cid', 'valid_from']);
        $t->index(['active', 'valid_until']);
    });
    echo "✓ created imported_ctots\n";
} else {
    echo "• imported_ctots already exists\n";
}

//
// v0.3.1: add op_level to airport_restrictions (PERTI TMU OpLevel taxonomy).
//
if ($schema->hasTable('airport_restrictions') && ! $schema->hasColumn('airport_restrictions', 'op_level')) {
    $schema->table('airport_restrictions', function (Blueprint $t) {
        $t->unsignedTinyInteger('op_level')->default(2)->after('reason')
            ->comment('1=Steady State, 2=Localized, 3=Regional, 4=NAS-Wide');
    });
    echo "✓ added airport_restrictions.op_level\n";
}

//
// v0.3.3: add fp_enroute_time_min to flights for ETA quality analysis.
// Parsed from flight_plan.enroute_time (HHMM string) into total minutes.
//
if ($schema->hasTable('flights') && ! $schema->hasColumn('flights', 'fp_enroute_time_min')) {
    $schema->table('flights', function (Blueprint $t) {
        $t->unsignedInteger('fp_enroute_time_min')->nullable()->after('fp_cruise_tas')
            ->comment('Filed enroute duration in minutes (from flight_plan.enroute_time HHMM)');
    });
    echo "✓ added flights.fp_enroute_time_min\n";
}

// (v0.4.0 added rot_observations + aar_calculations tables for the
// ROT/AAR derivation pipeline; v0.4.7 retired that pipeline because
// the ROI didn't justify the precision work — see CHANGELOG/CLAUDE.md.
// The migrations are removed; existing tables on already-deployed DBs
// stay in place harmlessly. Drop manually with `DROP TABLE
// rot_observations, aar_calculations;` if you want to reclaim the
// space.)

//
// v0.5.0: rename `cta` → `tldt` (Target Landing Time per EUROCONTROL
// Airport CDM Manual). The previous name "Calculated Time of Arrival"
// was a Network-Manager / CFMU concept and didn't match what we're
// actually storing — which is the local FMP's slot allocation.
// Idempotent: if `tldt` already exists, skip.
//
if ($schema->hasTable('flights')
    && $schema->hasColumn('flights', 'cta')
    && ! $schema->hasColumn('flights', 'tldt')
) {
    Capsule::connection()->statement(
        'ALTER TABLE flights CHANGE cta tldt DATETIME NULL'
    );
    echo "✓ renamed flights.cta → flights.tldt (v0.5.0)\n";
}

//
// v0.5.0: ELDT freeze columns. We snapshot the predicted ELDT once
// per flight when it first crosses inside the validation horizon
// (default 60 min before predicted landing). After ALDT is observed,
// the (ALDT − eldt_locked) delta tells us how good our prediction
// was at the moment we'd have to make a slot-blocking decision.
//
// See VatsimIngestor::stampEldtLockIfNeeded() and
// /api/v1/reports/summary which surfaces the bias / p90 / %-within-3min.
//
if ($schema->hasTable('flights') && ! $schema->hasColumn('flights', 'eldt_locked')) {
    $schema->table('flights', function (Blueprint $t) {
        $t->dateTime('eldt_locked')->nullable()->after('eldt')
            ->comment('ELDT snapshot at the validation horizon — frozen, not refreshed');
        $t->dateTime('eldt_locked_at')->nullable()->after('eldt_locked')
            ->comment('When the snapshot was taken');
        $t->string('eldt_locked_source', 16)->nullable()->after('eldt_locked_at')
            ->comment('EtaEstimator tier that produced the snapshot (OBSERVED_POS, FILED, etc.)');
    });
    echo "✓ added flights.eldt_locked + eldt_locked_at + eldt_locked_source (v0.5.0)\n";
}

//
// v0.5.0: TLDT provenance — when the allocator assigned the slot,
// and which restriction was binding. Helps debugging "why does this
// flight have a TLDT 8 minutes after its ELDT?"
//
if ($schema->hasTable('flights') && ! $schema->hasColumn('flights', 'tldt_assigned_at')) {
    $schema->table('flights', function (Blueprint $t) {
        $t->dateTime('tldt_assigned_at')->nullable()->after('tldt')
            ->comment('When the allocator assigned this TLDT');
    });
    echo "✓ added flights.tldt_assigned_at (v0.5.0)\n";
}

// v0.5.13: SimBrief flag — true when flight plan remarks contain "SIMBRIEF".
// SimBrief-filed ETEs include wind correction and proper routing, so the
// FILED tier ETA can be trusted more than a manually filed ETE.
if ($schema->hasTable('flights') && ! $schema->hasColumn('flights', 'is_simbrief')) {
    $schema->table('flights', function (Blueprint $t) {
        $t->boolean('is_simbrief')->default(false)->after('fp_enroute_time_min')
            ->comment('True if remarks contain SIMBRIEF — ETE is wind-corrected');
    });
    echo "✓ added flights.is_simbrief (v0.5.13)\n";
}

// v0.5.13: SimBrief ELDT — snapshot of the FILED-tier ELDT for SimBrief
// flights, preserved for triple comparison (SimBrief vs frozen vs ALDT).
if ($schema->hasTable('flights') && ! $schema->hasColumn('flights', 'eldt_simbrief')) {
    $schema->table('flights', function (Blueprint $t) {
        $t->dateTime('eldt_simbrief')->nullable()->after('eldt_locked_source')
            ->comment('FILED-tier ELDT for SimBrief flights (EOBT + taxi + ETE)');
    });
    echo "✓ added flights.eldt_simbrief (v0.5.13)\n";
}

// v0.5.13: ATC staffing flag — was approach/tower online at ADES when
// the flight was in the terminal area? For correlation analysis.
if ($schema->hasTable('flights') && ! $schema->hasColumn('flights', 'had_atc_at_arrival')) {
    $schema->table('flights', function (Blueprint $t) {
        $t->boolean('had_atc_at_arrival')->nullable()->after('eldt_simbrief')
            ->comment('True if APP/TWR was online at ADES during arrival');
    });
    echo "✓ added flights.had_atc_at_arrival (v0.5.13)\n";
}

// v0.5.15: PERTI ETA snapshot — captured at ELDT freeze time for
// three-way comparison: our ELDT vs PERTI ETA vs ALDT.
if ($schema->hasTable('flights') && ! $schema->hasColumn('flights', 'eldt_perti')) {
    $schema->table('flights', function (Blueprint $t) {
        $t->dateTime('eldt_perti')->nullable()->after('eldt_simbrief')
            ->comment('PERTI eta_utc snapshot at ELDT freeze time');
    });
    echo "✓ added flights.eldt_perti (v0.5.15)\n";
}

// v0.5.20: Seed CYWG runway thresholds if missing.
// These were in seed-airports.php but the seed was never re-run after
// CYWG data was added. Insert via migration so they deploy automatically.
if ($schema->hasTable('runway_thresholds')) {
    $existing = Capsule::table('runway_thresholds')->where('airport_icao', 'CYWG')->count();
    if ($existing === 0) {
        $cywgRwys = [
            ['CYWG', '18', 185, 49.924917, -97.234972, 49.895108, -97.241881],
            ['CYWG', '36', 5,   49.895108, -97.241881, 49.924917, -97.234972],
            ['CYWG', '13', 135, 49.913028, -97.252325, 49.895172, -97.227867],
            ['CYWG', '31', 315, 49.895172, -97.227867, 49.913028, -97.252325],
        ];
        foreach ($cywgRwys as [$icao, $ident, $hdg, $lat1, $lon1, $lat2, $lon2]) {
            Capsule::table('runway_thresholds')->insert([
                'airport_icao' => $icao,
                'runway_ident' => $ident,
                'heading_deg' => $hdg,
                'threshold_lat' => $lat1,
                'threshold_lon' => $lon1,
                'opposite_threshold_lat' => $lat2,
                'opposite_threshold_lon' => $lon2,
                'width_ft' => 200,
                'displaced_threshold_ft' => 0,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        echo "✓ seeded CYWG runway thresholds (v0.5.20)\n";
    }
}

// v0.5.22: CYOW now in vIFF admin scope with base_arrival_rate=24, taxitime=5.
// Previously 28/12 as a manual placeholder. Update the row if still at the
// old values — idempotent, won't clobber a later manual override.
if ($schema->hasTable('airports')) {
    $updated = Capsule::table('airports')
        ->where('icao', 'CYOW')
        ->where('base_arrival_rate', 28)
        ->update([
            'base_arrival_rate'   => 24,
            'base_departure_rate' => 24,
            'default_exot_min'    => 5,
        ]);
    if ($updated > 0) {
        echo "✓ updated CYOW rate 28->24 and exot 12->5 from vIFF (v0.5.22)\n";
    }
}

echo "done.\n";
