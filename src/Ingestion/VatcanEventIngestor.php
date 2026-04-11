<?php

declare(strict_types=1);

namespace Atfm\Ingestion;

use Atfm\Models\EventSource;
use Atfm\Models\ImportedCtot;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;

/**
 * Poll bookings.vatcan.ca/api/event/{event_code} for every active
 * event_source and upsert the results into imported_ctots.
 *
 * See docs/ARCHITECTURE.md §6.2.
 */
final class VatcanEventIngestor
{
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout'         => 15.0,
            'connect_timeout' => 5.0,
            'headers'         => [
                'User-Agent' => 'atfm-tools/0.3 (+https://github.com/skyelaird/atfm-tools)',
                'Accept'     => 'application/json',
            ],
        ]);
    }

    public function run(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stats = ['events_polled' => 0, 'slots_updated' => 0, 'errors' => 0];

        $events = EventSource::all()->filter(fn (EventSource $e) => $e->isLive($now));

        foreach ($events as $event) {
            try {
                $count = $this->ingestEvent($event, $now);
                $stats['slots_updated'] += $count;
                $stats['events_polled']++;
            } catch (\Throwable $e) {
                fwrite(STDERR, "[vatcan-events] {$event->event_code} error: " . $e->getMessage() . "\n");
                $stats['errors']++;
            }
        }

        return $stats;
    }

    private function ingestEvent(EventSource $event, DateTimeImmutable $now): int
    {
        $res = $this->http->get($event->apiUrl());
        $body = (string) $res->getBody();
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new \RuntimeException("Event {$event->event_code}: non-array response");
        }
        if (isset($data['error'])) {
            throw new \RuntimeException("Event {$event->event_code}: " . $data['error']);
        }

        $validFrom  = $event->start_utc ?? $now;
        $validUntil = $event->end_utc   ?? $now->modify('+24 hours');
        $sourceFile = 'vatcan:' . $event->event_code;

        $count = 0;
        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $cid  = isset($entry['cid']) ? (int) $entry['cid'] : null;
            $slot = isset($entry['slot']) ? (string) $entry['slot'] : null;
            if ($cid === null || $slot === null || $slot === '') {
                continue;
            }
            if (! preg_match('/^(\d{2})(\d{2})$/', $slot, $m)) {
                continue;
            }
            // Convert HHMM → datetime on the event's target day (start_utc's date, or today).
            $baseDay = ($event->start_utc ?? $now)->format('Y-m-d');
            $ctot = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                sprintf('%s %s:%s:00', $baseDay, $m[1], $m[2]),
                new DateTimeZone('UTC')
            );
            if ($ctot === false) {
                continue;
            }

            ImportedCtot::updateOrCreate(
                ['source_file' => $sourceFile, 'cid' => $cid],
                [
                    'source_label'             => $event->label,
                    'source_uploaded_at'       => $now,
                    'callsign'                 => null, // cid-only from VATCAN
                    'ctot'                     => $ctot,
                    'most_penalizing_airspace' => 'EVENT:' . $event->event_code,
                    'priority'                 => 50,
                    'valid_from'               => $validFrom,
                    'valid_until'              => $validUntil,
                    'active'                   => true,
                ],
            );
            $count++;
        }

        return $count;
    }
}
