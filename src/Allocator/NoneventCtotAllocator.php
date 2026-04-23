<?php

declare(strict_types=1);

namespace Atfm\Allocator;

use Atfm\Ingestion\EcfmpClient;
use Atfm\Models\Airport;
use Atfm\Models\NoneventSlot;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Non-event CTOT allocator — clock-aligned 4/hr per ADES.
 *
 * Workflow (see docs/DESIGN-NONEVENT-CTOT.md for the full spec):
 *   1. Reject flights whose ADEP or ADES is a CTP event airport OR
 *      one of our 7 Canadian airports (those are handled elsewhere).
 *   2. Validate the filed route against ECFMP flow measures.
 *      MANDATORY_ROUTE, PROHIBIT, GROUND_STOP → hard reject with guidance.
 *   3. Compute nominal ELDT = EOBT + estimated flight time.
 *      Flight time uses WindEta-style physics if the ADES is in our
 *      airports table, otherwise a great-circle + Mach-0.82 fallback.
 *   4. Find the next free clock-aligned ELDT slot at ADES
 *      (:00/:15/:30/:45 — 4 per hour) >= nominal ELDT.
 *   5. Reverse: CTOT = allocated_ELDT − transit_time.
 *      Freeze both ELDT and CTOT on the slot record.
 *   6. Return {ctot, eldt, slot_id, measures[]}.
 *
 * Slot capacity: one active slot per airport per quarter-hour bin.
 */
final class NoneventCtotAllocator
{
    public const SLOTS_PER_HOUR = 4;
    private const BIN_MINUTES = 60 / self::SLOTS_PER_HOUR;  // 15

    /** @var string[] Airports reserved for CTP event traffic only */
    private array $ctpAirports;

    /** @var string[] Our own Canadian airports — handled by the main allocator */
    private const CANADIAN_AIRPORTS = ['CYHZ', 'CYOW', 'CYUL', 'CYVR', 'CYWG', 'CYYC', 'CYYZ'];

    public function __construct(array $ctpAirports = [])
    {
        $this->ctpAirports = array_map('strtoupper', $ctpAirports);
    }

