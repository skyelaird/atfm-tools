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

        // 1. Load airport set into memory (small — 7 rows).
        $this->airportsByIcao = [];
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

        // Parse EOBT (HHMM) into a datetime. VATSIM's flight_plan.deptime is
        // HHMM with no date, so we have to guess the date. Heuristic:
        //   - default to today at HHMM
        //   - if that's more than 30 min in the past, roll it forward 24 h
        //     (pilot is filing for the upcoming day, not the current one)
        $eobt = null;
        if (! empty($fp['deptime'])) {
            $dep = str_pad((string) $fp['deptime'], 4, '0', STR_PAD_LEFT);
            if (preg_match('/^(\d{2})(\d{2})$/', $dep, $m)) {
                $candidate = $now->setTime((int) $m[1], (int) $m[2], 0);
                if ($candidate->getTimestamp() < $now->getTimestamp() - 1800) {
                    $candidate = $candidate->modify('+1 day');
                }
                $eobt = $candidate->format('Y-m-d H:i:s');
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

        // Observed A-CDM times — only set once, ratchet-style
        if ($phase === Flight::PHASE_TAXI_OUT && $flight->asat === null) {
            $flight->asat = $now;
            $flight->aobt = $now;
        }
        if ($phase === Flight::PHASE_DEPARTED && $flight->atot === null) {
            $flight->atot = $now;
            if ($flight->asat !== null) {
                $diffSeconds = $now->getTimestamp() - $flight->asat->getTimestamp();
                $flight->actual_exot_min = max(0, (int) round($diffSeconds / 60));
            }
        }
        if ($phase === Flight::PHASE_ARRIVING && $flight->eldt === null) {
            // First time we're arriving — compute a rough ELDT from ETA
            // (the ROT tracker refines this as the flight descends)
            if ($lat !== null && $lon !== null && $adesAirport !== null) {
                $etaMin = \Atfm\Allocator\Geo::etaMinutesFromPosition(
                    $lat, $lon, $adesAirport['latitude'], $adesAirport['longitude'], $gs
                );
                $flight->eldt = $now->modify("+{$etaMin} minutes");
            }
        }
        if ($phase === Flight::PHASE_ARRIVED) {
            if ($flight->aldt === null) {
                $flight->aldt = $now;
            }
            if ($flight->aibt === null) {
                $flight->aibt = $now;
                if ($flight->aldt !== null) {
                    $diffSeconds = $flight->aibt->getTimestamp() - $flight->aldt->getTimestamp();
                    $flight->actual_exit_min = max(0, (int) round($diffSeconds / 60));
                }
            }
        }

        // Non-CDM-airport defaults: TOBT = EOBT, TSAT = TOBT (ICAO fallback)
        if ($flight->tobt === null && $flight->eobt !== null) {
            $flight->tobt = $flight->eobt;
        }
        if ($flight->tsat === null && $flight->tobt !== null) {
            $flight->tsat = $flight->tobt;
        }
        if ($flight->ttot === null && $flight->tsat !== null && $flight->planned_exot_min !== null) {
            $flight->ttot = $flight->tsat->modify("+{$flight->planned_exot_min} minutes");
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
