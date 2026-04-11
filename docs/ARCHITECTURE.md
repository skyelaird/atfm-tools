# atfm-tools — Architecture

**Status**: Design lock for v0.3
**Last updated**: 2026-04-11
**Owner**: skyelaird

This document is the authoritative description of what atfm-tools is, what it
does, and how its pieces fit together. If something in this document contradicts
code, the document is right and the code is wrong — update the code, not the doc.

For per-term definitions (EOBT vs TOBT vs TSAT vs TTOT vs CTOT, EDCT vs CTOT,
TMI vs flow measure vs restriction, etc.), see [GLOSSARY.md](GLOSSARY.md).

---

## 1. Purpose

atfm-tools is a lightweight, independent, rate-based tactical air traffic flow
management tool for the VATSIM network, scoped initially to Canadian airports.
Its job is to:

1. **Collect** live traffic and airport activity data from VATSIM.
2. **Detect** when demand at a constrained airport exceeds capacity (or a manual
   regulation is in effect) and **compute per-callsign Calculated Take-Off Times
   (CTOTs)** that smooth the arrival stream.
3. **Serve** those CTOTs to the CDM EuroScope plugin via a wire-compatible
   replacement for `viff-system.network/etfms/restricted`.
4. **Archive** flight records in the ICAO A-CDM milestone model for post-event
   analysis (Runway Occupancy Time, achieved rates, ETA accuracy, EOBT quality,
   compliance distributions, etc.).

It is deployed on IONOS-class shared PHP hosting (no Docker, no background
daemons, no Redis) and is designed to run entirely under cron.

## 2. Non-goals

These are explicitly out of scope for v1 (and in some cases permanently):

- **En-route / airspace-based flow regulation.** We do airports only. ADEP and
  ADES filters, not waypoints or sector counts.
- **Deterministic GDP with Ration-By-Schedule.** See §9 for why. We run a
  rate-based tactical controller instead of a classical FAA TFMS GDP.
- **Wind modelling / GRIB parsing.** Filed TAS primary, type-table fallback,
  observed groundspeed for airborne flights. No meteorology.
- **Authoring UI for restrictions.** vIFF or PERTI owns the flow-manager web
  form. atfm-tools consumes the resulting state, it doesn't compete for the
  authoring role.
- **Replacing PERTI for vATCSCC.** atfm-tools is schema-compatible with PERTI
  but never a drop-in replacement. We target a much narrower scope.
- **VFR flight regulation.** VFR flights are filtered out of CTOT allocation.
- **ROT measurement for non-VATSIM traffic.** Everything is derived from what
  `data.vatsim.net` publishes.

## 3. High-level architecture

```
                         ┌──────────────────────────────────┐
                         │    data.vatsim.net (live feed)    │
                         └──────────────┬───────────────────┘
                                        │ every 5 min (allocator)
                                        │ every 60s adaptive (ROT)
                                        ▼
 ┌───────────┐           ┌──────────────────────────────────┐
 │ bookings. │──poll─────▶│     bin/ingest-vatsim.php       │
 │ vatcan.ca │  every 5m │  • filter to 7 Canadian airports │
 │ (events)  │           │  • upsert `flights` table         │
 └───────────┘           │  • append `position_scratch`      │
                         └──────────────┬───────────────────┘
                                        │
 ┌───────────┐                          │
 │ storage/  │  on-disk file polling    │
 │ imports/  │──────────────────────────┤
 │ ctots/    │   (CSV / JSON uploads)   │
 └───────────┘                          │
                                        ▼
                         ┌──────────────────────────────────┐
                         │   bin/compute-ctots.php          │
                         │   • read active restrictions      │
                         │   • read frozen CTOTs             │
                         │   • run priority-ladder allocator │
                         │   • update `flights.ctot_*`       │
                         │   • append `allocation_runs`      │
                         └──────────────┬───────────────────┘
                                        │
                                        ▼
                         ┌──────────────────────────────────┐
                         │   bin/rot-tracker.php            │
                         │   • adaptive state machine       │
                         │   • detect threshold crossings   │
                         │   • update `flights.a*` columns  │
                         │   • write to aar_calculations    │
                         └──────────────┬───────────────────┘
                                        │
                                        ▼
 ┌──────────────────────────────────────────────────────────┐
 │                      MySQL (ogzqox66_atfm)               │
 │  airports  runway_thresholds  airport_restrictions       │
 │  flights  position_scratch  allocation_runs              │
 │  event_sources  imported_ctots  aar_calculations         │
 └──────────────────────┬───────────────────────────────────┘
                        │
                        ▼
     ┌──────────────────────────────────────────────────────┐
     │    Slim 4 HTTP front-end (public/index.php)          │
     │                                                      │
     │    /cdm/etfms/restricted     → CDM plugin            │
     │    /cdm/airport              → CDM plugin            │
     │    /cdm/ifps/*               → CDM plugin (stubs)    │
     │    /api/v1/flight-information-region                  │
     │    /api/v1/flow-measure                               │
     │    /api/v1/plugin            → ECFMP plugin API mirror│
     │    /api/v1/airports          → admin                 │
     │    /api/v1/airport-restrictions → admin              │
     │    /api/v1/debug/*                                    │
     │    /map.html                 → Leaflet FIR map       │
     └──────────────────────────────────────────────────────┘
                        ▲
                        │ HTTP GET
                        │
                        ▼
               ┌─────────────────┐
               │   CDM plugin    │
               │   (EuroScope)   │
               │                 │
               │ <customRestricted
               │   url=".../cdm/
               │   etfms/restricted"/>
               └─────────────────┘
```

The pipeline is fully stateless across cron ticks. State lives in MySQL. Every
script can be interrupted, re-run, or replaced without corrupting anything.

## 4. Data model

All timestamps are UTC. String lengths follow PERTI's `adl_flight_*` conventions
where practical, to keep atfm-tools interoperable with PERTI's schema if we ever
want to push/pull data across.

### 4.1 `airports` — static config per airport

