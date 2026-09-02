<?php

declare(strict_types=1);

namespace Atfm\Ingestion;

use Atfm\Models\Airport;
use Atfm\Models\Flight;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;

/**
 * Adopt pilot-declared TOBT from vIFF's VDGS.
 *
 * The problem this solves: the CDM plugin's default private message sends every
 * pilot to vats.im/vdgs, where they can set a TOBT. That value lives in vIFF.
 * If we never read it, a pilot who correctly declares "I'm ready at 1750" gets
 * a slot computed from our own guess of 1735 — and neither system shows the
 * disagreement. Observed live on 2026-09-02: VDGS said the start-up window was
 * open, we said 13 more minutes.
 *
 * Feed (public, unauthenticated, per airport — which suits our seven):
 *
 *   GET https://viff-system.network/ifps/depAirport?airport=CYHZ
 *   [{"callsign":"FAL57","cid":"810489","departure":"CYHZ","eobt":"1735",
 *     "tobt":"1750","obt":"1750","reqTobt":"1750","taxi":10, ...,
 *     "cdmData":{"reqTobt":"1750","reqTobtType":"PILOT","confirmed":true,...}}]
 *
 * `cdmData.reqTobtType` carries attribution — PILOT or ATC — so we know who
 * declared it rather than guessing.
 *
 * Precedence: a declared TOBT beats our `max(EOBT, spawn + 20)` proxy, because
 * the proxy is a population statistic and this is the actual aircraft. It is
 * stored with `tobt_source='cdm'`, which the ingestor already treats as sticky
 * against EOBT jitter.
 *
 * Disabled unless VIFF_PILOT_TOBT_ENABLED=true. It moves TTOT and therefore any
 * CTOT, so adopting an external system's times is opt-in.
 */
final class ViffPilotTobtIngestor
{
    private const BASE = 'https://viff-system.network/ifps/depAirport?airport=';

    private Client $http;
    private string $base;

    public function __construct(?Client $http = null, ?string $base = null)
    {
        $this->base = $base ?? ($_ENV['VIFF_DEPAIRPORT_URL'] ?? self::BASE);
        $this->http = $http ?? new Client([
            'timeout'         => 15.0,
            'connect_timeout' => 5.0,
            'headers'         => [
                'User-Agent' => 'atfm-tools (+https://github.com/skyelaird/atfm-tools)',
                'Accept'     => 'application/json',
            ],
        ]);
    }

    public static function isEnabled(): bool
    {
        $v = $_ENV['VIFF_PILOT_TOBT_ENABLED'] ?? 'false';
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array{enabled:bool,airports:int,rows:int,declared:int,adopted:int,unchanged:int,unmatched:int,errors:int}
     */
    public function run(): array
    {
        $stats = [
            'enabled'   => self::isEnabled(),
            'airports'  => 0,
            'rows'      => 0,
            'declared'  => 0,
            'adopted'   => 0,
            'unchanged' => 0,
            'unmatched' => 0,
            'errors'    => 0,
        ];
        if (! self::isEnabled()) {
            return $stats;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach (Airport::all() as $airport) {
            $stats['airports']++;
            try {
                $res  = $this->http->get($this->base . $airport->icao);
                $rows = json_decode((string) $res->getBody(), true);
            } catch (\Throwable $e) {
                $stats['errors']++;
                continue;
            }
            if (! is_array($rows)) {
                $stats['errors']++;
                continue;
            }

            foreach ($rows as $r) {
                if (! is_array($r)) continue;
                $stats['rows']++;

                $callsign = strtoupper(trim((string) ($r['callsign'] ?? '')));
                if ($callsign === '') continue;

                // Prefer the explicitly-attributed request; fall back to the
                // plain tobt field, which carries the same value once set.
                $cdm      = is_array($r['cdmData'] ?? null) ? $r['cdmData'] : [];
                $declared = trim((string) ($cdm['reqTobt'] ?? '')) ?: trim((string) ($r['reqTobt'] ?? ''));
                $declared = $declared ?: trim((string) ($r['tobt'] ?? ''));
                $setBy    = strtoupper(trim((string) ($cdm['reqTobtType'] ?? '')));

                if (! preg_match('/^\d{4}$/', $declared)) {
                    continue; // nothing declared for this flight
                }
                // Only a human declaration counts. An empty type means vIFF
                // derived it, which is their proxy competing with ours — and
                // ours is calibrated on our own traffic.
                if ($setBy !== 'PILOT' && $setBy !== 'ATC') {
                    continue;
                }
                $stats['declared']++;

                $flight = Flight::where('callsign', $callsign)
                    ->where('adep', $airport->icao)
                    ->whereNull('atot')          // meaningless once airborne
                    ->whereNotIn('phase', [
                        Flight::PHASE_WITHDRAWN,
                        Flight::PHASE_ARRIVED,
                        Flight::PHASE_DISCONNECTED,
                    ])
                    ->orderBy('last_updated_at', 'desc')
                    ->first();

                if (! $flight) {
                    $stats['unmatched']++;
                    continue;
                }

                $tobt = $this->hhmmNear($declared, $flight->eobt ?? $now);
                if ($tobt === null) {
                    continue;
                }

                if ($flight->tobt !== null
                    && $flight->tobt->getTimestamp() === $tobt->getTimestamp()
                    && $flight->tobt_source === 'cdm'
                ) {
                    $stats['unchanged']++;
                    continue;
                }

                $flight->tobt        = $tobt;
                $flight->tobt_source = 'cdm';
                // Recascade immediately rather than waiting for the next ingest
                // cycle to notice — a pilot who moves their TOBT should see the
                // downstream times move with it.
                $flight->tsat = $tobt;
                if ($flight->planned_exot_min !== null) {
                    $flight->ttot = $tobt->modify("+{$flight->planned_exot_min} minutes");
                }
                $flight->save();
                $stats['adopted']++;
            }
        }

        return $stats;
    }

    /**
     * Resolve an HHMM into the absolute time nearest the reference, so a 2350
     * declaration against an 0010 EOBT lands on the previous day rather than
     * 24 hours out.
     */
    private function hhmmNear(string $hhmm, DateTimeImmutable $ref): ?DateTimeImmutable
    {
        $h = (int) substr($hhmm, 0, 2);
        $m = (int) substr($hhmm, 2, 2);
        if ($h > 23 || $m > 59) {
            return null;
        }
        $base = $ref->setTime($h, $m, 0);
        $best = $base;
        foreach ([-1, 1] as $shift) {
            $cand = $base->modify(sprintf('%+d day', $shift));
            if (abs($cand->getTimestamp() - $ref->getTimestamp())
                < abs($best->getTimestamp() - $ref->getTimestamp())) {
                $best = $cand;
            }
        }
        return $best;
    }
}