    /**
     * Request a CTOT for a filed flight.
     *
     * @param array{
     *   callsign:string, adep:string, ades:string, eobt:string, route:string,
     *   aircraft_type?:?string, cruise_fl?:?int, cid?:?int, submitted_by?:?string
     * } $req
     *
     * @return array{ok:bool, ctot?:string, eldt?:string, slot_id?:int,
     *               reason?:string, code?:string, measures?:array, mandatory_route?:string}
     */
    public function request(array $req): array
    {
        $cs    = strtoupper(trim((string) ($req['callsign'] ?? '')));
        $adep  = strtoupper(trim((string) ($req['adep']  ?? '')));
        $ades  = strtoupper(trim((string) ($req['ades']  ?? '')));
        $route = trim((string) ($req['route'] ?? ''));
        $eobt  = trim((string) ($req['eobt']  ?? ''));
        $type  = strtoupper(trim((string) ($req['aircraft_type'] ?? '')));
        $cfl   = $req['cruise_fl'] ?? null;
        $cid   = $req['cid'] ?? null;
        $submittedBy = (string) ($req['submitted_by'] ?? 'pilot');

        // --- 1. Static filter checks
        if ($cs === '' || $adep === '' || $ades === '' || $route === '' || $eobt === '') {
            return ['ok' => false, 'code' => 'MISSING_FIELD',
                    'reason' => 'callsign, adep, ades, route, eobt all required'];
        }
        if (strlen($adep) !== 4 || strlen($ades) !== 4) {
            return ['ok' => false, 'code' => 'BAD_ICAO',
                    'reason' => 'ADEP and ADES must be 4-letter ICAO'];
        }
        if (in_array($adep, $this->ctpAirports, true) || in_array($ades, $this->ctpAirports, true)) {
            $which = in_array($adep, $this->ctpAirports, true) ? 'ADEP' : 'ADES';
            return ['ok' => false, 'code' => 'CTP_EXCLUDED',
                    'reason' => "{$which} is a CTP event airport reserved for CTP participants — select another {$which}"];
        }
        if (in_array($adep, self::CANADIAN_AIRPORTS, true) || in_array($ades, self::CANADIAN_AIRPORTS, true)) {
            return ['ok' => false, 'code' => 'CANADIAN_HANDLED_ELSEWHERE',
                    'reason' => 'ADEP or ADES is a Canadian airport handled by the main atfm-tools allocator'];
        }

        $eobtDt = self::parseUtc($eobt);
        if ($eobtDt === null) {
            return ['ok' => false, 'code' => 'BAD_EOBT',
                    'reason' => 'EOBT must be an ISO 8601 datetime (UTC), e.g. 2026-04-25T14:00:00Z'];
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($eobtDt < $now->modify('-1 hour')) {
            return ['ok' => false, 'code' => 'EOBT_IN_PAST',
                    'reason' => 'EOBT is in the past'];
        }
        if ($eobtDt > $now->modify('+24 hours')) {
            return ['ok' => false, 'code' => 'EOBT_TOO_FAR',
                    'reason' => 'EOBT more than 24 hours ahead — file closer to departure'];
        }

        // --- 2. ECFMP validation
        //     Strip SID / STAR from the route string before feeding to the
        //     ECFMP filter matcher. Keeps the filed_route as-given for the
        //     record. SID/STAR patterns (5-letter + digit ± suffix) would
        //     otherwise generate false positive "waypoint" matches.
        $cleanRoute = self::stripSidStar($route);
        $tokens = preg_split('/\s+/', $cleanRoute, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $measures = EcfmpClient::measuresForFlight($adep, $ades, $cfl, $tokens);
        $hard = EcfmpClient::hardRejects($measures);
        if (!empty($hard)) {
            $m = $hard[0];
            $type = strtoupper((string) ($m['measure']['type'] ?? ''));
            $value = (string) ($m['measure']['value'] ?? '');
            $reason = (string) ($m['reason'] ?? '');
            $ident = (string) ($m['ident'] ?? '?');
            $msg = match ($type) {
                'MANDATORY_ROUTE' => "ECFMP measure {$ident} requires reroute: {$value}. Reason: {$reason}",
                'PROHIBIT'        => "ECFMP measure {$ident} prohibits your filed route. Reason: {$reason}",
                'GROUND_STOP'     => "ECFMP measure {$ident} is a ground stop. Reason: {$reason}",
                default           => "ECFMP measure {$ident} rejects this route: {$reason}",
            };
            return [
                'ok' => false,
                'code' => 'ECFMP_REJECT',
                'reason' => $msg,
                'mandatory_route' => $type === 'MANDATORY_ROUTE' ? $value : null,
                'measures' => $measures,
            ];
        }

        // --- 3. Estimate flight time: great-circle + Mach 0.82 as fallback.
        //     For more accuracy the WindEta + navdata pipeline could plug
        //     in here, but the destination may not be in our airports table
        //     (most non-CTP airports aren't), so we stay generic.
        $adepCoords = AirportCoords::coords($adep);
        $adesCoords = AirportCoords::coords($ades);
        if ($adepCoords === null || $adesCoords === null) {
            return ['ok' => false, 'code' => 'UNKNOWN_AIRPORT',
                    'reason' => 'ADEP or ADES not in airport catalogue — unable to estimate flight time. Contact support if this is a legitimate airport.'];
        }
        $dist = Geo::distanceNm($adepCoords[0], $adepCoords[1],
                                $adesCoords[0], $adesCoords[1]);
        $cruiseFl = (int) ($cfl ?? 370);
        $tas = self::machToTas(0.82, $cruiseFl * 100);
        $transitMin = ($dist / $tas) * 60 + 15;   // 15-min pad for taxi / climb / descent
        $nominalEldt = $eobtDt->modify('+' . (int) round($transitMin) . ' minutes');

        // --- 4. Find the next free clock-aligned slot at ADES >= nominalEldt
        $eldt = self::allocateSlot($ades, $nominalEldt);

        // --- 5. Reverse to CTOT
        $deltaMin = ($eldt->getTimestamp() - $nominalEldt->getTimestamp()) / 60;
        $ctot = $eobtDt->modify('+' . (int) round($deltaMin) . ' minutes');

        // --- 6. Persist + return
        $slot = NoneventSlot::create([
            'cid'           => $cid ? (int) $cid : null,
            'callsign'      => $cs,
            'adep'          => $adep,
            'ades'          => $ades,
            'eobt'          => $eobtDt->format('Y-m-d H:i:s'),
            'ctot'          => $ctot->format('Y-m-d H:i:s'),
            'eldt'          => $eldt->format('Y-m-d H:i:s'),
            'filed_route'   => $route,
            'aircraft_type' => $type ?: null,
            'filed_fl'      => $cfl ? (int) $cfl : null,
            'submitted_by'  => $submittedBy,
            'expires_at'    => $ctot->modify('+15 minutes')->format('Y-m-d H:i:s'),
        ]);

        return [
            'ok'       => true,
            'slot_id'  => $slot->id,
            'ctot'     => $ctot->format('c'),
            'eldt'     => $eldt->format('c'),
            'delay_min'=> (int) round($deltaMin),
            'measures' => $measures,
        ];
    }

    /**
     * Find the next :00/:15/:30/:45 slot at `ades` that has no active
     * NoneventSlot with an ELDT within ±7.5 min of it (the slot bin).
     */
    public static function allocateSlot(string $ades, DateTimeImmutable $earliestEldt): DateTimeImmutable
    {
        $slot = self::ceilToQuarter($earliestEldt);
        $tz = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);

        // Pull all non-released slots at this ADES with eldt >= slot − 1 bin
        // (small lookback to catch collisions near the same quarter).
        for ($attempts = 0; $attempts < 240; $attempts++) {   // 240 quarters = 60 hours, generous
            $binStart = $slot->modify('-' . self::BIN_MINUTES . ' minutes + 1 second');
            $binEnd   = $slot->modify('+' . self::BIN_MINUTES . ' minutes - 1 second');
            $taken = NoneventSlot::where('ades', $ades)
                ->whereNull('released_at')
                ->where('expires_at', '>', $now->format('Y-m-d H:i:s'))
                ->whereBetween('eldt', [
                    $binStart->format('Y-m-d H:i:s'),
                    $binEnd->format('Y-m-d H:i:s'),
                ])
                ->exists();
            if (!$taken) {
                return $slot;
            }
            $slot = $slot->modify('+' . self::BIN_MINUTES . ' minutes');
        }
        // Shouldn't happen; return as-is.
        return $slot;
    }

    private static function ceilToQuarter(DateTimeImmutable $t): DateTimeImmutable
    {
        $m = (int) $t->format('i');
        $ceil = (int) ceil($m / self::BIN_MINUTES) * self::BIN_MINUTES;
        $addMin = $ceil - $m;
        if ($addMin === 60) {
            return $t->setTime((int) $t->format('H') + 1, 0, 0);
        }
        return $t->setTime((int) $t->format('H'), $ceil, 0);
    }

    private static function parseUtc(string $s): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($s, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Strip SID and STAR tokens from a route string.
     *
     * Heuristic: a SID is the FIRST non-ADEP token matching the classic
     * procedure pattern 4-6 alpha chars + 1 digit + optional letter
     * (e.g. ALLRY7, VERDO7, JCOBY4, KISEP4, MZULO3, MRSSH3). A STAR is
     * the LAST non-ADES token matching the same pattern (e.g. DEDKI5,
     * IKLEN3, SEDOG6).
     *
     * The pattern deliberately requires ≥4 letters so airway identifiers
     * like Q97, J95, UL612, N633A are NOT matched. Waypoints without a
     * trailing digit (BARUD, TESPI, DOGAL, etc.) are never matched.
     *
     * Leading ADEP and trailing ADES tokens are also dropped if present,
     * since our allocator takes those as separate form fields.
     *
     * Pilot can paste the full filed route including SID/STAR and the
     * server cleans it up before ECFMP validation.
     */
    public static function stripSidStar(string $route): string
    {
        $toks = preg_split('/\s+/', trim($route), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($toks)) return '';
        $proc = '/^[A-Z]{4,6}\d[A-Z]?$/';
        $icao = '/^[A-Z]{4}$/';

        // Drop leading ADEP (if pilot pasted it) then a leading SID
        if (isset($toks[0]) && preg_match($icao, $toks[0])) array_shift($toks);
        if (isset($toks[0]) && preg_match($proc, $toks[0])) array_shift($toks);

        // Drop trailing ADES, then a trailing STAR
        if (!empty($toks) && preg_match($icao, end($toks))) array_pop($toks);
        if (!empty($toks) && preg_match($proc, end($toks))) array_pop($toks);

        return implode(' ', $toks);
    }

    private static function machToTas(float $mach, int $altFt): float
    {
        $tK = $altFt < 36089
            ? 288.15 - 1.9812 * ($altFt / 1000.0)
            : 216.65;
        $aKt = 38.967 * sqrt($tK);
        return $mach * $aKt;
    }
}
