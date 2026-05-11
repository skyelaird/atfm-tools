<?php

declare(strict_types=1);

namespace Atfm\Ingestion;

use Atfm\Allocator\FlightKey;
use Atfm\Allocator\Geo;
use Atfm\Allocator\Phase;
use Atfm\Models\Airport;
use Atfm\Models\Flight;
use Atfm\Models\PositionScratch;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;

/**
 * Ingest the VATSIM live data feed and update the `flights` table.
 *
 * See docs/ARCHITECTURE.md §6.1.
 *
 * Responsibilities:
 *   - fetch https://data.vatsim.net/v3/vatsim-data.json
 *   - filter pilots whose adep/ades matches one of our configured airports
 *   - upsert into `flights` keyed by flight_key (PERTI composite)
 *   - populate or refine A-CDM milestones from observed state
 *   - compute and update `phase`
 *   - flag flights that disappeared from the feed as DISCONNECTED
 */
final class VatsimIngestor
{
    public const FEED_URL = 'https://data.vatsim.net/v3/vatsim-data.json';

    /**
     * Validation horizon for the ELDT freeze. When a flight's predicted
     * landing time first drops below this many minutes from now, we
     * snapshot the current ELDT into eldt_locked. The choice of 60 min
     * matches the typical FMP slot-blocking decision horizon. Bump this
     * to test prediction quality at different lookback windows.
     */
    public const ELDT_LOCK_HORIZON_MIN = 92;

    private Client $http;

    /** @var array<string, array> map icao → airport row (flat array) */
    private array $airportsByIcao = [];

