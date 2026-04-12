<?php

declare(strict_types=1);

namespace Atfm\Api;

use Atfm\Models\Airport;
use Atfm\Models\AirportRestriction;
use Atfm\Models\AllocationRun;
use Atfm\Models\EventSource;
use Atfm\Models\Fir;
use Atfm\Models\Flight;
use Atfm\Models\FlowMeasure;
use Atfm\Models\ImportedCtot;
use Atfm\Models\RunwayThreshold;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Builds the Slim app and registers all HTTP routes.
 *
 * Routes are grouped into four surfaces:
 *   /cdm/*      — CDM plugin protocol (mirrors viff-system.network)
 *   /api/v1/*   — atfm-tools admin + debug + legacy ECFMP-style plugin API
 *   /api/health — liveness
 *   /map.html   — Leaflet map (served statically)
 *
 * See docs/ARCHITECTURE.md §11 for the full endpoint catalog.
 */
final class Kernel
{
    public static function create(): App
    {
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        $app->addErrorMiddleware(
            displayErrorDetails: ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
            logErrors: true,
            logErrorDetails: true,
        );

        self::registerHealth($app);
        self::registerLegacyFirAndFlowMeasure($app);
        self::registerEcfmpPluginMirror($app);
        self::registerCdmPluginProtocol($app);
        self::registerAdminEndpoints($app);
        self::registerReportsEndpoints($app);
        self::registerDebugEndpoints($app);

        return $app;
    }

    // ------------------------------------------------------------------
    //  Health
    // ------------------------------------------------------------------

    private static function registerHealth(App $app): void
    {
        // Root → bounce to the dashboard so the naked domain is useful.
        $app->get('/', function ($req, $res) {
            return $res
                ->withHeader('Location', '/dashboard.html')
                ->withStatus(302);
        });

        $app->get('/api/health', function ($req, $res) {
            return self::json($res, [
                'status'  => 'ok',
                'time'    => gmdate('c'),
                'version' => '0.3',
            ]);
        });
    }

    // ------------------------------------------------------------------
    //  Legacy (v0.1) FIR + FlowMeasure endpoints — kept for backwards compat
    // ------------------------------------------------------------------

    private static function registerLegacyFirAndFlowMeasure(App $app): void
    {
        $app->get('/api/v1/flight-information-region', function ($req, $res) {
            return self::json($res, Fir::orderBy('identifier')->get()->toArray());
        });

        $app->get('/api/v1/flight-information-region/{id}', function ($req, $res, array $args) {
            $fir = Fir::find((int) $args['id']);
            return $fir
                ? self::json($res, $fir->toArray())
                : self::json($res->withStatus(404), ['error' => 'not found']);
        });

        $app->get('/api/v1/flow-measure', function ($req, $res) {
            $params = $req->getQueryParams();
            $query  = FlowMeasure::with('fir')->orderBy('start_time');
            if (($params['state'] ?? null) === 'active') {
                $now = new DateTimeImmutable('now');
                $query->where('start_time', '<=', $now)
                      ->where('end_time',   '>=', $now);
            }
            return self::json($res, $query->get()->toArray());
        });

        $app->get('/api/v1/flow-measure/{id}', function ($req, $res, array $args) {
            $fm = FlowMeasure::with('fir')->find((int) $args['id']);
            return $fm
                ? self::json($res, $fm->toArray())
                : self::json($res->withStatus(404), ['error' => 'not found']);
        });
    }

    // ------------------------------------------------------------------
    //  ECFMP plugin mirror (/api/v1/plugin) — unchanged from v0.2
    // ------------------------------------------------------------------

    private static function registerEcfmpPluginMirror(App $app): void
    {
        $app->get('/api/v1/plugin', function ($req, $res) {
            $deleted = (($req->getQueryParams()['deleted'] ?? '0') === '1');
            $query = FlowMeasure::with('fir')->orderBy('start_time');
            if ($deleted) {
                $query->withTrashed();
            }
            $measures = $query->get()->map(fn (FlowMeasure $fm) => self::serializeMeasureForPlugin($fm))->all();
            $firs = Fir::orderBy('identifier')->get()->map(fn (Fir $f) => [
                'id'         => $f->id,
                'identifier' => $f->identifier,
                'name'       => $f->name,
            ])->all();

            return self::json($res, [
                'events'                     => [],
                'flight_information_regions' => $firs,
                'flow_measures'              => $measures,
            ]);
        });
    }

    // ------------------------------------------------------------------
    //  CDM plugin protocol — /cdm/* mirrors viff-system.network surface
    // ------------------------------------------------------------------

