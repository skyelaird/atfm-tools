<?php

declare(strict_types=1);

namespace Atfm\Ingestion;

/**
 * ECFMP Flow Measures client.
 *
 * ECFMP (European Central Flow Management Position) publishes a public,
 * unauthenticated REST API at https://ecfmp.vatsim.net/api/v1/flow-measure
 * listing all active flow-control measures — reroutes, level caps,
 * spacing constraints, airspace closures, etc.
 *
 * This class fetches and caches that list, and provides an AND-match
 * helper so the non-event CTOT allocator can answer:
 *   "For this flight (ADEP, ADES, cruise FL, filed route), is there
 *    an active measure that applies? If so, which ones?"
 *
 * ECFMP DOES NOT issue CTOTs — it issues constraints. Our allocator
 * is responsible for turning those constraints into slot timings.
 *
 * Measure types (per ECFMP/flow repo as of 2026-04):
 *   MINIMUM_DEPARTURE_INTERVAL, AVERAGE_DEPARTURE_INTERVAL, PER_HOUR,
 *   MILES_IN_TRAIL, MAX_IAS, MAX_MACH, IAS_REDUCTION, MACH_REDUCTION,
 *   PROHIBIT, MANDATORY_ROUTE, GROUND_STOP
 *
 * Filter types:
 *   ADEP, ADES, waypoint, level_above, level_below, level,
 *   member_event, member_not_event, range_to_destination
 */
final class EcfmpClient
{
    private const API_URL = 'https://ecfmp.vatsim.net/api/v1/flow-measure';
    private const CACHE_TTL_SEC = 120;   // 2 minutes — ECFMP data changes rarely

    /** @var array<int,array<string,mixed>>|null */
    private static ?array $cache = null;
    private static ?int $cacheAt = null;

    /**
     * @return array<int,array<string,mixed>> Current active flow measures
     *         (starttime ≤ now ≤ endtime, not withdrawn).
     */
    public static function activeMeasures(): array
    {
        if (self::$cache !== null && self::$cacheAt !== null
            && (time() - self::$cacheAt) < self::CACHE_TTL_SEC) {
            return self::$cache;
        }

        $raw = self::fetchRaw();
        if ($raw === null) {
            // Fail-soft: return the previous cache even if stale, or empty.
            return self::$cache ?? [];
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $active = [];
        foreach ($raw as $m) {
            if (!empty($m['withdrawn_at'])) continue;
            try {
                $start = new \DateTimeImmutable($m['starttime']);
                $end   = new \DateTimeImmutable($m['endtime']);
            } catch (\Throwable) {
                continue;
            }
            if ($now < $start || $now > $end) continue;
            $active[] = $m;
        }
        self::$cache = $active;
        self::$cacheAt = time();
        return $active;
    }

    /**
     * Return measures that apply to a specific flight.
     *
     * @param string $adep 4-letter ICAO
     * @param string $ades 4-letter ICAO
     * @param int|null $cruiseFl Filed cruise FL (e.g. 370), or null to
     *                 skip level filters
     * @param string[] $routeTokens Tokens in the filed route (waypoints
     *                 and airways). Airways are not expanded — matches
     *                 only explicit waypoint mentions.
     *
     * @return array<int,array<string,mixed>> Matching measures.
     */
    public static function measuresForFlight(
        string $adep,
        string $ades,
        ?int $cruiseFl,
        array $routeTokens
    ): array {
        $adep = strtoupper($adep);
        $ades = strtoupper($ades);
        $tokenSet = array_flip(array_map('strtoupper', $routeTokens));

        $matches = [];
        foreach (self::activeMeasures() as $m) {
            if (self::flightMatches($m, $adep, $ades, $cruiseFl, $tokenSet)) {
                $matches[] = $m;
            }
        }
        return $matches;
    }

    /**
     * AND-match all filters on a measure against a flight.
     *
     * @param array<string,mixed> $measure
     * @param array<string,int> $tokenSet flipped map for O(1) lookup
     */
    private static function flightMatches(
        array $measure,
        string $adep,
        string $ades,
        ?int $cruiseFl,
        array $tokenSet
    ): bool {
        $filters = $measure['filters'] ?? [];
        if (!is_array($filters)) return false;

        foreach ($filters as $f) {
            $type = $f['type'] ?? '';
            $value = $f['value'] ?? null;
            switch ($type) {
                case 'ADEP':
                    if (!is_array($value) || !in_array($adep, $value, true)) return false;
                    break;
                case 'ADES':
                    if (!is_array($value) || !in_array($ades, $value, true)) return false;
                    break;
                case 'waypoint':
                    if (!is_array($value)) return false;
                    $hit = false;
                    foreach ($value as $wp) {
                        if (isset($tokenSet[strtoupper((string) $wp)])) { $hit = true; break; }
                    }
                    if (!$hit) return false;
                    break;
                case 'level_above':
                    if ($cruiseFl === null || $cruiseFl < (int) $value) return false;
                    break;
                case 'level_below':
                    if ($cruiseFl === null || $cruiseFl > (int) $value) return false;
                    break;
                case 'level':
                    if ($cruiseFl === null || $cruiseFl !== (int) $value) return false;
                    break;
                case 'range_to_destination':
                    // Can't meaningfully evaluate without an origin position.
                    // Skip this filter — treat as non-constraining.
                    break;
                case 'member_event':
                case 'member_not_event':
                    // Not applicable to non-event CTP overflow traffic.
                    break;
                default:
                    // Unknown filter — be permissive (don't reject on uncertainty)
                    break;
            }
        }
        return true;
    }

    /**
     * Does the flight hit any HARD measure (MANDATORY_ROUTE or PROHIBIT)?
     *
     * @param array<int,array<string,mixed>> $matches
     * @return array<int,array<string,mixed>> Subset that are hard rejects.
     */
    public static function hardRejects(array $matches): array
    {
        $out = [];
        foreach ($matches as $m) {
            $type = strtoupper((string) ($m['measure']['type'] ?? ''));
            if ($type === 'MANDATORY_ROUTE' || $type === 'PROHIBIT' || $type === 'GROUND_STOP') {
                $out[] = $m;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    //  HTTP fetch
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>>|null */
    private static function fetchRaw(): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header'  => "Accept: application/json\r\nUser-Agent: atfm-tools/0.7 (+https://atfm.momentaryshutter.com)\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents(self::API_URL, false, $ctx);
        if ($body === false || $body === '') return null;
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }
}