    /** @var array<string, Airport> map icao → Airport model (for EtaEstimator) */
    private array $airportModelsByIcao = [];

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout'         => 15.0,
            'connect_timeout' => 5.0,
            'headers'         => [
                // VATSIM feed updates every ~15s. We poll every 2 min
                // (720 req/day) — well within their rate expectations.
                'User-Agent' => 'atfm-tools/' . \Atfm\Version::STRING . ' (+https://github.com/skyelaird/atfm-tools)',
            ],
        ]);
    }

    /** @return array{fetched: int, kept: int, disconnected: int, elapsed_ms: int} */
    public function run(): array
    {
        $start = microtime(true);
        $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // 1. Load airport set into memory (small — 7 rows). We keep both
        // a flat array (cheap to pass around for phase classification)
        // and the full Eloquent model (for EtaEstimator which expects an
        // Airport instance) keyed by ICAO.
        $this->airportsByIcao = [];
        $this->airportModelsByIcao = [];
        foreach (Airport::all() as $airport) {
            $this->airportsByIcao[$airport->icao] = [
                'icao'                => $airport->icao,
                'latitude'            => (float) $airport->latitude,
                'longitude'           => (float) $airport->longitude,
                'elevation_ft'        => (int) $airport->elevation_ft,
                'default_exot_min'    => (int) $airport->default_exot_min,
                'default_exit_min'    => (int) $airport->default_exit_min,
                'arrived_geofence_nm' => (int) $airport->arrived_geofence_nm,
            ];
            $this->airportModelsByIcao[$airport->icao] = $airport;
        }

        if (empty($this->airportsByIcao)) {
            return ['fetched' => 0, 'kept' => 0, 'disconnected' => 0, 'elapsed_ms' => 0];
        }

        // 2. Fetch VATSIM snapshot.
        $res = $this->http->get(self::FEED_URL);
        $payload = json_decode((string) $res->getBody(), true);
        if (! is_array($payload) || ! isset($payload['pilots']) || ! is_array($payload['pilots'])) {
            throw new \RuntimeException('Unexpected VATSIM feed shape');
        }

        $pilots = $payload['pilots'];
        $fetchedCount = count($pilots);

        // 3. Filter + upsert relevant pilots.
        $seenFlightKeys = [];
        $kept = 0;

        foreach ($pilots as $pilot) {
            $fp = $pilot['flight_plan'] ?? null;
            if (! is_array($fp)) {
                continue; // pilot online without a flight plan — can't classify
            }

            $adep = strtoupper(trim((string) ($fp['departure'] ?? '')));
            $ades = strtoupper(trim((string) ($fp['arrival']   ?? '')));

            $matchesAdep = $adep !== '' && isset($this->airportsByIcao[$adep]);
            $matchesAdes = $ades !== '' && isset($this->airportsByIcao[$ades]);
            if (! $matchesAdep && ! $matchesAdes) {
                continue;
            }

            $flightKey = FlightKey::fromVatsimPilot($pilot);
            $seenFlightKeys[$flightKey] = true;

            $this->upsertFlight($pilot, $fp, $flightKey, $adep, $ades, $now);
            $kept++;
        }

        // 4. Mark flights as DISCONNECTED if they weren't in the snapshot
        // OR if they haven't been updated in 15 minutes (belt-and-suspenders
        // for flights that fell through the flight_key matching due to
        // re-filings, CID changes, or feed quirks). The 15-min threshold
        // is 7x the 2-min ingest cadence — generous enough to survive a
        // few missed cycles, tight enough to clear stale flights promptly.
        $disconnected = 0;
        $staleCutoff = $now->modify('-15 minutes')->format('Y-m-d H:i:s');
        $candidates = Flight::whereNotIn('phase', [
                Flight::PHASE_ARRIVED,
                Flight::PHASE_WITHDRAWN,
                Flight::PHASE_DISCONNECTED,
            ])
            ->where('last_updated_at', '>=', $now->modify('-7 days')->format('Y-m-d H:i:s'))
            ->get(['id', 'flight_key', 'phase', 'last_updated_at']);

        foreach ($candidates as $flight) {
            $notInFeed = ! isset($seenFlightKeys[$flight->flight_key]);
            $stale = $flight->last_updated_at < $staleCutoff;

            if ($notInFeed || $stale) {
                $flight->phase = Flight::PHASE_DISCONNECTED;
                $flight->phase_updated_at = $now;
                if ($flight->first_disconnect_at === null) {
                    $flight->first_disconnect_at = $now;
                }
                $flight->save();
                $disconnected++;
            }
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        // Persist completeness snapshot for the dashboard status pill.
        // Small JSON cache — no schema. Lets /api/v1/status report
        // "feed pilots: N, scope: M" without a second DB query.
        try {
            $cacheDir = dirname(__DIR__, 2) . '/data/cache';
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
            @file_put_contents($cacheDir . '/ingest-stats.json', json_encode([
                'at'            => $now->format('c'),
                'feed_pilots'   => $fetchedCount,
                'scope_kept'    => $kept,
                'disconnected'  => $disconnected,
                'elapsed_ms'    => $elapsedMs,
            ]));
        } catch (\Throwable $e) { /* best-effort only */ }

        return [
            'fetched'      => $fetchedCount,
            'kept'         => $kept,
            'disconnected' => $disconnected,
            'elapsed_ms'   => $elapsedMs,
        ];
    }

    private function upsertFlight(
        array $pilot,
        array $fp,
        string $flightKey,
        string $adep,
        string $ades,
        DateTimeImmutable $now
    ): void {
        $callsign   = (string) ($pilot['callsign'] ?? '');
        $cid        = (int)    ($pilot['cid'] ?? 0);
        $lat        = isset($pilot['latitude'])   ? (float) $pilot['latitude']   : null;
        $lon        = isset($pilot['longitude'])  ? (float) $pilot['longitude']  : null;
        $altitude   = isset($pilot['altitude'])   ? (int)   $pilot['altitude']   : null;
        $gs         = isset($pilot['groundspeed']) ? (int)  $pilot['groundspeed'] : null;
        $heading    = isset($pilot['heading'])    ? (int)   $pilot['heading']    : null;

        $adepAirport = $this->airportsByIcao[$adep] ?? null;
        $adesAirport = $this->airportsByIcao[$ades] ?? null;

        $headerAltFt = $this->parseAltitude($fp['altitude'] ?? null);
        $stepClimb   = $this->parseRouteStepClimbs($fp['route'] ?? null);
        $filedAltFt  = max($headerAltFt ?? 0, $stepClimb['max_alt_ft'] ?? 0) ?: null;
        $phase = Phase::compute($lat, $lon, $altitude, $gs, $adepAirport, $adesAirport, $filedAltFt);

        // Parse EOBT (HHMM) into a datetime.
        //
        // First try: extract DOF/YYMMDD from flight_plan.remarks (ICAO
        // standard, pilots who file seriously include it). DOF gives us
        // the authoritative date, combined with deptime HHMM it fully
        // disambiguates "is this for today or tomorrow".
        //
        // Fallback: today at HHMM, rolled forward 24 h if more than 30 min
        // in the past.
        $eobt = null;
        if (! empty($fp['deptime'])) {
            $dep = str_pad((string) $fp['deptime'], 4, '0', STR_PAD_LEFT);
            if (preg_match('/^(\d{2})(\d{2})$/', $dep, $m)) {
                $dof = null;
                if (! empty($fp['remarks']) && preg_match('/\bDOF\/(\d{6})\b/', (string) $fp['remarks'], $dofMatch)) {
                    // DOF is YYMMDD
                    $yy = (int) substr($dofMatch[1], 0, 2);
                    $mm = (int) substr($dofMatch[1], 2, 2);
                    $dd = (int) substr($dofMatch[1], 4, 2);
                    $year = ($yy >= 70) ? (1900 + $yy) : (2000 + $yy);
                    try {
                        $dof = new DateTimeImmutable(
                            sprintf('%04d-%02d-%02d %s:%s:00', $year, $mm, $dd, $m[1], $m[2]),
                            new DateTimeZone('UTC')
                        );
                    } catch (\Throwable) {
                        $dof = null;
                    }
                }

                if ($dof !== null) {
                    $eobt = $dof;
                } else {
                    $candidate = $now->setTime((int) $m[1], (int) $m[2], 0);
                    if ($candidate->getTimestamp() < $now->getTimestamp() - 1800) {
                        $candidate = $candidate->modify('+1 day');
                    }
                    $eobt = $candidate;
                }
            }
        }

        // Parse enroute_time HHMM → total minutes (for ETA quality analysis)
        $enrouteTimeMin = null;
        if (! empty($fp['enroute_time'])) {
            $et = str_pad((string) $fp['enroute_time'], 4, '0', STR_PAD_LEFT);
            if (preg_match('/^(\d{2})(\d{2})$/', $et, $m)) {
                $enrouteTimeMin = ((int) $m[1]) * 60 + (int) $m[2];
                if ($enrouteTimeMin <= 0 || $enrouteTimeMin > 24 * 60) {
                    $enrouteTimeMin = null;
                }
            }
        }

        // Find existing flight.
        /** @var Flight|null $flight */
        $flight = Flight::where('flight_key', $flightKey)->first();
        $isNew = ($flight === null);

        if ($isNew) {
            $flight = new Flight();
            $flight->flight_key      = $flightKey;
            $flight->callsign        = $callsign;
            $flight->cid             = $cid;
            $flight->first_seen_at   = $now;
            $flight->adep            = $adep ?: null;
            $flight->ades            = $ades ?: null;
            if ($adesAirport !== null) {
                $flight->planned_exit_min = $adesAirport['default_exit_min'];
            }
            if ($adepAirport !== null) {
                // Try zone-based taxi time first (apron polygon × runway).
                // Falls back to the airport's flat default if position is
                // unknown or not inside any defined apron zone.
                $zoneTaxi = null;
                if ($lat !== null && $lon !== null) {
                    $zoneTaxi = \Atfm\Allocator\TaxiZones::lookup($adep, $lat, $lon);
                }
                $flight->planned_exot_min = $zoneTaxi ?? $adepAirport['default_exot_min'];
            }
        } else {
            // Reanimation from DISCONNECTED
            if ($flight->phase === Flight::PHASE_DISCONNECTED) {
                $flight->first_disconnect_at = null;
                $flight->reconnect_count     = ($flight->reconnect_count ?? 0) + 1;
            }

            // Stale reconnection: same flight_key but the pilot disappeared
            // for hours and is now back at the gate (same plan, new day or
            // second attempt). Reset milestones so the record is clean.
            // Trigger: flight was terminal (ARRIVED/WITHDRAWN/DISCONNECTED)
            // or has been unseen for 4+ hours, AND is now on the ground at
            // ADEP with low GS.
            $wasTerminal = in_array($flight->phase, [
                Flight::PHASE_ARRIVED, Flight::PHASE_WITHDRAWN, Flight::PHASE_DISCONNECTED,
            ], true);
            $staleHours = $flight->last_updated_at
                ? ($now->getTimestamp() - $flight->last_updated_at->getTimestamp()) / 3600
                : 999;
            // Inline geofence check — $atAdep is computed later (line ~459)
            // so we can't reference it here. This is the same distance test.
            $atGateForReset = $adepAirport !== null
                && $lat !== null && $lon !== null
                && \Atfm\Allocator\Geo::distanceNm($lat, $lon, $adepAirport['latitude'], $adepAirport['longitude'])
                   <= ($adepAirport['arrived_geofence_nm'] ?? 5)
                && ($gs === null || $gs < 5);

            if (($wasTerminal || $staleHours >= 4) && $atGateForReset) {
                // Reset all observed milestones — this is a fresh attempt.
                $flight->first_seen_at    = $now;
                $flight->aobt             = null;
                $flight->atot             = null;
                $flight->aldt             = null;
                $flight->aibt             = null;
                $flight->actual_exot_min  = null;
                $flight->actual_exit_min  = null;
                $flight->eldt             = null;
                $flight->eldt_locked      = null;
                $flight->eldt_locked_at   = null;
                $flight->eldt_locked_source = null;
                $flight->eldt_simbrief    = null;
                $flight->tldt             = null;
                $flight->tldt_assigned_at = null;
                $flight->ctot             = null;
                $flight->delay_minutes    = null;
                $flight->delay_status     = null;
                $flight->phase            = $phase;
                $flight->phase_updated_at = $now;
                $flight->finalized_at     = null;
                $flight->reconnect_count  = ($flight->reconnect_count ?? 0) + 1;
            }
        }

        // EOBT: always refresh from current flight plan (the pilot may have
        // refiled, or our date-rollover heuristic may have been wrong on the
        // first-seen pass).
        if ($eobt !== null) {
            $eobtMoved = ($flight->eobt === null)
                || (abs($flight->eobt->getTimestamp() - $eobt->getTimestamp()) > 300);
            $flight->eobt = $eobt;

            // Only clear downstream derived times if EOBT actually moved
            // AND the TOBT was auto-derived (not manually set by a controller).
            // A manual TOBT survives small EOBT jitter; a genuine refile
            // (>5 min shift) forces a reset even on manual TOBTs.
            if ($eobtMoved) {
                if (!in_array($flight->tobt_source, ['manual', 'cdm'], true)) {
                    $flight->tobt = null;
                    $flight->tsat = null;
                    $flight->ttot = null;
                } else {
                    // Manual TOBT — recascade TSAT/TTOT from the manual TOBT
                    // but don't touch TOBT itself unless refile is large
                    $flight->tsat = null;
                    $flight->ttot = null;
                }
            }
        }

        // Update classification from flight plan
        $flight->aircraft_type  = $fp['aircraft_short'] ?? $flight->aircraft_type ?? null;
        $flight->aircraft_faa   = $fp['aircraft']       ?? $flight->aircraft_faa  ?? null;
        $flight->flight_rules   = $fp['flight_rules']   ?? $flight->flight_rules  ?? null;
        $flight->alt_icao       = strtoupper((string) ($fp['alternate'] ?? '')) ?: $flight->alt_icao;
        $flight->fp_route       = $fp['route']          ?? $flight->fp_route;
        $headerTas = isset($fp['cruise_tas']) ? (int) $fp['cruise_tas'] : null;

        // Step-climb data was parsed once above ($stepClimb). Use the
        // highest altitude and last TAS from mid-route changes when they
        // exceed the header values.
        $flight->fp_altitude_ft = max(
            $headerAltFt ?? $flight->fp_altitude_ft ?? 0,
            $stepClimb['max_alt_ft'] ?? 0
        ) ?: $flight->fp_altitude_ft;

        $flight->fp_cruise_tas = $stepClimb['last_tas_kt']
            ?? $headerTas
            ?? $flight->fp_cruise_tas;
        if ($enrouteTimeMin !== null) {
            $flight->fp_enroute_time_min = $enrouteTimeMin;
        }
        // SimBrief detection — their remarks always contain "SIMBRIEF".
        // SimBrief ETEs are wind-corrected and route-following, so the
        // FILED tier ETA can be trusted more than a manually filed ETE.
        $remarks = (string) ($fp['remarks'] ?? '');
        $flight->is_simbrief = stripos($remarks, 'SIMBRIEF') !== false;

        // FIR EET extraction — ICAO remarks contain EET/FIRCHHM entries
        // giving cumulative time from EOBT to each FIR boundary. These
        // are computed by airline dispatch (winds-corrected, route-aware).
        // Extract the ETE to the destination airport's FIR as fp_fir_ete_min.
        // E.g. for THY→CYYZ: "EET/...CZYZ0919" → 9h19m = 559 min to CZYZ FIR.
        if ($flight->fp_fir_ete_min === null && $ades !== null) {
            $destFir = \Atfm\Allocator\FirMap::airportFir($ades);
            if ($destFir !== null
                && preg_match('/\bEET\/([A-Z0-9 ]+)/', $remarks, $eetBlock)
            ) {
                // Parse all FIR ETE entries: FIRHHMM
                if (preg_match_all('/(' . preg_quote($destFir) . ')(\d{4})/', $eetBlock[1], $eetM)) {
                    // Last match wins (if dest FIR appears multiple times,
                    // the last one is the entry into the FIR closest to dest).
                    $lastIdx = count($eetM[2]) - 1;
                    $h = (int) substr($eetM[2][$lastIdx], 0, 2);
                    $m = (int) substr($eetM[2][$lastIdx], 2, 2);
                    $eteMin = $h * 60 + $m;
                    if ($eteMin > 0 && $eteMin < 1440) { // sanity: < 24h
                        $flight->fp_fir_ete_min = $eteMin;
                    }
                }
            }
        }

        // Snapshot SimBrief ELDT once — the FILED tier ETE from SimBrief
        // is wind-corrected. Store it for triple comparison vs frozen vs ALDT.
        if ($flight->is_simbrief
            && $flight->eldt_simbrief === null
            && $flight->fp_enroute_time_min !== null
            && $flight->fp_enroute_time_min > 0
            && $flight->eobt !== null
            && $adesAirport !== null
        ) {
            $taxiMin = $flight->planned_exot_min ?? $adesAirport['default_exit_min'] ?? 12;
            $sbEpoch = $flight->eobt->getTimestamp()
                     + ($taxiMin * 60)
                     + ($flight->fp_enroute_time_min * 60);
            $flight->eldt_simbrief = (new \DateTimeImmutable('@' . $sbEpoch))
                ->setTimezone(new \DateTimeZone('UTC'));
        }

        if ($flight->airline_icao === null) {
            $flight->airline_icao = $this->airlineFromCallsign($callsign);
        }

        // ---- Accelerated sim-rate detection ----
        //
        // When a pilot runs their sim at 2x/4x realtime (cruise fast-forward),
        // the reported GS from the client is unchanged (sim thinks it's still
        // at cruise speed) but the position advances faster in wall-clock time.
        // Compute effective GS = delta_distance / delta_wall_clock. If the
        // ratio to reported GS is ≥1.3 for 3+ consecutive cycles (~6 min),
        // we've detected accelerated sim. Affects ELDT/TLDT accuracy → flag
        // for the diagnostic panel AND exclude from stats.
        //
        // Only meaningful at cruise: skip ground, taxi, and low-altitude ops.
        if ($lat !== null && $lon !== null
            && $flight->last_lat !== null && $flight->last_lon !== null
            && $flight->last_position_at !== null
            && $gs > 150 && $altitude > 10000) {
            $dtSec = $now->getTimestamp() - $flight->last_position_at->getTimestamp();
            if ($dtSec >= 60 && $dtSec <= 600) {
                $dNm = Geo::distanceNm(
                    (float) $flight->last_lat, (float) $flight->last_lon,
                    $lat, $lon
                );
                $effectiveGs = $dNm / ($dtSec / 3600.0);
                $reportedGs = (float) $gs;
                if ($reportedGs > 100) {
                    $ratio = $effectiveGs / $reportedGs;
                    if ($ratio >= 1.3 && $ratio <= 10) {  // upper cap rejects GPS jumps
                        $flight->sim_accel_cycles = ((int) $flight->sim_accel_cycles) + 1;
                        if ($flight->sim_accel_cycles >= 3) {
                            // Persistent acceleration — record
                            $flight->sim_accel_total_cycles = ((int) $flight->sim_accel_total_cycles) + 1;
                            if ($flight->sim_accel_max_ratio === null
                                || $ratio > (float) $flight->sim_accel_max_ratio) {
                                $flight->sim_accel_max_ratio = round($ratio, 2);
                            }
                        }
                    } else {
                        // Ratio dropped — reset consecutive counter
                        $flight->sim_accel_cycles = 0;
                    }
                }
            }
        }

        // Update position
        $flight->last_lat             = $lat;
        $flight->last_lon             = $lon;
        $flight->last_altitude_ft     = $altitude;
        $flight->last_groundspeed_kts = $gs;
        $flight->last_heading_deg     = $heading;
        $flight->last_position_at     = $now;

        // Refresh taxi time from zone lookup whenever the aircraft is on the
        // ground at its departure airport. The first-insert above uses the
        // initial spawn position; this handles repositioning (pilot tows to a
        // different gate) and gives us a zone-specific value even for flights
        // we first saw mid-taxi.
        if ($gs <= Phase::AIRBORNE_GS_THRESHOLD && $adepAirport !== null && $lat !== null && $lon !== null) {
            $distToAdep = Geo::distanceNm($lat, $lon, $adepAirport['latitude'], $adepAirport['longitude']);
            if ($distToAdep <= ($adepAirport['arrived_geofence_nm'] ?? 5)) {
                $zoneTaxi = \Atfm\Allocator\TaxiZones::lookup($adep, $lat, $lon);
                if ($zoneTaxi !== null) {
                    $flight->planned_exot_min = $zoneTaxi;
                }
            }
        }

        // Milestones: observe transitions and stamp times
        $previousPhase = $flight->phase;
        $flight->phase = $phase;
        if ($previousPhase !== $phase) {
            $flight->phase_updated_at = $now;
        }

        // Observed A-CDM times — ratchet semantics.
        //
        // ASAT (Actual Start-Up Approval Time) is a controller event we cannot
        // observe from VATSIM data, so we never stamp it from the ingestor.
        // It will only ever be populated by an upstream CDM/PERTI feed.
        //
        // AOBT (Actual Off-Block Time) is what we *can* observe: the moment
        // the aircraft is no longer at parking. We approximate it as the first
        // ingest cycle in which the phase is TAXI_OUT or later. Same for ATOT
        // when phase is DEPARTED or later.
        //
        // AOBT (Actual Off-Block Time): the moment the aircraft vacates
        // its parking position. On VATSIM this is the first observation
        // with GS > 0 at the departure airport — i.e. pushback has begun.
        // This is separate from TAXI_OUT phase (GS >= 5) because pushback
        // typically happens at GS 1-3 kt. Without this distinction, AOBT
        // stamps 5-10 minutes late and AXOT is systematically too short.
        //
        // CRITICAL: only stamp if transitioning from a pre-departure state.
        // If we first see a flight mid-cruise, AOBT=now would be a lie.
        $sawAobtThisCycle = false;
        $atAdep = $adepAirport !== null
            && $lat !== null && $lon !== null
            && \Atfm\Allocator\Geo::distanceNm($lat, $lon, $adepAirport['latitude'], $adepAirport['longitude'])
               <= ($adepAirport['arrived_geofence_nm'] ?? 5);
        if ($flight->aobt === null
            && $atAdep
            && $gs !== null && $gs > 0
            && in_array($previousPhase, [
                null,
                Flight::PHASE_PREFILE,
                Flight::PHASE_FILED,
                Flight::PHASE_TAXI_OUT,
            ], true)
        ) {
            $flight->aobt = $now;
            $sawAobtThisCycle = true;
        }
        if (in_array($phase, [
            Flight::PHASE_DEPARTED,
            Flight::PHASE_ENROUTE,
            Flight::PHASE_DESCENT,
            Flight::PHASE_ARRIVING,
            Flight::PHASE_FINAL,
            Flight::PHASE_ON_RUNWAY,
            Flight::PHASE_VACATED,
            Flight::PHASE_TAXI_IN,
            Flight::PHASE_ARRIVED,
        ], true)) {
            if ($flight->atot === null) {
                $flight->atot = $now;
                // Clear FLS-NRA if it was set — the flight actually departed.
                if ($flight->delay_status === 'FLS_NRA') {
                    $flight->delay_status = null;
                }
                // Compute EXOT only when we have a meaningful AOBT — i.e. it
                // was stamped on a *previous* ingest cycle (not this one),
                // and the gap is in a sane 1-60 min range. WJA1034 taught us
                // 123-min EXOTs come from pilots who spawn-then-idle.
                if ($flight->aobt !== null
                    && $flight->aobt->getTimestamp() < $now->getTimestamp()
                    && ! $sawAobtThisCycle
                ) {
                    $diffSeconds = $now->getTimestamp() - $flight->aobt->getTimestamp();
                    if ($diffSeconds >= 60 && $diffSeconds <= 3600) {
                        $flight->actual_exot_min = (int) round($diffSeconds / 60);
                    }
                }
            }
        }
        // ELDT refresh — only for flights that are airborne and at cruise.
        //
        // No ELDT for:
        //   - PREFILE/FILED: flight hasn't departed, ELDT is noise
        //   - TAXI_OUT: on the ground, not airborne yet
        //   - DEPARTED/climbing: GS and altitude aren't representative
        //   - FINAL/ON_RUNWAY/etc: ALDT ratchet takes over
        //
        // ELDT only when:
        //   - ENROUTE at cruise (alt >= filedAlt - 2000ft)
        //   - ARRIVING (already in descent, simple GS-based estimate)
        //
        // Cruise detection: airborne, ENROUTE phase, and either:
        //   (a) within 2000ft of filed altitude, OR
        //   (b) vertical rate < 1000 fpm (level flight, even if below filed alt)
        // The vertical rate check catches pilots who level off below their
        // filed altitude (e.g. filed FL360, cruising at FL334).
        $prevAlt = (int) ($flight->getOriginal('last_altitude_ft') ?? 0);
        $curAlt  = (int) ($altitude ?? 0);
        $vertRateFpm = ($prevAlt > 0 && $curAlt > 0)
            ? abs($curAlt - $prevAlt) / 2  // delta_ft over 2 min = fpm
            : 9999; // unknown — assume climbing
        $nearFiledAlt = $curAlt >= (($flight->fp_altitude_ft ?? 35000) - 2000);
        $levelFlight  = $vertRateFpm < 1000 && $curAlt > 10000; // above FL100 and level

        $atCruise = in_array($phase, [Flight::PHASE_ENROUTE], true)
            && ($nearFiledAlt || $levelFlight);
        $inApproach = in_array($phase, [Flight::PHASE_ARRIVING, Flight::PHASE_DESCENT], true);

        if ($adesAirport !== null && !$atCruise && !$inApproach) {
            // Not eligible for ELDT — clear any stale value from prior code
            // or a previous phase. The dashboard shows "—" which is honest.
            if ($flight->eldt !== null && $flight->eldt_locked === null) {
                $flight->eldt = null;
            }
            // Guard: if a freeze was captured during climb (bad data from
            // record reuse, key disruption, or pre-fix code), clear it so
            // it can re-freeze properly at cruise with good GS/altitude.
            // BUT: don't clear during descent! A flight that was frozen at
            // cruise and is now descending (below filedAlt - 2000 but
            // altitude decreasing) should keep its lock.
            $isClimbing = $vertRateFpm > 500 && $curAlt < (($flight->fp_altitude_ft ?? 35000) - 2000);
            if ($flight->eldt_locked !== null && $flight->aldt === null && $isClimbing) {
                $flight->eldt_locked        = null;
                $flight->eldt_locked_at     = null;
                $flight->eldt_locked_source = null;
                $flight->eldt               = null;
                $flight->tldt               = null;
                $flight->tldt_assigned_at   = null;
                $flight->eldt_perti         = null;
            }
        }

        if ($adesAirport !== null && ($atCruise || $inApproach)) {
            $airportRow = $this->airportModelsByIcao[$adesAirport['icao']] ?? null;
            if ($airportRow !== null) {
                // Force the airborne cascade for descending/arriving flights.
                // Without this an ARRIVING flight at, say, 2000ft fails the
                // "near filed altitude" gate and falls through to the FILED
                // ground tier, producing ATOT + filed enroute_time — wildly
                // wrong once the aircraft is on final.
                $est = \Atfm\Allocator\EtaEstimator::estimate($flight, $airportRow, $now, [
                    'force_observed' => $inApproach || ($levelFlight && !$nearFiledAlt),
                ]);
                $flight->eta_source     = $est['source'];
                $flight->eta_confidence = $est['confidence'];

                if ($est['epoch'] !== null) {
                    $flight->eldt = (new DateTimeImmutable('@' . $est['epoch']))
                        ->setTimezone(new DateTimeZone('UTC'));

                    // ELDT freeze (v0.5.0). Snapshot once when the flight
                    // first crosses inside the validation horizon (default
                    // 60 min before predicted landing). After ALDT lands,
                    // (ALDT − eldt_locked) is the prediction-quality KPI
                    // we surface on the reports page. The 4-minute window
                    // (56..60) accommodates the discreteness of 2-min ingest
                    // cycles — without it, a flight could skip past the
                    // 60-min threshold in a single cycle and never lock.
                    // Only lock from position-based sources — WIND_GRIB or
                    // OBSERVED_POS. Both use the aircraft's real observed
                    // position; WIND_GRIB additionally integrates real winds
                    // along the route and is the preferred airborne source
                    // (v0.5.75+). Planning estimates (FILED, FIR_EET, CALC_*)
                    // are excluded — they produce garbage locks when pilots
                    // depart hours after EOBT.
                    if ($flight->eldt_locked === null && $flight->aldt === null
                        && in_array($est['source'], ['WIND_GRIB', 'OBSERVED_POS'], true)) {
                        $minutesToLanding = ($flight->eldt->getTimestamp() - $now->getTimestamp()) / 60;
                        if ($minutesToLanding <= self::ELDT_LOCK_HORIZON_MIN) {
                            $flight->eldt_locked        = $flight->eldt;
                            $flight->eldt_locked_at     = $now;
                            $flight->eldt_locked_source = $est['source'];

                            // TLDT = frozen ELDT. This is an immovable
                            // arrival slot — the aircraft is airborne and
                            // physics determines when it lands. The allocator
                            // counts this against the declared rate.
                            if ($flight->tldt === null) {
                                $flight->tldt             = $flight->eldt;
                                $flight->tldt_assigned_at = $now;
                            }

                            // Snapshot PERTI's ETA at freeze time for
                            // three-way comparison: ours vs PERTI vs ALDT.
                            $this->snapshotPertiEta($flight);
                        }
                    }
                }
            }
        }

        // ALDT ratchet: any phase that implies we've already landed backfills
        // ALDT. Per ICAO 9971 A-CDM chronology: ON_RUNWAY → VACATED → TAXI_IN →
        // ARRIVED all imply ALDT has happened.
        if (in_array($phase, [
            Flight::PHASE_ON_RUNWAY,
            Flight::PHASE_VACATED,
            Flight::PHASE_TAXI_IN,
            Flight::PHASE_ARRIVED,
        ], true)) {
            if ($flight->aldt === null) {
                $flight->aldt = $now;
            }
        }

        // AIBT ratchet: stamp AIBT when phase=ARRIVED **and** ALDT was
        // stamped on a prior ingest cycle (not the same cycle we're in
        // right now). This is a relaxation of the "two consecutive
        // ARRIVED cycles" rule from v0.3.5: that rule required pilots
        // to stay connected long enough for two ARRIVED observations,
        // which on VATSIM most don't (they disconnect within a couple
        // of minutes of parking). The new rule still avoids the
        // degenerate same-cycle stamp by checking `aldt < now`, but
        // allows the previous cycle to have been any landed phase
        // (TAXI_IN / VACATED / ON_RUNWAY / ARRIVED) — which is the
        // common case for short final → gate transitions.
        // Use integer-second comparison, not datetime objects. Eloquent's
        // datetime cast strips microseconds from $flight->aldt (Carbon
        // stores .000000), but $now retains its original microseconds
        // (.123456). Without this, Carbon(16:08:02.000) < DTI(16:08:02.123)
        // evaluates TRUE even though they're the same wall-clock second,
        // causing AIBT to stamp on the same cycle as ALDT.
        if ($phase === Flight::PHASE_ARRIVED
            && $flight->aibt === null
            && $flight->aldt !== null
            && $flight->aldt->getTimestamp() < $now->getTimestamp()
        ) {
            $flight->aibt = $now;
            // AXIT capped 1-60 min — pilots who park then idle generate
            // outliers that would skew reports otherwise.
            $diffSeconds = $now->getTimestamp() - $flight->aldt->getTimestamp();
            if ($diffSeconds >= 60 && $diffSeconds <= 3600) {
                $flight->actual_exit_min = (int) round($diffSeconds / 60);
            }
        }

        // Non-CDM defaults — only for flights departing a monitored airport
        // where we actually observe the pushback. For non-monitored airports
        // (EDDF, EGLL, etc.) we have no AOBT observation and EOBT is garbage
        // on VATSIM — don't cascade a fictional TOBT/TSAT/TTOT from it.
        if ($adepAirport !== null) {
            // TOBT proxy cascade (v0.5.24). The data shows:
            //   - EOBT median error is -2 min (decent) but tails are ±20+ min
            //   - Spawn-to-pushback dwell averages 20 min across all airports
            //   - Pilots appear ~20 min before EOBT (spawning on time)
            //
            // Strategy: use EOBT as baseline, but snap forward to
            // created_at + DWELL_MIN for late spawners. This corrects
            // the fat right tail (pilots who spawn 30+ min after EOBT)
            // without disrupting the 56% who are within [-15, +5] of EOBT.
            if ($flight->tobt === null && $flight->eobt !== null) {
                $dwellMin = 20; // observed median spawn-to-pushback
                if ($flight->created_at !== null) {
                    $spawnBased = $flight->created_at->modify("+{$dwellMin} minutes");
                    // Take the later of EOBT and spawn+dwell — catches late spawners
                    $flight->tobt = ($spawnBased->getTimestamp() > $flight->eobt->getTimestamp())
                        ? $spawnBased
                        : $flight->eobt;
                } else {
                    $flight->tobt = $flight->eobt;
                }
                $flight->tobt_source = 'auto';
            }
            if ($flight->tsat === null && $flight->tobt !== null) {
                $flight->tsat = $flight->tobt;
            }
            if ($flight->ttot === null && $flight->tobt !== null && $flight->planned_exot_min !== null) {
                $flight->ttot = $flight->tobt->modify("+{$flight->planned_exot_min} minutes");
            }
        }

        $flight->last_updated_at = $now;

        // Belt-and-suspenders: clear FLS-NRA / WITHDRAWN for any flight
        // that is actively moving (AOBT set) or already airborne (ATOT set).
        // Catches false positives from the EOBT-derived TTOT check, and
        // flights whose ATOT was stamped before the clear fix deployed.
        if (in_array($flight->delay_status, ['FLS_NRA', Flight::DELAY_WITHDRAWN], true)
            && ($flight->atot !== null || ($flight->aobt !== null && $phase === Flight::PHASE_TAXI_OUT))
        ) {
            $flight->delay_status = null;
            // Also un-withdraw the phase if it was set to WITHDRAWN
            if ($flight->phase === Flight::PHASE_WITHDRAWN) {
                $flight->phase = $phase; // restore to current observed phase
                $flight->phase_updated_at = $now;
                $flight->finalized_at = null;
            }
        }

        // Stale TAXI_OUT detection: if a flight has been taxiing for more
        // than 30 min since pushback (AOBT), something is wrong — pilot
        // is AFK, holding indefinitely, or the sim froze. Flag as FLS-NRA.
        // Use AOBT (observed pushback) not TTOT (derived from garbage EOBT).
        if ($phase === Flight::PHASE_TAXI_OUT
            && $flight->aobt !== null
            && $now->getTimestamp() > $flight->aobt->getTimestamp() + (30 * 60)
            && $flight->atot === null
        ) {
            $flight->delay_status = 'FLS_NRA';
        }

        // FLS-NRA detection: "Filed, Not Reported Airborne." If the flight
        // has an EOBT in the past, is still on the ground (no ATOT), and
        // hasn't been assigned a CTOT, flag it. This tells the FMP "this
        // slot might open up — the pilot may be a no-show." The threshold
        // is EOBT + planned_exot + 10 min grace. Mirrors vIFF's FLS-NRA
        // status logic.
        if ($flight->eobt !== null
            && $flight->atot === null
            && $flight->ctot === null
            && in_array($phase, [Flight::PHASE_PREFILE, Flight::PHASE_FILED], true)
        ) {
            $graceMin = ($flight->planned_exot_min ?? 15) + 10;
            $deadline = $flight->eobt->getTimestamp() + ($graceMin * 60);
            if ($now->getTimestamp() > $deadline) {
                // FLS-NRA for more than 60 min past the grace deadline →
                // the pilot isn't coming. Withdraw so they don't consume
                // a slot or clutter the inbound/outbound view.
                if ($now->getTimestamp() > $deadline + (60 * 60)) {
                    $flight->phase = Flight::PHASE_WITHDRAWN;
                    $flight->delay_status = Flight::DELAY_WITHDRAWN;
                    $flight->phase_updated_at = $now;
                    $flight->finalized_at = $now;
                } else {
                    $flight->delay_status = 'FLS_NRA';
                }
            }
        }

        // Stale inbound cleanup: if a flight has an ELDT that's 30+ min in
        // the past and it's still showing as inbound (ARRIVING/FINAL/ENROUTE),
        // we lost track of it. Don't fabricate milestones we didn't observe —
        // mark DISCONNECTED and let the normal cleanup handle it.
        if (in_array($phase, [Flight::PHASE_ARRIVING, Flight::PHASE_FINAL, Flight::PHASE_ENROUTE, Flight::PHASE_DESCENT], true)
            && $flight->eldt !== null
            && $now->getTimestamp() > $flight->eldt->getTimestamp() + (30 * 60)
        ) {
            $flight->phase = Flight::PHASE_DISCONNECTED;
            $phase = Flight::PHASE_DISCONNECTED;
            $flight->phase_updated_at = $now;
        }

        // Finalize ARRIVED after a stable 10 min in-block observation
        if ($phase === Flight::PHASE_ARRIVED
            && $flight->aibt !== null
            && ($now->getTimestamp() - $flight->aibt->getTimestamp()) >= 600
            && $flight->finalized_at === null) {
            $flight->finalized_at = $now;
        }

        $flight->save();

        // Append a position_scratch row for this observation. This is the
        // raw history that bin/rot-tracker.php (v0.4) consumes to refine
        // ATOT/ALDT and compute approximate runway occupancy times.
        // Cleanup is bin/cleanup.php's job (48 h retention).
        if ($lat !== null && $lon !== null) {
            PositionScratch::create([
                'flight_id'       => $flight->id,
                'lat'             => $lat,
                'lon'             => $lon,
                'altitude_ft'     => $altitude,
                'groundspeed_kts' => $gs,
                'heading_deg'     => $heading,
                'observed_at'     => $now,
            ]);
        }
    }

    /**
     * Snapshot PERTI's current ETA for a flight at ELDT freeze time.
     * Best-effort — failure is silent (PERTI may be down or slow).
     */
    private function snapshotPertiEta(Flight $flight): void
    {
        if ($flight->eldt_perti !== null) {
            return; // already captured
        }

        // Lazy-load PERTI ADL index (once per ingest run)
        if ($this->pertiAdlIndex === null) {
            $this->pertiAdlIndex = $this->fetchPertiAdlIndex();
        }

        $pf = $this->pertiAdlIndex[$flight->flight_key]
            ?? $this->pertiAdlIndex[$flight->callsign . '|' . $flight->ades]
            ?? null;

        if ($pf !== null && !empty($pf['eta_utc'])) {
            $ts = strtotime($pf['eta_utc']);
            if ($ts && $ts > 0) {
                $flight->eldt_perti = (new DateTimeImmutable('@' . $ts))
                    ->setTimezone(new \DateTimeZone('UTC'));
            }
        }
    }

    /** @var array|null Lazy-loaded PERTI ADL index (flight_key + cs|ades) */
    private ?array $pertiAdlIndex = null;

    private function fetchPertiAdlIndex(): array
    {
        // Re-use the 2-min cache written by the /perti/compare endpoint
        // so we never hit PERTI more than once per 2 min across all callers.
        $cacheFile = sys_get_temp_dir() . '/atfm_perti_adl_cache.json';
        $cacheTtl  = 120;
        $raw = null;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $raw = file_get_contents($cacheFile);
        }
        if (!$raw) {
            $key = 'swim_pub_7783b37a28c167af41788599954e3e39';
            $ctx = stream_context_create([
                'http' => [
                    'header' => "Authorization: Bearer {$key}\r\n",
                    'timeout' => 5,
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $raw = @file_get_contents('https://perti.vatcscc.org/api/adl/current', false, $ctx);
            if ($raw !== false) {
                @file_put_contents($cacheFile, $raw);
            }
        }
        if ($raw === false) {
            return []; // PERTI unreachable — silent fail
        }
        $adl = json_decode($raw, true);
        if (!$adl || !isset($adl['flights'])) {
            return [];
        }

        $index = [];
        foreach ($adl['flights'] as $pf) {
            if (isset($pf['flight_key'])) {
                $index[$pf['flight_key']] = $pf;
            }
            $cs = $pf['callsign'] ?? '';
            $dest = $pf['fp_dest_icao'] ?? '';
            if ($cs && $dest) {
                $index[$cs . '|' . $dest] = $pf;
            }
        }
        return $index;
    }

    private function parseAltitude(?string $alt): ?int
    {
        if ($alt === null || $alt === '') {
            return null;
        }
        if (preg_match('/^FL(\d{2,3})$/i', trim($alt), $m)) {
            return ((int) $m[1]) * 100;
        }
        if (preg_match('/^\d+$/', trim($alt))) {
            return (int) $alt;
        }
        return null;
    }

    /**
     * Extract mid-route speed/level changes from an ICAO route string.
     *
     * ICAO FPL format embeds step-climbs and speed changes at waypoints:
     *   DEXIT/N0483F380   → TAS 483kt at FL380
     *   PIKIL/M085F390    → Mach 0.85 at FL390
     *   JANJO/N0489F400   → TAS 489kt at FL400
     *
     * Returns the highest altitude (ft) and the last TAS (kt) found.
     * Pilots who file an initial low FL (e.g. FL240 for European departure)
     * typically step-climb to their real cruise level mid-route — this
     * extracts that real cruise so we don't rely on the garbage initial.
     *
     * @return array{max_alt_ft: int|null, last_tas_kt: int|null}
     */
    private function parseRouteStepClimbs(?string $route): array
    {
        if ($route === null || $route === '') {
            return ['max_alt_ft' => null, 'last_tas_kt' => null];
        }

        $maxAlt  = null;
        $lastTas = null;

        // Match: FIX/N0489F400  or  FIX/M085F390  or  /N0483F380
        // N = TAS in kt (4 digits), M = Mach (3 digits)
        // F = flight level (3 digits), A = altitude in 100s ft
        if (preg_match_all('/\/(N(\d{4})|M(\d{3}))(F(\d{3})|A(\d{3}))/', $route, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                // Speed: N0489 = 489kt, M085 = Mach 0.85 (approx 490kt at FL350+)
                if (!empty($m[2])) {
                    $lastTas = (int) $m[2];
                } elseif (!empty($m[3])) {
                    // Mach to TAS rough conversion: M * 580 at typical cruise alt
                    $lastTas = (int) round(((int) $m[3]) / 100 * 580);
                }
                // Altitude: F390 = FL390 = 39000ft, A080 = 8000ft
                if (!empty($m[5])) {
                    $altFt = ((int) $m[5]) * 100;
                    if ($maxAlt === null || $altFt > $maxAlt) {
                        $maxAlt = $altFt;
                    }
                } elseif (!empty($m[6])) {
                    $altFt = ((int) $m[6]) * 100;
                    if ($maxAlt === null || $altFt > $maxAlt) {
                        $maxAlt = $altFt;
                    }
                }
            }
        }

        return ['max_alt_ft' => $maxAlt, 'last_tas_kt' => $lastTas];
    }

    private function airlineFromCallsign(string $callsign): ?string
    {
        if (preg_match('/^([A-Z]{3})\d/', $callsign, $m)) {
            return $m[1];
        }
        return null;
    }
}
