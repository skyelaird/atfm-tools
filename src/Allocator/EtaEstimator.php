<?php

declare(strict_types=1);

namespace Atfm\Allocator;

use Atfm\Models\Airport;
use Atfm\Models\Flight;
use DateTimeImmutable;

/**
 * Tiered ETA cascade — the answer to "how do you estimate ETA when
 * the pilot didn't file one?"
 *
 * VATSIM's data feed has no `eta` field. Pilots may or may not file
 * `enroute_time` (HHMM) or `cruise_tas`. The allocator needs a usable
 * arrival estimate for every flight inbound to a regulated airport in
 * order to allocate a slot, so we cascade:
 *
 * **Airborne (at cruise):**
 *   Tier A1  WIND_GRIB     GRIB wind along resolved route (best: position + real winds, conf 92)
 *   Tier A2  OBSERVED_POS  along-route distance ÷ filed TAS (position-aware, updates every cycle, conf 88-91)
 *   Tier A3  FILED         ATOT + filed ETE (static from takeoff — defensive fallback, unreachable in practice)
 *
 * **Ground / climbing:**
 *   Tier G1  FILED         filed enroute_time (SimBrief-quality when present)
 *                          → eobt + taxi + enroute_time
 *   Tier G1b FIR_EET       ICAO EET/ entries from remarks (dispatch-quality)
 *                          → eobt + taxi + fir_ete + approach time
 *   Tier G2  CALC_FILED_TAS great-circle(adep, ades) ÷ filed TAS
 *   Tier G3  CALC_TYPE_TAS  great-circle ÷ AircraftTas::typicalTas()
 *   Tier G4  CALC_DEFAULT   great-circle ÷ 430 kt (last resort)
 *
 * Rule for airborne flights: WIND_GRIB > OBSERVED_POS > FILED.
 * Position-based estimates update every cycle; FILED is static from
 * takeoff. OBSERVED_POS uses filed TAS for cruise speed (wind-neutral)
 * with GS fallback.
 *
 * Rule for ground flights: filed enroute_time > FIR EET > filed TAS >
 * aircraft-type table > default. Pilots who file enroute_time typically
 * got it from SimBrief which includes wind compensation. FIR EET comes
 * from airline dispatch (wind-corrected, route-aware) and is the next
 * best thing when no filed ETE exists.
 *
 * Flights we can't estimate at all (no position, no EOBT, no ADEP coords,
 * no ADES coords) return null → skipped by the allocator this cycle.
 *
 * See docs/ARCHITECTURE.md §2 (non-goals — no winds) and §8 (allocator).
 */
final class EtaEstimator
{
    public const SOURCE_WIND_GRIB     = 'WIND_GRIB';
    public const SOURCE_FILED         = 'FILED';
    public const SOURCE_FIR_EET       = 'FIR_EET';
    public const SOURCE_OBSERVED_POS  = 'OBSERVED_POS';
    public const SOURCE_CALC_FILED_TAS = 'CALC_FILED_TAS';
    public const SOURCE_CALC_TYPE_TAS  = 'CALC_TYPE_TAS';
    public const SOURCE_CALC_DEFAULT   = 'CALC_DEFAULT';
    public const SOURCE_NONE           = 'NONE';

