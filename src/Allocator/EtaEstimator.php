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
 *   Tier 1 FILED         filed enroute_time (SimBrief-quality when present)
 *                        → eobt + taxi + enroute_time
 *   Tier 2 OBSERVED_POS  airborne: great-circle from current position
 *                        ÷ observed groundspeed (or 450 kt fallback)
 *                        → now + flight_time_remaining
 *   Tier 3 CALC_FILED_TAS on ground: great-circle(adep, ades) ÷ filed cruise_tas
 *                        → eobt + taxi + cruise_time
 *   Tier 4 CALC_TYPE_TAS  on ground: great-circle ÷ AircraftTas::typicalTas()
 *                        → eobt + taxi + cruise_time
 *   Tier 5 CALC_DEFAULT   on ground: great-circle ÷ 430 kt (last resort)
 *
 * Rule for airborne flights: OBSERVED_POS beats everything else because
 * physical reality > pilot's filing. Filed enroute_time captures the
 * pilot's pre-flight plan but not actual winds / routing / speed choices.
 *
 * Rule for ground flights: filed enroute_time > filed TAS > aircraft-type
 * table > default. Pilots who file enroute_time typically got it from
 * SimBrief which includes wind compensation, so it beats our geometric
 * recomputation.
 *
 * Flights we can't estimate at all (no position, no EOBT, no ADEP coords,
 * no ADES coords) return null → skipped by the allocator this cycle.
 *
 * See docs/ARCHITECTURE.md §2 (non-goals — no winds) and §8 (allocator).
 */
final class EtaEstimator
{
    public const SOURCE_FILED         = 'FILED';
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

            // Still climbing — skip OBSERVED_POS, fall through to
            // ground tiers which use filed ETE or computed TAS.
            // "At cruise" = within 2000ft of filed altitude, OR level
            // flight above FL100 (vertical rate < 1000 fpm, detected
            // by the ingestor and passed as $forceObserved). This
            // catches pilots who cruise below their filed altitude.
            $forceObserved = ($options['force_observed'] ?? false);
            if (!$forceObserved && $currentAlt < $filedAlt - 2000) {
                // Fall through to Tier 1 (FILED) or Tiers 3-5 below.
                // Don't return — let the cascade continue.
            } else {
                // At or near cruise — compute descent-aware ETA from
                // observed position and groundspeed.
                $distNm = Geo::distanceNm(
                    (float) $flight->last_lat,
                    (float) $flight->last_lon,
                    (float) $destAirport->latitude,
                    (float) $destAirport->longitude
                );
                $gs = ($flight->last_groundspeed_kts !== null && $flight->last_groundspeed_kts > 100)
                    ? $flight->last_groundspeed_kts
                    : Geo::DEFAULT_CRUISE_KT;
                $altFt = $currentAlt;
                $descentIas = $flight->aircraft_type
                    ? AircraftTas::descentIasHigh($flight->aircraft_type)
                    : AircraftTas::DEFAULT_DESCENT_IAS_HIGH;
                $etaMin = Geo::etaMinutesWithDescent(
                    $distNm, $gs, $altFt, (int) $destAirport->elevation_ft, $descentIas
                );
                return [
                    'epoch'      => $now->getTimestamp() + (int) round($etaMin * 60),
                    'source'     => self::SOURCE_OBSERVED_POS,
                    'confidence' => 85,
                ];
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
}
