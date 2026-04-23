# Non-event CTOT portal — design v2 (implemented)

**Status:** v1 shipped in atfm-tools 0.7.x (tables + allocator + endpoints + portal tab).
**Owner:** atfm-tools (Joel). Coordinated with Roger Puig (rpuig2001 / vIFF /
CDM) on protocol; non-overlapping with his slot layer.

## Problem

During CTP event windows the Canadian oceanic entry points and the 21
CTP airports are saturated by booked traffic. Non-event pilots flying
e.g. KSEA→EDDF or CYQB→LEBL aren't in the CTP booking system but still
compete for the same TMA capacity downstream. The existing guidance
("don't fly or eat a delay") is a hammer. This layer is the scalpel:
a self-serve web portal that issues a calculated take-off time sized
to fit non-event traffic into the gaps without breaking the event push.

## Scope

- **Active window**: from now through the post-CTP test period. No
  event-date gating in v1 — the portal is open for validation /
  testing.
- **Rate**: **4 slots per hour per ADES**, clock-aligned to :00 /
  :15 / :30 / :45. One active slot per quarter-hour bin per airport.
- **Blocklist**: flights with ADEP or ADES in the CTP event airport
  set (21 airports) are refused with guidance to pick another. Flights
  with ADEP or ADES in our 7 Canadian airports are refused because
  they're already handled by the main atfm-tools allocator.
- **Validation**: every request is checked against active ECFMP flow
  measures. `MANDATORY_ROUTE`, `PROHIBIT`, `GROUND_STOP` →
  hard reject with the offending measure surfaced. Rate/spacing
  measures fold into the allocator as extra constraints.
- **Delivery**: web UI only — no CDM-plugin output. The target is
  North American pilots who don't have the plugin installed.
- **Identity**: VATSIM Connect OAuth.
  - **Pilot**: submits CTOT requests for themselves
  - **ATCO** (S1 / S2 / S3 / C1 / C3 / I1 / I3 / SUP / ADM rating):
    can pull a pilot's filed flight plan from our VATSIM ingestor
    cache and submit on their behalf; also sees the live CTOT
    dashboard across all flights.

## User flow

### Pilot
1. Sign in with VATSIM Connect.
2. Fill in callsign, ADEP, ADES, EOBT (ISO8601 Zulu), aircraft type,
   filed route, planned FL.
3. Submit. The server:
   - Rejects if ADEP/ADES is CTP-reserved or Canadian → shows the
     reason and prompts for a different airport.
   - Rejects if an active ECFMP measure prohibits / reroutes →
     shows the measure ident, reason, and (for `MANDATORY_ROUTE`)
     the required reroute text. Pilot refiles and retries.
   - Otherwise allocates the next free clock-aligned ELDT slot at
     ADES ≥ the flight's nominal ELDT, reverses to a CTOT, and
     returns `{ctot, eldt, delay_min, measures[]}`.
4. Pilot sees the allocated CTOT on the page. It's a stable floor —
   the allocator does not re-shift it as wind forecasts update.
5. Pilot holds block until CTOT. If they no-show > 15 min past CTOT
   the slot auto-releases.

### ATCO
Same as above plus:
- **Pull filed FP**: `GET /api/v1/ctot/fp/{callsign}` returns the most
  recent filed plan we've ingested from data.vatsim.net for that
  callsign. UI auto-fills the form so the ATCO doesn't re-type.
- **Live dashboard**: `GET /api/v1/ctot/active` lists every active
  non-event slot in the system. Useful for coordinators who need to
  spot-check load on a specific airport.
- **Release**: `DELETE /api/v1/ctot/{id}` cancels a slot (pilot can
  do this too for their own slots).

## Flight-time estimate (simple)

For v1, flight time = great-circle distance ÷ Mach 0.82 TAS at filed FL
+ 15 min taxi/climb/descent pad. Falls back to FL370 if FL not filed.
ELDT at ADES = EOBT + flight time. This is deliberately simple — the
atfm-tools WindEta 3-phase model exists but is tied to our 7 Canadian
airports' airport catalogue; for the general non-event case we don't
have wind data bound to every possible ADES. The ±3-5 min inaccuracy
from skipping wind is smaller than the 15-min slot bin width, so the
simplification is operationally neutral.