    /**
     * @return array{epoch:int|null, source:string, confidence:int}
     *         confidence is a rough 0-100 self-rating of the estimate quality
     */
    public static function estimate(Flight $flight, Airport $destAirport, DateTimeImmutable $now, array $options = []): array
    {
        // -- Tier 2: airborne with known position → descent-aware ETA
        //
        // SKIP flights still climbing. During climb the GS and altitude
        // are unrepresentative of cruise — computing ELDT from them
        // produces garbage (e.g. 284kt at 3866ft extrapolated over
        // 1452nm). Wait until the aircraft reaches cruise altitude
        // before computing. The ground-flight tiers (FILED, CALC_*)
        // still provide a usable estimate from EOBT + ETE.
        //
        // "Stable at cruise" = current alt >= 80% of filed cruise alt.
        // This catches top-of-climb within ~5000ft of the filed FL,
        // which is where GS stabilises and becomes representative.
        if ($flight->isAirborne()
            && $flight->last_lat !== null
            && $flight->last_lon !== null) {

            $currentAlt = $flight->last_altitude_ft ?? 0;
            $filedAlt   = $flight->fp_altitude_ft ?? 35000;

            // Mitigate pilots who file an initial low FL to clear
            // domestic EUR traffic (e.g. FL240) then climb to FL380.
            // If the aircraft is 5000+ ft above its filed altitude,
            // use the observed altitude as the effective cruise level.
            $effectiveAlt = max($filedAlt, $currentAlt);

            // Still climbing — skip OBSERVED_POS, fall through to
            // ground tiers which use filed ETE or computed TAS.
            // "At cruise" = within 2000ft of filed altitude, OR level
            // flight above FL100 (vertical rate < 1000 fpm, detected
            // by the ingestor and passed as $forceObserved). This
            // catches pilots who cruise below their filed altitude.
            $forceObserved = ($options['force_observed'] ?? false);
            if (!$forceObserved && $currentAlt < $effectiveAlt - 2000) {
                // Fall through to Tier 1 (FILED) or Tiers 3-5 below.
                // Don't return — let the cascade continue.
            } else {
                // --------------------------------------------------------
                // Airborne ETA cascade (at or near cruise)
                //
                // Priority:
                //   1. WIND_GRIB    — GRIB wind from observed position (best: position + real winds)
                //   2. OBSERVED_POS — geometric distance/TAS from current position (no wind, but position-aware)
                //   3. FILED        — ATOT + filed ETE (SimBrief winds, but static — doesn't track position)
                //
                // OBSERVED_POS beats FILED for airborne flights because a
                // position-based estimate updates every cycle while FILED
                // is static from takeoff. FILED is the better fallback when
                // position data is somehow unavailable (shouldn't happen for
                // an airborne flight, but defensive).
                //
                // All three can carry the flight to the freeze horizon at T-90m.
                // --------------------------------------------------------

                $destLat = (float) $destAirport->latitude;
                $destLon = (float) $destAirport->longitude;
                $aptElev = (int) $destAirport->elevation_ft;

                // --- Priority 1: GRIB wind-corrected ETA ---
                // Compute inline from WindEta when the wind grids are available
                // and the flight is within grid coverage (LAT 25-65, LON -130 to -30).
                // Multi-level: 250mb/300mb/500mb — level selected by cruise altitude.
                $windGrids = WindEta::loadWindGrids();
                if ($windGrids !== null) {
                    $destInfo = [
                        'lat' => $destLat,
                        'lon' => $destLon,
                        'elev' => $aptElev,
                    ];
                    $windEldt = WindEta::computeForFlight($flight, $windGrids, $destInfo);
                    if ($windEldt !== null) {
                        $windEpoch = $windEldt->getTimestamp();
                        $windEtaMin = ($windEpoch - $now->getTimestamp()) / 60.0;
                        if ($windEtaMin > 0) {
                            // Also write eldt_wind for QA comparison
                            if ($flight->eldt_wind === null
                                || abs($flight->eldt_wind->getTimestamp() - $windEpoch) > 60
                            ) {
                                Flight::where('id', $flight->id)
                                    ->update(['eldt_wind' => $windEldt->format('Y-m-d H:i:s')]);
                            }
                            return [
                                'epoch'      => $windEpoch,
                                'source'     => self::SOURCE_WIND_GRIB,
                                'confidence' => 92,
                            ];
                        }
                    }
                }

                // --- Priority 2: geometric OBSERVED_POS ---
                // Distance/TAS from observed position. Position-aware and
                // updates every cycle — better than static FILED for airborne.
                $routeCoords = !empty($flight->fp_route)
                    ? Geo::parseRouteCoordinates($flight->fp_route)
                    : [];
                $distNm = !empty($routeCoords)
                    ? Geo::alongRouteDistanceNm(
                        (float) $flight->last_lat, (float) $flight->last_lon,
                        $destLat, $destLon, $routeCoords
                    )
                    : Geo::distanceNm(
                        (float) $flight->last_lat, (float) $flight->last_lon,
                        $destLat, $destLon
                    );

                // TAS selection with sanity gate
                $filedTas = ($flight->fp_cruise_tas !== null && $flight->fp_cruise_tas >= 120 && $flight->fp_cruise_tas <= 650)
                    ? $flight->fp_cruise_tas
                    : null;
                if ($filedTas !== null && $flight->aircraft_type && AircraftTas::known($flight->aircraft_type)) {
                    $typeTas = AircraftTas::typicalTas($flight->aircraft_type);
                    if (abs($filedTas - $typeTas) > $typeTas * 0.30) {
                        $filedTas = null;
                    }
                }
                $typeFallback = ($flight->aircraft_type && AircraftTas::known($flight->aircraft_type))
                    ? AircraftTas::typicalTas($flight->aircraft_type)
                    : null;
                $observedGs = ($flight->last_groundspeed_kts !== null && $flight->last_groundspeed_kts > 100)
                    ? $flight->last_groundspeed_kts
                    : null;
                $cruiseKt = $filedTas ?? $typeFallback ?? $observedGs ?? Geo::DEFAULT_CRUISE_KT;

                $descentIas = $flight->aircraft_type
                    ? AircraftTas::descentIasHigh($flight->aircraft_type)
                    : AircraftTas::DEFAULT_DESCENT_IAS_HIGH;
                $etaMin = Geo::etaMinutesWithDescent(
                    $distNm, $cruiseKt, $currentAlt, $aptElev, $descentIas
                );

                return [
                    'epoch'      => $now->getTimestamp() + (int) round($etaMin * 60),
                    'source'     => self::SOURCE_OBSERVED_POS,
                    'confidence' => $filedTas !== null ? 91 : 88,
                ];

                // --- Priority 3 (airborne fallback): ATOT + filed enroute time ---
                // Static estimate from takeoff. Only reached if OBSERVED_POS
                // somehow didn't fire (no route coords + no distance).
                // Unreachable in practice for airborne flights with position,
                // but kept as defensive fallback.
                // Note: for ground/climbing flights, FILED is tier 1 below.
            }
            // Still climbing — fall through to ground tiers below.
        }

        // Ground flight cascade (also used for climbing airborne flights
        // that haven't reached cruise yet) — needs an EOBT to anchor.
        if ($flight->eobt === null) {
            return ['epoch' => null, 'source' => self::SOURCE_NONE, 'confidence' => 0];
        }

        $taxiMin = $flight->planned_exot_min ?? (int) $destAirport->default_exot_min;
        $eobtEpoch = $flight->eobt->getTimestamp();

        // -- Tier 1: filed enroute_time (SimBrief-quality when filed)
        if ($flight->fp_enroute_time_min !== null && $flight->fp_enroute_time_min > 0) {
            $epoch = $eobtEpoch + ($taxiMin * 60) + ($flight->fp_enroute_time_min * 60);
            return [
                'epoch'      => $epoch,
                'source'     => self::SOURCE_FILED,
                'confidence' => 90,
            ];
        }

        // -- Tier 1b: FIR EET from ICAO remarks (dispatch-quality)
        //
        // EET/CZYZ0919 = cumulative time from takeoff to CZYZ FIR boundary.
        // Airline dispatch computes these from actual winds and the planned
        // route — wind-corrected, route-following, no SimBrief needed.
        //
        // We add an approach time estimate (FIR boundary → threshold):
        // airport-specific because FIR boundary distance varies greatly.
        // Derived from real flight plans (e.g. THY7JE CZQM0818→CYHZ0902
        // = 44 min intra-FIR). Default 40 min covers most cases.
        if ($flight->fp_fir_ete_min !== null && $flight->fp_fir_ete_min > 0) {
            $approachMin = self::firApproachMinutes($flight->ades);
            $epoch = $eobtEpoch + ($taxiMin * 60)
                   + ($flight->fp_fir_ete_min * 60)
                   + ($approachMin * 60);
            return [
                'epoch'      => $epoch,
                'source'     => self::SOURCE_FIR_EET,
                'confidence' => 80,
            ];
        }

        // Tiers 3-5 need ADEP/ADES coordinates for great-circle computation.
        if (! $flight->adep || ! $flight->ades) {
            return ['epoch' => null, 'source' => self::SOURCE_NONE, 'confidence' => 0];
        }
        $adepCoords = AirportCoords::coords($flight->adep);
        if ($adepCoords === null) {
            // Unknown origin airport — can't geometry our way out of this.
            return ['epoch' => null, 'source' => self::SOURCE_NONE, 'confidence' => 0];
        }

        $distNm = Geo::distanceNm(
            $adepCoords[0], $adepCoords[1],
            (float) $destAirport->latitude, (float) $destAirport->longitude
        );

        // For ground tiers we assume the filed cruise altitude (or 35000
        // as a sensible default for jets) to compute TOD and the descent
        // time penalty. Descent IAS from the aircraft type table.
        $cruiseAlt  = $flight->fp_altitude_ft ?? 35000;
        $aptElev    = (int) $destAirport->elevation_ft;
        $descentIas = $flight->aircraft_type
            ? AircraftTas::descentIasHigh($flight->aircraft_type)
            : AircraftTas::DEFAULT_DESCENT_IAS_HIGH;

        // -- Tier 3: filed cruise TAS
        if ($flight->fp_cruise_tas !== null && $flight->fp_cruise_tas >= 120 && $flight->fp_cruise_tas <= 650) {
            $flightMin = Geo::etaMinutesWithDescent($distNm, $flight->fp_cruise_tas, $cruiseAlt, $aptElev, $descentIas);
            return [
                'epoch'      => $eobtEpoch + ($taxiMin * 60) + (int) round($flightMin * 60),
                'source'     => self::SOURCE_CALC_FILED_TAS,
                'confidence' => 70,
            ];
        }

        // -- Tier 4: aircraft type table
        if ($flight->aircraft_type && AircraftTas::known($flight->aircraft_type)) {
            $tas = AircraftTas::typicalTas($flight->aircraft_type);
            $flightMin = Geo::etaMinutesWithDescent($distNm, $tas, $cruiseAlt, $aptElev, $descentIas);
            return [
                'epoch'      => $eobtEpoch + ($taxiMin * 60) + (int) round($flightMin * 60),
                'source'     => self::SOURCE_CALC_TYPE_TAS,
                'confidence' => 55,
            ];
        }

        // -- Tier 5: default 430 kt
        $flightMin = Geo::etaMinutesWithDescent($distNm, AircraftTas::DEFAULT_TAS_KT, $cruiseAlt, $aptElev, $descentIas);
        return [
            'epoch'      => $eobtEpoch + ($taxiMin * 60) + (int) round($flightMin * 60),
            'source'     => self::SOURCE_CALC_DEFAULT,
            'confidence' => 40,
        ];
    }

