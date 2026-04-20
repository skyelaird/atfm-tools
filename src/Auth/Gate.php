<?php

declare(strict_types=1);

namespace Atfm\Auth;

use Atfm\Api\Kernel;
use Atfm\Models\Flight;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Authorization gate — single choke-point for "can this caller do X".
 *
 * **Permissive by default.** Without VATSIM Connect credentials in
 * `.env`, every gate returns `true` so the system behaves like an open
 * portal (trust-based, callsign-self-declared). This is the deliberate
 * stance while there's no installed user base.
 *
 * **Strict mode** flips on when `AUTH_STRICT=true` in `.env`. All gates
 * then require a valid session (from `Kernel::currentUser()`) and
 * enforce role/identity rules:
 *
 *   - `modifyFlight` : logged-in CID must match `flight.cid`, OR
 *                      logged-in user is connected as a controller at
 *                      the flight's ADEP (tower/ground/ramp) or ADES
 *                      (approach/arrival).
 *   - `createRestriction`, `liftRestriction` : logged-in user connected
 *                      as a controller with facility = airport or FIR.
 *   - `readAll`       : always true (public read).
 *
 * Usage:
 *   if (!Gate::modifyFlight($req, $flight)) {
 *       return self::json($res->withStatus(403), ['error' => 'not authorized']);
 *   }
 *
 * When auth goes live, the Gate is the ONLY place that needs tightening.
 * All sensitive endpoints route their permission check through here.
 */
final class Gate
{
    private static function strictMode(): bool
    {
        $v = $_ENV['AUTH_STRICT'] ?? getenv('AUTH_STRICT') ?? 'false';
        return $v === 'true' || $v === '1';
    }

    /**
     * Can this request modify the given flight (set TOBT, acknowledge CTOT,
     * etc.)? In permissive mode: always yes. In strict mode: caller CID
     * must match flight.cid, OR caller is a controller covering the
     * flight's airport(s).
     */
    public static function modifyFlight(ServerRequestInterface $req, Flight $flight): bool
    {
        if (!self::strictMode()) return true;
        $u = Kernel::currentUser($req);
        if (!$u) return false;
        // Pilot acting on own flight
        if ($flight->cid && (int) $flight->cid === (int) $u['cid']) return true;
        // Controller authority — based on current live position
        $live = $u['raw']['live'] ?? null;
        if (is_array($live) && $live['type'] === 'CONTROLLER') {
            $cs = (string) ($live['callsign'] ?? '');
            // CYYZ_TWR / CYYZ_GND / CYYZ_APP → authority over CYYZ flights
            if ($flight->adep && str_starts_with($cs, $flight->adep . '_')) return true;
            if ($flight->ades && str_starts_with($cs, $flight->ades . '_')) return true;
            // TODO: FIR-level authority (CZYZ_* covers CYYZ, CYHM, etc.)
            // deferred until we wire the FIR-airport map into this check.
        }
        return false;
    }

    /**
     * Can this request create or lift a regulation at the given airport?
     * In permissive mode: yes. In strict mode: controller connected as
     * a position at this airport (or its FIR).
     */
    public static function regulateAirport(ServerRequestInterface $req, string $icao): bool
    {
        if (!self::strictMode()) return true;
        $u = Kernel::currentUser($req);
        if (!$u) return false;
        $live = $u['raw']['live'] ?? null;
        if (!is_array($live) || $live['type'] !== 'CONTROLLER') return false;
        $cs = (string) ($live['callsign'] ?? '');
        return str_starts_with($cs, $icao . '_');
        // TODO: FIR-level check for FMP positions
    }

    /**
     * Public read-only — always allowed. Declared for consistency;
     * route handlers that don't need gating should just not call Gate.
     */
    public static function readAll(ServerRequestInterface $req): bool
    {
        return true;
    }
}