```
airports
├── id                       bigint PK
├── icao                     char(4) unique, e.g. 'CYHZ'
├── name                     varchar, e.g. 'Halifax / Stanfield Intl'
├── latitude                 double    -- airport reference point
├── longitude                double
├── elevation_ft             int       -- field elevation
├── base_arrival_rate        int       -- fallback rate (mvts/hr)
├── observed_arrival_rate    int null  -- computed from ROT data when sample_n > threshold
├── observed_rate_sample_n   int       -- how many observations inform it
├── observed_rate_updated_at datetime null
├── base_departure_rate      int
├── default_exot_min         int       -- ICAO EXOT: startup+pushback+taxi+queue, airport-wide fallback
├── default_exit_min         int       -- ICAO EXIT: airport-wide fallback taxi-in time
├── is_cdm_airport           boolean   -- runs full TOBT/TSAT/DPI protocol at the gate
├── arrived_geofence_nm      int default 5   -- radius for ARRIVED phase detection
├── final_threshold_nm       int default 10  -- distance from runway threshold = FINAL phase
├── created_at, updated_at
```

Seeded at install time with the 7 Canadian airports we track. Modified via
`/api/v1/airports` admin endpoints. Rates can be edited but the historical
values in `aar_calculations` are the source of truth when cross-checking.

### 4.2 `runway_thresholds` — per-direction landing ends

Each physical runway strip has **two rows** — one for each landing direction.
A flight landing on RWY 05 uses a different threshold coordinate than a flight
landing on RWY 23, even though they share the same pavement.

```
runway_thresholds
├── id                         bigint PK
├── airport_icao               char(4) FK → airports.icao
├── runway_ident               varchar(4), e.g. '05', '23L', '33R'
├── heading_deg                int       -- magnetic heading, e.g. 053 for RWY 05
├── threshold_lat              double    -- landing end of this direction
├── threshold_lon              double
├── opposite_threshold_lat     double    -- the other end of the strip, for length/polygon
├── opposite_threshold_lon     double
├── width_ft                   int default 200
├── elevation_ft               int null  -- differs from airport when runway is on a ridge
├── displaced_threshold_ft     int default 0
├── created_at, updated_at
│
├── unique (airport_icao, runway_ident)
```

Derived at query time:
- **Length** = haversine(threshold, opposite_threshold)
- **Centerline** = line segment between the two points
- **Runway polygon** = rectangle along centerline ± width_ft/2, for point-in-polygon
  checks during ROT state machine transitions

Seeded at install time from NAV CANADA AIP data.

### 4.3 `airport_restrictions` — rate reductions

A flow manager creates a row here when they want to regulate arrivals to a
specific airport. Mirrors vIFF's per-airport restriction model. When active,
an `airport_restrictions` row overrides `airports.observed_arrival_rate` and
`airports.base_arrival_rate` for the allocator's purposes.

```
airport_restrictions
├── id                           bigint PK
├── restriction_id               varchar(16) unique, auto-generated e.g. 'CYHZ11VP'
├── airport_id                   bigint FK → airports.id
├── runway_config                varchar(16) null  -- e.g. '05' or '05+14'; null = any
├── capacity                     int       -- reduced rate (mvts/hr)
├── reason                       varchar(32) default 'ATC_CAPACITY'
│                                -- ATC_CAPACITY | WEATHER | RUNWAY_CLOSED | STAFFING |
│                                -- EVENT | OTHER
├── type                         varchar(8) default 'ARR'  -- ARR | DEP | BOTH
├── runway                       varchar(4) null  -- required if type=DEP
├── tier_minutes                 int default 120  -- lookahead window for eligible flights
├── compliance_window_early_min  int default 5
├── compliance_window_late_min   int default 5
├── start_utc                    time      -- HHMM time-of-day (daily recurring)
├── end_utc                      time      -- HHMM time-of-day
├── active_from                  datetime  -- when this row becomes eligible
├── expires_at                   datetime null  -- auto-purge 24h after last active window
├── created_at, updated_at
├── deleted_at (soft delete)
│
├── index (airport_id, type)
├── index (expires_at)
```

**Max 5-hour duration enforced** at the application layer (matches vIFF's
constraint). Auto-expired 24h after the last window, matching vIFF's retention.

### 4.4 `flights` — the analytical core

**One row per flight. Mutable in place.** Every 5-minute ingest cycle updates
the columns that changed. When the flight reaches a terminal state (ARRIVED or
WITHDRAWN), `finalized_at` is set and the row is frozen.

This replaces the append-only-snapshot approach I initially designed. Position
history is kept separately in `position_scratch` with short retention; only the
*derived milestones* (A-CDM times) are retained long-term on the flight record.

