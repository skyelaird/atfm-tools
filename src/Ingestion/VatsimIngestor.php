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
    public const ELDT_LOCK_HORIZON_MIN = 60;

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

        $phase = Phase::compute($lat, $lon, $altitude, $gs, $adepAirport, $adesAirport);

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
                    $eobt = $dof->format('Y-m-d H:i:s');
                } else {
                    $candidate = $now->setTime((int) $m[1], (int) $m[2], 0);
                    if ($candidate->getTimestamp() < $now->getTimestamp() - 1800) {
                        $candidate = $candidate->modify('+1 day');
                    }
                    $eobt = $candidate->format('Y-m-d H:i:s');
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
        }

        // EOBT: always refresh from current flight plan (the pilot may have
        // refiled, or our date-rollover heuristic may have been wrong on the
        // first-seen pass).
        if ($eobt !== null) {
            $flight->eobt = $eobt;
            // Clear downstream derived times so the computation below will
            // regenerate them if the EOBT has actually moved.
            $flight->tobt = null;
            $flight->tsat = null;
            $flight->ttot = null;
        }

        // Update classification from flight plan
        $flight->aircraft_type  = $fp['aircraft_short'] ?? $flight->aircraft_type ?? null;
        $flight->aircraft_faa   = $fp['aircraft']       ?? $flight->aircraft_faa  ?? null;
        $flight->flight_rules   = $fp['flight_rules']   ?? $flight->flight_rules  ?? null;
        $flight->alt_icao       = strtoupper((string) ($fp['alternate'] ?? '')) ?: $flight->alt_icao;
        $flight->fp_route       = $fp['route']          ?? $flight->fp_route;
        $flight->fp_altitude_ft = $this->parseAltitude($fp['altitude'] ?? null) ?? $flight->fp_altitude_ft;
        $flight->fp_cruise_tas  = isset($fp['cruise_tas']) ? (int) $fp['cruise_tas'] : $flight->fp_cruise_tas;
        if ($enrouteTimeMin !== null) {
            $flight->fp_enroute_time_min = $enrouteTimeMin;
        }
        if ($flight->airline_icao === null) {
            $flight->airline_icao = $this->airlineFromCallsign($callsign);
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
        // CRITICAL: only ratchet AOBT if the flight is genuinely transitioning
        // from a pre-departure state (PREFILE/FILED/TAXI_OUT). If we first see
        // a flight mid-cruise (ENROUTE/ARRIVING/etc), stamping AOBT to "now"
        // would be a lie — the flight pushed back hours ago — and would later
        // produce a garbage EXOT once ATOT is observed. Better to leave it null.
        $sawAobtThisCycle = false;
        if (in_array($phase, [
            Flight::PHASE_TAXI_OUT,
            Flight::PHASE_DEPARTED,
        ], true)
            && in_array($previousPhase, [
                null,
                Flight::PHASE_PREFILE,
                Flight::PHASE_FILED,
                Flight::PHASE_TAXI_OUT,
            ], true)
        ) {
            if ($flight->aobt === null) {
                $flight->aobt = $now;
                $sawAobtThisCycle = true;
            }
        }
        if (in_array($phase, [
            Flight::PHASE_DEPARTED,
            Flight::PHASE_ENROUTE,
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
        // This means the ELDT column shows "—" until the flight reaches
        // cruise, then shows a meaningful physics-based estimate. Clean.
        $atCruise = in_array($phase, [Flight::PHASE_ENROUTE], true)
            && ($flight->last_altitude_ft ?? 0) >= (($flight->fp_altitude_ft ?? 35000) - 2000);
        $inApproach = $phase === Flight::PHASE_ARRIVING;

        if ($adesAirport !== null && !$atCruise && !$inApproach) {
            // Not eligible for ELDT — clear any stale value from prior code
            // or a previous phase. The dashboard shows "—" which is honest.
            if ($flight->eldt !== null && $flight->eldt_locked === null) {
                $flight->eldt = null;
            }
        }

        if ($adesAirport !== null && ($atCruise || $inApproach)) {
            $airportRow = $this->airportModelsByIcao[$adesAirport['icao']] ?? null;
            if ($airportRow !== null) {
                $est = \Atfm\Allocator\EtaEstimator::estimate($flight, $airportRow, $now);
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
                    if ($flight->eldt_locked === null && $flight->aldt === null) {
                        $minutesToLanding = ($flight->eldt->getTimestamp() - $now->getTimestamp()) / 60;
                        if ($minutesToLanding <= self::ELDT_LOCK_HORIZON_MIN
                            && $minutesToLanding >= self::ELDT_LOCK_HORIZON_MIN - 4
                        ) {
                            $flight->eldt_locked        = $flight->eldt;
                            $flight->eldt_locked_at     = $now;
                            $flight->eldt_locked_source = $est['source'];
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

        // Non-CDM-airport defaults per the EUROCONTROL Airport CDM Manual.
        // For airports without a real CDM platform we have no TOBT (AO/GH
        // input) and no TSAT (DMAN output), so we cascade:
        //   TOBT ← EOBT  (assume the AO is ready exactly when filed)
        //   TSAT ← TOBT  (assume ATC issues start-up at TOBT)
        //   TTOT = TOBT + EXOT  (canonical formula; with TSAT≡TOBT, equivalent
        //                        to TSAT + EXOT, which is what the manual
        //                        states for the DMAN-equipped case)
        if ($flight->tobt === null && $flight->eobt !== null) {
            $flight->tobt = $flight->eobt;
        }
        if ($flight->tsat === null && $flight->tobt !== null) {
            $flight->tsat = $flight->tobt;
        }
        if ($flight->ttot === null && $flight->tobt !== null && $flight->planned_exot_min !== null) {
            $flight->ttot = $flight->tobt->modify("+{$flight->planned_exot_min} minutes");
        }

        $flight->last_updated_at = $now;

        // Stale TAXI_OUT detection: if a flight has been in TAXI_OUT phase
        // for more than 30 min past its TTOT, something is wrong — pilot
        // is AFK, holding indefinitely, or the sim froze. Flag as FLS-NRA
        // so the FMP knows this slot is unreliable.
        if ($phase === Flight::PHASE_TAXI_OUT
            && $flight->ttot !== null
            && $now->getTimestamp() > $flight->ttot->getTimestamp() + (30 * 60)
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
        // it either landed or disappeared. For ARRIVING/FINAL: likely landed
        // and we missed the transition. For ENROUTE: likely disconnected
        // during cruise and the feed-based disconnect didn't catch it.
        // Either way, remove from the inbound list.
        if (in_array($phase, [Flight::PHASE_ARRIVING, Flight::PHASE_FINAL, Flight::PHASE_ENROUTE], true)
            && $flight->eldt !== null
            && $now->getTimestamp() > $flight->eldt->getTimestamp() + (30 * 60)
        ) {
            $flight->phase = Flight::PHASE_ARRIVED;
            $phase = Flight::PHASE_ARRIVED;
            if ($flight->aldt === null) {
                $flight->aldt = $flight->eldt; // best guess: landed around ELDT
            }
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

    private function airlineFromCallsign(string $callsign): ?string
    {
        if (preg_match('/^([A-Z]{3})\d/', $callsign, $m)) {
            return $m[1];
        }
        return null;
    }
}
