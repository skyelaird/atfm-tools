<?php

declare(strict_types=1);

namespace Atfm\Ingestion;

use Atfm\Models\ImportedCtot;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Scan `storage/imports/ctots/` for newly-uploaded CSV or JSON files,
 * parse them into `imported_ctots` rows, and move processed files to
 * `storage/imports/ctots/processed/<yyyy-mm-dd>/`.
 *
 * See docs/ARCHITECTURE.md §4.8 and §6.3.
 *
 * JSON format: array of objects with callsign OR cid, plus ctot,
 * reason (optional), valid_from (optional), valid_until (optional).
 *
 * CSV format: header row `callsign,cid,ctot,reason,valid_from,valid_until`
 */
final class FileImportIngestor
{
    private string $inboxDir;
    private string $processedDir;

    public function __construct(string $rootDir)
    {
        $this->inboxDir     = $rootDir . '/storage/imports/ctots';
        $this->processedDir = $this->inboxDir . '/processed';
    }

    public function run(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stats = ['files_scanned' => 0, 'files_processed' => 0, 'rows_imported' => 0, 'errors' => 0];

        if (! is_dir($this->inboxDir)) {
            @mkdir($this->inboxDir, 0755, true);
            return $stats;
        }
        if (! is_dir($this->processedDir)) {
            @mkdir($this->processedDir, 0755, true);
        }

        $files = glob($this->inboxDir . '/*.{json,JSON,csv,CSV}', GLOB_BRACE) ?: [];

        foreach ($files as $path) {
            $stats['files_scanned']++;
            try {
                $rowsImported = $this->ingestFile($path, $now);
                $stats['rows_imported'] += $rowsImported;
                $this->moveToProcessed($path, $now);
                $stats['files_processed']++;
            } catch (\Throwable $e) {
                fwrite(STDERR, "[file-import] {$path} error: " . $e->getMessage() . "\n");
                $stats['errors']++;
            }
        }

        return $stats;
    }

    private function ingestFile(string $path, DateTimeImmutable $now): int
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read $path");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $basename = basename($path);

        $entries = match ($ext) {
            'json' => $this->parseJson($contents),
            'csv'  => $this->parseCsv($contents),
            default => throw new \RuntimeException("Unsupported ext: $ext"),
        };

        $count = 0;
        foreach ($entries as $entry) {
            $ctot = $this->parseCtot($entry['ctot'] ?? null, $now);
            if ($ctot === null) {
                continue;
            }
            $callsign = $entry['callsign'] ?? null;
            $cid      = isset($entry['cid']) && $entry['cid'] !== '' ? (int) $entry['cid'] : null;
            if ($callsign === null && $cid === null) {
                continue;
            }

            $validFrom  = $this->parseDateTime($entry['valid_from'] ?? null) ?? $now->modify('-2 hours');
            $validUntil = $this->parseDateTime($entry['valid_until'] ?? null) ?? $now->modify('+12 hours');

            ImportedCtot::updateOrCreate(
                [
                    'source_file' => $basename,
                    'callsign'    => $callsign,
                    'cid'         => $cid,
                ],
                [
                    'source_label'             => $entry['source_label'] ?? null,
                    'source_uploaded_at'       => $now,
                    'ctot'                     => $ctot,
                    'most_penalizing_airspace' => $entry['reason'] ?? null,
                    'priority'                 => (int) ($entry['priority'] ?? 100),
                    'valid_from'               => $validFrom,
                    'valid_until'              => $validUntil,
                    'active'                   => true,
                ],
            );
            $count++;
        }

        return $count;
    }

    private function parseJson(string $s): array
    {
        $data = json_decode($s, true);
        if (! is_array($data)) {
            throw new \RuntimeException('JSON root must be an array');
        }
        return $data;
    }

    private function parseCsv(string $s): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($s));
        if (empty($lines)) {
            return [];
        }
        $header = array_map('trim', str_getcsv(array_shift($lines)));
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = $cols[$i] ?? null;
            }
            $out[] = $row;
        }
        return $out;
    }

    private function parseCtot(?string $s, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($s === null || $s === '') {
            return null;
        }
        // Accept HHMM (today) or ISO 8601.
        if (preg_match('/^(\d{2})(\d{2})$/', trim($s), $m)) {
            return $now->setTime((int) $m[1], (int) $m[2], 0);
        }
        try {
            $dt = new DateTimeImmutable($s, new DateTimeZone('UTC'));
            return $dt;
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDateTime(?string $s): ?DateTimeImmutable
    {
        if ($s === null || $s === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($s, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function moveToProcessed(string $path, DateTimeImmutable $now): void
    {
        $dayDir = $this->processedDir . '/' . $now->format('Y-m-d');
        if (! is_dir($dayDir)) {
            @mkdir($dayDir, 0755, true);
        }
        $dest = $dayDir . '/' . basename($path);
        if (file_exists($dest)) {
            $dest .= '.' . time();
        }
        @rename($path, $dest);
    }
}
