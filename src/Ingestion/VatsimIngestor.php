<?php

declare(strict_types=1);

namespace Atfm\Ingestion;

use Atfm\Allocator\FlightKey;
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
                'User-Agent' => 'atfm-tools/0.3 (+https://github.com/skyelaird/atfm-tools)',
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

        // 4. Mark flights as DISCONNECTED if they weren't in the snapshot.
        // Only consider non-terminal, non-already-disconnected flights that
        // are in our active scope to avoid hammering a huge UPDATE.
        $disconnected = 0;
        $candidates = Flight::whereNotIn('phase', [
                Flight::PHASE_ARRIVED,
                Flight::PHASE_WITHDRAWN,
                Flight::PHASE_DISCONNECTED,
            ])
            ->where('last_updated_at', '>=', $now->modify('-7 days')->format('Y-m-d H:i:s'))
            ->get(['id', 'flight_key', 'phase']);

        foreach ($candidates as $flight) {
            if (! isset($seenFlightKeys[$flight->flight_key])) {
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
                $flight->planned_exot_min = $adepAirport['default_exot_min'];
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
                // Compute EXOT only when we have a meaningful AOBT — i.e. it
                // was stamped on a *previous* ingest cycle (not this one),
                // and the gap is in a sane 1-60 min range. WJA1034 taught us
                // 123-min EXOTs come from pilots who spawn-then-idle.
                if ($flight->aobt !== null
                    && $flight->aobt < $now
                    && ! $sawAobtThisCycle
                ) {
                    $diffSeconds = $now->getTimestamp() - $flight->aobt->getTimestamp();
                    if ($diffSeconds >= 60 && $diffSeconds <= 3600) {
                        $flight->actual_exot_min = (int) round($diffSeconds / 60);
                    }
                }
            }
        }
        // ELDT refresh — every cycle, for any non-terminal flight inbound
        // to one of our airports. Previously we only stamped ELDT once
        // when phase entered ARRIVING (~50 NM out), which meant the
        // dashboard showed "—" for every long-range inbound. The user
        // wants to see ELDT all the way out, since inbound load planning
        // depends on it.
        //
        // Delegates to EtaEstimator's 5-tier cascade so we get the best
        // available estimate (OBSERVED_POS for airborne; FILED for ground;
        // CALC_FILED_TAS / CALC_TYPE_TAS / CALC_DEFAULT for fallback).
        //
        // Once the flight reaches FINAL/ON_RUNWAY/VACATED/etc the ALDT
        // ratchet below takes over and ELDT becomes irrelevant (real
        // landing is observed), so we skip the refresh in those phases.
        if ($adesAirport !== null
            && in_array($phase, [
                Flight::PHASE_TAXI_OUT,
                Flight::PHASE_DEPARTED,
                Flight::PHASE_ENROUTE,
                Flight::PHASE_ARRIVING,
            ], true)
        ) {
            $airportRow = $this->airportModelsByIcao[$adesAirport['icao']] ?? null;
            if ($airportRow !== null) {
                $est = \Atfm\Allocator\EtaEstimator::estimate($flight, $airportRow, $now);
                if ($est['epoch'] !== null) {
                    $flight->eldt = (new DateTimeImmutable('@' . $est['epoch']))
                        ->setTimezone(new DateTimeZone('UTC'));
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

        // AIBT ratchet: only ARRIVED implies full in-block — but to give EXIT
        // a chance at being a non-degenerate number on a 5-min ingest cadence,
        // we deliberately delay AIBT by one cycle. First ARRIVED observation
        // stamps ALDT (via the block above); the next ARRIVED observation
        // stamps AIBT, giving us a real ALDT→AIBT delta of at least one
        // ingest period.
        if ($phase === Flight::PHASE_ARRIVED && $previousPhase === Flight::PHASE_ARRIVED) {
            if ($flight->aibt === null) {
                $flight->aibt = $now;
                // EXIT requires ALDT stamped on a *previous* cycle. Same sanity
                // cap as EXOT — anything > 60 min is implausible taxi-in for
                // these airports and almost certainly an artifact.
                if ($flight->aldt !== null && $flight->aldt < $now) {
                    $diffSeconds = $now->getTimestamp() - $flight->aldt->getTimestamp();
                    if ($diffSeconds >= 60 && $diffSeconds <= 3600) {
                        $flight->actual_exit_min = (int) round($diffSeconds / 60);
                    }
                }
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
