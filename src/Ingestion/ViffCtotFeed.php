<?php

declare(strict_types=1);

namespace Atfm\Ingestion;

/**
 * Read-only view of vIFF's CTOTs and CDM times for our airports (v0.7.78).
 *
 * vIFF is the CTOT authority for our airports (see CLAUDE.md, "CTOT
 * authority"). This class is the display half: it reads vIFF's public API,
 * caches it briefly on disk so every viewer of public/viff-ctot.html shares
 * one upstream fetch, and normalises the records into one flat shape.
 *
 * Two views, two upstream endpoints:
 *
 *   destination — GET /etfms/restricted
 *     Every flight on the network that currently holds a CTOT. Filtered to
 *     arrivals at our airports. This is the view for a US clearance delivery
 *     controller (issue the CTOT as an EDCT) or an FMP watching inbounds.
 *
 *   origin — GET /ifps/depAirport?airport=XXXX
 *     The full departure list for one of our airports, with TOBT/TSAT/TTOT
 *     from the CDM master as well as any CTOT. This is the view for a
 *     controller issuing start-up without the CDM plugin (e.g. CTR working
 *     an unstaffed field top-down).
 *
 * Both endpoints are public; no API key is needed for reads. vIFF's guidance
 * is to poll every ~30 s and avoid overlapping requests, hence CACHE_TTL.
 * On an upstream failure the last good copy is served and marked stale.
 */
final class ViffCtotFeed
{
    public const CACHE_TTL = 30;
    private const DEFAULT_BASE = 'https://api.viffsys.com';

    private string $base;
    private string $cacheDir;

    public function __construct(?string $base = null, ?string $cacheDir = null)
    {
        $this->base     = rtrim($base ?? ($_ENV['VIFF_API_BASE'] ?? self::DEFAULT_BASE), '/');
        $this->cacheDir = $cacheDir ?? (__DIR__ . '/../../data/cache');
    }

    /**
     * Flights holding a vIFF CTOT whose destination is in $airports.
     *
     * @param  string[] $airports ICAO codes in scope
     * @return array{flights: array<int, array<string,mixed>>, fetched_at: ?string, age_s: ?int, stale: bool, error: ?string}
     */
    public function byDestination(array $airports): array
    {
        $got = $this->fetch('/etfms/restricted', 'viff-restricted.json');
        $scope = array_flip($airports);
        $flights = [];
        foreach ($got['data'] as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $f = self::normalise($rec);
            if (isset($scope[$f['ades']])) {
                $flights[] = $f;
            }
        }
        usort($flights, fn ($a, $b) => strcmp($a['ctot'] ?: '9999', $b['ctot'] ?: '9999'));
        return ['flights' => $flights] + self::meta($got);
    }

    /**
     * Departures from each airport in $airports, still on the ground.
     *
     * @param  string[] $airports ICAO codes in scope
     * @return array{flights: array<int, array<string,mixed>>, fetched_at: ?string, age_s: ?int, stale: bool, error: ?string}
     */
    public function byOrigin(array $airports): array
    {
        $flights = [];
        $metas = [];
        foreach ($airports as $icao) {
            $got = $this->fetch(
                '/ifps/depAirport?airport=' . rawurlencode($icao),
                'viff-dep-' . preg_replace('/[^A-Z0-9]/', '', $icao) . '.json'
            );
            $metas[] = $got;
            foreach ($got['data'] as $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                $f = self::normalise($rec);
                // A start-up list: once airborne the flight is no longer
                // anyone's to release, so drop it.
                if ($f['atot'] !== '') {
                    continue;
                }
                $flights[] = $f;
            }
        }
        usort($flights, function ($a, $b) {
            $ka = $a['ctot'] ?: ($a['ttot'] ?: ($a['tobt'] ?: ($a['eobt'] ?: '9999')));
            $kb = $b['ctot'] ?: ($b['ttot'] ?: ($b['tobt'] ?: ($b['eobt'] ?: '9999')));
            return strcmp($ka, $kb);
        });

        // Report the oldest cache across the airports we read, and stale if any were.
        $oldest = null;
        $stale = false;
        $errors = [];
        foreach ($metas as $m) {
            $meta = self::meta($m);
            if ($meta['age_s'] !== null && ($oldest === null || $meta['age_s'] > $oldest['age_s'])) {
                $oldest = $meta;
            }
            $stale = $stale || $meta['stale'];
            if ($meta['error'] !== null) {
                $errors[] = $meta['error'];
            }
        }
        return [
            'flights'    => $flights,
            'fetched_at' => $oldest['fetched_at'] ?? null,
            'age_s'      => $oldest['age_s'] ?? null,
            'stale'      => $stale,
            'error'      => $errors ? implode('; ', array_unique($errors)) : null,
        ];
    }