```
flights
├── id                         bigint PK
│
├── -- Identity
├── flight_key                 varchar(64) unique
│                              -- composite: cid|callsign|adep|ades|deptime
│                              -- matches PERTI's sp_Adl_RefreshFromVatsim composition
├── callsign                   varchar(16)
├── cid                        int
├── first_seen_at              datetime
├── last_updated_at            datetime
├── finalized_at               datetime null  -- terminal state reached
│
├── -- Aircraft / flight plan classification
├── aircraft_type              varchar(8) null   -- ICAO type, e.g. 'B738'
├── aircraft_faa               varchar(32) null  -- FAA format
├── wake_category              varchar(2) null   -- L/M/H/J
├── flight_rules               char(1) null      -- I/V/Y/Z
├── airline_icao               char(3) null      -- derived from callsign pattern
│
├── -- Route
├── adep                       char(4) null      -- ICAO departure
├── ades                       char(4) null      -- ICAO destination
├── alt_icao                   char(4) null      -- alternate
├── fp_route                   text null
├── fp_altitude_ft             int null
├── fp_cruise_tas              int null
│
├── -- Runway / gate (FIXM-aligned)
├── departure_runway           varchar(4) null
├── arrival_runway             varchar(4) null   -- detected from track or assigned
├── departure_gate             varchar(10) null
├── arrival_gate               varchar(10) null
│
├── -- A-CDM milestones (the analytical gold)
├── eobt                       datetime null     -- filed off-block time
├── tobt                       datetime null     -- target off-block (= eobt for non-CDM airports)
├── tsat                       datetime null     -- target start-up approval (computed)
├── ttot                       datetime null     -- target take-off (tsat + exot)
├── ctot                       datetime null     -- calculated take-off (allocator output)
├── asat                       datetime null     -- actual start-up approval (observed)
├── aobt                       datetime null     -- actual off-block (observed)
├── atot                       datetime null     -- actual take-off (threshold crossing)
├── eldt                       datetime null     -- estimated landing (computed)
├── cta                        datetime null     -- calculated arrival (allocator output)
├── aldt                       datetime null     -- actual landing (threshold crossing)
├── aibt                       datetime null     -- actual in-block (stationary at gate)
│
├── -- Planned vs actual (derived, populated on state transitions)
├── planned_exot_min           int null          -- copied from airport.default_exot_min
├── actual_exot_min            int null          -- atot - asat, post-departure
├── planned_exit_min           int null
├── actual_exit_min            int null          -- aibt - aldt, post-arrival
│
├── -- Regulation state
├── ctl_type                   varchar(32) null  -- AIRPORT_ARR_RATE | AIRPORT_DEP_RATE | EVENT_BOOKED | IMPORTED_CTOT | NONE
├── ctl_element                varchar(16) null  -- which airport ICAO bound this flight
├── ctl_restriction_id         varchar(16) null  -- FK to airport_restrictions.restriction_id
├── delay_minutes              int null          -- ctot - ttot delta (positive if delayed)
├── delay_status               varchar(16) null
│                              -- ON_TIME | DELAYED | COMPLIANT_DEPARTED | NON_COMPLIANT |
│                              -- WITHDRAWN | EXEMPT
│
├── -- State machine
├── phase                      varchar(16) null
│                              -- PREFILE | FILED | TAXI_OUT | DEPARTED | ENROUTE |
│                              -- ARRIVING | FINAL | ON_RUNWAY | VACATED | TAXI_IN |
│                              -- ARRIVED | GO_AROUND | DISCONNECTED | WITHDRAWN
├── phase_updated_at           datetime null
│
├── -- Last known position (updated in place, not historical)
├── last_lat                   double null
├── last_lon                   double null
├── last_altitude_ft           int null
├── last_groundspeed_kts       int null
├── last_heading_deg           int null
├── last_position_at           datetime null
│
├── -- Disconnect handling
├── first_disconnect_at        datetime null     -- when we last stopped seeing it
├── reconnect_count            int default 0     -- how many times we've reanimated it
│
├── created_at, updated_at
│
├── index (callsign)
├── index (cid)
├── index (adep, phase)
├── index (ades, phase)
├── index (finalized_at)
├── index (phase, last_updated_at)
```

**Finalization rules:**
- `ARRIVED` (terminal): last position within `arrived_geofence_nm` of ADES,
  groundspeed ≤ 5 kt, stable for ≥ 10 min. Set `aibt` and `finalized_at = now`.
- `WITHDRAWN` (terminal, admin timeout): DISCONNECTED for > 10 hours.
  `finalized_at = first_disconnect_at + 10h`. Triggered by daily cleanup cron.
- Any other terminal event (emergency diversion, flight plan cancellation, etc.)
  is not separately modelled — it either ends in ARRIVED or times out into
  WITHDRAWN.

**Reanimation**: if a DISCONNECTED flight's `flight_key` reappears in the
VATSIM feed, we resume updating in place. Increment `reconnect_count`, clear
`first_disconnect_at`, set `phase` based on new position.

### 4.5 `position_scratch` — short-retention position history

Feeds the ROT state machine and allows back-referencing recent trajectory for
debugging or ETA refinement. **Purged aggressively** — 48 hours retention, no
long-term archive.

```
position_scratch
├── id                         bigint PK
├── flight_id                  bigint FK → flights.id
├── lat, lon                   double
├── altitude_ft                int
├── groundspeed_kts            int
├── heading_deg                int
├── observed_at                datetime
│
├── index (flight_id, observed_at)
├── index (observed_at)    -- for cleanup queries
```

Written by `bin/rot-tracker.php` during adaptive polling. Read by the same
script's state machine. Purged by `bin/cleanup.php` on a daily cron.

### 4.6 `allocation_runs` — allocator audit trail

One row per allocator cycle. Cheap (~300 rows/day), high debug value: "why did
this flight get this CTOT?" becomes queryable.

```
allocation_runs
├── id                         bigint PK
├── run_uuid                   varchar(36) unique
├── started_at                 datetime
├── finished_at                datetime
├── airports_considered        int
├── restrictions_active        int
├── flights_evaluated          int
├── ctots_frozen_kept          int      -- carried from previous runs
├── ctots_issued               int      -- new this run
├── ctots_released             int      -- compliance / departure / withdrawal
├── ctots_reissued             int      -- non-compliance
├── elapsed_ms                 int
├── notes                      text null
│
├── index (started_at)
```

### 4.7 `event_sources` — VATCAN event booking configuration

Admin-managed list of event codes to poll from `bookings.vatcan.ca`.

```
event_sources
├── id                         bigint PK
├── event_code                 varchar(16) unique   -- opaque, e.g. 'xXpFB'
├── label                      varchar(64)          -- display, e.g. 'CTP 2024'
├── start_utc                  datetime null
├── end_utc                    datetime null
├── active                     boolean default true
├── created_at, updated_at
```

Typically 0 rows. 1-5 rows during an event weekend. Admin adds a row when the
event organizer publishes the code, removes (or marks `active=false`) when the
event ends.

### 4.8 `imported_ctots` — file-uploaded CTOT ingestion

Supports pre-computed CTOT lists uploaded as files (CSV or JSON) to
`storage/imports/ctots/` on the server. Used when an event organizer or
external tool produces a canonical CTOT list that atfm-tools should serve
without running its own allocator for those flights.

```
imported_ctots
├── id                         bigint PK
├── source_file                varchar(255)    -- filename the row came from
├── source_label               varchar(64)     -- free-text label
├── source_uploaded_at         datetime
├── callsign                   varchar(16) null
├── cid                        int null
├── ctot                       datetime        -- absolute, not HHMM
├── most_penalizing_airspace   varchar(64) null
├── priority                   int default 100 -- lower number = wins over allocator output
├── valid_from                 datetime
├── valid_until                datetime
├── active                     boolean default true
├── created_at, updated_at
│
├── index (callsign, valid_from)
├── index (cid, valid_from)
```

