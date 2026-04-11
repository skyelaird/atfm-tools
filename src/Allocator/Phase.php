<?php

declare(strict_types=1);

namespace Atfm\Allocator;

use Atfm\Models\Airport;
use Atfm\Models\Flight;

/**
 * Phase computation from observed position + airport context.
 *
 * The ROT tracker's state machine does higher-fidelity work (threshold
 * crossings, runway polygons). This class implements the coarser phase
 * buckets used by the allocator's main ingest loop — good enough to
 * classify a flight for demand/supply purposes without needing the full
 * tracker.
 *
 * Phases (see docs/ARCHITECTURE.md §7 for the full state machine):
 *   FILED       on ground, at ADEP, gs < 5
 *   TAXI_OUT    on ground, at ADEP, gs >= 5 and gs <= 50
 *   DEPARTED    airborne, within ~30 nm of ADEP, altitude < 5000 AGL
 *   ENROUTE     airborne, more than 40 nm from ADES, or high-altitude cruise
 *   ARRIVING    airborne, within 40 nm of ADES, descending
 *   FINAL       (left to ROT tracker — requires runway geometry)
 *   TAXI_IN     on ground, at ADES, gs > 0
 *   ARRIVED     on ground, at ADES, gs < 5 (stable for 10 min → finalized)
 *
 * DISCONNECTED and WITHDRAWN are set elsewhere by the ingest loop based
 * on feed-presence and timeout state.
 */
final class Phase
{
    public const AIRBORNE_GS_THRESHOLD = 50;
    public const TAXI_GS_THRESHOLD     = 5;
    public const APPROACH_NM           = 40;
    public const NEAR_DEP_NM           = 30;
    public const LOW_ALT_AGL_FT        = 5000;

    /**
     * Compute a phase from the raw position + airport coordinates.
     *
     * @param array|null $adepAirport Airport row (array) or null if unknown
     * @param array|null $adesAirport
     */
    public static function compute(
        ?float $lat,
        ?float $lon,
        ?int $altitudeFt,
        ?int $groundspeedKts,
        ?array $adepAirport,
        ?array $adesAirport
    ): string {
        $gs = $groundspeedKts ?? 0;
        $airborne = $gs > self::AIRBORNE_GS_THRESHOLD;

        if ($lat === null || $lon === null) {
            return Flight::PHASE_FILED;
        }

        // Distance to ADES (for arriving / cruising classification)
        $distToAdes = null;
        if ($adesAirport !== null) {
            $distToAdes = Geo::distanceNm($lat, $lon, $adesAirport['latitude'], $adesAirport['longitude']);
        }

        // Distance to ADEP (for taxi / departed classification)
        $distToAdep = null;
        if ($adepAirport !== null) {
            $distToAdep = Geo::distanceNm($lat, $lon, $adepAirport['latitude'], $adepAirport['longitude']);
        }

        if ($airborne) {
            // Near destination, descending → ARRIVING
            if ($distToAdes !== null
                && $distToAdes <= self::APPROACH_NM
                && $adesAirport !== null
                && $altitudeFt !== null
                && ($altitudeFt - $adesAirport['elevation_ft']) < 15000) {
                return Flight::PHASE_ARRIVING;
            }
            // Near departure, low altitude → DEPARTED
            if ($distToAdep !== null
                && $distToAdep <= self::NEAR_DEP_NM
                && $adepAirport !== null
                && $altitudeFt !== null
                && ($altitudeFt - $adepAirport['elevation_ft']) < self::LOW_ALT_AGL_FT) {
                return Flight::PHASE_DEPARTED;
            }
            return Flight::PHASE_ENROUTE;
        }

        // On the ground. Where?
        $geofenceAdes = $adesAirport['arrived_geofence_nm'] ?? 5;
        $geofenceAdep = $adepAirport['arrived_geofence_nm'] ?? 5;

        if ($distToAdes !== null && $distToAdes <= $geofenceAdes) {
            if ($gs < self::TAXI_GS_THRESHOLD) {
                return Flight::PHASE_ARRIVED;
            }
            return Flight::PHASE_TAXI_IN;
        }

        if ($distToAdep !== null && $distToAdep <= $geofenceAdep) {
            if ($gs < self::TAXI_GS_THRESHOLD) {
                return Flight::PHASE_FILED;
            }
            return Flight::PHASE_TAXI_OUT;
        }

        // On the ground but not at either configured airport — probably a
        // diversion or a flight-plan ADEP/ADES we don't have. Default to FILED.
        return Flight::PHASE_FILED;
    }
}
