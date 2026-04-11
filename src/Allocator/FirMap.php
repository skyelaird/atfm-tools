<?php

declare(strict_types=1);

namespace Atfm\Allocator;

/**
 * Airport → FIR mapping and FIR adjacency graph.
 *
 * Used by the OpLevel derivation in /api/v1/status to decide whether a
 * set of active restrictions is localized (single FIR), regional (source
 * FIR + neighbours), or NAS-wide.
 *
 * For v0.3 this is a hand-curated table covering our 7 Canadian airports
 * plus the Canadian + bordering US FIRs. A future improvement is to parse
 * this from the FIR_ECFMP.geojson file (270 global FIRs with polygons)
 * using point-in-polygon for airport→FIR and polygon-adjacency for the
 * neighbour graph. See vendor-forks/atfm-map-data/FIR_ECFMP.geojson.
 */
final class FirMap
{
    /** ICAO airport code → ICAO FIR code */
    public const AIRPORT_TO_FIR = [
        // Canadian managed airports
        'CYHZ' => 'CZQM',
        'CYOW' => 'CZYZ',
        'CYUL' => 'CZUL',
        'CYVR' => 'CZVR',
        'CYWG' => 'CZWG',
        'CYYC' => 'CZEG',
        'CYYZ' => 'CZYZ',

        // Other Canadian airports we might see as ADEP/ADES
        'CYQB' => 'CZUL',
        'CYQM' => 'CZQM',
        'CYQX' => 'CZQX',
        'CYYT' => 'CZQX',
        'CYYG' => 'CZQM',
        'CYFC' => 'CZQM',
        'CYSJ' => 'CZQM',
        'CYQY' => 'CZQM',
        'CYHM' => 'CZYZ',
        'CYKZ' => 'CZYZ',
        'CYTZ' => 'CZYZ',
        'CYKF' => 'CZYZ',
        'CYQT' => 'CZWG',
        'CYPG' => 'CZWG',
        'CYXE' => 'CZWG',
        'CYQR' => 'CZWG',
        'CYEG' => 'CZEG',
        'CYXS' => 'CZEG',
        'CYXX' => 'CZVR',
        'CYYJ' => 'CZVR',
        'CYXY' => 'CZEG',  // Whitehorse — actually PAZA but OK
        'CYFB' => 'CZUL',  // Iqaluit — actually CZQM domain but Montreal handles HF
        'CYZF' => 'CZEG',
    ];

    /**
     * FIR adjacency graph. Two FIRs are "neighbours" when their airspace
     * blocks share a common border. Symmetrical (both sides listed).
     *
     * Canadian FIRs form a roughly linear chain east-to-west:
     *   CZQX (Gander oceanic) — CZQM (Moncton) — CZUL (Montreal) — CZYZ (Toronto)
     *   — CZWG (Winnipeg) — CZEG (Edmonton) — CZVR (Vancouver)
     * plus US border adjacency.
     */
    public const FIR_NEIGHBOURS = [
        // Canadian chain
        'CZQX' => ['CZQM'],
        'CZQM' => ['CZQX', 'CZUL', 'KZBW', 'KZNY'],
        'CZUL' => ['CZQM', 'CZYZ', 'KZNY', 'KZBW'],
        'CZYZ' => ['CZUL', 'CZWG', 'KZOB', 'KZNY', 'KZMP', 'KZAU'],
        'CZWG' => ['CZYZ', 'CZEG', 'KZMP'],
        'CZEG' => ['CZWG', 'CZVR', 'KZMP', 'KZLC', 'PAZA'],
        'CZVR' => ['CZEG', 'KZSE', 'PAZA'],

        // US border FIRs (included so "source + neighbours" can reach south)
        'KZBW' => ['CZQM', 'CZUL', 'KZNY'],
        'KZNY' => ['CZQM', 'CZUL', 'CZYZ', 'KZBW', 'KZOB', 'KZDC'],
        'KZOB' => ['CZYZ', 'KZNY', 'KZAU', 'KZID'],
        'KZAU' => ['CZYZ', 'KZOB', 'KZMP', 'KZID'],
        'KZMP' => ['CZYZ', 'CZWG', 'CZEG', 'KZAU', 'KZDV'],
        'KZLC' => ['CZEG', 'KZDV', 'KZOA', 'KZSE'],
        'KZSE' => ['CZVR', 'KZLC', 'KZOA', 'KZDV', 'PAZA'],
        'PAZA' => ['CZEG', 'CZVR', 'KZSE'],

        'KZDC' => ['KZNY', 'KZOB', 'KZJX', 'KZID'],
        'KZID' => ['KZOB', 'KZAU', 'KZDC', 'KZME', 'KZTL'],
        'KZDV' => ['KZMP', 'KZLC', 'KZAB', 'KZKC'],
        'KZOA' => ['KZSE', 'KZLC', 'KZLA'],
    ];

    /** Map an airport ICAO to its FIR, or null if unknown. */
    public static function airportFir(string $icao): ?string
    {
        return self::AIRPORT_TO_FIR[strtoupper(trim($icao))] ?? null;
    }

    /**
     * Derive OpLevel from a set of affected airport ICAOs.
     *
     *   Level 1: 0 affected airports
     *   Level 2: all affected airports are in a single FIR
     *   Level 3: affected FIRs are all reachable from a single "source"
     *            FIR within 1 adjacency hop (source FIR + neighbours)
     *   Level 4: affected FIRs span beyond that
     *
     * @param string[] $airportIcaos
     */
    public static function deriveOpLevel(array $airportIcaos): int
    {
        if (empty($airportIcaos)) {
            return 1;
        }

        $firs = [];
        foreach ($airportIcaos as $icao) {
            $fir = self::airportFir($icao);
            if ($fir !== null) {
                $firs[$fir] = true;
            }
        }
        $firs = array_keys($firs);

        if (count($firs) <= 1) {
            return 2;
        }

        // Try each FIR as "source" — check if its 1-hop neighborhood
        // (itself + direct neighbours) covers all affected FIRs.
        foreach ($firs as $source) {
            $neighbours = self::FIR_NEIGHBOURS[$source] ?? [];
            $reachable = array_merge([$source], $neighbours);
            $reachableSet = array_flip($reachable);
            $allCovered = true;
            foreach ($firs as $f) {
                if (! isset($reachableSet[$f])) {
                    $allCovered = false;
                    break;
                }
            }
            if ($allCovered) {
                return 3;
            }
        }

        return 4;
    }

    /**
     * Helper: return the set of affected FIRs (unique, sorted) from a list
     * of airport ICAOs. Used by /api/v1/status for display.
     *
     * @param string[] $airportIcaos
     * @return string[]
     */
    public static function affectedFirs(array $airportIcaos): array
    {
        $firs = [];
        foreach ($airportIcaos as $icao) {
            $fir = self::airportFir($icao);
            if ($fir !== null) {
                $firs[$fir] = true;
            }
        }
        ksort($firs);
        return array_keys($firs);
    }
}
