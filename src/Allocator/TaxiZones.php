<?php

declare(strict_types=1);

namespace Atfm\Allocator;

/**
 * Taxi time lookup from apron-zone × runway configuration.
 *
 * Data sourced from the vIFF CDM configuration (CZQM-vACC/CDM/taxizones.txt),
 * originally built from OpenStreetMap Overpass API apron polygons. Each zone
 * is a bounding box (4 corners defining a rectangle) around an apron area.
 * The taxi time (in minutes) varies by which zone the aircraft is parked in
 * and which runway is active.
 *
 * Usage:
 *   $taxi = TaxiZones::lookup('CYYZ', 44.6890, -79.6280, '24L');
 *   // Returns 12 (Terminal 1 → Runway 24L) or null if no zone matches.
 *
 * When no zone matches (aircraft position not inside any defined apron
 * polygon), falls back to null and the caller should use the airport's
 * default_exot_min as before.
 */
final class TaxiZones
{
    /**
     * @var array<string, list<array{
     *   runway: string,
     *   bl_lat: float, bl_lon: float,
     *   tl_lat: float, tl_lon: float,
     *   tr_lat: float, tr_lon: float,
     *   br_lat: float, br_lon: float,
     *   taxi_min: int
     * }>> Keyed by airport ICAO.
     */
    private static ?array $zones = null;

    /**
     * Look up the taxi time for an aircraft parked at (lat, lon) at the
     * given airport, heading to the specified runway.
     *
     * @return int|null Taxi time in minutes, or null if no zone matches.
     */
    public static function lookup(string $icao, float $lat, float $lon, ?string $runway = null): ?int
    {
        self::ensureLoaded();

        $icao = strtoupper($icao);
        if (! isset(self::$zones[$icao])) {
            return null;
        }

        // Find all zones this position falls inside.
        $matches = [];
        foreach (self::$zones[$icao] as $z) {
            if ($runway !== null && strtoupper($z['runway']) !== strtoupper($runway)) {
                continue;
            }
            if (self::pointInBox($lat, $lon, $z)) {
                $matches[] = $z['taxi_min'];
            }
        }

        if (empty($matches)) {
            // No runway specified or no match — try all runways for this
            // position to at least give a zone-based default.
            if ($runway !== null) {
                return self::lookup($icao, $lat, $lon, null);
            }
            return null;
        }

        // If multiple zones match (overlapping polygons), take the median.
        sort($matches);
        return $matches[(int) (count($matches) / 2)];
    }

    /**
     * Get the list of all taxi times for an airport+runway combination,
     * regardless of position. Useful for validation / display.
     *
     * @return list<int> All defined taxi times for this airport+runway.
     */
    public static function allForRunway(string $icao, string $runway): array
    {
        self::ensureLoaded();
        $icao = strtoupper($icao);
        $runway = strtoupper($runway);
        $times = [];
        foreach (self::$zones[$icao] ?? [] as $z) {
            if (strtoupper($z['runway']) === $runway) {
                $times[] = $z['taxi_min'];
            }
        }
        return $times;
    }

    /**
     * Simple axis-aligned bounding-box point-in-rect test. The zones
     * from taxizones.txt are rectangles defined by four corners; we just
     * need min/max lat and min/max lon.
     */
    private static function pointInBox(float $lat, float $lon, array $z): bool
    {
        $lats = [$z['bl_lat'], $z['tl_lat'], $z['tr_lat'], $z['br_lat']];
        $lons = [$z['bl_lon'], $z['tl_lon'], $z['tr_lon'], $z['br_lon']];
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLon = min($lons);
        $maxLon = max($lons);
        return $lat >= $minLat && $lat <= $maxLat && $lon >= $minLon && $lon <= $maxLon;
    }

    private static function ensureLoaded(): void
    {
        if (self::$zones !== null) {
            return;
        }
        self::$zones = [];
        $path = __DIR__ . '/../../data/taxizones.txt';
        if (! file_exists($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            $parts = explode(':', $line);
            if (count($parts) !== 11) {
                continue;
            }
            $icao = strtoupper($parts[0]);
            self::$zones[$icao][] = [
                'runway'  => $parts[1],
                'bl_lat'  => (float) $parts[2],
                'bl_lon'  => (float) $parts[3],
                'tl_lat'  => (float) $parts[4],
                'tl_lon'  => (float) $parts[5],
                'tr_lat'  => (float) $parts[6],
                'tr_lon'  => (float) $parts[7],
                'br_lat'  => (float) $parts[8],
                'br_lon'  => (float) $parts[9],
                'taxi_min' => (int) $parts[10],
            ];
        }
    }
}