    private static function registerCdmPluginProtocol(App $app): void
    {
        // The ONE endpoint we actually serve real data on.
        // CDM plugin polls every 5 min when customRestricted is set.
        // Response: bare JSON array of {callsign, ctot, mostPenalizingAirspace}.
        $app->get('/cdm/etfms/restricted', function ($req, $res) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $flights = Flight::query()
                ->whereNotNull('ctot')
                ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED])
                ->where('ctot', '>=', $now->format('Y-m-d H:i:s'))
                ->get(['callsign', 'ctot', 'ctl_element']);

            $out = $flights->map(fn (Flight $f) => [
                'callsign'               => (string) $f->callsign,
                'ctot'                   => $f->ctot?->format('Hi') ?? '',
                'mostPenalizingAirspace' => ($f->ctl_element ?? '') . '-ARR',
            ])->values()->all();

            return self::json($res, $out);
        });

        // /cdm/airport — list our configured airports
        $app->get('/cdm/airport', function ($req, $res) {
            $out = Airport::orderBy('icao')->get()->map(fn (Airport $a) => [
                'icao'         => $a->icao,
                'name'         => $a->name,
                'rate'         => $a->effectiveArrivalRate(),
                'taxiTime'     => (int) $a->default_exot_min,
                'isCdmAirport' => (bool) $a->is_cdm_airport,
            ])->values()->all();
            return self::json($res, $out);
        });

        // /cdm/ifps/depAirport?airport=XXXX — flights departing from X
        $app->get('/cdm/ifps/depAirport', function ($req, $res) {
            $airport = strtoupper((string) ($req->getQueryParams()['airport'] ?? ''));
            if ($airport === '') {
                return self::json($res, []);
            }
            $flights = Flight::where('adep', $airport)
                ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])
                ->orderBy('eobt')
                ->get();

            $out = $flights->map(fn (Flight $f) => [
                'callsign' => $f->callsign,
                'cid'      => $f->cid,
                'adep'     => $f->adep,
                'ades'     => $f->ades,
                'eobt'     => $f->eobt?->format('Hi'),
                'ctot'     => $f->ctot?->format('Hi'),
                'phase'    => $f->phase,
            ])->values()->all();

            return self::json($res, $out);
        });

        // ---- Stubs for the rest of the CDM protocol surface ----
        // These endpoints exist because the CDM plugin calls them. Returning
        // empty/true responses keeps the plugin happy without maintaining
        // state we don't need. See ARCHITECTURE.md §11.3.

        $app->get('/cdm/etfms/restrictions', fn ($req, $res) => self::json($res, []));
        $app->get('/cdm/etfms/relevant',     fn ($req, $res) => self::json($res, []));
        $app->get('/cdm/ifps/cidCheck',      fn ($req, $res) => self::json($res, ['exists' => false]));
        $app->get('/cdm/ifps/allStatus',     fn ($req, $res) => self::json($res, []));
        $app->get('/cdm/ifps/allOnTime',     fn ($req, $res) => self::json($res, []));
        $app->get('/cdm/ifps/dpi',           fn ($req, $res) => self::json($res, ['ok' => true]));
        $app->get('/cdm/ifps/setCdmData',    fn ($req, $res) => self::json($res, ['ok' => true]));
        $app->get('/cdm/airport/setMaster',  fn ($req, $res) => self::json($res, ['ok' => true]));
        $app->get('/cdm/airport/removeMaster', fn ($req, $res) => self::json($res, ['ok' => true]));
        $app->get('/cdm/airport/removeAllMasterByPosition', fn ($req, $res) => self::json($res, ['ok' => true]));
        $app->get('/cdm/airport/removeAllMasterByAirport', fn ($req, $res) => self::json($res, ['ok' => true]));
    }

    // ------------------------------------------------------------------
    //  Admin endpoints — /api/v1/airports, /airport-restrictions, /event-sources
    // ------------------------------------------------------------------

    private static function registerAdminEndpoints(App $app): void
    {
        // Dashboard status — compact single-call rollup
        $app->get('/api/v1/status', function ($req, $res) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $airportCount     = Airport::count();
            $activeFlights    = Flight::whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])->count();
            $activeCtots      = Flight::whereNotNull('ctot')
                ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])
                ->where('ctot', '>=', $now->format('Y-m-d H:i:s'))
                ->count();
            // Return timestamps as proper ISO8601 with offset so browsers parse
            // them as UTC rather than local time.
            $lastFlightUpdateRaw = Flight::max('last_updated_at');
            $lastFlightUpdate = $lastFlightUpdateRaw
                ? (new DateTimeImmutable($lastFlightUpdateRaw, new DateTimeZone('UTC')))->format('c')
                : null;
            $lastRun          = AllocationRun::orderBy('started_at', 'desc')->first();

            // Active restrictions: query all not-deleted, within active_from/expires_at,
            // then filter by HHMM window.
            $restrictionRows = AirportRestriction::query()
                ->whereNull('deleted_at')
                ->where('active_from', '<=', $now->format('Y-m-d H:i:s'))
                ->where(function ($q) use ($now) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>=', $now->format('Y-m-d H:i:s'));
                })
                ->get()
                ->filter(fn (AirportRestriction $r) => $r->isActiveAt($now));

            // Derive system OpLevel per PERTI TMU taxonomy, using FIR-based
            // scope analysis (not airport count):
            //   Level 1 Steady State     — 0 active restrictions
            //   Level 2 Localized Impact — within a single FIR
            //   Level 3 Regional Impact  — source FIR + directly adjacent FIRs
            //   Level 4 NAS-Wide Impact  — beyond the 1-hop neighborhood
            // Adjacency graph lives in Atfm\Allocator\FirMap. Max of the
            // derived level and the highest per-restriction op_level wins.
            $affectedAirportIcaos = $restrictionRows
                ->map(fn ($r) => $r->airport?->icao)
                ->filter()
                ->unique()
                ->values()
                ->all();
            $affectedAirports = count($affectedAirportIcaos);
            $affectedFirs     = \Atfm\Allocator\FirMap::affectedFirs($affectedAirportIcaos);
            $derived          = \Atfm\Allocator\FirMap::deriveOpLevel($affectedAirportIcaos);
            $maxTagged        = (int) $restrictionRows->max(fn ($r) => (int) ($r->op_level ?? 2));
            $systemOpLevel    = max($derived, $maxTagged ?: 1);

            return self::json($res, [
                'time_utc'             => $now->format('c'),
                'airport_count'        => $airportCount,
                'active_flight_count'  => $activeFlights,
                'active_ctot_count'    => $activeCtots,
                'active_restrictions'  => $restrictionRows->count(),
                'affected_airports'    => $affectedAirports,
                'affected_airport_icaos' => $affectedAirportIcaos,
                'affected_firs'        => $affectedFirs,
                'op_level'             => $systemOpLevel,
                'op_level_label'       => AirportRestriction::OP_LEVEL_LABELS[$systemOpLevel] ?? '',
                'last_ingest_at'       => $lastFlightUpdate,
                'last_allocation_at'   => $lastRun?->started_at?->format('c'),
                'last_allocation_stats' => $lastRun ? [
                    'airports_considered' => (int) $lastRun->airports_considered,
                    'restrictions_active' => (int) $lastRun->restrictions_active,
                    'flights_evaluated'   => (int) $lastRun->flights_evaluated,
                    'ctots_frozen_kept'   => (int) $lastRun->ctots_frozen_kept,
                    'ctots_issued'        => (int) $lastRun->ctots_issued,
                    'ctots_released'      => (int) $lastRun->ctots_released,
                    'ctots_reissued'      => (int) $lastRun->ctots_reissued,
                    'elapsed_ms'          => (int) $lastRun->elapsed_ms,
                ] : null,
                'version'              => '0.3',
            ]);
        });

        // All currently active restrictions across airports — for dashboard display
        $app->get('/api/v1/restrictions', function ($req, $res) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $rows = AirportRestriction::query()
                ->whereNull('deleted_at')
                ->where('active_from', '<=', $now->format('Y-m-d H:i:s'))
                ->where(function ($q) use ($now) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>=', $now->format('Y-m-d H:i:s'));
                })
                ->with('airport')
                ->get()
                ->filter(fn (AirportRestriction $r) => $r->isActiveAt($now))
                ->map(fn (AirportRestriction $r) => [
                    'restriction_id'              => $r->restriction_id,
                    'airport_icao'                => $r->airport?->icao,
                    'airport_name'                => $r->airport?->name,
                    'capacity'                    => (int) $r->capacity,
                    'reason'                      => $r->reason,
                    'op_level'                    => (int) ($r->op_level ?? 2),
                    'op_level_label'              => AirportRestriction::OP_LEVEL_LABELS[(int) ($r->op_level ?? 2)] ?? '',
                    'type'                        => $r->type,
                    'runway'                      => $r->runway,
                    'tier_minutes'                => (int) $r->tier_minutes,
                    'compliance_window_early_min' => (int) $r->compliance_window_early_min,
                    'compliance_window_late_min'  => (int) $r->compliance_window_late_min,
                    'start_utc'                   => $r->start_utc,
                    'end_utc'                     => $r->end_utc,
                    'active_from'                 => $r->active_from?->format('c'),
                    'expires_at'                  => $r->expires_at?->format('c'),
                ])
                ->values()
                ->all();
            return self::json($res, $rows);
        });

        // Airports
        $app->get('/api/v1/airports', function ($req, $res) {
            $airports = Airport::with(['thresholds', 'restrictions' => function ($q) {
                $q->whereNull('deleted_at');
            }])->orderBy('icao')->get();

            // Per-airport live traffic counts. DISCONNECTED flights are
            // excluded from live counts — they can re-animate but shouldn't
            // clutter the "what's happening now" view.
            $excludePhases = [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED];
            $out = $airports->map(function (Airport $a) use ($excludePhases) {
                $arr = $a->toArray();
                $arr['inbound_count'] = Flight::where('ades', $a->icao)
                    ->whereNotIn('phase', $excludePhases)
                    ->count();
                $arr['outbound_count'] = Flight::where('adep', $a->icao)
                    ->whereNotIn('phase', $excludePhases)
                    ->count();
                return $arr;
            });
            return self::json($res, $out->toArray());
        });

        $app->get('/api/v1/airports/{icao}', function ($req, $res, array $args) {
            $a = Airport::with('thresholds')->where('icao', strtoupper($args['icao']))->first();
            return $a
                ? self::json($res, $a->toArray())
                : self::json($res->withStatus(404), ['error' => 'not found']);
        });

        // Composite detail view for the dashboard airport-card click.
        // Returns airport meta, active restrictions, current inbound + outbound
        // flights, recent arrivals, runway thresholds, and rolling movement
        // stats in a single call.
        $app->get('/api/v1/airports/{icao}/detail', function ($req, $res, array $args) {
            $icao = strtoupper($args['icao']);
            $airport = Airport::with(['thresholds', 'restrictions' => function ($q) {
                $q->whereNull('deleted_at');
            }])->where('icao', $icao)->first();

            if (! $airport) {
                return self::json($res->withStatus(404), ['error' => 'not found']);
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            // Current inbound (not yet terminal, not disconnected)
            $liveExclude = [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED];
            $inbound = Flight::where('ades', $icao)
                ->whereNotIn('phase', $liveExclude)
                ->orderByRaw('COALESCE(ctot, eldt, eobt) ASC')
                ->limit(100)
                ->get()
                ->map(fn (Flight $f) => [
                    'callsign'        => $f->callsign,
                    'cid'             => (int) $f->cid,
                    'aircraft_type'   => $f->aircraft_type,
                    'adep'            => $f->adep,
                    'phase'           => $f->phase,
                    'eobt'            => $f->eobt?->format('c'),
                    'ttot'            => $f->ttot?->format('c'),
                    'eldt'            => $f->eldt?->format('c'),
                    'ctot'            => $f->ctot?->format('c'),
                    'cta'             => $f->cta?->format('c'),
                    'delay_minutes'   => $f->delay_minutes,
                    'delay_status'    => $f->delay_status,
                    'last_gs'         => $f->last_groundspeed_kts,
                    'last_alt'        => $f->last_altitude_ft,
                ])->values()->all();

            // Current outbound (not yet terminal, not disconnected)
            $outbound = Flight::where('adep', $icao)
                ->whereNotIn('phase', $liveExclude)
                ->orderBy('eobt')
                ->limit(100)
                ->get()
                ->map(fn (Flight $f) => [
                    'callsign'      => $f->callsign,
                    'cid'           => (int) $f->cid,
                    'aircraft_type' => $f->aircraft_type,
                    'ades'          => $f->ades,
                    'phase'         => $f->phase,
                    'eobt'          => $f->eobt?->format('c'),
                    'tobt'          => $f->tobt?->format('c'),
                    'tsat'          => $f->tsat?->format('c'),
                    'ttot'          => $f->ttot?->format('c'),
                    'ctot'          => $f->ctot?->format('c'),
                    'atot'          => $f->atot?->format('c'),
                    'delay_minutes' => $f->delay_minutes,
                ])->values()->all();

            // Recent arrivals (last 6h, terminal)
            $since = $now->modify('-6 hours');
            $recentArrivals = Flight::where('ades', $icao)
                ->where('phase', Flight::PHASE_ARRIVED)
                ->where('aldt', '>=', $since->format('Y-m-d H:i:s'))
                ->orderBy('aldt', 'desc')
                ->limit(50)
                ->get()
                ->map(fn (Flight $f) => [
                    'callsign'        => $f->callsign,
                    'aircraft_type'   => $f->aircraft_type,
                    'adep'            => $f->adep,
                    'aldt'            => $f->aldt?->format('c'),
                    'aibt'            => $f->aibt?->format('c'),
                    'actual_exit_min' => $f->actual_exit_min,
                ])->values()->all();

            // Recent departures (last 6h)
            $recentDepartures = Flight::where('adep', $icao)
                ->whereNotNull('atot')
                ->where('atot', '>=', $since->format('Y-m-d H:i:s'))
                ->orderBy('atot', 'desc')
                ->limit(50)
                ->get()
                ->map(function (Flight $f) {
                    $eobtDelayMin = null;
                    if ($f->eobt && $f->aobt) {
                        $eobtDelayMin = (int) round(
                            ($f->aobt->getTimestamp() - $f->eobt->getTimestamp()) / 60
                        );
                    }
                    return [
                        'callsign'        => $f->callsign,
                        'aircraft_type'   => $f->aircraft_type,
                        'ades'            => $f->ades,
                        'eobt'            => $f->eobt?->format('c'),
                        'aobt'            => $f->aobt?->format('c'),
                        'atot'            => $f->atot?->format('c'),
                        'actual_exot_min' => $f->actual_exot_min,
                        'eobt_delay_min'  => $eobtDelayMin,
                        'delay_minutes'   => $f->delay_minutes,
                    ];
                })->values()->all();

            // Hourly-bucketed movement counts for the last 24 hours
            $hourly = [];
            for ($i = 23; $i >= 0; $i--) {
                $bucketStart = $now->modify("-{$i} hours")->setTime((int) $now->modify("-{$i} hours")->format('H'), 0, 0);
                $bucketEnd   = $bucketStart->modify('+1 hour');
                $arr = Flight::where('ades', $icao)
                    ->whereBetween('aldt', [$bucketStart->format('Y-m-d H:i:s'), $bucketEnd->format('Y-m-d H:i:s')])
                    ->count();
                $dep = Flight::where('adep', $icao)
                    ->whereBetween('atot', [$bucketStart->format('Y-m-d H:i:s'), $bucketEnd->format('Y-m-d H:i:s')])
                    ->count();
                $hourly[] = [
                    'hour'       => $bucketStart->format('Hi') . 'Z',
                    'arrivals'   => $arr,
                    'departures' => $dep,
                ];
            }

            // Rolling stats
            $lastHour = $now->modify('-1 hour');
            $arrivalsLastHour = Flight::where('ades', $icao)
                ->where('aldt', '>=', $lastHour->format('Y-m-d H:i:s'))
                ->count();
            $departuresLastHour = Flight::where('adep', $icao)
                ->whereNotNull('atot')
                ->where('atot', '>=', $lastHour->format('Y-m-d H:i:s'))
                ->count();
            $avgExotLastDay = Flight::where('adep', $icao)
                ->where('atot', '>=', $now->modify('-24 hours')->format('Y-m-d H:i:s'))
                ->whereNotNull('actual_exot_min')
                ->avg('actual_exot_min');
            $avgExitLastDay = Flight::where('ades', $icao)
                ->where('aibt', '>=', $now->modify('-24 hours')->format('Y-m-d H:i:s'))
                ->whereNotNull('actual_exit_min')
                ->avg('actual_exit_min');

            return self::json($res, [
                'airport'           => [
                    'icao'                  => $airport->icao,
                    'name'                  => $airport->name,
                    'latitude'              => (float) $airport->latitude,
                    'longitude'             => (float) $airport->longitude,
                    'elevation_ft'          => (int) $airport->elevation_ft,
                    'base_arrival_rate'     => (int) $airport->base_arrival_rate,
                    'observed_arrival_rate' => $airport->observed_arrival_rate,
                    'observed_rate_sample_n'=> (int) $airport->observed_rate_sample_n,
                    'base_departure_rate'   => (int) $airport->base_departure_rate,
                    'default_exot_min'      => (int) $airport->default_exot_min,
                    'default_exit_min'      => (int) $airport->default_exit_min,
                    'is_cdm_airport'        => (bool) $airport->is_cdm_airport,
                    'arrived_geofence_nm'   => (int) $airport->arrived_geofence_nm,
                    'final_threshold_nm'    => (int) $airport->final_threshold_nm,
                ],
                'restrictions'      => $airport->restrictions->map(fn (AirportRestriction $r) => [
                    'restriction_id'              => $r->restriction_id,
                    'capacity'                    => (int) $r->capacity,
                    'reason'                      => $r->reason,
                    'op_level'                    => (int) ($r->op_level ?? 2),
                    'op_level_label'              => AirportRestriction::OP_LEVEL_LABELS[(int) ($r->op_level ?? 2)] ?? '',
                    'type'                        => $r->type,
                    'runway'                      => $r->runway,
                    'tier_minutes'                => (int) $r->tier_minutes,
                    'compliance_window_early_min' => (int) $r->compliance_window_early_min,
                    'compliance_window_late_min'  => (int) $r->compliance_window_late_min,
                    'start_utc'                   => $r->start_utc,
                    'end_utc'                     => $r->end_utc,
                    'active_from'                 => $r->active_from?->format('c'),
                    'expires_at'                  => $r->expires_at?->format('c'),
                ])->values(),
                'runways'           => $airport->thresholds->map(fn (RunwayThreshold $t) => [
                    'runway_ident'           => $t->runway_ident,
                    'heading_deg'            => (int) $t->heading_deg,
                    'threshold_lat'          => (float) $t->threshold_lat,
                    'threshold_lon'          => (float) $t->threshold_lon,
                    'opposite_threshold_lat' => (float) $t->opposite_threshold_lat,
                    'opposite_threshold_lon' => (float) $t->opposite_threshold_lon,
                    'width_ft'               => (int) $t->width_ft,
                ])->values(),
                'inbound'           => $inbound,
                'outbound'          => $outbound,
                'recent_arrivals'   => $recentArrivals,
                'recent_departures' => $recentDepartures,
                'hourly_movements'  => $hourly,
                'stats'             => [
                    'arrivals_last_hour'   => $arrivalsLastHour,
                    'departures_last_hour' => $departuresLastHour,
                    'avg_actual_exot_min'  => $avgExotLastDay !== null ? round((float) $avgExotLastDay, 1) : null,
                    'avg_actual_exit_min'  => $avgExitLastDay !== null ? round((float) $avgExitLastDay, 1) : null,
                ],
            ]);
        });

        $app->post('/api/v1/airports', function ($req, $res) {
            $body = (array) $req->getParsedBody();
            $airport = Airport::updateOrCreate(
                ['icao' => strtoupper((string) ($body['icao'] ?? ''))],
                $body
            );
            return self::json($res, $airport->toArray());
        });

        // Airport restrictions
        $app->get('/api/v1/airports/{icao}/restrictions', function ($req, $res, array $args) {
            $airport = Airport::where('icao', strtoupper($args['icao']))->first();
            if (! $airport) {
                return self::json($res->withStatus(404), ['error' => 'airport not found']);
            }
            return self::json($res, $airport->restrictions()->get()->toArray());
        });

        $app->post('/api/v1/airports/{icao}/restrictions', function ($req, $res, array $args) {
            $airport = Airport::where('icao', strtoupper($args['icao']))->first();
            if (! $airport) {
                return self::json($res->withStatus(404), ['error' => 'airport not found']);
            }
            $body = (array) $req->getParsedBody();
            $r = new AirportRestriction();
            $r->restriction_id = AirportRestriction::generateId($airport->icao);
            $r->airport_id     = $airport->id;
            $r->capacity       = (int) ($body['capacity'] ?? $airport->base_arrival_rate);
            $r->reason         = (string) ($body['reason'] ?? 'ATC_CAPACITY');
            $r->op_level       = max(1, min(4, (int) ($body['op_level'] ?? 2)));
            $r->type           = (string) ($body['type']   ?? 'ARR');
            $r->runway         = $body['runway'] ?? null;
            $r->runway_config  = $body['runway_config'] ?? null;
            $r->tier_minutes   = (int) ($body['tier_minutes'] ?? 120);
            $r->compliance_window_early_min = (int) ($body['compliance_window_early_min'] ?? 5);
            $r->compliance_window_late_min  = (int) ($body['compliance_window_late_min']  ?? 5);
            $r->start_utc      = (string) ($body['start_utc'] ?? '0000');
            $r->end_utc        = (string) ($body['end_utc']   ?? '2359');
            $r->active_from    = new DateTimeImmutable('now');
            if (! empty($body['expires_at'])) {
                $r->expires_at = new DateTimeImmutable((string) $body['expires_at']);
            }
            $r->save();
            return self::json($res, $r->toArray());
        });

        $app->delete('/api/v1/airport-restrictions/{id}', function ($req, $res, array $args) {
            $r = AirportRestriction::where('restriction_id', $args['id'])->first();
            if (! $r) {
                return self::json($res->withStatus(404), ['error' => 'not found']);
            }
            $r->delete();
            return self::json($res, ['ok' => true]);
        });

        // Event sources
        $app->get('/api/v1/event-sources', function ($req, $res) {
            return self::json($res, EventSource::orderBy('event_code')->get()->toArray());
        });

        $app->post('/api/v1/event-sources', function ($req, $res) {
            $body = (array) $req->getParsedBody();
            $e = EventSource::updateOrCreate(
                ['event_code' => (string) ($body['event_code'] ?? '')],
                [
                    'label'     => (string) ($body['label'] ?? ''),
                    'start_utc' => ! empty($body['start_utc']) ? new DateTimeImmutable($body['start_utc']) : null,
                    'end_utc'   => ! empty($body['end_utc'])   ? new DateTimeImmutable($body['end_utc'])   : null,
                    'active'    => (bool) ($body['active'] ?? true),
                ],
            );
            return self::json($res, $e->toArray());
        });

        $app->delete('/api/v1/event-sources/{event_code}', function ($req, $res, array $args) {
            $e = EventSource::where('event_code', $args['event_code'])->first();
            if (! $e) {
                return self::json($res->withStatus(404), ['error' => 'not found']);
            }
            $e->delete();
            return self::json($res, ['ok' => true]);
        });
    }

    // ------------------------------------------------------------------
    //  Reports endpoints
    // ------------------------------------------------------------------

    private static function registerReportsEndpoints(App $app): void
    {
        // Compact per-airport rollup for the reports page.
        //
        // Note on terminology — per EUROCONTROL Airport CDM Implementation
        // Manual (Mar 2017): the metrics labelled "exot/exit" in this
        // payload are actually AXOT (ATOT − AOBT) and AXIT (AIBT − ALDT),
        // i.e. the *actual* taxi times. Schema column names are kept for
        // backwards compat (`actual_exot_min`, `actual_exit_min`) but the
        // displayed labels in reports.html use the canonical AXOT/AXIT.
        //
        // Query param: ?hours=N  (default 24, max 168)
        $app->get('/api/v1/reports/summary', function ($req, $res) {
            $hours = max(1, min(168, (int) ($req->getQueryParams()['hours'] ?? 24)));
            $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $since = $now->modify("-{$hours} hours");
            $sinceStr = $since->format('Y-m-d H:i:s');

            $airports = Airport::orderBy('icao')->get();
            $rows = [];

            foreach ($airports as $a) {
                $arrivals = Flight::where('ades', $a->icao)
                    ->where('aldt', '>=', $sinceStr)
                    ->get(['aldt', 'aibt', 'actual_exit_min', 'fp_enroute_time_min', 'atot', 'aobt', 'eobt']);

                $departures = Flight::where('adep', $a->icao)
                    ->whereNotNull('atot')
                    ->where('atot', '>=', $sinceStr)
                    ->get(['eobt', 'aobt', 'atot', 'actual_exot_min']);

                $exotValues = $departures->pluck('actual_exot_min')->filter()->values()->all();
                $exitValues = $arrivals->pluck('actual_exit_min')->filter()->values()->all();

                $eobtDelays = [];
                foreach ($departures as $f) {
                    if ($f->eobt && $f->aobt) {
                        $eobtDelays[] = (int) round(($f->aobt->getTimestamp() - $f->eobt->getTimestamp()) / 60);
                    }
                }

                // ETA errors: (actual aldt) - (aobt + enroute_time)
                $etaErrors = [];
                foreach ($arrivals as $f) {
                    if ($f->aldt && $f->aobt && $f->fp_enroute_time_min) {
                        $predicted = $f->aobt->getTimestamp() + ($f->fp_enroute_time_min * 60);
                        $etaErrors[] = (int) round(($f->aldt->getTimestamp() - $predicted) / 60);
                    }
                }

                $rows[] = [
                    'icao'                 => $a->icao,
                    'name'                 => $a->name,
                    'base_arrival_rate'    => (int) $a->base_arrival_rate,
                    'arrivals'             => $arrivals->count(),
                    'departures'           => $departures->count(),
                    'avg_exot_min'         => self::avg($exotValues),
                    'p50_exot_min'         => self::percentile($exotValues, 50),
                    'p90_exot_min'         => self::percentile($exotValues, 90),
                    'avg_exit_min'         => self::avg($exitValues),
                    'p50_exit_min'         => self::percentile($exitValues, 50),
                    'p90_exit_min'         => self::percentile($exitValues, 90),
                    'avg_eobt_delay_min'   => self::avg($eobtDelays),
                    'p90_eobt_delay_min'   => self::percentile($eobtDelays, 90),
                    'avg_eta_error_min'    => self::avg($etaErrors),
                    'p50_eta_error_min'    => self::percentile($etaErrors, 50),
                    'p90_eta_error_min'    => self::percentile($etaErrors, 90),
                    'eta_sample_n'         => count($etaErrors),
                ];
            }

            // Overall totals
            $totalFlights = Flight::where('last_updated_at', '>=', $sinceStr)->count();
            $ctotsIssued  = Flight::whereNotNull('ctot')
                ->where('updated_at', '>=', $sinceStr)
                ->count();
            $compliant    = Flight::where('delay_status', Flight::DELAY_COMPLIANT_DEPARTED)
                ->where('updated_at', '>=', $sinceStr)
                ->count();
            $nonCompliant = Flight::where('delay_status', Flight::DELAY_NON_COMPLIANT)
                ->where('updated_at', '>=', $sinceStr)
                ->count();

            // Aircraft type distribution (top 20)
            $typeRows = Flight::selectRaw('aircraft_type, COUNT(*) as n, AVG(actual_exot_min) as avg_exot, AVG(actual_exit_min) as avg_exit')
                ->whereNotNull('aircraft_type')
                ->where('last_updated_at', '>=', $sinceStr)
                ->groupBy('aircraft_type')
                ->orderByDesc('n')
                ->limit(20)
                ->get()
                ->map(fn ($r) => [
                    'aircraft_type' => $r->aircraft_type,
                    'count'         => (int) $r->n,
                    'avg_exot_min'  => $r->avg_exot !== null ? round((float) $r->avg_exot, 1) : null,
                    'avg_exit_min'  => $r->avg_exit !== null ? round((float) $r->avg_exit, 1) : null,
                ])
                ->all();

            return self::json($res, [
                'generated_at' => $now->format('c'),
                'window' => [
                    'hours' => $hours,
                    'start' => $since->format('c'),
                    'end'   => $now->format('c'),
                ],
                'totals' => [
                    'flights_seen'        => $totalFlights,
                    'ctots_issued'        => $ctotsIssued,
                    'compliant_departed'  => $compliant,
                    'non_compliant'       => $nonCompliant,
                    'compliance_rate'     => ($compliant + $nonCompliant) > 0
                        ? round($compliant / ($compliant + $nonCompliant), 3)
                        : null,
                ],
                'airports'     => $rows,
                'top_aircraft' => $typeRows,
            ]);
        });
    }

    private static function avg(array $xs): ?float
    {
        if (empty($xs)) return null;
        return round(array_sum($xs) / count($xs), 1);
    }

    private static function percentile(array $xs, int $p): ?float
    {
        if (empty($xs)) return null;
        sort($xs);
        $k = ($p / 100) * (count($xs) - 1);
        $f = floor($k); $c = ceil($k);
        if ($f === $c) return round($xs[(int) $k], 1);
        return round($xs[(int) $f] + ($k - $f) * ($xs[(int) $c] - $xs[(int) $f]), 1);
    }

    // ------------------------------------------------------------------
    //  Debug endpoints
    // ------------------------------------------------------------------

    private static function registerDebugEndpoints(App $app): void
    {
        $app->get('/api/v1/debug/traffic', function ($req, $res) {
            $airport = strtoupper((string) ($req->getQueryParams()['airport'] ?? ''));
            $query = Flight::query()
                ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN]);
            if ($airport !== '') {
                $query->where(function ($q) use ($airport) {
                    $q->where('adep', $airport)->orWhere('ades', $airport);
                });
            }
            return self::json($res, $query->orderBy('last_updated_at', 'desc')->limit(200)->get()->toArray());
        });

        $app->get('/api/v1/debug/flights/active', function ($req, $res) {
            $flights = Flight::whereNotNull('ctot')
                ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED])
                ->orderBy('ctot')
                ->get(['callsign', 'cid', 'aircraft_type', 'adep', 'ades', 'eobt', 'ttot', 'ctot', 'ctl_element', 'ctl_type', 'delay_minutes', 'delay_status', 'phase']);
            return self::json($res, $flights->toArray());
        });

        $app->get('/api/v1/debug/allocation', function ($req, $res) {
            $runs = AllocationRun::orderBy('started_at', 'desc')->limit(20)->get();
            return self::json($res, $runs->toArray());
        });

        $app->get('/api/v1/debug/imported-ctots', function ($req, $res) {
            $rows = ImportedCtot::where('active', true)->orderBy('ctot')->limit(200)->get();
            return self::json($res, $rows->toArray());
        });

        $app->get('/api/v1/debug/runway-thresholds', function ($req, $res) {
            return self::json($res, RunwayThreshold::orderBy('airport_icao')->orderBy('runway_ident')->get()->toArray());
        });
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private static function formatIsoUtc(\DateTimeInterface $dt): string
    {
        return (new \DateTimeImmutable('@' . $dt->getTimestamp()))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private static function serializeMeasureForPlugin(FlowMeasure $fm): array
    {
        $filters = is_array($fm->filters) ? $fm->filters : [];
        return [
            'id'         => (int) $fm->id,
            'ident'      => (string) $fm->identifier,
            'event_id'   => null,
            'reason'     => (string) $fm->reason,
            'starttime'  => self::formatIsoUtc($fm->start_time),
            'endtime'    => self::formatIsoUtc($fm->end_time),
            'measure'    => [
                'type'  => (string) $fm->type,
                'value' => $fm->value,
            ],
            'filters'    => $filters,
            'notified_flight_information_regions' => $fm->fir_id !== null ? [(int) $fm->fir_id] : [],
            'withdrawn_at' => $fm->deleted_at ? self::formatIsoUtc($fm->deleted_at) : null,
        ];
    }

    private static function json(ResponseInterface $res, mixed $payload): ResponseInterface
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json');
    }
}
