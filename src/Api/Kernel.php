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
        self::registerDebugEndpoints($app);

        return $app;
    }

    // ------------------------------------------------------------------
    //  Health
    // ------------------------------------------------------------------

    private static function registerHealth(App $app): void
    {
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
                ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])
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
        // Airports
        $app->get('/api/v1/airports', function ($req, $res) {
            return self::json($res, Airport::with('thresholds')->orderBy('icao')->get()->toArray());
        });

        $app->get('/api/v1/airports/{icao}', function ($req, $res, array $args) {
            $a = Airport::with('thresholds')->where('icao', strtoupper($args['icao']))->first();
            return $a
                ? self::json($res, $a->toArray())
                : self::json($res->withStatus(404), ['error' => 'not found']);
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
                ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])
                ->orderBy('ctot')
                ->get(['callsign', 'cid', 'adep', 'ades', 'eobt', 'ttot', 'ctot', 'ctl_element', 'ctl_type', 'delay_minutes', 'delay_status', 'phase']);
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