    /**
     * One flat record per flight. All times are HHMM UTC strings, '' if unset.
     *
     * @param  array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function normalise(array $r): array
    {
        $cdm   = is_array($r['cdmData'] ?? null) ? $r['cdmData'] : [];
        $atfcm = is_array($r['atfcmData'] ?? null) ? $r['atfcmData'] : [];

        $regulation = (string) ($atfcm['mostPenalisingRegulation'] ?? '');
        if ($regulation === '') {
            $regulation = (string) ($r['mostPenalizingAirspace'] ?? '');
        }

        return [
            'callsign'     => strtoupper(trim((string) ($r['callsign'] ?? ''))),
            'adep'         => strtoupper((string) ($r['departure'] ?? '')),
            'ades'         => strtoupper((string) ($r['arrival'] ?? '')),
            'eobt'         => self::hhmm($r['eobt'] ?? ''),
            // CDM master's TOBT wins; else vIFF's own (which may be pilot-set).
            'tobt'         => self::hhmm($cdm['tobt'] ?? '') ?: self::hhmm($r['tobt'] ?? ''),
            'tsat'         => self::hhmm($cdm['tsat'] ?? ''),
            'ttot'         => self::hhmm($cdm['ttot'] ?? ''),
            'ctot'         => self::hhmm($r['ctot'] ?? '') ?: self::hhmm($cdm['ctot'] ?? ''),
            'regulation'   => $regulation,
            'atfcm_status' => (string) ($r['atfcmStatus'] ?? ''),
            'cdm_status'   => (string) ($r['cdmSts'] ?? ''),
            'informed'     => (bool) ($atfcm['informed'] ?? ($r['informed'] ?? false)),
            'excluded'     => (bool) ($atfcm['excluded'] ?? false),
            'dep_info'     => (string) ($cdm['depInfo'] ?? ''),
            'aobt'         => self::hhmm($r['aobt'] ?? ''),
            'atot'         => self::hhmm($r['atot'] ?? ''),
            'eta'          => self::hhmm($r['eta'] ?? ''),
            'taxi_min'     => is_numeric($r['taxi'] ?? null) ? (int) $r['taxi'] : null,
        ];
    }

    /** "121000" → "1210", "1930" → "1930", anything else → "". */
    private static function hhmm(mixed $v): string
    {
        $s = preg_replace('/\D/', '', (string) $v);
        return strlen($s) >= 4 ? substr($s, 0, 4) : '';
    }

    /**
     * @return array{data: array<int,mixed>, fetched_at: ?int, stale: bool, error: ?string}
     */
    private function fetch(string $path, string $cacheName): array
    {
        $file = $this->cacheDir . '/' . $cacheName;
        $mtime = is_file($file) ? (int) filemtime($file) : null;

        if ($mtime !== null && (time() - $mtime) < self::CACHE_TTL) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data)) {
                return ['data' => $data, 'fetched_at' => $mtime, 'stale' => false, 'error' => null];
            }
        }

        $ctx = stream_context_create(['http' => [
            'timeout'       => 8,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\nUser-Agent: atfm-tools (+https://github.com/skyelaird/atfm-tools)\r\n",
        ]]);
        $raw = @file_get_contents($this->base . $path, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if ($status === 200 && is_array($data)) {
            if (!is_dir($this->cacheDir)) {
                @mkdir($this->cacheDir, 0775, true);
            }
            // Write-then-rename so a concurrent reader never sees half a file.
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $raw) !== false) {
                @rename($tmp, $file);
            }
            return ['data' => $data, 'fetched_at' => time(), 'stale' => false, 'error' => null];
        }

        $error = 'vIFF ' . $path . ' → ' . ($status ?: 'no response');
        if ($mtime !== null) {
            $old = json_decode((string) @file_get_contents($file), true);
            if (is_array($old)) {
                return ['data' => $old, 'fetched_at' => $mtime, 'stale' => true, 'error' => $error];
            }
        }
        return ['data' => [], 'fetched_at' => null, 'stale' => true, 'error' => $error];
    }

    /**
     * @param  array{fetched_at: ?int, stale: bool, error: ?string} $got
     * @return array{fetched_at: ?string, age_s: ?int, stale: bool, error: ?string}
     */
    private static function meta(array $got): array
    {
        $at = $got['fetched_at'];
        return [
            'fetched_at' => $at !== null ? gmdate('c', $at) : null,
            'age_s'      => $at !== null ? max(0, time() - $at) : null,
            'stale'      => $got['stale'],
            'error'      => $got['error'],
        ];
    }
}
