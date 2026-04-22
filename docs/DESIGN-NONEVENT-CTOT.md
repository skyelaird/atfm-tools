# Non-event CTOT portal for CTP — design note (not built)

**Status:** design only. Parked pending CTP authority confirmation.
**Owner:** atfm-tools (Joel), coordinated with Roger Puig (vIFF/CDM, `rpuig2001`).

## Problem

During a CTP event, booked traffic saturates the Canadian oceanic entry
points (AVPUT, CLAVY, EMBOK, KETLA, NIFTY, SAVRY, AVUTI, CUDDY, ENNSO,
IRLOK, NEEKO, SAXAN, ALLRY, ELSIR, JOOPY, PORTI, SUPRY, RAFIN — and
the broader non-active set like PIDSO, MAXAR, HOIST, JANJO, LOMSI,
RIKAL, etc. used by the ~540 other booked flights).

Unbooked (non-event) traffic flying e.g. CYYZ → EGLL the same day
wants to cross the Atlantic without colliding with the metered event
stream. Current CTP guidance is "don't fly, you'll eat a ground delay
if you do" — which is a hammer. A finer tool would issue those pilots
a CTOT that lands them on **a different, non-event-OEP** with an
interval rate CTP declares.

The Canadian OEPs stay event-only. Non-event traffic is routed via
oceanic entry points outside the Canadian region (Shanwick boundary,
Reykjavik handoffs, southern Santa Maria, etc.) where CTP publishes a
per-OEP interval for non-event use.

## Pilot workflow (portal)

1. Pilot opens `public/portal.html` → "Non-event CTP CTOT" tab.
2. Enters:
   - Callsign
   - ADEP (any North American airport)
   - ADES (any European airport)
   - Route string
   - Desired EOBT (best-effort — not binding)
   - Aircraft type (or Mach number)
3. Portal POSTs to `/api/v1/ctp/nonevent/request`.
4. Server returns one of:
   - **Green**: `{CTOT, OEP, ETO_at_OEP, delay_min, reason: "cleared"}`
   - **Amber**: `{CTOT, OEP, delay_min >15, reason: "flow delay at <OEP>"}`
   - **Red**: `{reason: "route uses event-reserved OEP <OEP>, refile via <list>"}` or
     `{reason: "no OEP in route", reason: "route invalid per ECFMP flow measure <id>"}`
5. Pilot accepts / refiles.

CTOT is **advisory**: pilot is trusted to hold block until CTOT. No
TOBT / OBT management by us — we leave the pilot and their sim to
absorb delay whichever way. (Simpler contract, mirrors how VGDS works
for slot-holders.)

## Server pipeline

| Step | Logic | Reuses |
|---|---|---|
| a | Parse route string → waypoint coords | `Geo::parseRouteCoordinates()` |
| b | Scan waypoints for the first OEP token that matches the **non-event allowed list** (published by CTP — configurable per event). Reject if a Canadian event OEP appears first. | new scan helper |
| c | Validate route against ECFMP flow measures (or CTP feed — see authority question below). Reject with the violated measure ID. | new — depends on feed source |
| d | Wind-corrected transit time from ADEP to OEP at filed Mach / cruise level | `WindEta::computeForFlight()` adapted to stop at an arbitrary waypoint instead of the airport threshold |
| e | ETO at OEP = `EOBT + taxi_out + transit_dep_to_OEP` | trivial |
| f | Allocate slot at OEP: push to first free slot with ≥ `interval_min` gap from existing assigned slots on that OEP | adapted `CtotAllocator` — per-OEP rate instead of per-airport AAR |
| g | Reverse: `CTOT = ETO_allocated − transit_dep_to_OEP − taxi_out` | trivial |
| h | Persist in `ctp_nonevent_slots` (new small table: `id, cid, callsign, adep, ades, route, oep, eto, ctot, requested_at, expires_at`) | new |
| i | Expose via existing `/cdm/etfms/restricted` — non-event CTOTs read the same way as event CTOTs by the CDM plugin at North American airports | existing |

No TOBT / OBT manipulation. No interaction with the booked event
slot pool. Non-event allocation runs in its own namespace per OEP.

## Schema add

```
CREATE TABLE ctp_nonevent_slots (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cid            INT UNSIGNED NULL,        -- VATSIM CID when we wire OAuth
    callsign       VARCHAR(16) NOT NULL,
    adep           CHAR(4) NOT NULL,
    ades           CHAR(4) NOT NULL,
    route          TEXT NOT NULL,
    oep            VARCHAR(8) NOT NULL,
    eto_utc        DATETIME NOT NULL,        -- allocated slot time at OEP
    ctot_utc       DATETIME NOT NULL,        -- reversed to ADEP departure
    requested_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at     DATETIME NOT NULL,        -- drop if pilot no-shows by then
    INDEX idx_oep_eto (oep, eto_utc),
    INDEX idx_cs_expiry (callsign, expires_at)
);
```

## Open questions (resolve before build)

1. **Authoritative flow-measure source for route validation**:
   (a) ECFMP live feed — does CTP publish non-event restrictions there?
   (b) CTP API `/events/{id}/...` — does it expose non-event OEP rates?
   (c) Manual FMP config — just hand-set a JSON per event with the
        allowed-OEP list and per-OEP interval.
   **Lean: (c) for v1, upgrade to (a) or (b) once contract stabilises.**
2. **Allowed-OEP list per event** — who publishes it, how often does it
   change mid-event? We'll need a config or admin UI to edit.
3. **Per-OEP interval** — fixed per event (e.g. 5 min), varies through
   the window, or derived from a declared rate?
4. **Auth** — anonymous self-serve, VATSIM Connect OAuth, or requires
   the pilot to be connected? OAuth hooks already exist in
   `src/Auth/Gate.php` (permissive until `AUTH_STRICT=true`).
5. **Coordination with rpuig2001/CDM** — does Roger want his plugin
   to call our `/api/v1/ctp/nonevent/request` directly, or does the
   pilot always go via the web portal? Either way, CTOTs appear in
   `/cdm/etfms/restricted` so controllers see them in EuroScope.
6. **Stale slot cleanup** — cron to drop slots whose CTOT has passed by
   >15 min with no corresponding VATSIM flight (pilot no-show). Pair
   with the existing daily `bin/cleanup.php`.

## Build scope once unblocked

Small. New:
- `bin/compute-nonevent-ctot.php` — CLI + endpoint handler
- `src/Allocator/OepAllocator.php` — the per-OEP slot allocator (smaller
  cousin of `CtotAllocator`)
- `src/Models/CtpNoneventSlot.php` — Eloquent model + migration
- `public/portal.html` — new tab
- One new endpoint in `src/Api/Kernel.php`:
  `POST /api/v1/ctp/nonevent/request`

Reuses: WindEta, Geo, AircraftTas, the existing CDM endpoint, portal
layout.

Estimated time once questions are answered: half a day.