## Route parsing

Simple whitespace tokenisation. **No SID / STAR expansion, no airway
expansion.** Explicit waypoints are the only thing we match against
ECFMP `waypoint` filters. If a measure filters on `BPK` and the pilot
filed `UL9` (which passes through BPK), we do NOT catch it — accepted
v1 trade-off. The reject message will point the pilot to
https://ecfmp.vatsim.net/dashboard for the authoritative flow-measure
list.

## Schema

```sql
CREATE TABLE nonevent_slots (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cid             INT UNSIGNED NULL,            -- VATSIM CID (may be null in permissive mode)
    callsign        VARCHAR(16) NOT NULL,
    adep            CHAR(4) NOT NULL,
    ades            CHAR(4) NOT NULL,
    eobt            DATETIME NOT NULL,
    ctot            DATETIME NOT NULL,            -- reverse-computed from eldt
    eldt            DATETIME NOT NULL,            -- the slot we reserved
    filed_route     TEXT NOT NULL,
    aircraft_type   VARCHAR(4) NULL,
    filed_fl        SMALLINT UNSIGNED NULL,       -- e.g. 370
    submitted_by    VARCHAR(32) NOT NULL DEFAULT 'pilot',  -- 'pilot' or 'atco:<CID>'
    expires_at      DATETIME NOT NULL,            -- ctot + 15 min
    released_at     DATETIME NULL,
    release_reason  VARCHAR(32) NULL,             -- 'superseded' | 'expired' | 'cancelled'
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    INDEX idx_ades_eldt (ades, eldt),
    INDEX idx_cid_ctot  (cid, ctot),
    INDEX idx_cs_ctot   (callsign, ctot)
);
```

## Endpoints

| Method | Path | Role | Purpose |
|---|---|---|---|
| POST   | `/api/v1/ctot/request`         | pilot/atco | Submit a CTOT request |
| GET    | `/api/v1/ctot/mine`            | pilot | List my own active slots |
| GET    | `/api/v1/ctot/active`          | any | Live dashboard of all active slots |
| GET    | `/api/v1/ctot/fp/{callsign}`   | atco | Pull filed plan for a pilot (AUTH_STRICT) |
| GET    | `/api/v1/ctot/ecfmp`           | any | Proxy: active ECFMP measures |
| DELETE | `/api/v1/ctot/{id}`            | owner/atco | Release a slot |

## Components

| Class / file | Role |
|---|---|
| `src/Models/NoneventSlot.php` | Eloquent model for the slot record |
| `src/Allocator/NoneventCtotAllocator.php` | Validation + slot allocation + CTOT computation |
| `src/Ingestion/EcfmpClient.php` | ECFMP API client with in-memory cache + flight-filter matcher |
| `src/Auth/Gate.php` | Authorization gate (permissive until `AUTH_STRICT=true`) |
| `src/Auth/VatsimOAuth.php` | VATSIM Connect OAuth 2.0 client |
| `public/ctot.html` | Pilot portal + ATCO dashboard UI |

## Open / deferred

- **CTP airport list** is hardcoded in v1. For v2, pull from
  `https://planning.ctp.vatsim.net/api/events/{id}/airports` daily.
- **Wind integration** — could plug `WindEta::computeForFlight()` in
  for the Canadian-overflight portion of any non-event flight for more
  accurate ELDT. Low priority; 15-min bins already absorb the error.
- **Airway expansion** for ECFMP `waypoint` matches — would catch
  more "filed UL9 → passes through BPK" cases. Requires `data/airways.json`
  resolution at validation time.
- **Slot re-request** (pilot refiles with a different route after a
  reject) should free the previous attempt if we were tracking it. v1
  doesn't track failed attempts, so no cleanup needed.
- **Cron cleanup** of expired slots — currently done lazily at query
  time. Could be a daily batch if the table grows.