**File format** (preferred JSON, same shape as VATCAN bookings + extras):

```json
[
  { "callsign": "ACA456", "cid": 810489, "ctot": "2026-04-11T18:47:00Z",
    "reason": "CYHZ-ARR", "valid_from": "2026-04-11T17:00:00Z",
    "valid_until": "2026-04-11T20:00:00Z" },
  { "cid": 123456, "ctot": "2026-04-11T18:49:00Z", "reason": "CYHZ-ARR" }
]
```

CSV supported as well, columns: `callsign,cid,ctot,reason,valid_from,valid_until`.
Either `callsign` or `cid` must be present; matching uses whichever is given.

**Ingestion**: every allocator cycle, scan `storage/imports/ctots/` for files
modified since last ingest, parse and upsert into `imported_ctots`. Files are
moved to `storage/imports/ctots/processed/` after ingestion to avoid re-parsing.

**Priority**: imported_ctots rows with lower `priority` value override allocator
output for the same callsign. Default priority 100, so imported rows with
priority 10 always win. This lets you say *"for this event, use this
manually-curated CTOT, ignore the allocator"* without disabling the allocator
globally.

### 4.9 `aar_calculations` — rolling AAR derivations

Optional table. Written by `bin/compute-aar.php` on a daily cron. Reads from
observed ROT data (threshold crossings, approach speeds) and computes per-airport
per-runway AAR per the ICAO 9971 Part II App II-B formula.

```
aar_calculations
├── id                         bigint PK
├── airport_icao               char(4)
├── runway_ident               varchar(4)
├── window_start               datetime
├── window_end                 datetime
├── mean_threshold_gs_kts      int
├── mean_spacing_nm            double
├── computed_aar               int      -- GS / spacing, rounded down
├── sample_count               int
├── confidence_pct             int
├── preceding_wake             char(1) null  -- L/M/H/J — for wake-pair AARs later
├── follower_wake              char(1) null
├── created_at
│
├── index (airport_icao, window_end)
```

Used to update `airports.observed_arrival_rate` when `sample_count` crosses a
threshold (default 100 observations in the window). Until that threshold,
`base_arrival_rate` is used.

## 5. Vocabulary

atfm-tools touches four different flow-management systems (ICAO A-CDM, FAA TFMS,
Eurocontrol CFMU, VATSIM conventions), each with overlapping but inconsistent
terminology. The internal code and this document use the **ICAO A-CDM
milestone names** (EOBT, TOBT, TSAT, TTOT, CTOT, ATOT, AOBT, ELDT, CTA, ALDT,
AIBT) as the primary vocabulary.

Adapter layers at the HTTP edge translate to/from other systems' field names:

- `/cdm/etfms/restricted` emits `{callsign, ctot, mostPenalizingAirspace}` —
  the CDM plugin's expected shape. Our internal `ctot` → CDM's `ctot` (same
  concept, same field name, lucky coincidence).
- `/api/v1/plugin` mirrors ECFMP/flow's PluginApiController. Our internal
  `ctot` → ECFMP's `measure.value` or `starttime/endtime` depending on context.
- Future PERTI integration uses `ctd_utc` / `cta_utc` in the PERTI SWIM shape.

All term definitions, cross-references, and FAQs live in
[GLOSSARY.md](GLOSSARY.md). Read that before this section makes you dizzy.

## 6. Ingestion

Five data sources feed atfm-tools. Each has its own cron entry-point script.

### 6.1 VATSIM live feed — `bin/ingest-vatsim.php`

**Source**: `https://data.vatsim.net/v3/vatsim-data.json` (public, no auth, ~3 MB
JSON snapshot updated every ~15 s by VATSIM).

**Cadence**: every 5 minutes via cron. Does not hit the endpoint more than once
per 5 min even if invoked more frequently.

**Filter**: pilots where `flight_plan.departure` OR `flight_plan.arrival` is in
our 7 configured airports.

**Actions per pilot**:
1. Compute `flight_key` (composite `cid|callsign|adep|ades|deptime`).
2. UPSERT into `flights` table keyed by `flight_key`.
3. Update A-CDM milestone fields as they become observable:
   - `eobt`: from `flight_plan.deptime` (once on first FILED observation)
   - `asat` / `aobt`: first time groundspeed > 0 near ADEP
   - `atot`: first time altitude > 200ft AGL near ADEP
   - `aldt`: first time altitude descends through 200ft AGL near ADES
   - `aibt`: first stationary observation near ADES
4. Update `last_*` position fields.
5. Compute `phase` from position / groundspeed / altitude / proximity.
6. Update `phase_updated_at` if phase changed.
7. Set `first_seen_at` if not set.

**Reanimation logic**: if a pilot is in the current snapshot but the flight
record's `phase = DISCONNECTED`, clear `first_disconnect_at`, increment
`reconnect_count`, recompute phase from the new observation.

**Missing pilots**: any flight with `phase NOT IN (ARRIVED, WITHDRAWN)` that's
not in the current snapshot gets `phase = DISCONNECTED` and `first_disconnect_at
= now` (if not already set). It stays DISCONNECTED until either reanimated or
the daily cleanup flips it to WITHDRAWN.

### 6.2 VATCAN event bookings — `bin/ingest-events.php`

**Source**: `https://bookings.vatcan.ca/api/event/{event_code}` (public, no auth).

**Cadence**: every 5 minutes via cron, but only calls the endpoint for rows in
`event_sources` where `active = true`.

**Response shape**: bare JSON array of `{cid: int, slot: string}` entries, where
`slot` is HHMM format. Error responses are `{error: "..."}`.

**Actions**: for each row, upsert a matching `imported_ctots` row with
`source_file = 'vatcan:' + event_code`, `priority = 50` (higher than file
imports by default), `valid_from` = event start, `valid_until` = event end.

### 6.3 File-based CTOT imports — `bin/ingest-imports.php`