    /**
     * Estimated minutes from destination FIR boundary to threshold.
     *
     * These are rough constants derived from examining real flight plans.
     * They account for intra-FIR transit, approach vectoring, STAR routing,
     * and typical sequencing delays. Conservative (slightly long) is better
     * than optimistic since we'd rather predict a late ELDT than an early one.
     *
     * Example calibration: THY7JE LTFM→CYHZ, CZQM0818 + total ETE 0902 = 44 min.
     */
    private static function firApproachMinutes(?string $ades): int
    {
        return match ($ades) {
            // CZQM FIR — CYHZ is ~150nm inside, typical 40-45 min
            'CYHZ'  => 44,
            // CZYZ FIR — CYYZ is deep inside, long STAR routing, ~50 min
            'CYYZ'  => 50,
            // CZYZ FIR — CYOW is near CZUL/CZYZ boundary, ~25 min
            'CYOW'  => 25,
            // CZUL FIR — CYUL is central, ~35 min
            'CYUL'  => 35,
            // CZVR FIR — CYVR is near the coast, long oceanic approach, ~45 min
            'CYVR'  => 45,
            // CZWG FIR — CYWG is central, ~40 min
            'CYWG'  => 40,
            // CZEG FIR — CYYC is in southern Alberta, ~40 min
            'CYYC'  => 40,
            default => 40,
        };
    }
}
