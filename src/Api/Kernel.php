<?php

declare(strict_types=1);

namespace Atfm\Api;

use Atfm\Allocator\Geo;
use Atfm\Auth\VatsimOAuth;
use Atfm\Models\Airport;
use Atfm\Models\AirportRestriction;
use Atfm\Models\AllocationRun;
use Atfm\Models\AuthSession;
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
        self::registerFlightsApi($app);
        self::registerTobtEndpoint($app);
        self::registerAdminEndpoints($app);
        self::registerReportsEndpoints($app);
        self::registerDebugEndpoints($app);
        self::registerEmergencyEndpoints($app);
        self::registerAuthEndpoints($app);

        return $app;
    }

    /**
     * Current authenticated user from the cookie, or null if not logged in.
     * Called by route handlers that want to know who the caller is.
     *
     * @return array{cid:int, name:string, rating:string, division:string, raw:array}|null
     */
    public static function currentUser(ServerRequestInterface $req): ?array
    {
        $token = $req->getCookieParams()['atfm_session'] ?? null;
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $s = AuthSession::where('token', $token)
            ->where('expires_at', '>', $now)
            ->first();
        if (!$s) return null;
        $s->last_seen_at = $now;
        $s->save();
        $d = $s->user_data ?: [];
        // Attach live-connection info so downstream Gate checks can read it
        // without re-polling the VATSIM feed. Cached feed read is cheap.
        $live = self::lookupLiveConnection((int) $s->vatsim_cid);
        return [
            'cid'      => (int) $s->vatsim_cid,
            'name'     => $d['personal']['name_full'] ?? ('CID ' . $s->vatsim_cid),
            'rating'   => $d['vatsim']['rating']['short'] ?? null,
            'division' => $d['vatsim']['division']['id']  ?? null,
            'raw'      => array_merge($d, ['live' => $live]),
        ];
    }

    private static function registerAuthEndpoints(App $app): void
    {
        // Starts the VATSIM OAuth flow. Sets a CSRF state cookie and
        // redirects to VATSIM's authorize endpoint. Optional ?return= to
        // come back to a specific page after login.
        $app->get('/oauth/vatsim/login', function (ServerRequestInterface $req, ResponseInterface $res) {
            if (!VatsimOAuth::isConfigured()) {
                return self::json($res->withStatus(503), [
                    'error' => 'VATSIM OAuth not configured — set VATSIM_OAUTH_CLIENT_ID/_SECRET in .env',
                ]);
            }
            $state = bin2hex(random_bytes(16));
            $returnTo = (string) ($req->getQueryParams()['return'] ?? '/');
            // Whitelist return paths to prevent open-redirect
            if (!preg_match('#^/[A-Za-z0-9_\-./?=&]{0,120}$#', $returnTo)) $returnTo = '/';
            $url = VatsimOAuth::authorizeUrl($state, $returnTo);
            $secure = ($_ENV['APP_URL'] ?? '') && str_starts_with($_ENV['APP_URL'], 'https:');
            $cookie = sprintf(
                'atfm_oauth_state=%s; Path=/; Max-Age=600; HttpOnly; SameSite=Lax%s',
                $state, $secure ? '; Secure' : ''
            );
            return $res->withStatus(302)
                ->withHeader('Location', $url)
                ->withHeader('Set-Cookie', $cookie);
        });

        // VATSIM redirects here with ?code=... & state=...
        $app->get('/oauth/vatsim/callback', function (ServerRequestInterface $req, ResponseInterface $res) {
            $q = $req->getQueryParams();
            $code = (string) ($q['code'] ?? '');
            $state = (string) ($q['state'] ?? '');
            if ($code === '' || $state === '') {
                return self::json($res->withStatus(400), ['error' => 'missing code or state']);
            }
            $cookieState = $req->getCookieParams()['atfm_oauth_state'] ?? '';
            [$stateNonce, $returnB64] = array_pad(explode('|', $state, 2), 2, '');
            if (!hash_equals($cookieState, $stateNonce)) {
                return self::json($res->withStatus(400), ['error' => 'state mismatch (CSRF)']);
            }
            $returnTo = $returnB64 ? base64_decode($returnB64) : '/';
            if (!is_string($returnTo) || !preg_match('#^/#', $returnTo)) $returnTo = '/';

            $tok = VatsimOAuth::exchangeCode($code);
            if (!$tok) {
                return self::json($res->withStatus(502), ['error' => 'token exchange failed']);
            }
            $user = VatsimOAuth::fetchUser($tok['access_token']);
            if (!$user || empty($user['cid'])) {
                return self::json($res->withStatus(502), ['error' => 'user fetch failed']);
            }

            // Create session
            $token = AuthSession::generateToken();
            $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+30 days');
            AuthSession::create([
                'token'        => $token,
                'vatsim_cid'   => (int) $user['cid'],
                'user_data'    => $user,
                'expires_at'   => $expires->format('Y-m-d H:i:s'),
                'last_seen_at' => $expires->modify('-30 days')->format('Y-m-d H:i:s'),
            ]);
            $secure = ($_ENV['APP_URL'] ?? '') && str_starts_with($_ENV['APP_URL'], 'https:');
            $sessionCookie = sprintf(
                'atfm_session=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax%s',
                $token, 60 * 60 * 24 * 30, $secure ? '; Secure' : ''
            );
            // Clear the state cookie
            $clearState = 'atfm_oauth_state=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax';
            return $res->withStatus(302)
                ->withHeader('Location', $returnTo)
                ->withHeader('Set-Cookie', $sessionCookie)
                ->withAddedHeader('Set-Cookie', $clearState);
        });

        // Logout — delete session record + clear cookie
        $app->post('/oauth/logout', function (ServerRequestInterface $req, ResponseInterface $res) {
            $token = $req->getCookieParams()['atfm_session'] ?? null;
            if ($token) AuthSession::where('token', $token)->delete();
            $clear = 'atfm_session=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax';
            return $res->withStatus(204)->withHeader('Set-Cookie', $clear);
        });

        // Who-am-I — powers the UI's "Sign in with VATSIM" button vs
        // "Logged in as X" indicator. Also returns the user's current
        // live connection (if online) so role-based views can adapt.
        $app->get('/api/v1/auth/me', function (ServerRequestInterface $req, ResponseInterface $res) {
            $u = self::currentUser($req);
            if (!$u) return self::json($res, ['authenticated' => false]);

            // Augment with current VATSIM network position (if online).
            // Reads from the VatsimIngestor's cached controller data if
            // available; otherwise polls VATSIM v3 data feed directly.
            $live = self::lookupLiveConnection($u['cid']);

            return self::json($res, [
                'authenticated' => true,
                'cid'           => $u['cid'],
                'name'          => $u['name'],
                'rating'        => $u['rating'],
                'division'      => $u['division'],
                'live'          => $live,
            ]);
        });
    }

    /**
     * Look up a user's current VATSIM network connection (if online).
     * Returns {type: 'PILOT'|'CONTROLLER'|null, callsign, facility, ...}.
     */
    private static function lookupLiveConnection(int $cid): ?array
    {
        // Feed is poll-cached by the ingestor; also check a local cache
        // here so /auth/me is fast.
        $cacheFile = sys_get_temp_dir() . '/atfm_vatsim_feed.json';
        $raw = null;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 60) {
            $raw = @file_get_contents($cacheFile);
        }
        if (!$raw) {
            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $raw = @file_get_contents('https://data.vatsim.net/v3/vatsim-data.json', false, $ctx);
            if ($raw) @file_put_contents($cacheFile, $raw);
        }
        if (!$raw) return null;
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;

        foreach (($data['pilots'] ?? []) as $p) {
            if ((int) ($p['cid'] ?? 0) === $cid) {
                return [
                    'type'      => 'PILOT',
                    'callsign'  => $p['callsign'] ?? null,
                    'departure' => $p['flight_plan']['departure'] ?? null,
                    'arrival'   => $p['flight_plan']['arrival'] ?? null,
                ];
            }
        }
        foreach (($data['controllers'] ?? []) as $c) {
            if ((int) ($c['cid'] ?? 0) === $cid) {
                return [
                    'type'      => 'CONTROLLER',
                    'callsign'  => $c['callsign'] ?? null,
                    'facility'  => $c['facility'] ?? null,
                    'frequency' => $c['frequency'] ?? null,
                ];
            }
        }
        foreach (($data['atis'] ?? []) as $a) {
            if ((int) ($a['cid'] ?? 0) === $cid) {
                return [
                    'type'     => 'ATIS',
                    'callsign' => $a['callsign'] ?? null,
                ];
            }
        }
        return null;
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
                'version' => \Atfm\Version::STRING,
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
        // The ONE endpoint we actually serve real data on in Mode A
        // (customRestricted-only override). See docs/CDM-PLUGIN.md for the
        // full contract. Plugin polls every ~15 s per active master (fixed
        // gate in CDMSingle.cpp::main loop, NOT the RefreshTime XML setting).
        // Response: bare JSON array of {callsign, ctot, mostPenalizingAirspace}.
        // CTOT must be EXACTLY 4 chars (HHMM, zero-padded) or plugin silently drops it.
        // Omitting a callsign is authoritative — plugin clears that flight's CTOT.
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
        // setCdmData — the CDM plugin pushes TOBT/TSAT/TTOT/CTOT/reason/depInfo
        // back to the server whenever a controller edits times in the EuroScope tag.
        // This is the write-back half of the A-CDM round trip.
        // Query params: callsign, tobt, tsat, ttot, ctot, reason, asrt, depInfo
        // All time values are HHMM (4 chars, zero-padded).
        $app->get('/cdm/ifps/setCdmData', function ($req, $res) {
            $params = $req->getQueryParams();
            $callsign = strtoupper(trim($params['callsign'] ?? ''));
            if ($callsign === '') {
                return self::json($res, ['ok' => false, 'error' => 'missing callsign']);
            }

            $flight = Flight::where('callsign', $callsign)
                ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])
                ->first();

            if (!$flight) {
                return self::json($res, ['ok' => true]); // don't error — plugin sends for all flights
            }

            $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
            $parseHhmm = function (string $hhmm) use ($today): ?DateTimeImmutable {
                $hhmm = trim($hhmm);
                if (strlen($hhmm) < 4 || !ctype_digit(substr($hhmm, 0, 4))) return null;
                try {
                    return new DateTimeImmutable($today . ' ' . substr($hhmm, 0, 2) . ':' . substr($hhmm, 2, 2) . ':00', new DateTimeZone('UTC'));
                } catch (\Exception $e) {
                    return null;
                }
            };

            $changed = false;
            $tobt = $parseHhmm($params['tobt'] ?? '');
            if ($tobt !== null) {
                $flight->tobt = $tobt;
                $flight->tobt_source = 'cdm';
                $changed = true;
            }
            $tsat = $parseHhmm($params['tsat'] ?? '');
            if ($tsat !== null) { $flight->tsat = $tsat; $changed = true; }
            $ttot = $parseHhmm($params['ttot'] ?? '');
            if ($ttot !== null) { $flight->ttot = $ttot; $changed = true; }

            // depInfo = "RWY/SID" e.g. "05/JEDLI4"
            $depInfo = trim($params['depInfo'] ?? '');
            if ($depInfo !== '') {
                $parts = explode('/', $depInfo, 2);
                $flight->departure_runway = $parts[0];
                $changed = true;
            }

            if ($changed) {
                $flight->save();
            }

            return self::json($res, ['ok' => true]);
        });
        $app->get('/cdm/airport/setMaster',  fn ($req, $res) => self::json($res, ['ok' => true]));
        $app->get('/cdm/airport/removeMaster', fn ($req, $res) => self::json($res, ['ok' => true]));
        $app->get('/cdm/airport/removeAllMasterByPosition', fn ($req, $res) => self::json($res, ['ok' => true]));
        $app->get('/cdm/airport/removeAllMasterByAirport', fn ($req, $res) => self::json($res, ['ok' => true]));
    }

    // ------------------------------------------------------------------
    //  Flights API — /api/v1/flights
    //  PERTI SWIM v1-compatible schema with atfm-tools A-CDM extensions.
    //  Designed for Jeremy (vATCSCC/PERTI) to consume our flight data
    //  natively alongside his own.
    // ------------------------------------------------------------------

    private static function registerFlightsApi(App $app): void
    {
        $app->get('/api/v1/flights', function ($req, $res) {
            $params  = $req->getQueryParams();
            $airport = isset($params['airport']) ? strtoupper(trim((string) $params['airport'])) : null;
            $dir     = $params['direction'] ?? 'both';
            $active  = ($params['active'] ?? '1') === '1';
            $hours   = max(1, min(48, (int) ($params['hours'] ?? 6)));
            $phases  = isset($params['phase'])
                ? array_map('trim', explode(',', strtoupper((string) $params['phase'])))
                : null;

            $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $since   = $now->modify("-{$hours} hours")->format('Y-m-d H:i:s');

            $query = Flight::query()->where('last_updated_at', '>=', $since);

            // Airport + direction filter
            if ($airport !== null) {
                if ($dir === 'inbound') {
                    $query->where('ades', $airport);
                } elseif ($dir === 'outbound') {
                    $query->where('adep', $airport);
                } else {
                    $query->where(function ($q) use ($airport) {
                        $q->where('adep', $airport)->orWhere('ades', $airport);
                    });
                }
            }

            // Active filter — exclude terminal phases
            if ($active) {
                $query->whereNotIn('phase', [
                    Flight::PHASE_ARRIVED,
                    Flight::PHASE_WITHDRAWN,
                    Flight::PHASE_DISCONNECTED,
                ]);
            }

            // Phase filter
            if ($phases !== null) {
                $query->whereIn('phase', $phases);
            }

            $flights = $query->orderByRaw('COALESCE(eldt, eobt) ASC')
                ->limit(500)
                ->get()
                ->map(function (Flight $f) {
                    $meter = \Atfm\Allocator\MeteringFix::resolve($f);
                    return [
                        // --- PERTI SWIM v1 compatible fields ---
                        'callsign'       => $f->callsign,
                        'cid'            => (int) $f->cid,
                        'departure'      => $f->adep,
                        'arrival'        => $f->ades,
                        'aircraft_short' => $f->aircraft_type,
                        'deptime'        => $f->eobt?->format('Hi'),
                        'ctd_utc'        => $f->ctot?->format('c'),
                        'cta_utc'        => $f->tldt?->format('c'),
                        'phase'          => $f->phase,
                        'delay_status'   => $f->delay_status,

                        // --- atfm-tools extensions ---
                        'flight_key'     => $f->flight_key,
                        'flight_rules'   => $f->flight_rules,
                        'wake_category'  => $f->wake_category,
                        'fp_route'       => $f->fp_route,
                        'fp_altitude_ft' => $f->fp_altitude_ft,
                        'fp_cruise_tas'  => $f->fp_cruise_tas,
                        'fp_enroute_time_min' => $f->fp_enroute_time_min,

                        // A-CDM milestones
                        'eobt'           => $f->eobt?->format('c'),
                        'tobt'           => $f->tobt?->format('c'),
                        'tobt_source'    => $f->tobt_source,
                        'tsat'           => $f->tsat?->format('c'),
                        'ttot'           => $f->ttot?->format('c'),
                        'ctot'           => $f->ctot?->format('c'),
                        'aobt'           => $f->aobt?->format('c'),
                        'atot'           => $f->atot?->format('c'),
                        'eldt'           => $f->eldt?->format('c'),
                        'eldt_perti'     => $f->eldt_perti?->format('c'),
                        'eldt_wind'      => $f->eldt_wind?->format('c'),
                        'eta_source'     => $f->eta_source,
                        'eta_confidence' => $f->eta_confidence,
                        'eldt_locked'    => $f->eldt_locked?->format('c'),
                        'eldt_locked_at' => $f->eldt_locked_at?->format('c'),
                        'eldt_locked_source' => $f->eldt_locked_source,
                        'tldt'           => $f->tldt?->format('c'),
                        'tldt_assigned_at' => $f->tldt_assigned_at?->format('c'),
                        'aldt'           => $f->aldt?->format('c'),
                        'aibt'           => $f->aibt?->format('c'),

                        // Taxi metrics
                        'planned_exot_min'  => $f->planned_exot_min,
                        'actual_exot_min'   => $f->actual_exot_min,
                        'planned_exit_min'  => $f->planned_exit_min,
                        'actual_exit_min'   => $f->actual_exit_min,

                        // Regulation
                        'ctl_type'           => $f->ctl_type,
                        'ctl_element'        => $f->ctl_element,
                        'ctl_restriction_id' => $f->ctl_restriction_id,
                        'delay_minutes'      => $f->delay_minutes,

                        // Arrival metering (derived from fp_route + star-catalog.json)
                        'star'              => $meter['star'] ?? null,
                        'metering_fix'      => $meter['metering_fix'] ?? null,
                        'filed_transition'  => $meter['filed_transition'] ?? null,

                        // Position
                        'position' => [
                            'lat'            => $f->last_lat,
                            'lon'            => $f->last_lon,
                            'altitude_ft'    => $f->last_altitude_ft,
                            'groundspeed_kts'=> $f->last_groundspeed_kts,
                            'heading_deg'    => $f->last_heading_deg,
                            'updated_at'     => $f->last_position_at?->format('c'),
                        ],
                    ];
                })->values()->all();

            return self::json($res, [
                'generated_at' => $now->format('c'),
                'version'      => \Atfm\Version::STRING,
                'source'       => 'atfm-tools',
                'count'        => count($flights),
                'filters'      => [
                    'airport'   => $airport,
                    'direction' => $dir,
                    'active'    => $active,
                    'hours'     => $hours,
                    'phase'     => $phases,
                ],
                'flights'      => $flights,
            ]);
        });

        // --- Arrival metering fix summary ---
        //
        // For each scope airport, show the current inbound load grouped by
        // STAR metering fix. Used by the reports page (and future dashboard
        // widget) to surface MIT pressure before it becomes a regulation.
        //
        // ?window=60 : minutes ahead (by ELDT) to include. Default 60.
        // ?airport=CYYZ : limit to one airport. Optional.
        // Demand distribution rollup — landed flights grouped by
        // destination → metering fix (ALL catalog fixes, including zero).
        // Direct-to filers resolved by bearing inference. Used by the
        // reports page Demand Distribution panel to inform MIT allocation.
        // ?hours=N (default 24, max 720)
        $app->get('/api/v1/reports/demand-distribution', function ($req, $res) {
            $hours = max(1, min(720, (int) ($req->getQueryParams()['hours'] ?? 24)));
            $scope = ['CYVR','CYYC','CYWG','CYYZ','CYOW','CYUL','CYHZ'];
            $catalog = \Atfm\Allocator\MeteringFix::loadCatalog();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            // Initialize result buckets — all catalog fixes with zero counts
            $byApt = [];
            foreach ($scope as $apt) {
                $fixes = $catalog['metering_fixes'][$apt] ?? [];
                $byApt[$apt] = [
                    'total'      => 0,
                    'unresolved' => 0,
                    'fixes'      => array_map(fn($e) => [
                        'fix'      => $e['fix'],
                        'corridor' => $e['corridor'] ?? null,
                        'count'    => 0,
                        'filed'    => 0,
                        'inferred' => 0,
                    ], $fixes),
                ];
            }

            // Strategy: for windows > 24h, use daily cache where possible
            // (full days >= today). Today's partial day is always computed
            // fresh. Small windows (<=24h) always compute live.
            $cacheFile = __DIR__ . '/../../data/cache/demand-history.json';
            $cacheUsed = false;
            $cachedDays = 0;
            $liveSince = $now->modify("-{$hours} hours");

            if ($hours > 24 && file_exists($cacheFile)) {
                $raw = @file_get_contents($cacheFile);
                $cache = $raw ? json_decode($raw, true) : null;
                if (is_array($cache) && !empty($cache['days'])) {
                    $today = $now->setTime(0, 0, 0);
                    $cutoff = $today;
                    $windowStart = $liveSince;
                    // Iterate cached full days from windowStart up to (but not including) today
                    $cursor = $today->modify("-{$hours} hours")->setTime(0, 0, 0);
                    while ($cursor < $today) {
                        $key = $cursor->format('Y-m-d');
                        if (isset($cache['days'][$key])) {
                            foreach ($cache['days'][$key] as $apt => $dayData) {
                                if (!isset($byApt[$apt])) continue;
                                $byApt[$apt]['total']      += (int) ($dayData['total'] ?? 0);
                                $byApt[$apt]['unresolved'] += (int) ($dayData['unresolved'] ?? 0);
                                foreach (($dayData['fixes'] ?? []) as $fixName => $count) {
                                    foreach ($byApt[$apt]['fixes'] as &$row) {
                                        if ($row['fix'] === $fixName) {
                                            $row['count'] += (int) $count;
                                            $row['filed'] += (int) $count; // cache doesn't split filed vs inferred
                                            break;
                                        }
                                    }
                                    unset($row);
                                }
                            }
                            $cachedDays++;
                        }
                        $cursor = $cursor->modify('+1 day');
                    }
                    // For the live portion, restrict to today only
                    $liveSince = $today;
                    $cacheUsed = $cachedDays > 0;
                }
            }

            // Live portion (today OR whole window when <= 24h)
            $flights = Flight::whereIn('ades', $scope)
                ->whereNotNull('aldt')
                ->where('aldt', '>=', $liveSince->format('Y-m-d H:i:s'))
                ->get(['id', 'callsign', 'adep', 'ades', 'fp_route', 'last_lat', 'last_lon', 'aldt']);

            foreach ($flights as $f) {
                $apt = $f->ades;
                if (!isset($byApt[$apt])) continue;
                $byApt[$apt]['total']++;
                $r = \Atfm\Allocator\MeteringFix::resolve($f);
                if (!$r || !$r['metering_fix']) {
                    $byApt[$apt]['unresolved']++;
                    continue;
                }
                $fixName = $r['metering_fix'];
                foreach ($byApt[$apt]['fixes'] as &$row) {
                    if ($row['fix'] === $fixName) {
                        $row['count']++;
                        if ($r['inferred'] ?? false) {
                            $row['inferred']++;
                        } else {
                            $row['filed']++;
                        }
                        break;
                    }
                }
                unset($row);
            }

            // Compute percentages + sort
            foreach ($byApt as $apt => &$data) {
                $resolved = $data['total'] - $data['unresolved'];
                foreach ($data['fixes'] as &$row) {
                    $row['pct'] = $resolved > 0
                        ? round($row['count'] / $resolved * 100, 1)
                        : 0.0;
                }
                unset($row);
                usort($data['fixes'], fn($a, $b) => $b['count'] <=> $a['count']);
                $data['resolved'] = $resolved;
            }
            unset($data);

            return self::json($res, [
                'generated_at' => $now->format('c'),
                'hours'        => $hours,
                'cache_used'   => $cacheUsed,
                'cached_days'  => $cachedDays,
                'by_airport'   => $byApt,
            ]);
        });

        // Historical STAR usage rollup — landed flights grouped by
        // destination → STAR → transition. Used by the reports page
        // panel to show which STARs/transitions are actually flown.
        // Retroactive: reads fp_route on any historical flight with ALDT.
        // ?hours=N (default 24, max 720)
        $app->get('/api/v1/reports/star-usage', function ($req, $res) {
            $hours = max(1, min(720, (int) ($req->getQueryParams()['hours'] ?? 24)));
            $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify("-{$hours} hours")
                ->format('Y-m-d H:i:s');
            $scope = ['CYVR','CYYC','CYWG','CYYZ','CYOW','CYUL','CYHZ'];
            $catalog = \Atfm\Allocator\MeteringFix::loadCatalog();

            // Seed all airports with all catalog STARs at zero count, so
            // unused STARs still appear in the panel (same pattern as the
            // Demand Distribution panel on metering fixes).
            $byApt = [];
            foreach ($scope as $apt) {
                $byApt[$apt] = ['resolved' => 0, 'unresolved' => 0, 'stars' => []];
                foreach (($catalog['stars'][$apt] ?? []) as $starName => $starData) {
                    // Skip retired stubs: no entry fix and no metering fix
                    if (empty($starData['entry_fix']) && empty($starData['metering_fix'])) continue;
                    $byApt[$apt]['stars'][$starName] = [
                        'star'         => $starName,
                        'metering_fix' => $starData['metering_fix'] ?? null,
                        'count'        => 0,
                        'transitions'  => [],
                    ];
                }
            }

            $flights = Flight::whereIn('ades', $scope)
                ->whereNotNull('aldt')
                ->where('aldt', '>=', $since)
                ->get(['id', 'callsign', 'ades', 'fp_route']);

            foreach ($flights as $f) {
                $apt = $f->ades;
                if (!isset($byApt[$apt])) continue;
                $r = \Atfm\Allocator\MeteringFix::resolve($f);
                if (!$r || !$r['star']) {
                    $byApt[$apt]['unresolved']++;
                    continue;
                }
                $byApt[$apt]['resolved']++;
                $star = $r['star'];
                $tKey = $r['filed_transition'] ?? '(direct)';
                // If inference produced a STAR not in catalog (shouldn't happen now),
                // seed the entry ad-hoc rather than drop the count.
                $byApt[$apt]['stars'][$star] ??= [
                    'star'         => $star,
                    'metering_fix' => $r['metering_fix'],
                    'count'        => 0,
                    'transitions'  => [],
                ];
                $byApt[$apt]['stars'][$star]['count']++;
                $byApt[$apt]['stars'][$star]['transitions'][$tKey] =
                    ($byApt[$apt]['stars'][$star]['transitions'][$tKey] ?? 0) + 1;
            }

            // Sort stars by count desc (unused STARs fall to the bottom).
            // Transitions sorted desc by count.
            foreach ($byApt as $icao => &$apt) {
                $stars = array_values($apt['stars']);
                usort($stars, fn($a, $b) => $b['count'] <=> $a['count']);
                foreach ($stars as &$s) {
                    arsort($s['transitions']);
                }
                unset($s);
                $apt['stars'] = $stars;
            }
            unset($apt);

            return self::json($res, [
                'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
                'hours'        => $hours,
                'by_airport'   => $byApt,
            ]);
        });

        // Forward-looking demand distribution — inbound flights with ELDT
        // in the next window_min minutes, grouped by metering fix. Same
        // response shape as /reports/demand-distribution but sourced from
        // future-ETE flights rather than past landings. Powers the reports
        // page "Current demand distribution" panel.
        //
        // ?window=60 (default, minutes; range 15-480)
        $app->get('/api/v1/reports/current-demand', function ($req, $res) {
            $windowMin = max(15, min(480, (int) ($req->getQueryParams()['window'] ?? 60)));
            $scope = ['CYVR','CYYC','CYWG','CYYZ','CYOW','CYUL','CYHZ'];
            $catalog = \Atfm\Allocator\MeteringFix::loadCatalog();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $horizon = $now->modify("+{$windowMin} minutes");

            // Seed all catalog MFs at zero so zero-demand fixes still render
            $byApt = [];
            foreach ($scope as $apt) {
                $fixes = $catalog['metering_fixes'][$apt] ?? [];
                $byApt[$apt] = [
                    'total'      => 0,
                    'unresolved' => 0,
                    'fixes'      => array_map(fn($e) => [
                        'fix'      => $e['fix'],
                        'corridor' => $e['corridor'] ?? null,
                        'count'    => 0,
                        'filed'    => 0,
                        'inferred' => 0,
                    ], $fixes),
                ];
            }

            // Forward-looking: flights with ELDT in (now, horizon] that are
            // still tracked (not landed/withdrawn/disconnected).
            $flights = Flight::whereIn('ades', $scope)
                ->whereNotNull('eldt')
                ->where('eldt', '>=', $now->format('Y-m-d H:i:s'))
                ->where('eldt', '<=', $horizon->format('Y-m-d H:i:s'))
                ->whereNotIn('phase', [
                    Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED,
                    Flight::PHASE_ON_RUNWAY, Flight::PHASE_VACATED, Flight::PHASE_TAXI_IN,
                ])
                ->get(['id','callsign','adep','ades','fp_route','last_lat','last_lon','eldt','phase','fp_altitude_ft','last_altitude_ft']);

            foreach ($flights as $f) {
                $apt = $f->ades;
                if (!isset($byApt[$apt])) continue;
                $byApt[$apt]['total']++;
                $r = \Atfm\Allocator\MeteringFix::resolve($f);
                if (!$r || !$r['metering_fix']) {
                    $byApt[$apt]['unresolved']++;
                    continue;
                }
                $fixName = $r['metering_fix'];
                foreach ($byApt[$apt]['fixes'] as &$row) {
                    if ($row['fix'] === $fixName) {
                        $row['count']++;
                        if ($r['inferred'] ?? false) {
                            $row['inferred']++;
                        } else {
                            $row['filed']++;
                        }
                        break;
                    }
                }
                unset($row);
            }

            foreach ($byApt as $apt => &$data) {
                $resolved = $data['total'] - $data['unresolved'];
                foreach ($data['fixes'] as &$row) {
                    $row['pct'] = $resolved > 0
                        ? round($row['count'] / $resolved * 100, 1)
                        : 0.0;
                }
                unset($row);
                usort($data['fixes'], fn($a, $b) => $b['count'] <=> $a['count']);
                $data['resolved'] = $resolved;
            }
            unset($data);

            return self::json($res, [
                'generated_at' => $now->format('c'),
                'horizon_utc'  => $horizon->format('c'),
                'window_min'   => $windowMin,
                'by_airport'   => $byApt,
            ]);
        });

        $app->get('/api/v1/metering', function ($req, $res) {
            $params = $req->getQueryParams();
            $windowMin = max(15, min(240, (int) ($params['window'] ?? 60)));
            $airport = isset($params['airport']) ? strtoupper((string) $params['airport']) : null;

            $scope = ['CYVR','CYYC','CYWG','CYYZ','CYOW','CYUL','CYHZ'];
            if ($airport !== null && in_array($airport, $scope, true)) {
                $scope = [$airport];
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $horizon = $now->modify("+{$windowMin} minutes");

            $flights = Flight::whereIn('ades', $scope)
                ->whereNotNull('eldt')
                ->where('eldt', '<=', $horizon->format('Y-m-d H:i:s'))
                ->whereNotIn('phase', [
                    Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED,
                    Flight::PHASE_ON_RUNWAY, Flight::PHASE_VACATED, Flight::PHASE_TAXI_IN,
                ])
                ->get();

            // Group by airport → metering_fix → [{callsign, eldt, star, transition}]
            $grouped = [];
            $unresolved = [];
            foreach ($flights as $f) {
                $r = \Atfm\Allocator\MeteringFix::resolve($f);
                $apt = $f->ades;
                if (!$r || !$r['metering_fix']) {
                    $unresolved[] = [
                        'callsign' => $f->callsign,
                        'ades' => $apt,
                        'eldt' => $f->eldt?->format('c'),
                        'phase' => $f->phase,
                    ];
                    continue;
                }
                $fix = $r['metering_fix'];
                $grouped[$apt] ??= [];
                $grouped[$apt][$fix] ??= ['count' => 0, 'flights' => []];
                $grouped[$apt][$fix]['count']++;
                $grouped[$apt][$fix]['flights'][] = [
                    'callsign'       => $f->callsign,
                    'aircraft_type'  => $f->aircraft_type,
                    'adep'           => $f->adep,
                    'eldt'           => $f->eldt?->format('c'),
                    'phase'          => $f->phase,
                    'star'           => $r['star'],
                    'transition'     => $r['filed_transition'],
                ];
            }

            // Sort each airport's fixes by count desc
            foreach ($grouped as $apt => &$fixes) {
                uasort($fixes, fn($a, $b) => $b['count'] <=> $a['count']);
            }
            unset($fixes);

            return self::json($res, [
                'generated_at' => $now->format('c'),
                'version'      => \Atfm\Version::STRING,
                'window_min'   => $windowMin,
                'horizon_utc'  => $horizon->format('c'),
                'by_airport'   => $grouped,
                'unresolved'   => $unresolved,
                'unresolved_count' => count($unresolved),
            ]);
        });
    }

    // ------------------------------------------------------------------
    //  TOBT update — controller portal / VGDS
    // ------------------------------------------------------------------

    private static function registerTobtEndpoint(App $app): void
    {
        // GET /api/v1/pilot/{callsign} — single-flight lookup for the
        // Pilot Portal. Trust-based: anyone can query any callsign.
        // Returns the fields the VDGS-style portal needs: CALLSIGN,
        // EOBT, TOBT, TSAT, TTOT, CTOT, EXOT, Reason, ATFCM status.
        $app->get('/api/v1/pilot/{callsign}', function ($req, $res, array $args) {
            $callsign = strtoupper(trim($args['callsign']));
            if (!preg_match('/^[A-Z0-9]{2,10}$/', $callsign)) {
                return self::json($res->withStatus(400), ['error' => 'invalid callsign']);
            }
            $f = Flight::where('callsign', $callsign)
                ->whereNotIn('phase', [Flight::PHASE_WITHDRAWN])
                ->orderBy('last_updated_at', 'desc')
                ->first();
            if (!$f) {
                return self::json($res->withStatus(404), ['error' => 'flight not found']);
            }
            // Restriction / ATFCM context — if the flight has a CTOT, what
            // restriction is behind it? Fetch the active restriction at
            // ADES for that info.
            $restriction = null;
            if ($f->ctot && $f->ades) {
                $apt = Airport::where('icao', $f->ades)->first();
                if ($apt) {
                    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                    $restriction = AirportRestriction::where('airport_id', $apt->id)
                        ->whereNull('deleted_at')
                        ->where('active_from', '<=', $now->format('Y-m-d H:i:s'))
                        ->where(function ($q) use ($now) {
                            $q->whereNull('expires_at')
                              ->orWhere('expires_at', '>=', $now->format('Y-m-d H:i:s'));
                        })
                        ->get()
                        ->filter(fn (AirportRestriction $r) => $r->isActiveAt($now))
                        ->first();
                }
            }
            // Status narrative — "not yet time for start-up", "your window
            // is now", etc. Computed from TSAT and current time.
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $narrative = null;
            $secondsToWindow = null;
            $windowEarly = 5 * 60;
            $windowLate  = 5 * 60;
            if ($f->tsat) {
                $secondsToTsat = $f->tsat->getTimestamp() - $now->getTimestamp();
                if ($secondsToTsat > $windowEarly) {
                    $narrative = 'Not yet time for start-up. Wait for your window or update TOBT.';
                    $secondsToWindow = $secondsToTsat - $windowEarly;
                } elseif ($secondsToTsat >= -$windowLate) {
                    $narrative = 'Your start-up window is now. Request clearance.';
                } else {
                    $narrative = 'Window expired. Contact ATC or update TOBT for a new slot.';
                }
            } elseif ($f->aobt) {
                $narrative = 'Pushed back — taxi to holding point.';
            } elseif ($f->atot) {
                $narrative = 'Airborne.';
            } else {
                $narrative = 'Flight plan on file. Awaiting TOBT or start-up time.';
            }

            return self::json($res, [
                'callsign'       => $f->callsign,
                'cid'            => $f->cid,
                'adep'           => $f->adep,
                'ades'           => $f->ades,
                'phase'          => $f->phase,
                'aircraft_type'  => $f->aircraft_type,
                'route'          => $f->fp_route,
                'fp_altitude_ft' => $f->fp_altitude_ft,
                'eobt'           => $f->eobt?->format('c'),
                'tobt'           => $f->tobt?->format('c'),
                'tobt_source'    => $f->tobt_source,
                'tsat'           => $f->tsat?->format('c'),
                'ttot'           => $f->ttot?->format('c'),
                'ctot'           => $f->ctot?->format('c'),
                'eldt'           => $f->eldt?->format('c'),
                'aobt'           => $f->aobt?->format('c'),
                'atot'           => $f->atot?->format('c'),
                'aldt'           => $f->aldt?->format('c'),
                'planned_exot_min' => $f->planned_exot_min,
                'delay_minutes'  => $f->delay_minutes,
                'delay_status'   => $f->delay_status,
                'ctl_type'       => $f->ctl_type,
                'ctl_element'    => $f->ctl_element,
                'narrative'      => $narrative,
                'seconds_to_window' => $secondsToWindow,
                'restriction'    => $restriction ? [
                    'restriction_id' => $restriction->restriction_id,
                    'airport'        => $restriction->airport?->icao,
                    'capacity'       => (int) $restriction->capacity,
                    'reason'         => $restriction->reason,
                    'op_level'       => (int) $restriction->op_level,
                ] : null,
                'server_time'    => $now->format('c'),
            ]);
        });

        // PATCH /api/v1/flights/{callsign}/tobt
        // Body: { "tobt": "2026-04-16T14:30:00Z" }
        // Sets a manual TOBT. The next ingest cycle will cascade TSAT/TTOT
        // from this TOBT (but not overwrite it). The next allocator run
        // (≤2 min) will recompute CTOT from the new TTOT.
        //
        // To clear a manual TOBT and revert to auto: { "tobt": null }
        $app->patch('/api/v1/flights/{callsign}/tobt', function ($req, $res, array $args) {
            $callsign = strtoupper(trim($args['callsign']));
            $flight = Flight::where('callsign', $callsign)
                ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])
                ->first();

            if (!$flight) {
                return self::json($res->withStatus(404), [
                    'error' => 'Flight not found or already arrived/withdrawn',
                ]);
            }
            // Auth gate — permissive today, strict when AUTH_STRICT=true
            if (!\Atfm\Auth\Gate::modifyFlight($req, $flight)) {
                return self::json($res->withStatus(403), [
                    'error' => 'not authorized to modify this flight',
                ]);
            }

            $body = (array) $req->getParsedBody();
            $raw  = $body['tobt'] ?? null;

            if ($raw === null || $raw === '') {
                // Revert to auto — next ingest cycle will re-derive from EOBT
                $flight->tobt        = null;
                $flight->tobt_source = null;
                $flight->tsat        = null;
                $flight->ttot        = null;
            } else {
                try {
                    $tobt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
                } catch (\Exception $e) {
                    return self::json($res->withStatus(400), [
                        'error' => 'Invalid tobt format — use ISO 8601 (e.g. 2026-04-16T14:30:00Z)',
                    ]);
                }
                // Window validation — matches vats.im/vdgs behaviour:
                // TOBT must be between now+5m and now+2h. Earlier than +5m
                // is past/impossible-to-push; later than +2h is aspirational
                // and wastes a slot that couldn't be used if the pilot
                // doesn't follow through.
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $diffMin = ($tobt->getTimestamp() - $now->getTimestamp()) / 60;
                if ($diffMin < 5) {
                    return self::json($res->withStatus(400), [
                        'error' => 'TOBT must be at least 5 minutes from now (got ' . round($diffMin) . 'm).',
                    ]);
                }
                if ($diffMin > 120) {
                    return self::json($res->withStatus(400), [
                        'error' => 'TOBT must be within 2 hours from now (got ' . round($diffMin) . 'm).',
                    ]);
                }
                $flight->tobt        = $tobt;
                $flight->tobt_source = 'manual';
                $flight->tsat        = $tobt;
                // Cascade TTOT = TOBT + EXOT
                $exot = $flight->planned_exot_min;
                if ($exot !== null) {
                    $flight->ttot = $tobt->modify("+{$exot} minutes");
                } else {
                    $flight->ttot = $tobt; // fallback: no taxi time
                }
            }

            $flight->save();

            return self::json($res, [
                'callsign'    => $flight->callsign,
                'tobt'        => $flight->tobt?->format('c'),
                'tobt_source' => $flight->tobt_source,
                'tsat'        => $flight->tsat?->format('c'),
                'ttot'        => $flight->ttot?->format('c'),
                'ctot'        => $flight->ctot?->format('c'),
                'note'        => 'CTOT will be recalculated on next allocator run (≤2 min)',
            ]);
        });
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

            // Ingest completeness snapshot — written by VatsimIngestor at
            // end of each run. Drives the dashboard "feed / scope" pill.
            $ingestStats = null;
            $cacheFile = __DIR__ . '/../../data/cache/ingest-stats.json';
            if (file_exists($cacheFile)) {
                $raw = @file_get_contents($cacheFile);
                $decoded = $raw ? json_decode($raw, true) : null;
                if (is_array($decoded)) $ingestStats = $decoded;
            }

            // PERTI match rate — read the cached ADL if fresh (< 2 min),
            // compare by flight_key. Skip the network fetch entirely if
            // cache is stale, to keep /status latency predictable.
            $pertiStats = null;
            $pertiCache = sys_get_temp_dir() . '/atfm_perti_adl_cache.json';
            if (file_exists($pertiCache) && (time() - filemtime($pertiCache)) < 120) {
                $raw = @file_get_contents($pertiCache);
                $adl = $raw ? json_decode($raw, true) : null;
                if (is_array($adl) && isset($adl['flights'])) {
                    $scope = ['CYVR','CYYC','CYWG','CYYZ','CYOW','CYUL','CYHZ'];
                    $pertiScopeKeys = [];
                    foreach ($adl['flights'] as $pf) {
                        $dest = $pf['fp_dest_icao'] ?? '';
                        if (in_array($dest, $scope, true) && !empty($pf['flight_key'])) {
                            $pertiScopeKeys[$pf['flight_key']] = true;
                        }
                    }
                    $pertiScopeN = count($pertiScopeKeys);
                    $oursHave = Flight::whereIn('flight_key', array_keys($pertiScopeKeys))
                        ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN])
                        ->count();
                    $pertiStats = [
                        'perti_scope'  => $pertiScopeN,
                        'our_matched'  => $oursHave,
                        'match_pct'    => $pertiScopeN > 0 ? round(100 * $oursHave / $pertiScopeN) : null,
                    ];
                }
            }

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
                'last_ingest_stats'    => $ingestStats,
                'perti_completeness'   => $pertiStats,
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
                'version'              => \Atfm\Version::STRING,
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
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            // Auto-expire restrictions whose daily time window has passed.
            // end_utc is HHMM format (e.g. "0300"). If current UTC time is
            // past end_utc (and past start_utc, so we're not in the window),
            // soft-delete the restriction.
            $nowHhmm = $now->format('Hi');
            AirportRestriction::whereNull('deleted_at')
                ->whereNotNull('end_utc')
                ->get()
                ->each(function (AirportRestriction $r) use ($now, $nowHhmm) {
                    $start = $r->start_utc ?? '0000';
                    $end = $r->end_utc;
                    // Handle overnight window (e.g. 2300-0300)
                    $overnight = $end < $start;
                    $inWindow = $overnight
                        ? ($nowHhmm >= $start || $nowHhmm < $end)
                        : ($nowHhmm >= $start && $nowHhmm < $end);
                    if (!$inWindow) {
                        // Check if it's been at least 30 min past end to avoid
                        // deleting right at the boundary
                        $endMin = intval(substr($end, 0, 2)) * 60 + intval(substr($end, 2, 2));
                        $nowMin = intval($now->format('G')) * 60 + intval($now->format('i'));
                        $pastEnd = $overnight
                            ? ($nowMin > $endMin + 30 && $nowMin < intval(substr($start, 0, 2)) * 60 + intval(substr($start, 2, 2)))
                            : ($nowMin > $endMin + 30);
                        if ($pastEnd) {
                            $r->deleted_at = $now->format('Y-m-d H:i:s');
                            $r->save();
                        }
                    }
                });

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

            // Current inbound (not yet terminal, not disconnected). Note we
            // expose AOBT and ATOT in the payload so the drawer can show
            // the full timeline (filed → pushed back → wheels up → landing
            // estimate). ELDT is computed live via EtaEstimator if the
            // ingestor's stored value is null — that way long-range
            // ENROUTE flights still get a usable arrival estimate.
            $liveExclude = [
                Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED,
                Flight::PHASE_TAXI_IN, Flight::PHASE_ON_RUNWAY, Flight::PHASE_VACATED,
            ];
            // Sort inbound by ELDT ascending, nulls last. Flights without
            // an ELDT (e.g. FILED phase, no ETE filed) sink to the bottom
            // rather than floating to the top via EOBT fallback — an
            // ARRIVING flight 20 min out is more operationally relevant
            // than a FILED flight that hasn't even departed.
            $inbound = Flight::where('ades', $icao)
                ->whereNotIn('phase', $liveExclude)
                ->orderByRaw('CASE WHEN eldt IS NULL THEN 1 ELSE 0 END ASC, eldt ASC')
                ->limit(100)
                ->get()
                ->map(function (Flight $f) use ($airport, $now) {
                    $eldtIso = $f->eldt?->format('c');
                    $eldtSource = $f->eldt !== null ? 'STORED' : null;
                    // Live ELDT fallback: only compute for flights that are
                    // at cruise or approaching — same eligibility as the
                    // ingestor. No ELDT for FILED, FLS-NRA, or climbing.
                    $atCruise = $f->phase === Flight::PHASE_ENROUTE
                        && ($f->last_altitude_ft ?? 0) >= (($f->fp_altitude_ft ?? 35000) - 2000);
                    $inApproach = $f->phase === Flight::PHASE_ARRIVING;
                    // Suppress ELDT for FLS-NRA — flight hasn't departed,
                    // any stored ELDT is noise from before the filter deployed.
                    if ($f->delay_status === 'FLS_NRA' && $f->eldt_locked === null) {
                        $eldtIso = null;
                        $eldtSource = null;
                    }
                    // SimBrief ELDT fallback: for pre-cruise flights with
                    // no computed ELDT, show the SimBrief-derived estimate.
                    // Displayed in a distinct colour (orange) as advisory only.
                    if ($eldtIso === null && $f->eldt_simbrief !== null) {
                        $eldtIso = $f->eldt_simbrief->format('c');
                        $eldtSource = 'SIMBRIEF';
                    }
                    // For ARRIVING flights, always recompute live — the
                    // controller needs "how far out is this aircraft now",
                    // not a stale frozen value from hours ago.
                    if (($eldtIso === null && ($atCruise || $inApproach))
                        || $inApproach
                    ) {
                        $est = \Atfm\Allocator\EtaEstimator::estimate($f, $airport, $now);
                        if ($est['epoch'] !== null) {
                            $eldtIso = (new DateTimeImmutable('@' . $est['epoch']))
                                ->setTimezone(new DateTimeZone('UTC'))
                                ->format('c');
                            $eldtSource = $est['source'];
                        }
                    }
                    return [
                        'callsign'        => $f->callsign,
                        'cid'             => (int) $f->cid,
                        'aircraft_type'   => $f->aircraft_type,
                        'adep'            => $f->adep,
                        'phase'           => $f->phase,
                        'display_phase'   => self::displayPhase($f),
                        'is_simbrief'     => (bool) $f->is_simbrief,
                        'eobt'            => $f->eobt?->format('c'),
                        'aobt'            => $f->aobt?->format('c'),
                        'atot'            => $f->atot?->format('c'),
                        'ttot'            => $f->ttot?->format('c'),
                        'eldt'            => $eldtIso,
                        'eldt_source'     => $eldtSource,
                        'eldt_locked'     => $f->eldt_locked?->format('c'),
                        'tldt'            => $f->tldt?->format('c'),
                        'ctot'            => $f->ctot?->format('c'),
                        'delay_minutes'   => $f->delay_minutes,
                        'delay_status'    => $f->delay_status,
                        'last_gs'         => $f->last_groundspeed_kts,
                        'last_alt'        => $f->last_altitude_ft,
                        'last_hdg'        => $f->last_heading_deg,
                        'last_lat'        => $f->last_lat ? round((float) $f->last_lat, 4) : null,
                        'last_lon'        => $f->last_lon ? round((float) $f->last_lon, 4) : null,
                        'fp_route'        => $f->fp_route,
                        'fp_alt'          => $f->fp_altitude_ft,
                        'fp_tas'          => $f->fp_cruise_tas,
                        'fp_ete_min'      => $f->fp_enroute_time_min,
                        'alt_icao'        => $f->alt_icao,
                        'first_seen_at'   => $f->first_seen_at?->format('c'),
                        'aldt'            => $f->aldt?->format('c'),
                        'aibt'            => $f->aibt?->format('c'),
                    ];
                })->values()->all();

            // Current outbound — flights still at or departing this airport.
            // Once ATOT is set (wheels up), the flight is no longer
            // actionable from this airport's perspective — it belongs to
            // the inbound view of whatever ADES it's heading to. Drop it.
            $outbound = Flight::where('adep', $icao)
                ->whereNull('atot')
                ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED])
                ->orderBy('eobt')
                ->limit(100)
                ->get()
                ->map(fn (Flight $f) => [
                    'callsign'       => $f->callsign,
                    'cid'            => (int) $f->cid,
                    'aircraft_type'  => $f->aircraft_type,
                    'ades'           => $f->ades,
                    'phase'          => $f->phase,
                    'eobt'           => $f->eobt?->format('c'),
                    'first_seen_at'  => $f->first_seen_at?->format('c'),
                    'aobt'           => $f->aobt?->format('c'),
                    'tobt'           => $f->tobt?->format('c'),
                    'tsat'           => $f->tsat?->format('c'),
                    'ttot'           => $f->ttot?->format('c'),
                    'ctot'           => $f->ctot?->format('c'),
                    'atot'           => $f->atot?->format('c'),
                    'delay_minutes'  => $f->delay_minutes,
                    'last_gs'        => $f->last_groundspeed_kts,
                ])->values()->all();

            // Recent arrivals — last 60 min, sorted newest first.
            // 60 min gives enough context for rate observation without
            // cluttering the view with stale data.
            $recentSince = $now->modify('-60 minutes');
            $recentArrivals = Flight::where('ades', $icao)
                ->whereIn('phase', [
                    Flight::PHASE_ON_RUNWAY, Flight::PHASE_VACATED,
                    Flight::PHASE_TAXI_IN, Flight::PHASE_ARRIVED,
                ])
                ->where(function ($q) use ($recentSince) {
                    $q->where('aldt', '>=', $recentSince->format('Y-m-d H:i:s'))
                      ->orWhere('phase_updated_at', '>=', $recentSince->format('Y-m-d H:i:s'));
                })
                ->orderBy('aldt', 'desc')
                ->limit(30)
                ->get()
                ->map(fn (Flight $f) => [
                    'callsign'        => $f->callsign,
                    'aircraft_type'   => $f->aircraft_type,
                    'adep'            => $f->adep,
                    'aldt'            => $f->aldt?->format('c'),
                    'aibt'            => $f->aibt?->format('c'),
                    'actual_exit_min' => $f->actual_exit_min,
                    'eldt_locked'     => $f->eldt_locked?->format('c'),
                    'eldt_simbrief'   => $f->eldt_simbrief?->format('c'),
                    'eldt_perti'      => $f->eldt_perti?->format('c'),
                    'is_simbrief'     => (bool) $f->is_simbrief,
                    'tldt'            => $f->tldt?->format('c'),
                ])->values()->all();

            // Recent departures — last 60 min, sorted newest first.
            $recentDepartures = Flight::where('adep', $icao)
                ->whereNotNull('atot')
                ->where('atot', '>=', $recentSince->format('Y-m-d H:i:s'))
                ->orderBy('atot', 'desc')
                ->limit(30)
                ->get()
                ->map(function (Flight $f) {
                    $eobtDelayMin = null;
                    if ($f->eobt && $f->aobt) {
                        $eobtDelayMin = (int) round(
                            ($f->aobt->getTimestamp() - $f->eobt->getTimestamp()) / 60
                        );
                    }
                    // Spawn-to-pushback: how long did the pilot sit at
                    // the gate after connecting before starting to move?
                    $spawnToMovMin = null;
                    if ($f->first_seen_at && $f->aobt) {
                        $delta = $f->aobt->getTimestamp() - $f->first_seen_at->getTimestamp();
                        if ($delta >= 0 && $delta <= 7200) { // cap at 2h sanity
                            $spawnToMovMin = (int) round($delta / 60);
                        }
                    }
                    return [
                        'callsign'         => $f->callsign,
                        'aircraft_type'    => $f->aircraft_type,
                        'ades'             => $f->ades,
                        'eobt'             => $f->eobt?->format('c'),
                        'first_seen_at'    => $f->first_seen_at?->format('c'),
                        'aobt'             => $f->aobt?->format('c'),
                        'atot'             => $f->atot?->format('c'),
                        'actual_exot_min'  => $f->actual_exot_min,
                        'spawn_to_mov_min' => $spawnToMovMin,
                        'eobt_delay_min'   => $eobtDelayMin,
                        'delay_minutes'    => $f->delay_minutes,
                    ];
                })->values()->all();

            // Hourly-bucketed movement counts:
            //   past 24 hours (actual): ALDT for arrivals, ATOT for departures
            //   next 4 hours  (forecast): ELDT for arrivals, TTOT for departures
            // Forecast buckets let the FMP see the incoming spike against the
            // 24h baseline in one chart.
            $hourly = [];
            for ($i = 23; $i >= 0; $i--) {
                $anchor = $now->modify("-{$i} hours");
                $bucketStart = $anchor->setTime((int) $anchor->format('H'), 0, 0);
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
                    'forecast'   => false,
                ];
            }
            // Next 4 hours of forecast: flights with ELDT / TTOT in each bucket
            for ($i = 1; $i <= 4; $i++) {
                $anchor = $now->modify("+{$i} hours");
                $bucketStart = $anchor->setTime((int) $anchor->format('H'), 0, 0);
                // Use rolling (not aligned) for forecast to avoid double-counting
                // the current partial hour and under-reporting the last bucket.
                $bucketStart = $now->modify("+" . ($i - 1) . " hours");
                $bucketEnd   = $now->modify("+{$i} hours");
                $arrFc = Flight::where('ades', $icao)
                    ->whereBetween('eldt', [$bucketStart->format('Y-m-d H:i:s'), $bucketEnd->format('Y-m-d H:i:s')])
                    ->whereNotIn('phase', [Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED])
                    ->count();
                $depFc = Flight::where('adep', $icao)
                    ->whereBetween('ttot', [$bucketStart->format('Y-m-d H:i:s'), $bucketEnd->format('Y-m-d H:i:s')])
                    ->whereNull('atot')
                    ->count();
                $hourly[] = [
                    'hour'       => $bucketStart->format('Hi') . 'Z',
                    'arrivals'   => $arrFc,
                    'departures' => $depFc,
                    'forecast'   => true,
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
                    'active_arr_rate'       => $airport->active_arr_rate ? (int) $airport->active_arr_rate : null,
                    'active_dep_rate'       => $airport->active_dep_rate ? (int) $airport->active_dep_rate : null,
                    'active_config_name'    => $airport->active_config_name,
                    'active_config_set_at'  => $airport->active_config_set_at,
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
            $icao = strtoupper($args['icao']);
            $airport = Airport::where('icao', $icao)->first();
            if (! $airport) {
                return self::json($res->withStatus(404), ['error' => 'airport not found']);
            }
            if (!\Atfm\Auth\Gate::regulateAirport($req, $icao)) {
                return self::json($res->withStatus(403), [
                    'error' => 'not authorized to regulate this airport',
                ]);
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
            $icao = $r->airport?->icao ?? '';
            if (!\Atfm\Auth\Gate::regulateAirport($req, $icao)) {
                return self::json($res->withStatus(403), [
                    'error' => 'not authorized to lift this restriction',
                ]);
            }
            $r->delete();
            return self::json($res, ['ok' => true]);
        });

        // Allocator preview — shadow-run the allocator with a hypothetical
        // restriction and return what CTOTs WOULD be issued, without any
        // persisted change. Wraps everything in a DB transaction rolled
        // back at the end so the temp restriction never touches real data.
        //
        // POST body: { airport, capacity, duration_min?, op_level?, reason? }
        // Response: { capacity, duration_min, summary, flights: [{callsign, ades, adep, aircraft_type, eldt, ctot, delay_min, delay_stat, phase, action}, ...] }
        $app->post('/api/v1/allocator/preview', function ($req, $res) {
            $body = (array) $req->getParsedBody();
            $icao = strtoupper((string) ($body['airport'] ?? ''));
            $apt  = Airport::where('icao', $icao)->first();
            if (! $apt) {
                return self::json($res->withStatus(404), ['error' => 'airport not found']);
            }

            $durationMin = max(15, min(480, (int) ($body['duration_min'] ?? 60)));
            $capacity    = (int) ($body['capacity'] ?? ($apt->active_arr_rate ?? $apt->base_arrival_rate));

            $db = \Illuminate\Database\Capsule\Manager::connection();
            $db->beginTransaction();

            $log = [];
            $error = null;
            try {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $end = $now->modify("+{$durationMin} minutes");

                $r = new AirportRestriction();
                $r->restriction_id = AirportRestriction::generateId($icao);
                $r->airport_id     = $apt->id;
                $r->capacity       = $capacity;
                $r->reason         = (string) ($body['reason'] ?? 'ATC_CAPACITY');
                $r->op_level       = max(1, min(4, (int) ($body['op_level'] ?? 2)));
                $r->type           = 'ARR';
                $r->tier_minutes   = (int) ($body['tier_minutes'] ?? 120);
                $r->compliance_window_early_min = 5;
                $r->compliance_window_late_min  = 5;
                $r->start_utc      = $now->format('Hi');
                $r->end_utc        = $end->format('Hi');
                $r->active_from    = $now;
                $r->expires_at     = $end;
                $r->save();

                $result = (new \Atfm\Allocator\CtotAllocator())->run(true);
                $log = $result['shadow_log'] ?? [];
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            } finally {
                // ALWAYS rollback — this is a preview, nothing persists
                $db->rollBack();
            }

            if ($error) {
                return self::json($res->withStatus(500), ['error' => $error]);
            }

            // Filter to this airport and keep only actions that affect the FMP
            $relevant = array_values(array_filter($log, function ($e) use ($icao) {
                return ($e['ades'] ?? null) === $icao
                    && in_array($e['action'] ?? '', [
                        'slot_allocated', 'imported_ctot',
                        'release_stale', 'release_non_compliant', 'compliance_check',
                    ], true);
            }));
            // Sort by TLDT/eldt
            usort($relevant, fn($a, $b) => strcmp(($a['tldt'] ?? $a['eldt'] ?? ''), ($b['tldt'] ?? $b['eldt'] ?? '')));

            $issuedCount  = 0;
            $maxDelay     = 0;
            $totalDelay   = 0;
            foreach ($relevant as $e) {
                if ($e['action'] === 'slot_allocated' && !empty($e['delay_min'])) {
                    $issuedCount++;
                    $maxDelay   = max($maxDelay, (int) $e['delay_min']);
                    $totalDelay += (int) $e['delay_min'];
                }
            }

            return self::json($res, [
                'airport'       => $icao,
                'capacity'      => $capacity,
                'duration_min'  => $durationMin,
                'op_level'      => $r->op_level,
                'start_utc'     => $r->start_utc,
                'end_utc'       => $r->end_utc,
                'flights'       => $relevant,
                'summary'       => [
                    'affected_flights'  => count($relevant),
                    'ctots_would_issue' => $issuedCount,
                    'max_delay_min'     => $maxDelay,
                    'avg_delay_min'     => $issuedCount ? round($totalDelay / $issuedCount, 1) : 0,
                ],
            ]);
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

            // Tolerance for the "% within ±N min" KPI columns. The user's
            // gut number for "ELDT good enough to allocate slots against."
            $toleranceMin = 3;

            foreach ($airports as $a) {
                $arrivals = Flight::where('ades', $a->icao)
                    ->where('aldt', '>=', $sinceStr)
                    ->get([
                        'aldt', 'aibt', 'actual_exit_min',
                        'eldt_locked', 'eldt_locked_source', 'tldt',
                    ]);

                $departures = Flight::where('adep', $a->icao)
                    ->whereNotNull('atot')
                    ->where('atot', '>=', $sinceStr)
                    ->get(['eobt', 'aobt', 'atot', 'actual_exot_min', 'created_at']);

                $exotValues = $departures->pluck('actual_exot_min')->filter()->values()->all();
                $exitValues = $arrivals->pluck('actual_exit_min')->filter()->values()->all();

                // Spawn-to-pushback dwell: AOBT − created_at (minutes).
                // Replaces EOBT delay which was proven unreliable.
                // Capped at 120 min to exclude spawn-and-idle outliers.
                $dwellValues = [];
                foreach ($departures as $f) {
                    if ($f->created_at && $f->aobt) {
                        $dwell = (int) round(($f->aobt->getTimestamp() - $f->created_at->getTimestamp()) / 60);
                        if ($dwell > 0 && $dwell <= 120) {
                            $dwellValues[] = $dwell;
                        }
                    }
                }

                // ELDT prediction quality. For each completed arrival with
                // an eldt_locked snapshot (taken when the flight was T-60 min
                // from predicted landing), compute (ALDT − eldt_locked) in
                // minutes. Bias is the signed mean (negative = we predicted
                // late). Spread is the p90 of absolute values.
                $eldtErrors = [];
                $eldtAbsErrors = [];
                $eldtBucket3 = 0;   // |err| <= 3
                $eldtBucket5 = 0;   // 3 < |err| <= 5
                $eldtBucket10 = 0;  // 5 < |err| <= 10
                $eldtBucketOver = 0; // |err| > 10
                // Mid-connect partials skew stats badly — pilot joined the
                // flight already airborne, so the first ELDT we computed was
                // based on far less route than planned. Exclude any flight
                // whose observed flight time is < 50% of its filed ETE.
                // Also exclude flights flagged as accelerated sim (≥2x real-
                // time observed via position-delta/wall-clock GS ratio).
                $isPartial = function ($f) {
                    if (!$f->atot || !$f->aldt) return false;
                    $fte = $f->fp_enroute_time_min;
                    if (!$fte || $fte <= 0) return false;
                    $observedMin = ($f->aldt->getTimestamp() - $f->atot->getTimestamp()) / 60;
                    if ($observedMin < $fte * 0.5) return true;
                    // Treat ≥2x sim accel as a partial-equivalent outlier
                    if ($f->sim_accel_max_ratio !== null
                        && (float) $f->sim_accel_max_ratio >= 2.0) return true;
                    return false;
                };
                foreach ($arrivals as $f) {
                    if ($f->aldt && $f->eldt_locked
                        && $f->aldt->getTimestamp() !== $f->eldt_locked->getTimestamp() // exclude synthetic
                        && !$isPartial($f)
                    ) {
                        $err = ($f->aldt->getTimestamp() - $f->eldt_locked->getTimestamp()) / 60;
                        if (abs($err) > 120) continue; // outlier cap — beyond 2h is bad data
                        $eldtErrors[]    = $err;
                        $eldtAbsErrors[] = abs($err);
                        $absErr = abs($err);
                        if ($absErr <= 3) { $eldtBucket3++; }
                        elseif ($absErr <= 5) { $eldtBucket5++; }
                        elseif ($absErr <= 10) { $eldtBucket10++; }
                        else { $eldtBucketOver++; }
                    }
                }

                // ELDT accuracy by ETA source tier. Uses eldt_locked_source
                // (the tier active at freeze time) to attribute error to the
                // right estimator branch. This is the diagnostic tool for
                // "which tier is dragging down accuracy?"
                $eldtByTier = [];
                foreach ($arrivals as $f) {
                    if ($f->aldt && $f->eldt_locked && $f->eldt_locked_source
                        && $f->aldt->getTimestamp() !== $f->eldt_locked->getTimestamp()
                        && !$isPartial($f)
                    ) {
                        $err = ($f->aldt->getTimestamp() - $f->eldt_locked->getTimestamp()) / 60;
                        if (abs($err) > 120) continue;
                        $tier = $f->eldt_locked_source;
                        if (!isset($eldtByTier[$tier])) {
                            $eldtByTier[$tier] = ['errors' => [], 'within' => 0];
                        }
                        $eldtByTier[$tier]['errors'][] = $err;
                        if (abs($err) <= $toleranceMin) {
                            $eldtByTier[$tier]['within']++;
                        }
                    }
                }
                $tierBreakdown = [];
                foreach ($eldtByTier as $tier => $td) {
                    $n = count($td['errors']);
                    $tierBreakdown[$tier] = [
                        'n'       => $n,
                        'err_min' => self::avg($td['errors']),
                        'pct_ok'  => $n > 0 ? round(100 * $td['within'] / $n, 1) : null,
                    ];
                }

                // TLDT slot fidelity. For each completed arrival with a
                // tldt assigned by the allocator, compute (ALDT − tldt) in
                // minutes. This is the operational metric: how well did
                // the system's slot allocation match reality?
                $tldtErrors = [];
                $tldtAbsErrors = [];
                $tldtBucket3 = 0;   // |err| <= 3
                $tldtBucket5 = 0;   // 3 < |err| <= 5
                $tldtBucket10 = 0;  // 5 < |err| <= 10
                $tldtBucketOver = 0; // |err| > 10
                foreach ($arrivals as $f) {
                    if ($f->aldt && $f->tldt && !$isPartial($f)) {
                        $err = ($f->aldt->getTimestamp() - $f->tldt->getTimestamp()) / 60;
                        $tldtErrors[]    = $err;
                        $tldtAbsErrors[] = abs($err);
                        $absErr = abs($err);
                        if ($absErr <= 3) { $tldtBucket3++; }
                        elseif ($absErr <= 5) { $tldtBucket5++; }
                        elseif ($absErr <= 10) { $tldtBucket10++; }
                        else { $tldtBucketOver++; }
                    }
                }

                $rows[] = [
                    'icao'                 => $a->icao,
                    'name'                 => $a->name,
                    'longitude'            => (float) $a->longitude,
                    'arr_rate'             => (int) ($a->active_arr_rate ?? $a->base_arrival_rate),
                    'arrivals'             => $arrivals->count(),
                    'departures'           => $departures->count(),
                    'avg_exot_min'         => self::avg($exotValues),
                    'p90_exot_min'         => self::percentile($exotValues, 90),
                    'avg_exit_min'         => self::avg($exitValues),
                    'p90_exit_min'         => self::percentile($exitValues, 90),
                    'median_dwell_min'     => self::percentile($dwellValues, 50),
                    'dwell_sample_n'       => count($dwellValues),
                    // ELDT prediction quality — median resists outliers
                    'eldt_err_min'            => self::percentile($eldtErrors, 50),
                    'eldt_p90_abs_min'         => self::percentile($eldtAbsErrors, 90),
                    'eldt_pct_le3'            => count($eldtErrors) > 0
                                                  ? round(100 * $eldtBucket3 / count($eldtErrors), 1)
                                                  : null,
                    'eldt_pct_3to5'           => count($eldtErrors) > 0
                                                  ? round(100 * $eldtBucket5 / count($eldtErrors), 1)
                                                  : null,
                    'eldt_pct_5to10'          => count($eldtErrors) > 0
                                                  ? round(100 * $eldtBucket10 / count($eldtErrors), 1)
                                                  : null,
                    'eldt_pct_gt10'           => count($eldtErrors) > 0
                                                  ? round(100 * $eldtBucketOver / count($eldtErrors), 1)
                                                  : null,
                    'eldt_sample_n'            => count($eldtErrors),
                    'eldt_by_tier'             => $tierBreakdown,
                    // TLDT slot fidelity — median resists outliers
                    'tldt_err_min'            => self::percentile($tldtErrors, 50),
                    'tldt_p90_abs_min'         => self::percentile($tldtAbsErrors, 90),
                    'tldt_pct_le3'            => count($tldtErrors) > 0
                                                  ? round(100 * $tldtBucket3 / count($tldtErrors), 1)
                                                  : null,
                    'tldt_pct_3to5'           => count($tldtErrors) > 0
                                                  ? round(100 * $tldtBucket5 / count($tldtErrors), 1)
                                                  : null,
                    'tldt_pct_5to10'          => count($tldtErrors) > 0
                                                  ? round(100 * $tldtBucket10 / count($tldtErrors), 1)
                                                  : null,
                    'tldt_pct_gt10'           => count($tldtErrors) > 0
                                                  ? round(100 * $tldtBucketOver / count($tldtErrors), 1)
                                                  : null,
                    'tldt_sample_n'            => count($tldtErrors),
                ];
            }

            // Surface the tolerance value so the UI can label its columns.
            $toleranceForResponse = $toleranceMin;

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

            // Live ETA source distribution — how many active flights are
            // on each estimator tier right now? Useful for diagnosing
            // "90% of my flights are on CALC_DEFAULT" situations.
            $etaSourceDist = Flight::selectRaw('eta_source, COUNT(*) as n')
                ->whereNotNull('eta_source')
                ->where('eta_source', '!=', 'NONE')
                ->where('last_updated_at', '>=', $sinceStr)
                ->groupBy('eta_source')
                ->pluck('n', 'eta_source')
                ->all();

            // Aircraft type distribution (top 20) with per-airport breakdown.
            // Two queries: one for the global top-20 ranking, then a second
            // for the per-airport counts of those types. Keeps the SQL simple
            // and avoids a 7-way CASE pivot.
            // West-to-east ordering for the per-airport columns in the
            // aircraft types table. Sorting by longitude ascending puts
            // CYVR on the left and CYHZ on the right — the natural
            // geographic reading order for Canadian airports.
            $allAirportIcaos = $airports->sortBy('longitude')->pluck('icao')->all();
            // Aircraft type distribution — completed movements at our 7 airports.
            // Uses same scope as movements table (ALDT for arrivals, ATOT for departures).
            // Top-20 ranking uses a broader query; per-airport totals are exact.
            $ourIcaos = $airports->pluck('icao')->all();
            $topTypes = Flight::selectRaw('aircraft_type, COUNT(*) as n')
                ->whereNotNull('aircraft_type')
                ->where(function ($q) use ($ourIcaos, $sinceStr) {
                    $q->where(function ($q2) use ($ourIcaos, $sinceStr) {
                        $q2->whereIn('ades', $ourIcaos)
                            ->whereNotNull('aldt')
                            ->where('aldt', '>=', $sinceStr);
                    })->orWhere(function ($q2) use ($ourIcaos, $sinceStr) {
                        $q2->whereIn('adep', $ourIcaos)
                            ->whereNotNull('atot')
                            ->where('atot', '>=', $sinceStr);
                    });
                })
                ->groupBy('aircraft_type')
                ->orderByDesc('n')
                ->limit(20)
                ->get();

            // Wake category lookup from approach categories
            $wakeFile = __DIR__ . '/../../data/wake-separation.json';
            $appCats = [];
            if (file_exists($wakeFile)) {
                $wd = json_decode(file_get_contents($wakeFile), true);
                $appCats = $wd['approach_categories'] ?? [];
            }
            $typeToWake = [];
            foreach ($appCats as $catInfo) {
                $wake = $catInfo['wake'] ?? 'M';
                foreach ($catInfo['examples'] ?? [] as $ex) {
                    $typeToWake[$ex] = $wake;
                }
            }
            $topTypeNames = $topTypes->pluck('aircraft_type')->all();

            // Per-airport completed movement counts (ADES + ADEP).
            // Arrivals: flights with ALDT in window. Departures: flights with ATOT in window.
            // Matches the movements table scope exactly.
            $perAirportCounts = [];
            if (! empty($topTypeNames)) {
                // Completed arrivals (aldt)
                $rawArr = Flight::selectRaw('aircraft_type, ades as airport, COUNT(*) as cnt')
                    ->whereIn('aircraft_type', $topTypeNames)
                    ->whereNotNull('aldt')
                    ->where('aldt', '>=', $sinceStr)
                    ->whereIn('ades', $allAirportIcaos)
                    ->groupBy('aircraft_type', 'ades')
                    ->get();
                foreach ($rawArr as $rc) {
                    $perAirportCounts[$rc->aircraft_type][$rc->airport] =
                        ($perAirportCounts[$rc->aircraft_type][$rc->airport] ?? 0) + (int) $rc->cnt;
                }
                // Completed departures (atot)
                $rawDep = Flight::selectRaw('aircraft_type, adep as airport, COUNT(*) as cnt')
                    ->whereIn('aircraft_type', $topTypeNames)
                    ->whereNotNull('atot')
                    ->where('atot', '>=', $sinceStr)
                    ->whereIn('adep', $allAirportIcaos)
                    ->groupBy('aircraft_type', 'adep')
                    ->get();
                foreach ($rawDep as $rc) {
                    $perAirportCounts[$rc->aircraft_type][$rc->airport] =
                        ($perAirportCounts[$rc->aircraft_type][$rc->airport] ?? 0) + (int) $rc->cnt;
                }
            }

            $typeRows = $topTypes->map(function ($r) use ($allAirportIcaos, $perAirportCounts, $typeToWake) {
                $byAirport = [];
                $movSum = 0;
                foreach ($allAirportIcaos as $icaoCode) {
                    $n = $perAirportCounts[$r->aircraft_type][$icaoCode] ?? 0;
                    $byAirport[$icaoCode] = $n;
                    $movSum += $n;
                }
                return [
                    'aircraft_type' => $r->aircraft_type,
                    'count'         => $movSum,  // sum of per-airport = always adds up
                    'wake'          => $typeToWake[$r->aircraft_type] ?? '?',
                    'by_airport'    => $byAirport,
                ];
            })->all();

            return self::json($res, [
                'generated_at' => $now->format('c'),
                'tolerance_min' => $toleranceForResponse,
                'eldt_lock_horizon_min' => \Atfm\Ingestion\VatsimIngestor::ELDT_LOCK_HORIZON_MIN,
                'airport_icaos' => $allAirportIcaos,
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
                'eta_source_distribution' => $etaSourceDist,
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

        // ELDT accuracy report — all flights with both eldt_locked and aldt
        $app->get('/api/v1/accuracy', function ($req, $res) {
            $params = $req->getQueryParams();
            $days = min((int) ($params['days'] ?? 7), 30);
            $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("-{$days} days");

            // Include any flight with ALDT that has at least one predicted
            // ETA (ours locked, PERTI's, GRIB wind, or SimBrief). Previously
            // we required eldt_locked, which under-sampled PERTI accuracy:
            // PERTI publishes ETAs for flights we may not have frozen, and
            // those should count toward PERTI-vs-ALDT stats independently.
            $flights = Flight::whereNotNull('aldt')
                ->where('aldt', '>=', $since->format('Y-m-d H:i:s'))
                ->where(function ($q) {
                    $q->whereNotNull('eldt_locked')
                      ->orWhereNotNull('eldt_perti')
                      ->orWhereNotNull('eldt_wind')
                      ->orWhereNotNull('eldt_simbrief');
                })
                ->orderBy('aldt', 'desc')
                ->limit(1000)
                ->get();

            $rows = $flights->map(function (Flight $f) {
                $aldt = $f->aldt->getTimestamp();
                $ourErr = $f->eldt_locked
                    ? round(($aldt - $f->eldt_locked->getTimestamp()) / 60)
                    : null;
                $pertiErr = $f->eldt_perti
                    ? round(($aldt - $f->eldt_perti->getTimestamp()) / 60)
                    : null;
                $sbErr = $f->eldt_simbrief
                    ? round(($aldt - $f->eldt_simbrief->getTimestamp()) / 60)
                    : null;
                $windErr = $f->eldt_wind
                    ? round(($aldt - $f->eldt_wind->getTimestamp()) / 60)
                    : null;
                $synthetic = $f->eldt_locked && $f->aldt->getTimestamp() === $f->eldt_locked->getTimestamp();
                $windReason = $f->eldt_wind === null
                    ? \Atfm\Allocator\WindEta::classifyHistoricalMissing($f)
                    : null;
                return [
                    'callsign'      => $f->callsign,
                    'aircraft_type' => $f->aircraft_type,
                    'adep'          => $f->adep,
                    'ades'          => $f->ades,
                    'aldt'          => $f->aldt->format('c'),
                    'eldt_locked'   => $f->eldt_locked?->format('c'),
                    'eldt_perti'    => $f->eldt_perti?->format('c'),
                    'eldt_simbrief' => $f->eldt_simbrief?->format('c'),
                    'eldt_wind'     => $f->eldt_wind?->format('c'),
                    'our_err_min'   => $ourErr,
                    'perti_err_min' => $pertiErr,
                    'wind_err_min'  => $windErr,
                    'wind_reason'   => $windReason,
                    'sb_err_min'    => $sbErr,
                    'is_simbrief'   => (bool) $f->is_simbrief,
                    'synthetic'     => $synthetic,
                    'locked_source' => $f->eldt_locked_source,
                ];
            })->values()->all();

            // Stats excluding synthetic and extreme outliers (>2h error = bad data).
            // Each error source has its own sample — a PERTI row with no
            // frozen ELDT from us still counts toward PERTI stats.
            $real = array_filter($rows, fn($r) => !$r['synthetic']);
            $ourErrs = array_values(array_filter(
                array_map(fn($r) => $r['our_err_min'], $real),
                fn($v) => $v !== null && abs($v) <= 120
            ));
            $pertiErrs = array_values(array_filter(
                array_map(fn($r) => $r['perti_err_min'], $real),
                fn($v) => $v !== null && abs($v) <= 120
            ));
            $windErrs = array_values(array_filter(
                array_map(fn($r) => $r['wind_err_min'], $real),
                fn($v) => $v !== null && abs($v) <= 120
            ));

            return self::json($res, [
                'days'          => $days,
                'total'         => count($rows),
                'synthetic'     => count($rows) - count($real),
                'real'          => count($real),
                'our_mean_err'  => count($ourErrs) > 0 ? round(array_sum($ourErrs) / count($ourErrs), 1) : null,
                'our_mean_abs'  => count($ourErrs) > 0 ? round(array_sum(array_map('abs', $ourErrs)) / count($ourErrs), 1) : null,
                'perti_mean_err'=> count($pertiErrs) > 0 ? round(array_sum($pertiErrs) / count($pertiErrs), 1) : null,
                'perti_mean_abs'=> count($pertiErrs) > 0 ? round(array_sum(array_map('abs', $pertiErrs)) / count($pertiErrs), 1) : null,
                'wind_mean_err' => count($windErrs) > 0 ? round(array_sum($windErrs) / count($windErrs), 1) : null,
                'wind_mean_abs' => count($windErrs) > 0 ? round(array_sum(array_map('abs', $windErrs)) / count($windErrs), 1) : null,
                'flights'       => $rows,
            ]);
        });

        $app->get('/api/v1/debug/runway-thresholds', function ($req, $res) {
            return self::json($res, RunwayThreshold::orderBy('airport_icao')->orderBy('runway_ident')->get()->toArray());
        });

        $app->get('/api/v1/runway-configs', function ($req, $res) {
            $data = json_decode(
                file_get_contents(__DIR__ . '/../../data/runway-configs.json'),
                true
            );
            return self::json($res, $data ?? []);
        });

        // ------------------------------------------------------------------
        //  Observed wind from recent final approach speeds
        // ------------------------------------------------------------------
        $app->get('/api/v1/aar/observed-wind', function ($req, $res) {
            $params = $req->getQueryParams();
            $icao = strtoupper($params['airport'] ?? '');
            $hours = min((int) ($params['hours'] ?? 2), 6);

            if (!$icao) {
                return self::json($res, ['error' => 'airport required'], 400);
            }

            $wakeData = json_decode(
                file_get_contents(__DIR__ . '/../../data/wake-separation.json'),
                true
            );
            $cats = $wakeData['categories'];

            // Map types to Vat
            $typeToVat = [];
            foreach ($cats as $cat => $info) {
                foreach ($info['examples'] as $type) {
                    $typeToVat[$type] = $info['vat_kt'];
                }
            }

            // Find recent arrivals with ALDT
            $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("-{$hours} hours");
            $arrivals = Flight::where('ades', $icao)
                ->whereNotNull('aldt')
                ->where('aldt', '>=', $since->format('Y-m-d H:i:s'))
                ->whereNotNull('aircraft_type')
                ->get(['id', 'callsign', 'aircraft_type', 'aldt']);

            $observations = [];
            $headwinds = [];

            $airport = Airport::where('icao', $icao)->first();
            $aptLat = $airport ? (float) $airport->latitude : null;
            $aptLon = $airport ? (float) $airport->longitude : null;

            foreach ($arrivals as $f) {
                // Get the last position_scratch observation for this flight
                // where altitude < 3000ft AGL and within 10nm of airport
                $aptElev = $airport ? (int) $airport->elevation_ft : 0;
                $lastPos = \Atfm\Models\PositionScratch::where('flight_id', $f->id)
                    ->where('altitude_ft', '<', $aptElev + 3000)
                    ->where('groundspeed_kts', '>', 50) // on approach, not taxiing
                    ->orderBy('observed_at', 'desc')
                    ->first();

                if (!$lastPos) continue;

                // Check within 10nm of airport
                if ($aptLat !== null) {
                    $dist = Geo::distanceNm(
                        (float) $lastPos->lat, (float) $lastPos->lon,
                        $aptLat, $aptLon
                    );
                    if ($dist > 10) continue;
                }

                $vat = $typeToVat[$f->aircraft_type] ?? 135; // default Cat C
                $gs = $lastPos->groundspeed_kts;
                $hw = $vat - $gs; // positive = headwind

                $observations[] = [
                    'callsign' => $f->callsign,
                    'type'     => $f->aircraft_type,
                    'vat_kt'   => $vat,
                    'gs_kt'    => $gs,
                    'hw_kt'    => $hw,
                    'alt_ft'   => $lastPos->altitude_ft,
                    'dist_nm'  => $aptLat ? round(Geo::distanceNm(
                        (float) $lastPos->lat, (float) $lastPos->lon,
                        $aptLat, $aptLon
                    ), 1) : null,
                    'at'       => $lastPos->observed_at->format('c'),
                ];
                $headwinds[] = $hw;
            }

            $avgHw = count($headwinds) > 0
                ? (int) round(array_sum($headwinds) / count($headwinds))
                : null;

            return self::json($res, [
                'airport'          => $icao,
                'hours'            => $hours,
                'sample_count'     => count($observations),
                'avg_headwind_kt'  => $avgHw,
                'observations'     => $observations,
            ]);
        });

        // ------------------------------------------------------------------
        //  AAR Calculator
        // ------------------------------------------------------------------
        $app->get('/api/v1/aar/calculate', function ($req, $res) {
            $params = $req->getQueryParams();
            $icao = strtoupper($params['airport'] ?? '');
            $headwindKt = (int) ($params['headwind'] ?? 0);

            // Load approach category + wake separation data
            $wakeData = json_decode(
                file_get_contents(__DIR__ . '/../../data/wake-separation.json'),
                true
            );
            $appCats = $wakeData['approach_categories'];
            $sepMatrix = $wakeData['separation_nm'];

            // Build type → approach category mapping
            $typeToAppCat = [];
            foreach ($appCats as $cat => $info) {
                foreach ($info['examples'] as $type) {
                    $typeToAppCat[$type] = $cat;
                }
            }

            // Build the mix from query params: mix[A]=5&mix[C]=60&mix[D]=30
            $mixParam = $params['mix'] ?? null;
            $mix = [];
            $mixSource = 'default';

            if ($mixParam && is_array($mixParam)) {
                $total = array_sum($mixParam);
                if ($total > 0) {
                    foreach ($mixParam as $cat => $pct) {
                        $mix[strtoupper($cat)] = (float) $pct / $total;
                    }
                    $mixSource = 'manual';
                }
            }

            // If no manual mix, compute from recent arrivals
            if (empty($mix) && $icao) {
                $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-7 days');
                $typeCounts = Flight::where('ades', $icao)
                    ->whereNotNull('aldt')
                    ->where('aldt', '>=', $since->format('Y-m-d H:i:s'))
                    ->whereNotNull('aircraft_type')
                    ->selectRaw('aircraft_type, COUNT(*) as cnt')
                    ->groupBy('aircraft_type')
                    ->pluck('cnt', 'aircraft_type')
                    ->toArray();

                $catCounts = [];
                foreach ($typeCounts as $type => $count) {
                    $cat = $typeToAppCat[$type] ?? 'C';
                    $catCounts[$cat] = ($catCounts[$cat] ?? 0) + $count;
                }
                $total = array_sum($catCounts);
                if ($total > 0) {
                    foreach ($catCounts as $cat => $count) {
                        $mix[$cat] = $count / $total;
                    }
                    $mixSource = 'historical_7d';
                }
            }

            // Fallback
            if (empty($mix)) {
                $mix = ['B' => 0.10, 'C' => 0.65, 'D' => 0.25];
                $mixSource = 'generic';
            }

            // GS on final for each approach cat (Vat - headwind)
            $gs = [];
            foreach ($appCats as $cat => $info) {
                $gs[$cat] = max($info['vat_kt'] - $headwindKt, 70);
            }

            // Weighted average inter-arrival gap in minutes.
            // Separation comes from WAKE category (looked up via approach cat).
            $activeCats = array_keys(array_filter($mix, fn($p) => $p > 0));

            $avgGapMin = 0;
            foreach ($activeCats as $leader) {
                foreach ($activeCats as $follower) {
                    $pairProb = ($mix[$leader] ?? 0) * ($mix[$follower] ?? 0);
                    if ($pairProb <= 0) continue;
                    $leaderWake = $appCats[$leader]['wake'] ?? 'M';
                    $followerWake = $appCats[$follower]['wake'] ?? 'M';
                    $sepNm = $sepMatrix[$leaderWake][$followerWake] ?? 3;
                    $gapMin = ($sepNm / $gs[$follower]) * 60;
                    $avgGapMin += $pairProb * $gapMin;
                }
            }
            if ($avgGapMin <= 0) $avgGapMin = 2;
            $aar = (int) round(60 / $avgGapMin);

            // Calm wind AAR for comparison
            $calmGapMin = 0;
            foreach ($activeCats as $leader) {
                foreach ($activeCats as $follower) {
                    $pairProb = ($mix[$leader] ?? 0) * ($mix[$follower] ?? 0);
                    if ($pairProb <= 0) continue;
                    $leaderWake = $appCats[$leader]['wake'] ?? 'M';
                    $followerWake = $appCats[$follower]['wake'] ?? 'M';
                    $sepNm = $sepMatrix[$leaderWake][$followerWake] ?? 3;
                    $calmGapMin += $pairProb * ($sepNm / $appCats[$follower]['vat_kt']) * 60;
                }
            }
            $aarCalm = $calmGapMin > 0 ? (int) round(60 / $calmGapMin) : $aar;

            // Per-category detail
            $catDetail = [];
            foreach ($appCats as $cat => $info) {
                $catDetail[] = [
                    'cat'       => $cat,
                    'label'     => $info['label'],
                    'vat_kt'    => $info['vat_kt'],
                    'gs_kt'     => $gs[$cat],
                    'wake'      => $info['wake'],
                    'mix_pct'   => round(($mix[$cat] ?? 0) * 100, 1),
                    'examples'  => array_slice($info['examples'], 0, 4),
                ];
            }

            return self::json($res, [
                'airport'        => $icao,
                'headwind_kt'    => $headwindKt,
                'aar'            => $aar,
                'aar_calm'       => $aarCalm,
                'avg_gap_sec'    => round($avgGapMin * 60),
                'mix_source'     => $mixSource,
                'categories'     => $catDetail,
                'separation_nm'  => $sepMatrix,
            ]);
        });

        // ------------------------------------------------------------------
        //  PERTI cross-validation endpoint
        // ------------------------------------------------------------------
        $app->get('/api/v1/perti/compare', function ($req, $res) {
            $swimKey = 'swim_pub_7783b37a28c167af41788599954e3e39';
            $airports = ['CYHZ', 'CYOW', 'CYUL', 'CYVR', 'CYWG', 'CYYC', 'CYYZ'];
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            // Fetch PERTI ADL — cached for 2 min to avoid hammering their server.
            // Multiple page loads and the ingestor all share the same cache file.
            $cacheFile = sys_get_temp_dir() . '/atfm_perti_adl_cache.json';
            $cacheTtl  = 120; // seconds
            $raw = null;
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
                $raw = file_get_contents($cacheFile);
            }
            if (!$raw) {
                $ctx = stream_context_create([
                    'http' => [
                        'header' => "Authorization: Bearer {$swimKey}\r\n",
                        'timeout' => 10,
                    ],
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $raw = @file_get_contents('https://perti.vatcscc.org/api/adl/current', false, $ctx);
                if ($raw === false) {
                    return self::json($res, ['error' => 'Failed to fetch PERTI ADL'], 502);
                }
                @file_put_contents($cacheFile, $raw);
            }
            $adl = json_decode($raw, true);
            if (!$adl || !isset($adl['flights'])) {
                return self::json($res, ['error' => 'Invalid PERTI response'], 502);
            }

            // Index PERTI by flight_key and callsign|ades
            $pertiByKey = [];
            $pertiByCsAdes = [];
            foreach ($adl['flights'] as $pf) {
                if (isset($pf['flight_key'])) $pertiByKey[$pf['flight_key']] = $pf;
                $cs = $pf['callsign'] ?? '';
                $dest = $pf['fp_dest_icao'] ?? '';
                if ($cs && $dest) $pertiByCsAdes[$cs . '|' . $dest] = $pf;
            }

            // Load our active inbound flights — exclude post-landing phases
            $ours = Flight::whereIn('ades', $airports)
                ->whereNotIn('phase', [
                    Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED,
                    Flight::PHASE_ON_RUNWAY, Flight::PHASE_VACATED, Flight::PHASE_TAXI_IN,
                ])
                ->get();

            // Lookup destination coords for wind_reason classification
            $aptCoords = [];
            foreach (Airport::whereIn('icao', $airports)->get() as $apt) {
                $aptCoords[$apt->icao] = [
                    'lat' => (float) $apt->latitude,
                    'lon' => (float) $apt->longitude,
                    'elev' => (int) ($apt->elevation_ft ?? 0),
                ];
            }

            $comparisons = [];
            $matched = 0;
            $unmatched = 0;

            foreach ($ours as $f) {
                $pf = $pertiByKey[$f->flight_key] ?? $pertiByCsAdes[$f->callsign . '|' . $f->ades] ?? null;
                if (!$pf) { $unmatched++; continue; }
                $matched++;

                $ourEldt = $f->eldt ? $f->eldt->getTimestamp() : null;
                $pertiEta = isset($pf['eta_utc']) && $pf['eta_utc'] ? strtotime($pf['eta_utc']) : null;
                $etaDelta = ($ourEldt && $pertiEta && $pertiEta > 0)
                    ? round(($ourEldt - $pertiEta) / 60)
                    : null;

                // Why no ELDT?
                $noEldtReason = null;
                if ($ourEldt === null) {
                    $alt = (int) ($f->last_altitude_ft ?? 0);
                    $fpAlt = (int) ($f->fp_altitude_ft ?? 35000);
                    if (in_array($f->phase, [Flight::PHASE_PREFILE, Flight::PHASE_FILED], true)) {
                        $noEldtReason = 'At gate';
                    } elseif ($f->phase === Flight::PHASE_TAXI_OUT) {
                        $noEldtReason = 'Taxiing out';
                    } elseif ($f->phase === Flight::PHASE_DEPARTED || ($f->phase === Flight::PHASE_ENROUTE && $alt < $fpAlt - 2000)) {
                        $noEldtReason = 'Climbing (FL' . round($alt / 100) . ', needs FL' . round(($fpAlt - 2000) / 100) . '+)';
                    } elseif ($f->phase === Flight::PHASE_DESCENT) {
                        $noEldtReason = 'Descending (no route data?)';
                    } else {
                        $noEldtReason = 'Unknown';
                    }
                }

                // Why no eldt_wind? (classify so the UI can show a hint
                // instead of blank — "outside grid", "climbing", etc.)
                $windReason = null;
                if ($f->eldt_wind === null) {
                    $windReason = \Atfm\Allocator\WindEta::classifyMissing(
                        $f,
                        $aptCoords[$f->ades] ?? null
                    );
                }

                $comparisons[] = [
                    'callsign'      => $f->callsign,
                    'aircraft_type' => $f->aircraft_type,
                    'adep'          => $f->adep,
                    'ades'          => $f->ades,
                    'our_phase'     => $f->phase,
                    'display_phase' => self::displayPhase($f),
                    'perti_phase'   => $pf['phase'] ?? null,
                    'our_eldt'      => $f->eldt?->format('c'),
                    'eldt_wind'     => $f->eldt_wind?->format('c'),
                    'wind_reason'   => $windReason,
                    'perti_eta'     => $pf['eta_utc'] ?? null,
                    'eta_delta_min' => $etaDelta,
                    'no_eldt_reason'=> $noEldtReason,
                    'our_frozen'    => $f->eldt_locked !== null,
                    'our_simbrief'  => (bool) $f->is_simbrief,
                    'last_alt'      => $f->last_altitude_ft,
                    'fp_alt'        => $f->fp_altitude_ft,
                    'perti_dist_nm' => $pf['dist_to_dest_nm'] ?? null,
                    'perti_edct'    => $pf['edct_utc'] ?? null,
                    'match_method'  => isset($pertiByKey[$f->flight_key]) ? 'flight_key' : 'callsign',
                ];
            }

            // TMI check — only flights with actual EDCT/CTD/CTA assigned.
            // gs_flag in PERTI means "on the ground", NOT "ground stop" —
            // don't use it as a TMI indicator.
            $tmis = [];
            foreach ($adl['flights'] as $pf) {
                $dest = $pf['fp_dest_icao'] ?? '';
                if (in_array($dest, $airports, true)
                    && (!empty($pf['edct_utc']) || !empty($pf['ctd_utc']) || !empty($pf['cta_utc']))
                ) {
                    $tmis[] = [
                        'callsign' => $pf['callsign'] ?? '?',
                        'adep'     => $pf['fp_dept_icao'] ?? '?',
                        'ades'     => $dest,
                        'edct'     => $pf['edct_utc'] ?? null,
                        'ctd'      => $pf['ctd_utc'] ?? null,
                        'cta'      => $pf['cta_utc'] ?? null,
                    ];
                }
            }

            // Post-landing accuracy: flights with ALDT in last 2h that had
            // a frozen ELDT. Three-way: ours vs PERTI vs ALDT.
            $landedSince = $now->modify('-120 minutes');
            $landings = Flight::whereIn('ades', $airports)
                ->whereNotNull('aldt')
                ->whereNotNull('eldt_locked')
                ->where('aldt', '>=', $landedSince->format('Y-m-d H:i:s'))
                ->orderBy('aldt', 'desc')
                ->limit(50)
                ->get()
                ->map(function (Flight $f) {
                    $aldt = $f->aldt->getTimestamp();
                    $ourErr = round(($aldt - $f->eldt_locked->getTimestamp()) / 60);
                    $pertiErr = null;
                    if ($f->eldt_perti) {
                        $pertiErr = round(($aldt - $f->eldt_perti->getTimestamp()) / 60);
                    }
                    $sbErr = null;
                    if ($f->eldt_simbrief) {
                        $sbErr = round(($aldt - $f->eldt_simbrief->getTimestamp()) / 60);
                    }
                    $windErr = null;
                    if ($f->eldt_wind) {
                        $windErr = round(($aldt - $f->eldt_wind->getTimestamp()) / 60);
                    }
                    // Detect synthetic ALDT (ALDT = eldt_locked exactly)
                    $synthetic = $f->aldt->getTimestamp() === $f->eldt_locked->getTimestamp();
                    return [
                        'callsign'      => $f->callsign,
                        'aircraft_type' => $f->aircraft_type,
                        'adep'          => $f->adep,
                        'ades'          => $f->ades,
                        'aldt'          => $f->aldt->format('c'),
                        'eldt_locked'   => $f->eldt_locked->format('c'),
                        'eldt_perti'    => $f->eldt_perti?->format('c'),
                        'eldt_wind'     => $f->eldt_wind?->format('c'),
                        'eldt_simbrief' => $f->eldt_simbrief?->format('c'),
                        'our_err_min'   => $ourErr,
                        'wind_err_min'  => $windErr,
                        'wind_reason'   => $f->eldt_wind === null
                            ? \Atfm\Allocator\WindEta::classifyHistoricalMissing($f)
                            : null,
                        'perti_err_min' => $pertiErr,
                        'sb_err_min'    => $sbErr,
                        'is_simbrief'   => (bool) $f->is_simbrief,
                        'synthetic'     => $synthetic,
                    ];
                })->values()->all();

            return self::json($res, [
                'timestamp'       => $now->format('c'),
                'perti_snapshot'  => $adl['snapshot_utc'] ?? null,
                'perti_total'     => count($adl['flights']),
                'our_active'      => count($ours),
                'matched'         => $matched,
                'unmatched'       => $unmatched,
                'comparisons'     => $comparisons,
                'active_tmis'     => $tmis,
                'recent_landings' => $landings,
            ]);
        });

        // ------------------------------------------------------------------
        //  TOBT proxy analysis — spawn-to-pushback statistics
        // ------------------------------------------------------------------
        $app->get('/api/v1/debug/tobt-analysis', function ($req, $res) {
            $hours = max(1, min(168, (int) ($req->getQueryParams()['hours'] ?? 168)));
            $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify("-{$hours} hours")->format('Y-m-d H:i:s');
            $scope = ['CYVR','CYYC','CYWG','CYYZ','CYOW','CYUL','CYHZ'];

            // 1. EOBT error (AOBT - EOBT) by departure airport
            $eobtByApt = \Illuminate\Database\Capsule\Manager::select("
                SELECT
                    adep,
                    COUNT(*) as n,
                    ROUND(AVG(TIMESTAMPDIFF(SECOND, eobt, aobt)) / 60, 1) as avg_min,
                    ROUND(STDDEV(TIMESTAMPDIFF(SECOND, eobt, aobt)) / 60, 1) as std_min
                FROM flights
                WHERE aobt IS NOT NULL AND eobt IS NOT NULL
                  AND aobt >= ?
                  AND ABS(TIMESTAMPDIFF(SECOND, eobt, aobt)) < 14400
                GROUP BY adep HAVING n >= 3
                ORDER BY n DESC
            ", [$since]);

            // 2. Distribution buckets
            $buckets = \Illuminate\Database\Capsule\Manager::select("
                SELECT
                    CASE
                        WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) < -3600 THEN 'a_lt_neg60'
                        WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) < -900  THEN 'b_neg60_neg15'
                        WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) <= 300  THEN 'c_neg15_pos5'
                        WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) <= 900  THEN 'd_pos5_pos15'
                        WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) <= 3600 THEN 'e_pos15_pos60'
                        WHEN TIMESTAMPDIFF(SECOND, eobt, aobt) <= 7200 THEN 'f_pos60_pos120'
                        ELSE 'g_gt_pos120'
                    END as bucket,
                    COUNT(*) as n
                FROM flights
                WHERE aobt IS NOT NULL AND eobt IS NOT NULL
                  AND aobt >= ?
                  AND ABS(TIMESTAMPDIFF(SECOND, eobt, aobt)) < 14400
                  AND adep IN ('" . implode("','", $scope) . "')
                GROUP BY bucket ORDER BY bucket
            ", [$since]);

            // 3. Spawn-to-pushback dwell (AOBT - created_at)
            $dwell = \Illuminate\Database\Capsule\Manager::select("
                SELECT
                    adep,
                    COUNT(*) as n,
                    ROUND(AVG(TIMESTAMPDIFF(SECOND, created_at, aobt)) / 60, 1) as avg_dwell_min,
                    ROUND(MIN(TIMESTAMPDIFF(SECOND, created_at, aobt)) / 60, 1) as min_dwell,
                    ROUND(MAX(TIMESTAMPDIFF(SECOND, created_at, aobt)) / 60, 1) as max_dwell
                FROM flights
                WHERE aobt IS NOT NULL AND created_at IS NOT NULL
                  AND aobt >= ?
                  AND TIMESTAMPDIFF(SECOND, created_at, aobt) > 0
                  AND TIMESTAMPDIFF(SECOND, created_at, aobt) < 14400
                  AND adep IN ('" . implode("','", $scope) . "')
                GROUP BY adep HAVING n >= 3
                ORDER BY n DESC
            ", [$since]);

            // 4. EOBT vs first_seen (created_at - eobt)
            $spawnVsEobt = \Illuminate\Database\Capsule\Manager::select("
                SELECT
                    adep,
                    COUNT(*) as n,
                    ROUND(AVG(TIMESTAMPDIFF(SECOND, eobt, created_at)) / 60, 1) as avg_spawn_vs_eobt
                FROM flights
                WHERE created_at IS NOT NULL AND eobt IS NOT NULL AND aobt IS NOT NULL
                  AND aobt >= ?
                  AND ABS(TIMESTAMPDIFF(SECOND, eobt, created_at)) < 14400
                  AND adep IN ('" . implode("','", $scope) . "')
                GROUP BY adep HAVING n >= 3
                ORDER BY n DESC
            ", [$since]);

            // 5. Percentiles (combined scope airports)
            $allErrors = \Illuminate\Database\Capsule\Manager::select("
                SELECT ROUND(TIMESTAMPDIFF(SECOND, eobt, aobt) / 60, 1) as err_min
                FROM flights
                WHERE aobt IS NOT NULL AND eobt IS NOT NULL
                  AND aobt >= ?
                  AND ABS(TIMESTAMPDIFF(SECOND, eobt, aobt)) < 14400
                  AND adep IN ('" . implode("','", $scope) . "')
                ORDER BY err_min
            ", [$since]);
            $vals = array_map(fn($r) => (float) $r->err_min, $allErrors);
            $n = count($vals);
            $pctiles = [];
            if ($n > 0) {
                foreach ([5, 10, 25, 50, 75, 90, 95] as $p) {
                    $idx = max(0, min($n - 1, (int) floor($n * $p / 100)));
                    $pctiles["p{$p}"] = $vals[$idx];
                }
                $pctiles['mean'] = round(array_sum($vals) / $n, 1);
                $pctiles['n'] = $n;
            }

            return self::json($res, [
                'window_hours' => $hours,
                'eobt_error_by_airport' => $eobtByApt,
                'eobt_error_distribution' => $buckets,
                'spawn_to_pushback_dwell' => $dwell,
                'spawn_vs_eobt' => $spawnVsEobt,
                'eobt_error_percentiles' => $pctiles,
            ]);
        });
    }

    // ------------------------------------------------------------------
    //  Emergency: manual ingest trigger (no cron needed)
    // ------------------------------------------------------------------

    private static function registerEmergencyEndpoints(App $app): void
    {
        // POST /api/v1/admin/trigger-ingest — run the ingestor once on demand.
        // Use when the cron is dead (WHC hosting kills it after repeated failures).
        // Returns the ingestor's normal result payload.
        $app->post('/api/v1/admin/trigger-ingest', function ($req, $res) {
            try {
                $result = (new \Atfm\Ingestion\VatsimIngestor())->run();
                return self::json($res, ['ok' => true, 'result' => $result]);
            } catch (\Throwable $e) {
                $res->getBody()->write(json_encode([
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ]));
                return $res->withStatus(500)->withHeader('Content-Type', 'application/json');
            }
        });

        // POST /api/v1/active-config — set the active runway config for an airport.
        // Called by the AAR page when the FMP selects/auto-proposes a config.
        // Body: { "airport": "CYYZ", "config": "24 Direction", "arr_rate": 46, "dep_rate": 30 }
        // Single source of truth: FSM, dashboard, allocator all read this.
        $app->post('/api/v1/active-config', function ($req, $res) {
            $body = json_decode((string) $req->getBody(), true);
            $icao = strtoupper($body['airport'] ?? '');
            $configName = $body['config'] ?? null;
            $arrRate = isset($body['arr_rate']) ? (int) $body['arr_rate'] : null;
            $depRate = isset($body['dep_rate']) ? (int) $body['dep_rate'] : null;

            if (!$icao || $arrRate === null) {
                return self::json($res->withStatus(400), ['error' => 'airport and arr_rate required']);
            }

            $airport = Airport::where('icao', $icao)->first();
            if (!$airport) {
                return self::json($res->withStatus(404), ['error' => 'airport not found']);
            }

            $airport->active_config_name = $configName;
            $airport->active_arr_rate = $arrRate;
            $airport->active_dep_rate = $depRate;
            $airport->active_config_set_at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $airport->save();

            return self::json($res, [
                'ok' => true,
                'airport' => $icao,
                'config' => $configName,
                'arr_rate' => $arrRate,
                'dep_rate' => $depRate,
            ]);
        });

        // GET /api/v1/reports/tldt-accuracy — TLDT prediction accuracy for
        // departed flights. Measures TLDT vs ALDT for flights that departed
        // within the 90m scope window, received a TLDT, and landed.
        // This is the gate for trusting CTOT issuance.
        $app->get('/api/v1/reports/tldt-accuracy', function ($req, $res) {
            $hours = max(1, min(720, (int) ($req->getQueryParams()['hours'] ?? 48)));
            $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $since = $now->modify("-{$hours} hours");
            $sinceStr = $since->format('Y-m-d H:i:s');

            // Flights with ATOT + TLDT + ALDT (the complete lifecycle)
            $flights = Flight::whereNotNull('atot')
                ->whereNotNull('tldt')
                ->whereNotNull('aldt')
                ->where('atot', '>=', $sinceStr)
                ->orderBy('aldt', 'desc')
                ->get();

            $records = [];
            $errors = [];
            $errorsBySource = [];
            $errorsByAirport = [];

            foreach ($flights as $f) {
                $tldtEpoch = $f->tldt->getTimestamp();
                $aldtEpoch = $f->aldt->getTimestamp();
                $atotEpoch = $f->atot->getTimestamp();
                $errMin = round(($tldtEpoch - $aldtEpoch) / 60, 1);

                // Also compute ELDT error if we have the locked source
                $eldtErr = null;
                if ($f->eldt_locked !== null) {
                    $eldtErr = round(($f->eldt_locked->getTimestamp() - $aldtEpoch) / 60, 1);
                }

                // Cap garbage errors
                if (abs($errMin) > 120) {
                    continue;
                }

                $source = $f->eldt_locked_source ?? $f->eta_source ?? 'UNKNOWN';
                $distNm = null;
                if ($f->adep && $f->ades) {
                    $adepCoords = \Atfm\Allocator\AirportCoords::coords($f->adep);
                    $adesCoords = \Atfm\Allocator\AirportCoords::coords($f->ades);
                    if ($adepCoords && $adesCoords) {
                        $distNm = (int) round(Geo::distanceNm(
                            $adepCoords[0], $adepCoords[1],
                            $adesCoords[0], $adesCoords[1]
                        ));
                    }
                }

                $flightTimeMin = round(($aldtEpoch - $atotEpoch) / 60);

                // ATOT + filed ETE as naive baseline comparison
                $filedEta = null;
                $filedEtaErr = null;
                $filedEte = $f->fp_enroute_time_min;
                if ($filedEte && $filedEte > 0) {
                    $filedEtaEpoch = $atotEpoch + ($filedEte * 60);
                    $filedEta = (new DateTimeImmutable('@' . $filedEtaEpoch))->format('c');
                    $filedEtaErr = round(($filedEtaEpoch - $aldtEpoch) / 60, 1);
                }

                // Mid-connect detection: we observed less than half the filed
                // ETE, meaning the pilot connected mid-flight. These flights
                // are shown in the table (dimmed) but excluded from accuracy
                // stats — we never had the data to predict well.
                $partial = $filedEte && $filedEte > 0
                    && $flightTimeMin < ($filedEte * 0.5);

                // Accelerated sim rate (≥2x) — also excluded from stats as
                // outlier. Peak ratio stored during ingest. Separate flag from
                // "partial" so UI can distinguish.
                $simAccel = ($f->sim_accel_max_ratio !== null
                             && (float) $f->sim_accel_max_ratio >= 2.0);
                $simAccelMild = ($f->sim_accel_max_ratio !== null
                                 && (float) $f->sim_accel_max_ratio >= 1.3
                                 && (float) $f->sim_accel_max_ratio < 2.0);

                // Per-flight "why was this off" diagnostic. Empty for near-zero
                // errors; populated with concise cause bullets when |err| > 5.
                // Reads only stored flight fields — no external data needed.
                $errReasons = [];
                if (($simAccel || $simAccelMild) && abs($errMin) > 3) {
                    $mins = (int) round(((int) $f->sim_accel_total_cycles) * 2); // 2-min cycles
                    $errReasons[] = sprintf(
                        'accelerated sim rate detected (up to %.1fx reported GS for ~%dm)',
                        (float) $f->sim_accel_max_ratio, $mins
                    );
                }
                if (!$partial && !$simAccel && abs($errMin) > 5) {
                    // 1. Lock source quality
                    if ($source === 'FILED' || $source === 'FIR_EET' ||
                        strpos($source, 'CALC_') === 0) {
                        $errReasons[] = "locked from ground tier ({$source}) — no live position at freeze";
                    } elseif ($source === 'OBSERVED_POS') {
                        $errReasons[] = 'locked from OBSERVED_POS (no wind correction)';
                    }

                    // 2. Freeze timing — how early did we commit?
                    if ($f->eldt_locked_at) {
                        $minsBeforeLanding = round(
                            ($aldtEpoch - $f->eldt_locked_at->getTimestamp()) / 60
                        );
                        if ($minsBeforeLanding > 120) {
                            $errReasons[] = "froze early ({$minsBeforeLanding}m before landing — drift room)";
                        }
                    }

                    // 3. Observed flight time vs filed ETE. "Shorter" could mean
                    // tailwind / direct clearance / higher speed; "longer"
                    // could mean headwind / vectoring / holding. We don't have
                    // enough info to pinpoint which — just flag the fact and
                    // let the Wind tier comparison (bullet 4) distinguish wind
                    // from other causes.
                    if ($filedEte && $filedEte > 0) {
                        $delta = $flightTimeMin - $filedEte;
                        $pctOff = abs($delta) / $filedEte * 100;
                        if ($pctOff >= 10 && abs($delta) >= 5) {
                            $label = $delta > 0
                                ? 'longer than filed ETE (possible headwind / vectoring / holding)'
                                : 'shorter than filed ETE (possible tailwind / direct clearance / higher speed)';
                            $errReasons[] = sprintf(
                                '%s — actual %dm vs filed %dm (%+dm)',
                                $label, $flightTimeMin, $filedEte, $delta
                            );
                        }
                    }

                    // 4. Wind estimate comparison — did eldt_wind beat the lock?
                    if ($f->eldt_wind) {
                        $windErr = round(($f->eldt_wind->getTimestamp() - $aldtEpoch) / 60);
                        if (abs($windErr) < abs($errMin) - 3) {
                            $errReasons[] = sprintf(
                                'GRIB wind estimate would have been better (%+dm vs locked %+dm)',
                                $windErr, $errMin
                            );
                        }
                    }

                    // 5. Aircraft type coverage
                    if (!$f->aircraft_type) {
                        $errReasons[] = 'no aircraft type filed';
                    }

                    // 6. Route resolution sanity
                    $routeLen = $f->fp_route ? strlen(trim($f->fp_route)) : 0;
                    if ($routeLen < 10) {
                        $errReasons[] = 'sparse / missing route (poor GRIB integration)';
                    }

                    // 7. Pre-departure delay (big gap between EOBT and ATOT)
                    if ($f->eobt && $atotEpoch - $f->eobt->getTimestamp() > 3600) {
                        $delayMin = round(($atotEpoch - $f->eobt->getTimestamp()) / 60);
                        $errReasons[] = "big pre-departure delay ({$delayMin}m after EOBT)";
                    }
                }

                $records[] = [
                    'callsign'      => $f->callsign,
                    'aircraft_type' => $f->aircraft_type,
                    'adep'          => $f->adep,
                    'ades'          => $f->ades,
                    'dist_nm'       => $distNm,
                    'flight_time'   => (int) $flightTimeMin,
                    'filed_ete'     => $filedEte,
                    'partial'       => $partial,
                    'sim_accel'     => $simAccel,
                    'sim_accel_mild'=> $simAccelMild,
                    'sim_accel_ratio' => $f->sim_accel_max_ratio !== null ? (float) $f->sim_accel_max_ratio : null,
                    'atot'          => $f->atot->format('c'),
                    'filed_eta'     => $filedEta,
                    'filed_eta_err' => $filedEtaErr,
                    'tldt'          => $f->tldt->format('c'),
                    'aldt'          => $f->aldt->format('c'),
                    'tldt_err_min'  => $errMin,
                    'eldt_err_min'  => $eldtErr,
                    'eta_source'    => $source,
                    'ctl_type'      => $f->ctl_type,
                    'err_reasons'   => $errReasons,
                ];

                // Exclude partial-observation and ≥2x sim-accel flights from
                // accuracy stats — both are outliers we can't model.
                if ($partial || $simAccel) {
                    continue;
                }

                $errors[] = $errMin;

                if (!isset($errorsBySource[$source])) {
                    $errorsBySource[$source] = [];
                }
                $errorsBySource[$source][] = $errMin;

                $ades = $f->ades ?? 'UNKNOWN';
                if (!isset($errorsByAirport[$ades])) {
                    $errorsByAirport[$ades] = [];
                }
                $errorsByAirport[$ades][] = $errMin;
            }

            // Compute summary stats
            $computeStats = function (array $errs): array {
                if (empty($errs)) {
                    return ['n' => 0, 'median' => null, 'mae' => null, 'pct_le3' => null, 'pct_3to5' => null, 'pct_5to10' => null, 'pct_gt10' => null];
                }
                sort($errs);
                $n = count($errs);
                $median = $n % 2 === 0
                    ? ($errs[$n/2 - 1] + $errs[$n/2]) / 2
                    : $errs[(int) floor($n/2)];
                $mae = array_sum(array_map('abs', $errs)) / $n;
                // Exclusive buckets: ≤3, 3-5, 5-10, >10
                $le3  = count(array_filter($errs, fn($e) => abs($e) <= 3));
                $b3t5 = count(array_filter($errs, fn($e) => abs($e) > 3 && abs($e) <= 5));
                $b5t10 = count(array_filter($errs, fn($e) => abs($e) > 5 && abs($e) <= 10));
                $gt10 = count(array_filter($errs, fn($e) => abs($e) > 10));
                return [
                    'n' => $n,
                    'median' => round($median, 1),
                    'mae' => round($mae, 1),
                    'pct_le3' => round(100 * $le3 / $n),
                    'pct_3to5' => round(100 * $b3t5 / $n),
                    'pct_5to10' => round(100 * $b5t10 / $n),
                    'pct_gt10' => round(100 * $gt10 / $n),
                ];
            };

            $sourceStats = [];
            foreach ($errorsBySource as $src => $errs) {
                $sourceStats[$src] = $computeStats($errs);
            }

            $airportStats = [];
            foreach ($errorsByAirport as $apt => $errs) {
                $airportStats[$apt] = $computeStats($errs);
            }

            return self::json($res, [
                'hours' => $hours,
                'overall' => $computeStats($errors),
                'by_source' => $sourceStats,
                'by_airport' => $airportStats,
                'flights' => $records,
            ]);
        });

        // POST /api/v1/admin/wind-eldt — batch-update wind-corrected ELDT.
        // Called by the wind-shadow experiment script (bin/experiments/wind-shadow.py)
        // to write GRIB-derived ELDTs onto flight records for three-way comparison.
        // Body: { "updates": [ { "callsign": "ACA881", "eldt_wind": "2026-04-17T20:44:00Z" }, ... ] }
        $app->post('/api/v1/admin/wind-eldt', function ($req, $res) {
            $body = json_decode((string) $req->getBody(), true);
            $updates = $body['updates'] ?? [];
            $updated = 0;
            foreach ($updates as $u) {
                $cs = $u['callsign'] ?? null;
                $eldtStr = $u['eldt_wind'] ?? null;
                if (!$cs || !$eldtStr) continue;
                try {
                    $dt = new \DateTimeImmutable($eldtStr);
                } catch (\Throwable $e) {
                    continue;
                }
                $n = Flight::where('callsign', $cs)
                    ->whereNull('aldt')
                    ->update(['eldt_wind' => $dt->format('Y-m-d H:i:s')]);
                $updated += $n;
            }
            return self::json($res, ['ok' => true, 'updated' => $updated]);
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

    /**
     * Translate internal phase to operationally meaningful display label.
     *
     * Internal phases are stable identifiers used in queries/logic. Display
     * phases are what the controller sees — they account for altitude vs
     * filed altitude to distinguish CLIMBOUT/CRUISE/DESCENT, and rename
     * ARRIVED to IN_BLOCKS.
     */
    private static function displayPhase(Flight $f): string
    {
        $alt = (int) ($f->last_altitude_ft ?? 0);
        $fp  = (int) ($f->fp_altitude_ft ?? 35000);

        return match ($f->phase) {
            Flight::PHASE_PREFILE,
            Flight::PHASE_FILED      => 'FILED',
            Flight::PHASE_TAXI_OUT   => 'TAXI_OUT',
            Flight::PHASE_DEPARTED   => 'CLIMBOUT',
            Flight::PHASE_ENROUTE    => ($alt < $fp - 2000 && $f->eldt === null) ? 'CLIMBOUT'
                                      : (($f->last_groundspeed_kts !== null
                                          && $f->ades !== null
                                          && $f->last_lat !== null && $f->last_lon !== null
                                          && self::isDescending($f)) ? 'DESCENT' : 'CRUISE'),
            Flight::PHASE_ARRIVING   => 'ARRIVING',
            Flight::PHASE_FINAL      => 'ARRIVING',
            Flight::PHASE_ON_RUNWAY,
            Flight::PHASE_VACATED    => 'LANDED',
            Flight::PHASE_TAXI_IN    => 'TAXI_IN',
            Flight::PHASE_ARRIVED    => 'IN_BLOCKS',
            Flight::PHASE_DISCONNECTED => 'DISCONNECTED',
            Flight::PHASE_WITHDRAWN  => 'WITHDRAWN',
            default                  => $f->phase ?? 'UNKNOWN',
        };
    }

    /**
     * Heuristic: is this ENROUTE flight descending toward its destination?
     * True when altitude is below filed cruise AND within the 3° TOD
     * distance of the destination airport.
     */
    private static function isDescending(Flight $f): bool
    {
        $alt = (int) ($f->last_altitude_ft ?? 0);
        $fp  = (int) ($f->fp_altitude_ft ?? 35000);

        // Must be below filed cruise altitude
        if ($alt >= $fp - 2000) {
            return false;
        }

        // Must be within TOD distance of destination
        if ($f->ades === null || $f->last_lat === null || $f->last_lon === null) {
            return false;
        }
        $destAirport = Airport::where('icao', $f->ades)->first();
        if ($destAirport === null) {
            return false;
        }
        $distNm = Geo::distanceNm(
            (float) $f->last_lat, (float) $f->last_lon,
            (float) $destAirport->latitude, (float) $destAirport->longitude
        );
        $todNm = ($alt - (int) $destAirport->elevation_ft) / 318.0;

        return $distNm <= $todNm * 1.3; // 30% margin for STAR routing
    }

    private static function json(ResponseInterface $res, mixed $payload): ResponseInterface
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json');
    }
}