**Source**: files in `storage/imports/ctots/` on the server, uploaded via SFTP
or `POST /api/v1/admin/ctot-imports` (future endpoint).

**Cadence**: every 5 minutes, scanning the directory for new / modified files.

**Formats accepted**:
- `.json`: JSON array matching the schema in §4.8
- `.csv`: headers `callsign,cid,ctot,reason,valid_from,valid_until`

**Actions**: parse, upsert to `imported_ctots`, move processed files to
`storage/imports/ctots/processed/<date>/`.

### 6.4 ROT / position tracker — `bin/rot-tracker.php`

**Source**: same VATSIM feed, but with adaptive polling cadence inspired by the
`cyhz-rot-collector` Python reference implementation.

**Cadence decision logic** (mirrors collector):
| Situation | Interval |
|---|---|
| No tracked flights | 10 min |
| Closest flight > 100 nm from ADES | 10 min |
| Closest 50-100 nm | 5 min |
| Closest 30-50 nm | 2 min |
| Closest ≤ 30 nm | 60 s |
| Any in APPROACH / FINAL / ON_RUNWAY | 15 s |

**Actions**:
1. Fetch VATSIM feed (reusing cached response from `ingest-vatsim.php` if fresh
   enough, else fetching a new one).
2. For each tracked flight, run the state machine (see §8) and write transitions
   to `flights` and position samples to `position_scratch`.
3. On `FINAL → ON_RUNWAY → VACATED` transitions, record threshold crossing time
   and runway vacate time. Compute ROT delta and populate
   `flights.actual_exit_min` (for arrivals) or `flights.actual_exot_min` (for
   departures).

### 6.5 Future: PERTI SWIM live feed

Deferred until after coordination with Jeremy Peterson (PERTI operator). When
enabled:
- Base URL: `https://perti.vatcscc.org/api/swim/v1/`
- Auth: Bearer token (partner-tier API key issued by vATCSCC)
- Replaces / supplements `ingest-vatsim.php` with PERTI-enriched flight records
  (PERTI already computes phase, ctd, cta, delay_status for us)
- Our internal schema is already compatible — swap the ingest source, nothing
  else changes.

## 7. Flight lifecycle state machine

```
                          ┌─────────┐
  (first observation)─────▶ PREFILE │  (flight plan filed, pilot offline)
                          └────┬────┘
                               │  pilot connects
                               ▼
                          ┌─────────┐
                          │  FILED  │  (pilot visible, GS = 0, at ADEP)
                          └────┬────┘
                               │  GS > 0
                               ▼
                          ┌─────────┐
                          │TAXI_OUT │  (GS > 0, on ground, within ADEP geofence)
                          └────┬────┘
                               │  GS > 50, altitude climbing
                               ▼
                          ┌─────────┐
                          │DEPARTED │  (just airborne, still low)
                          └────┬────┘
                               │  altitude > 2000 AGL, > 40 nm from ADES
                               ▼
                          ┌─────────┐
                          │ ENROUTE │
                          └────┬────┘
                               │  within 40 nm of ADES, descending
                               ▼
                          ┌─────────┐
                          │ARRIVING │
                          └────┬────┘
                               │  within 10 nm of runway threshold,
                               │  heading aligned ±15° with runway,
                               │  altitude < 3000 AGL
                               ▼
                          ┌─────────┐
                          │  FINAL  │──────┐
                          └────┬────┘      │  altitude climbing
                               │            │  > threshold_elev + 500
                               │  point in   ▼
                               │  runway  ┌──────────┐
                               │  polygon │GO_AROUND │──┐
                               │          └─────┬────┘  │
                               │                │        │
                               │         (re-enters      │
                               │          ARRIVING)      │
                               ▼                         │
                          ┌───────────┐◀────────────────┘
                          │ ON_RUNWAY │
                          └─────┬─────┘
                                │  point leaves polygon, still on ground
                                ▼
                          ┌─────────┐
                          │ VACATED │
                          └────┬────┘
                                │  still inside airport geofence, GS > 0
                                ▼
                          ┌─────────┐
                          │TAXI_IN  │
                          └────┬────┘
                                │  GS = 0, within arrived_geofence_nm, stable 10 min
                                ▼
                          ┌─────────┐
                          │ ARRIVED │  (TERMINAL — finalized_at set)
                          └─────────┘

  At any non-terminal phase, the flight may transition to:
                          ┌────────────────┐
                          │  DISCONNECTED  │  (transient — can re-animate)
                          └───────┬────────┘
                                  │  10h with no reappearance
                                  ▼
                          ┌───────────┐
                          │ WITHDRAWN │  (TERMINAL, admin timeout)
                          └───────────┘
```

**DISCONNECTED is not terminal.** Long-haul sim pilots routinely disconnect for
multi-hour sleep breaks in the middle of transatlantic flights. If the same
`flight_key` reappears, we resume at whatever phase the current position
implies, without regression.

**flight_key changes** (pilot refiles with different `deptime` or ADES) create a
new flight record. The old one ends up WITHDRAWN after 10 h.

## 7.1 ETA estimation cascade

VATSIM's data feed does not publish a computed ETA. The allocator needs
a usable arrival time for every flight inbound to a regulated airport.
`src/Allocator/EtaEstimator.php` implements a 5-tier cascade and
returns the highest-tier estimate it can compute:

| Tier | Source | Applies when | Formula | Confidence |
|---|---|---|---|---|
| **1** | **FILED** | Pilot filed `enroute_time` (HHMM) in the flight plan, flight is on ground | `EOBT + taxi + enroute_time` | 90 |
| **2** | **OBSERVED_POS** | Flight is airborne with known lat/lon | `now + great_circle(pos, dest) / observed_gs` | 85 |
| **3** | **CALC_FILED_TAS** | On ground, filed `cruise_tas` is present and plausible (120-650 kt) | `EOBT + taxi + great_circle(adep, ades) / filed_tas` | 70 |
| **4** | **CALC_TYPE_TAS** | On ground, aircraft_type is in `AircraftTas::TABLE` | `EOBT + taxi + great_circle / type_table_tas` | 55 |
| **5** | **CALC_DEFAULT** | On ground, unknown type | `EOBT + taxi + great_circle / 430 kt` | 40 |
| —  | **NONE** | No EOBT, no position, or unknown ADEP coords | (skip flight this cycle) | 0 |

