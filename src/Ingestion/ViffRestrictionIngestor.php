<?php

declare(strict_types=1);

namespace Atfm\Ingestion;

use Atfm\Models\Airport;
use Atfm\Models\AirportRestriction;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;

/**
 * Mirror vIFF's active arrival restrictions into our own restriction table.
 *
 * The division of labour this implements: a human authors the constraint once,
 * in vIFF, where every CDM controller and the VDGS panel already look. We read
 * it and allocate CTOTs against it with our own ELDT — which is the part we
 * have measured. vIFF owns the constraint; we own the slot.
 *
 * Feed: GET https://viff-system.network/etfms/restrictions?type=ARR
 * Public, unauthenticated, and shaped like this:
 *
 *   [{"airspace":"CYHZ","type":"ARR","capacity":20,"runway":""}, ...]
 *
 * Note what is NOT in it: no id, no start/end window, no reason. The feed is a
 * statement of what is active *right now*, exactly like the CTOT list we serve
 * to the CDM plugin. So presence means active and absence means lifted, and we
 * mirror that by writing rows with a wide-open HHMM window and deleting them
 * the moment they stop appearing.
 *
 * Duplicates do occur (the same airport can appear several times when vIFF has
 * overlapping windows). We take the MOST RESTRICTIVE capacity for an airport —
 * under-delivering slots is recoverable, over-delivering them is not.
 *
 * Safety properties, deliberate:
 *   - Only ever touches rows it authored (source='viff'). An FMP regulation
 *     created in our dashboard is never modified or deleted by this ingestor.
 *   - Only ARR. We do not allocate departure slots.
 *   - Only airports in our scope. Everything else in the feed is ignored.
 *   - Disabled unless VIFF_RESTRICTIONS_ENABLED=true. An external system
 *     causing real CTOTs to be issued to real pilots is an opt-in.
 */
final class ViffRestrictionIngestor
{
    public const SOURCE = 'viff';
    private const DEFAULT_URL = 'https://viff-system.network/etfms/restrictions?type=ARR';

    private Client $http;
    private string $url;

    public function __construct(?Client $http = null, ?string $url = null)
    {
        $this->url  = $url ?? ($_ENV['VIFF_RESTRICTIONS_URL'] ?? self::DEFAULT_URL);
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
        $v = $_ENV['VIFF_RESTRICTIONS_ENABLED'] ?? 'false';
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array{enabled:bool,fetched:int,in_scope:int,created:int,updated:int,released:int,errors:int}
     */
    public function run(): array
    {
        $stats = [
            'enabled'  => self::isEnabled(),
            'fetched'  => 0,
            'in_scope' => 0,
            'created'  => 0,
            'updated'  => 0,
            'released' => 0,
            'errors'   => 0,
        ];

        if (! self::isEnabled()) {
            return $stats;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $res = $this->http->get($this->url);
            $rows = json_decode((string) $res->getBody(), true);
        } catch (\Throwable $e) {
            // A feed we cannot read is NOT an instruction to lift anything.
            // Leave existing mirrored rows in place and try again next cycle:
            // releasing regulations because someone's server blipped would be
            // the worst possible failure mode.
            $stats['errors']++;
            return $stats;
        }

        if (! is_array($rows)) {
            $stats['errors']++;
            return $stats;
        }
        $stats['fetched'] = count($rows);

        // Scope airports, keyed by ICAO.
        $airports = [];
        foreach (Airport::all() as $a) {
            $airports[$a->icao] = $a;
        }

        // Collapse to the most restrictive capacity per in-scope airport.
        $wanted = [];
        foreach ($rows as $r) {
            if (! is_array($r)) continue;
            $icao = strtoupper((string) ($r['airspace'] ?? ''));
            $type = strtoupper((string) ($r['type'] ?? ''));
            $cap  = (int) ($r['capacity'] ?? 0);
            if ($type !== 'ARR' || $cap < 1 || ! isset($airports[$icao])) {
                continue;
            }
            if (! isset($wanted[$icao]) || $cap < $wanted[$icao]) {
                $wanted[$icao] = $cap;
            }
        }
        $stats['in_scope'] = count($wanted);

        $existing = AirportRestriction::query()
            ->whereNull('deleted_at')
            ->where('source', self::SOURCE)
            ->get()
            ->keyBy(fn (AirportRestriction $r) => $r->source_ref);

        foreach ($wanted as $icao => $capacity) {
            $airport = $airports[$icao];
            $row = $existing->get($icao);

            if ($row === null) {
                $row = new AirportRestriction();
                $row->restriction_id = AirportRestriction::generateId($icao);
                $row->source     = self::SOURCE;
                $row->source_ref = $icao;
                $row->airport_id = $airport->id;
                $row->type       = 'ARR';
                $row->reason     = 'VIFF';
                $row->op_level   = 2;
                // Presence in the feed IS the window. A row that stops being
                // published is deleted below, so an always-open HHMM window
                // plus an open-ended expiry is the honest encoding.
                $row->start_utc   = '0000';
                $row->end_utc     = '2359';
                $row->active_from = $now->format('Y-m-d H:i:s');
                $row->expires_at  = null;
                $row->capacity    = $capacity;
                $row->save();
                $stats['created']++;
                continue;
            }

            if ((int) $row->capacity !== $capacity) {
                $row->capacity = $capacity;
                $row->save();
                $stats['updated']++;
            }
        }

        // Absence means lifted — but only for rows we authored.
        foreach ($existing as $ref => $row) {
            if (! isset($wanted[(string) $ref])) {
                $row->delete();
                $stats['released']++;
            }
        }

        return $stats;
    }
}