**Why OBSERVED_POS beats FILED for airborne flights**: physical reality is
always better than pre-flight planning once the flight exists. Filed
enroute_time is typically SimBrief-quality — it includes winds at filing
time — but doesn't account for mid-flight ATC routing changes, speed
choices, or wind shifts. Observed groundspeed + current position captures
all of those implicitly.

**Why FILED beats TAS-based calculation for ground flights**: pilots who
file `enroute_time` typically got it from SimBrief, which includes wind
compensation for the forecast period. Our geometric recomputation has no
wind model at all. So a filed value from a serious filer is better than
our computation.

The allocator does not need to know which tier produced an ETA for its
slot-assignment decision — it just uses the epoch. Future ROT analysis
can stratify ETA accuracy by source to measure how each tier performs
in practice.

See §2 for why we deliberately don't integrate wind/GRIB data.

## 8. Allocation algorithm (CASA-light priority ladder)

Runs every 5 minutes via `bin/compute-ctots.php`. Stateless across runs except
for the set of frozen CTOTs carried over from prior cycles.

### 8.1 Top-level flow

```
for each airport with an active restriction:
    determine effective capacity (§9)
    determine slot duration = 3600 / capacity_per_hour (seconds)
    determine regulation window (start_utc, end_utc → now-relative datetimes)
    tier_cutoff = now + restriction.tier_minutes

    1. Load frozen CTOTs for this airport:
         - from flights.ctot where ctl_element = airport AND phase != TERMINAL
         - validate: pilot still in feed, not departed, compliance window hasn't passed
         - those that pass: immovable this cycle
         - those that fail: released (slot available for reuse) or reissued

    2. Airborne inbound pre-consume:
         - flights with phase ∈ {ENROUTE, ARRIVING, FINAL} AND ades = airport
         - compute unconstrained ETA from current position + filed TAS
         - occupy slot at that time (no CTOT issued)

    3. Event bookings / imports (both are "pre-booked" sources):
         - load imported_ctots rows where callsign or cid matches an inbound flight
         - order by priority (lower wins)
         - for each: reserve the slot at the specified ctot
           (displaces whatever was there if priority is higher)

    4. Ground CASA — sort ground flights by EOBT, allocate:
         - candidates = flights inbound to airport, on ground, within tier
         - also: any flights released from step 1 (previously frozen but non-compliant)
         - for each (in EOBT order):
             - compute unconstrained ETA
             - walk the slot schedule from that time forward until a free slot
             - if delay >= 5 min: issue CTOT, mark frozen, write to flights.ctot
             - if delay < 5 min: no CTOT (flight is on-time)

    5. Beyond tier: skipped entirely. Re-evaluated when they cross the tier.
```

### 8.2 Compliance and freezing

Once a CTOT is issued:

- It is **frozen** for the duration of the compliance window.
- Compliance check: `now BETWEEN (ctot - compliance_window_early_min) AND
  (ctot + compliance_window_late_min)`.
- If the flight takes off within the window → COMPLIANT_DEPARTED, slot released
  for next-cycle compression.
- If `now > ctot + compliance_window_late_min` and the flight is still on
  ground → NON_COMPLIANT, slot released, flight re-enters the candidate pool
  with a new CTOT.
- If the flight withdraws → slot released, flight marked WITHDRAWN after 10 h.

### 8.3 No popup reserves

Unlike real FAA GDPs, atfm-tools does **not** reserve slots for pop-up traffic.
Rationale: on VATSIM, every flight is essentially a popup (no commercial
schedule, no advance demand forecast). Reserving slots would create a gameable
two-tier system with no principled population to justify it. One queue,
strict EOBT-order CASA, no reserves.

## 9. Capacity model (AAR derivation)

### 9.1 Sources of truth, in order

For each allocator run, the effective capacity of an airport is determined by:

1. If an **`airport_restrictions`** row is active AND its `capacity` field is
   set → use that.
2. Else if **`airports.observed_arrival_rate`** is populated AND `sample_n >
   100` → use that.
3. Else use **`airports.base_arrival_rate`** (operator-configured fallback, e.g.
   copied from vIFF's published value).

### 9.2 How observed rates are computed

The ICAO 9971 Part II Appendix II-B formula:

```
AAR = round_down( mean_ground_speed_at_threshold_kts / mean_spacing_between_aircraft_nm )
```

`bin/compute-aar.php` runs daily and:
1. Queries recent arrivals (last 7 days) at each airport with threshold crossing
   events from `position_scratch` (or `runway_events` if we add it).
2. Computes mean threshold groundspeed from the observations.
3. Computes mean spacing by pairwise consecutive threshold-crossing times × GS.
4. Derives AAR per the formula.
5. Upserts `aar_calculations` row for the (airport, runway, window) triple.
6. If `sample_n > 100`, updates `airports.observed_arrival_rate`.

### 9.3 Runway-specific rates

Capacity varies by runway configuration (e.g. CYHZ RWY 05 has different
geometry from RWY 23). Rows in `aar_calculations` are keyed by
`(airport_icao, runway_ident)`, so we can maintain separate rates per direction.

The allocator looks up the current runway via the ROT tracker's most recent
detected configuration (from `detected_runway_config`-style track-based
inference, not ATIS parsing), then uses the matching row's AAR.

### 9.4 Future: wake-pair AAR

Adding `preceding_wake` and `follower_wake` columns to `aar_calculations` lets
us compute per-wake-pair AARs (e.g. "CYYZ 06L with Medium→Heavy sequences
sustains 42/hr"). Not implemented in v1; schema already accommodates it.

## 10. Runway configuration detection

We do **not** parse ATIS text, and we do **not** call vIFF or PERTI for
runway-in-use information. Instead, we infer the current runway configuration
from observed flight tracks using the approach in PERTI's
`090_detected_runway_config.sql`.

Every 15-30 minutes, for each airport:
1. Collect the last 60 minutes of arrivals at that airport from `flights` where
   `aldt IS NOT NULL`.
2. For each arrival, compute the touchdown heading from the last few
   `position_scratch` rows before `aldt`.
3. Bucket by 10° intervals, find the modal runway heading.
4. Match to the nearest `runway_thresholds` row by heading + airport.
5. Store as the current arrival runway. Same for departures using `atot`
   events.

This is self-correcting (recomputes every cycle), works when ATC is not online,
and degrades gracefully during runway changes (lags by up to an hour, then
snaps to the new config as observations accumulate).

## 11. CTOT delivery surfaces

atfm-tools exposes multiple delivery surfaces for its computed CTOTs. Each is
backed by an adapter class that translates the internal `flights` schema into
the target system's wire format.

### 11.1 `/cdm/etfms/restricted` (primary — for CDM EuroScope plugin)

**Target**: CDM plugin 2.27+ with `<customRestricted url=".../cdm/etfms/restricted"/>`
in CDMConfig.xml.

**Wire format** (bare JSON array, exactly matching CDM's expectations):

```json
[
  { "callsign": "ACA123", "ctot": "1847", "mostPenalizingAirspace": "CYHZ-ARR" },
  { "callsign": "JZA456", "ctot": "1849", "mostPenalizingAirspace": "CYHZ-ARR" }
]
```

**Query**: `SELECT callsign, ctot, ctl_element FROM flights WHERE ctot IS NOT
NULL AND phase NOT IN ('ARRIVED', 'WITHDRAWN') AND ctot > now()`.

### 11.2 Other `/cdm/*` endpoints (for full protocol mirror)

See §11.3 for the full endpoint list. Most are **stubbed to return `[]` or
`200 OK`** for v1 — CDM plugin tolerates empty / stub responses gracefully,
and we only need real data on `/cdm/etfms/restricted` for the use case. This
lets users drop-in replace viff-system.network by changing only the base URL
in their `CDMConfig.xml`.

### 11.3 Full CDM protocol surface

```
GET  /cdm/etfms/restricted          → real data (CTOT list)
GET  /cdm/etfms/restrictions?type=DEP → [] (stub)
GET  /cdm/etfms/relevant            → [] (stub)
GET  /cdm/airport                   → real (configured airports)
GET  /cdm/airport/setMaster         → 200 OK (no-op)
GET  /cdm/airport/removeMaster      → 200 OK
GET  /cdm/ifps/cidCheck?callsign=X  → {exists: false} (stub)
GET  /cdm/ifps/depAirport?airport=X → real (flights ground-side at X)
GET  /cdm/ifps/dpi?...              → 200 OK (no-op, write-back deferred)
GET  /cdm/ifps/allStatus            → [] (stub)
GET  /cdm/ifps/allOnTime            → [] (stub)
```

### 11.4 ECFMP plugin API mirror — `/api/v1/plugin`

Mirrors `ECFMP/flow`'s `PluginApiController` response shape:

```json
{
  "events": [],
  "flight_information_regions": [...],
  "flow_measures": [...]
}
```

Lets CDM-plugin-style clients that consume ECFMP's flow-measure feed read
atfm-tools' restrictions as flow measures. Already implemented in v0.2.

### 11.5 FMP admin endpoints

```
GET    /api/v1/airports              → list
GET    /api/v1/airports/{icao}       → one
POST   /api/v1/airports              → create (admin)
PUT    /api/v1/airports/{icao}       → update

GET    /api/v1/airports/{icao}/restrictions
POST   /api/v1/airports/{icao}/restrictions
DELETE /api/v1/airport-restrictions/{id}

GET    /api/v1/event-sources
POST   /api/v1/event-sources

POST   /api/v1/admin/ctot-imports    → upload a CTOT file
GET    /api/v1/admin/ctot-imports    → list recent imports
```

### 11.6 Debug endpoints

```
GET /api/v1/debug/traffic          → snapshot of active flights
GET /api/v1/debug/allocation       → last allocation run's inputs/outputs
GET /api/v1/debug/runway-config    → currently detected runway per airport
GET /api/v1/debug/aar              → current observed AAR per airport per runway
```

## 12. Deployment (WHC shared hosting)

**Host**: WHC Web Hosting Pro, srv19.swhc.ca, 173.209.32.98 port 27.
**Account**: ogzqox66
**Subdomain**: `atfm.momentaryshutter.com` → DocumentRoot
`/home/ogzqox66/atfm-tools/public`
**Database**: MariaDB 10.6, database `ogzqox66_atfm`

PHP version: 8.2+ (confirmed 8.4 on srv19)
Composer: pre-installed globally
MySQL client: present

### 12.1 Deploy script

`scripts/deploy-whc.sh` (from v0.1):

```bash
ssh ogzqox66@173.209.32.98 -p 27
cd ~/atfm-tools
git pull
composer install --no-dev --optimize-autoloader
php bin/migrate.php
```

SSH key auth via `~/.ssh/atfm_whc` (set up in v0.2 setup). No password prompts.

### 12.2 Cron schedule

```cron
# Data collection
*/5 * * * *   cd ~/atfm-tools && php bin/ingest-vatsim.php >> logs/ingest.log 2>&1
*/5 * * * *   cd ~/atfm-tools && php bin/ingest-events.php >> logs/events.log 2>&1
*/5 * * * *   cd ~/atfm-tools && php bin/ingest-imports.php >> logs/imports.log 2>&1
* * * * *     cd ~/atfm-tools && php bin/rot-tracker.php >> logs/rot.log 2>&1

# Computation
*/5 * * * *   cd ~/atfm-tools && php bin/compute-ctots.php >> logs/ctots.log 2>&1

# Daily maintenance
0 3 * * *     cd ~/atfm-tools && php bin/compute-aar.php >> logs/aar.log 2>&1
0 4 * * *     cd ~/atfm-tools && php bin/cleanup.php >> logs/cleanup.log 2>&1
```

**Staggering**: all the `*/5` entries start at :00, :05, :10, etc. In practice
they run within ~1 second of each other. MySQL handles the concurrent writes
fine because each script operates on different table subsets. If we see lock
contention we stagger by minute offsets (e.g. ingest-vatsim at :00, :05, :10;
compute-ctots at :01, :06, :11; etc.).

## 13. Configuration reference

All values live in `.env` or `airports` / `airport_restrictions` rows.

### 13.1 .env — global defaults

```
APP_ENV=production
APP_DEBUG=false

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=ogzqox66_atfm
DB_USERNAME=ogzqox66_atfm
DB_PASSWORD=<secret>

ATFM_ALLOCATOR_CADENCE_MIN=5
ATFM_ROT_BASELINE_MIN=10
ATFM_ROT_CRITICAL_SEC=15

# Defaults for new restrictions if not specified per-row
ATFM_DEFAULT_TIER_MINUTES=120
ATFM_DEFAULT_COMPLIANCE_EARLY_MIN=5
ATFM_DEFAULT_COMPLIANCE_LATE_MIN=5
ATFM_DEFAULT_MIN_DELAY_TO_ISSUE_MIN=5

# Flight lifecycle
ATFM_DEFAULT_ARRIVED_GEOFENCE_NM=5
ATFM_WITHDRAWN_TIMEOUT_HOURS=10

# Observed-rate promotion
ATFM_OBSERVED_AAR_MIN_SAMPLES=100

# Import watching
ATFM_IMPORTS_PATH=/home/ogzqox66/atfm-tools/storage/imports/ctots
```

### 13.2 Seeded airport defaults (from vIFF + ICAO 9971)

| ICAO | Name | base_arr_rate | taxi_out | taxi_in | lat | lon |
|---|---|---|---|---|---|---|
| CYHZ | Halifax Stanfield | 24 | 10 | 6 | 44.8808 | -63.5086 |
| CYOW | Ottawa Macdonald-Cartier | 28 | 12 | 8 | 45.3225 | -75.6692 |
| CYUL | Montreal Pierre Elliott Trudeau | 40 | 15 | 10 | 45.4706 | -73.7408 |
| CYVR | Vancouver International | 50 | 10 | 8 | 49.1939 | -123.1844 |
| CYWG | Winnipeg James Armstrong Richardson | 36 | 8 | 6 | 49.9100 | -97.2399 |
| CYYC | Calgary International | 32 | 10 | 8 | 51.1139 | -114.0203 |
| CYYZ | Toronto Pearson | 66 | 20 | 12 | 43.6772 | -79.6306 |

`base_arrival_rate` values are sourced from vIFF's configured values for the
FMP's CY/CZ admin scope, except CYOW which is set to 28 per operator
preference pending calibration from observed data.

## 14. Security / privacy

- `.env` chmod 600.
- SSH key auth only (no passwords in deploy).
- Database password isolated to the `ogzqox66_atfm` user, not root.
- Public endpoints return only public data (no personal info beyond VATSIM CIDs,
  which are themselves publicly visible in `data.vatsim.net`).
- No VATSIM OAuth integration in v1 (no user accounts). Admin endpoints are
  IP-allowlisted or behind HTTP basic auth (TBD in implementation phase).
- No API key authentication for `/cdm/etfms/restricted` — the data is public by
  nature (derived from public VATSIM feed) and the endpoint is what CDM plugin
  users will point at.

## 15. Future work (deferred items)

Tracked but not in v1:

- **CYWG runway geometry** — needs NAV CANADA threshold data from operator.
- **Jeremy Peterson coordination** for PERTI SWIM partner key, enabling live
  PERTI data ingest and eventual flow-measure push.
- **Wake-pair AAR modelling** — schema ready, algorithm not implemented.
- **FMP dashboard web UI** — currently just debug JSON endpoints; full UI TBD.
- **VATCAN flight plan remarks amendment** — the Slots-Plugin pattern of writing
  `CTP SLOT / HHMM` into flight plan remarks. Requires VATSIM write access which
  we don't have.
- **Multi-FMP coordination** — currently single-admin model. Master/slave airport
  assignment (CDM's setMaster protocol) is stubbed; real implementation later.
- **Fleet-mix-aware AAR** — preceding/follower wake categorization.
- **VATSIM v3 API field inventory** research pass to confirm field names and
  fallback behavior for partial flight plans.
- **ECFMP consumer-side research** to understand their pop-up handling for
  comparison / validation.
- **GRIB wind integration** — intentionally not planned. See §2.

## 16. Glossary

See [GLOSSARY.md](GLOSSARY.md) for the authoritative term reference covering
ICAO A-CDM, FAA TFMS, Eurocontrol CFMU, ECFMP, PERTI, vIFF, CDM plugin,
VATSIM, and our own internal vocabulary.

## 17. References

- **ICAO Doc 9971** — *Manual on Collaborative Air Traffic Flow Management*,
  3rd Edition, 2018, Part II (Airport Arrival Rate, Appendix II-B) and
  Part III (A-CDM).
- **PERTI** — `vATCSCC/PERTI` on GitHub. Particularly:
  - `adl/procedures/sp_Adl_RefreshFromVatsim_Normalized.sql` (flight_key
    composition)
  - `database/migrations/swim/012_swim_fixm_airports_gates.sql` (FIXM
    column model)
  - `adl/migrations/090_detected_runway_config.sql` (track-based runway
    detection)
  - `database/queries/export_vatsim_adl_schema.sql`
- **ECFMP/flow** — `ECFMP/flow` on GitHub, particularly `routes/api.php` and
  `app/Http/Resources/FlowMeasureResource.php`.
- **CDM Plugin** — `rpuig2001/CDM` on GitHub. `CDMSingle.cpp` has the
  `customRestrictedUrl` logic at ~line 9211 and `getEcfmpData` at ~line 7210.
- **cyhz-rot-collector** — `skyelaird/cyhz-rot-collector`. State machine
  reference for ROT detection.
- **VATCAN Slots-Plugin** — `VATSIMCanada/Slots-Plugin`. Source of the
  `bookings.vatcan.ca/api/event/{code}` endpoint and the event_code model.
- **vIFF / cdm.vatsimspain.es** — Roger Puig's VATSIM flow management system,
  source of our initial capacity values for the 7 Canadian airports.
