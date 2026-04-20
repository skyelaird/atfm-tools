# atfm-tools — Claude Context

> This file is read automatically by Claude Code when starting a session in
> this repo. It exists so a fresh chat can pick up where the previous one
> left off without re-litigating decisions. **If you're adding context that
> will be useful next session, put it here.**

## Project in one sentence

A lightweight, rate-based tactical CTOT allocator for VATSIM Canadian
airports — schema-compatible with PERTI but a fully independent consumer
from the VATSIM data feed. Serves the CDM EuroScope plugin via its
`customRestricted` URL contract.

## Authoritative docs (read these first if anything is unclear)

- `docs/ARCHITECTURE.md` — full design: schema, state machines, allocator
  algorithm, deployment, cron schedule, ETA cascade (§7.1)
- `docs/GLOSSARY.md` — cross-system term reference (ICAO A-CDM, FAA TFMS,
  Eurocontrol, PERTI, ECFMP, vIFF, CDM plugin, our internal naming)

## Stack

- PHP 8.2+, Slim 4, Illuminate Database (Eloquent), MariaDB
- Vanilla JS dashboard, **no build step**, no SPA framework
- Deployed to WHC shared hosting at `atfm.momentaryshutter.com`
- Cron every 2 min: ingest, events, imports, ctots; daily cleanup

## Scope (locked)

7 Canadian airports: `CYHZ CYOW CYUL CYVR CYWG CYYC CYYZ`.
Not multi-region. Not a generalised flow management platform.

## Hard rules / non-goals

- GRIB 250mb wind is **authoritative** in the ETA cascade (v0.5.64+).
  `WindEta::computeForFlight()` runs inline during ingest — top-priority
  airborne tier (WIND_GRIB, conf 92). `eldt_wind` column retained for
  QA comparison on the PERTI page. `bin/compute-wind-eldt.php` also
  available as standalone cron for batch updates. Pure PHP, no Python.
- Never invent A-CDM milestones we can't observe (e.g. **never stamp ASAT**
  from the ingestor — it's a controller event, not a position event)
- Never persist CTOTs across restriction lifetimes — stale CTOTs are
  released at the start of every allocator run
- Never display DISCONNECTED flights on live dashboard views (filter at
  the API edge); reports may include them
- Mirror ICAO A-CDM milestone vocabulary **internally**; translate at HTTP
  edges only when serving PERTI-compatible payloads to the CDM plugin

## A-CDM milestone semantics (this trips people up)

Authoritative reference: **EUROCONTROL *Airport CDM Implementation Manual*,
v5.0, 31 March 2017**. The complete table with quoted definitions lives in
`docs/GLOSSARY.md §1`. Short version below.

Naming convention: `S*` scheduled, `E*` estimated, `T*` target, `C*`
calculated (regulation), `A*` actual.

| Milestone | What it means | How we observe |
|-----------|---------------|----------------|
| EOBT | Estimated Off-Block — filed time | from `flight_plan.deptime` + DOF (ICAO remarks) |
| SOBT | Scheduled Off-Block — published timetable | not consumed (no schedule feed) |
| TOBT | Target Off-Block — AO/GH ready time | non-CDM fallback: TOBT = EOBT |
| TSAT | Target Start-Up Approval — DMAN output | non-CDM fallback: TSAT = TOBT |
| ETOT | Estimated Take-Off = **EOBT + EXOT** | not stored separately; ≡ TTOT for non-CDM |
| TTOT | Target Take-Off = **TOBT + EXOT** | computed in ingestor |
| CTOT | Calculated Take-Off — slot allocation | what our allocator emits |
| **ASAT** | Actual Start-Up Approval | **never stamped** by ingest — controller event, no VATSIM signal. *"Can be in advance of TSAT"* per the manual. |
| **AOBT** | Actual Off-Block — *"pushes back / vacates parking position"* | first ingest cycle with GS > 0 at ADEP geofence (pushback detection), only if previousPhase ∈ {null, PREFILE, FILED, TAXI_OUT} |
| ATOT | Actual Take-Off | first ingest cycle in DEPARTED or later |
| ELDT | Estimated Landing | from EtaEstimator (5-tier cascade) |
| ALDT | Actual Landing | first ingest cycle in ON_RUNWAY/VACATED/TAXI_IN/ARRIVED |
| AIBT | Actual In-Block | second consecutive ARRIVED cycle (delayed one cycle so AXIT can be non-zero on a 5-min cadence) |

**EXOT vs AXOT (don't conflate)** — the manual is explicit:

- **EXOT** = *Estimated* Taxi-Out Time. A **planning value**, the input to
  `TTOT = TOBT + EXOT`. Stored in `airports.default_exot_min` and
  `flights.planned_exot_min`.
- **AXOT** = *Actual* Taxi-Out Time. The **measurement**: `ATOT − AOBT`.
  Stored (legacy column name) in `flights.actual_exot_min` — UI labels
  it as AXOT. Computed only when AOBT was stamped on a *prior* cycle,
  capped 1–60 min.
- Same E/A split for **EXIT** (planned) vs **AXIT** (= `AIBT − ALDT`).

The 60-min cap exists because pilots who spawn-then-idle (or controllers
who reposition aircraft) produce 100+ min outliers that skew reports.

## ETA estimation (`src/Allocator/EtaEstimator.php`)

Airborne cascade (v0.5.63+), then ground fallback:

**Airborne (at cruise):**
1. **WIND_GRIB** — GRIB wind from observed position + route, conf 92.
   Computed inline by `WindEta::computeForFlight()`. Grid coverage:
   LAT 15-70, LON -170 to +30 (covers CONUS, Caribbean, NAT, Europe,
   trans-Pacific east of dateline).
   Also writes `eldt_wind` column for QA comparison.
2. **OBSERVED_POS** — along-route distance from observed position, filed TAS
   preferred over GS (wind-neutral), conf 91/88. Position-aware, updates
   every cycle — beats FILED for airborne flights.
3. **FILED** (airborne fallback) — ATOT + filed enroute_time.
   Static from takeoff. Unreachable in practice (OBSERVED_POS always fires
   first for airborne flights with position).

**Ground / climbing:**
4. **FILED** (ground) — filed enroute_time + taxi, conf 90
4b. **FIR_EET** — ICAO EET/ from remarks (dispatch winds-corrected) +
    airport-specific approach time, conf 80
5. **CALC_FILED_TAS** — descent-aware from filed cruise_tas, conf 70
6. **CALC_TYPE_TAS** — descent-aware from `AircraftTas::typicalTas()`, conf 55
7. **CALC_DEFAULT** — descent-aware from 430 kt, conf 40

**ELDT eligibility**: only computed for flights at cruise altitude
(alt >= filed altitude − 2000 ft) or in ARRIVING phase. Flights in
FILED, CLIMBOUT, or FLS-NRA phases show no ELDT.

**Descent model** (`Geo::etaMinutesWithDescent()`): standard 3° glidepath
with published speed constraints — 250 kt below FL100, 220 kt within
20nm, type-specific IAS above FL100 (310 kt for B77W, 280 for B738,
etc. from PMDG/iniBuilds profiles). TOD at altitude/318 nm.

**Taxi time**: zone-based from `data/taxizones.txt` (apron polygon ×
runway → minutes). Falls back to airport default.

**ELDT freeze**: snapshots at **T-90m / 92 min** (freeze window ~88..92 min
before predicted landing). Aligned with CTOT scope — candidates have
ETE ≤ 1:30, so allocator lookahead and freeze horizon are the same clock.
The frozen value becomes TLDT (committed slot). Target accuracy: ±3 min.
See [docs/DESIGN.md](docs/DESIGN.md) §4 for rationale.

## Route resolution (`Geo::parseRouteCoordinates()`)

4-layer pipeline resolves ICAO route strings to coordinate waypoint arrays
(v0.5.29+). A route like `SSM V300 YVV DCT TONNY BOXUM7` yields ~18
resolved waypoints instead of 2.

1. **Coordinate waypoints** — `49N050W`, `5530N02030W` parsed directly
2. **Named fixes** — lookup in `data/waypoints.json` (124 684 fixes from
   Navigraph ISEC + AIRWAY + PMDG SidStars terminal fixes)
3. **Airway segments** — `FIX_A J501 FIX_B` expanded via adjacency graph
   in `data/airways.json` (4 654 airways, 38 654 fix entries)
4. **SID/STAR procedures** — e.g. `BOXUM7` expanded via
   `data/procedures.json` (61 procedures for the 7 Canadian airports)

`bin/import-navdata.php` regenerates all three JSON files from Navigraph
AIRAC data + PMDG SidStars. The Python `wind-shadow.py` mirrors the same
4-layer parsing.

## Wind-corrected ELDT (v0.5.66+, authoritative)

`src/Allocator/WindEta.php` — pure PHP multi-level GRIB wind integration.
Downloads GFS 1° subregion at **3 pressure levels** (250mb ≈ FL340,
300mb ≈ FL300, 500mb ≈ FL180) from NOAA NOMADS in one call (cached 6h).
Level selected by cruise altitude — turboprops at FL180 get 500mb winds,
jets at FL380 get 250mb. Integrates wind per grid cell along the resolved
route; descent segment uses no-wind model. Grid coverage: LAT 25-65,
LON -170 to -30.

**Authoritative in the ETA cascade**: `WIND_GRIB` is the top-priority
airborne tier (conf 92). Computed inline during ingest by
`WindEta::computeForFlight()`. Flights outside the grid fall to
ATOT + filed ETE (conf 90), then geometric OBSERVED_POS (conf 85).

`eldt_wind` column also written for three-way QA comparison on the
PERTI page (our ELDT / GRIB wind / PERTI). `bin/compute-wind-eldt.php`
available as standalone cron for batch updates.

Legacy: `bin/compute-wind-eldt.py` (Python) and `bin/experiments/wind-shadow.py`
(research prototype with SQLite) retained for reference.

## Reports page KPIs

**Dwell** = median spawn-to-pushback time: `AOBT − created_at` in minutes.
Replaces the old ΔOBT (AOBT−EOBT) which was proven unreliable because
EOBT is garbage on VATSIM. Capped at 120 min to exclude idle spawners.
Used to validate the TOBT proxy (TOBT = max(EOBT, spawned + 20 min)).

**ELDT err / TLDT err** = **median** prediction error, not mean. Median
resists outliers (one disconnected flight producing a 400-min error
would destroy a mean with n=9). Sample sizes < 5 are dimmed as
statistically meaningless.

**Type table** counts completed movements only (arrivals with ALDT,
departures with ATOT) — same scope as the movements row. Per-airport
columns sum ADES + ADEP movements. Total = sum of per-airport columns
(always adds up, no dedup discrepancy).

## Active runway configuration

Server-side single source of truth on the `airports` table:
`active_config_name`, `active_arr_rate`, `active_dep_rate`,
`active_config_set_at`. Set by AAR page via `POST /api/v1/active-config`.
All consumers (dashboard, FSM, reports, allocator restrictions) read
`active_arr_rate ?? base_arrival_rate`. Physics-based rates from
`data/runway-configs.json` feed the AAR page calculator; the AAR page
writes the result to the DB.

## AAR page (`public/aar.html`)

Wind-aware runway configuration selector. Fetches live METAR from AVWX,
computes headwind/crosswind per runway, proposes optimal config.

**Magnetic variation**: `Mag = True + MAG_VAR` where MAG_VAR is positive
for West variation (eastern Canada) and negative for East variation
(western Canada). Values from NOAA NCEI WMM, epoch ~2025. AVWX returns
true wind; runway headings in DB are magnetic.

**Wind limits**: MAX_TAILWIND 5kt, MAX_XW_DRY 30kt (MATS), MAX_XW_WET 15kt.

**Auto-propose scoring**: composite `score = declared_rate + max(0, hw) * 0.5`.
Headwind bonus lets a well-aligned lower-rate config beat a poorly-aligned
higher-rate config (e.g. CYHZ 14 ILS with 17kt HW beats 05 with 1kt HW
despite similar declared rates). Dual-parallel configs (rate 42+) still
dominate. Exceptional configs (CYYZ 15/33) tried only if no preferred
config is available.

**LAHSO**: shown when airport has LAHSO configs in `runway-configs.json`,
conditions are VMC + dry. "no LAHSO" badge has tooltip explaining why
(Requires VMC / Requires dry runway).

**Airport-specific notes**:
- CYHZ: 14 has ILS (preferred arrival in IMC), 05/23 longer for heavy deps
- CYOW: crossing runways 07/25 + 14/32 — dependent configs available
- CYWG: crossing runways 18/36 + 13/31 — dependent + LAHSO configs
- CYVR: north runway (08R/26L) normally arrivals, south (08L/26R) departures

## OpLevel taxonomy (PERTI-compatible)

1. Steady State, 2. Localized, 3. Regional, 4. NAS-Wide.
Derived from FIR adjacency in `src/Allocator/FirMap.php`.

## Source layout

```
src/
  Allocator/      CtotAllocator, EtaEstimator, AircraftTas, TaxiZones,
                  FirMap, Geo, Phase, FlightKey, WindEta
  Api/            Kernel.php (Slim routes — single file, all endpoints)
  Ingestion/      VatsimIngestor.php (2-min cron)
  Imports/        EventBookings, ImportedCtots
  Models/         Flight, Airport, AirportRestriction, ImportedCtot,
                  EventSource, AllocationRun, RunwayThreshold, PositionScratch
  Version.php     Single source of truth for running version
public/
  dashboard.html  FMP view + airport detail right-docked drawer
  reports.html    per-airport KPIs + ELDT/TLDT accuracy + aircraft mix
  guide.html      FMP training manual / reference guide
  map.html        live map (disabled — no operational use yet, shows FIR boundaries only)
data/
  taxizones.txt   apron polygons x runway -> taxi time (from vIFF CDM config)
  rates.txt       runway-config arrival/departure rates
  waypoints.json  124,684 enroute + terminal fixes (from Navigraph + PMDG)
  airways.json    4,654 airways with adjacency graph (from Navigraph AIRWAY.txt)
  procedures.json 61 SID/STAR procedures for the 7 Canadian airports
bin/
  ingest-vatsim.php   cron: VatsimIngestor (every 2 min)
  compute-ctots.php   cron: CtotAllocator (every 2 min). --shadow for dry-run
  ingest-events.php   cron: VATCAN event bookings (every 2 min)
  ingest-imports.php  cron: imported CTOTs (every 2 min)
  compute-wind-eldt.php  cron: GRIB wind-corrected ELDT (every 5 min)
  compute-demand-history.php  cron: daily metering-fix demand rollup to data/cache/demand-history.json (trailing 30d)
  cleanup.php         cron: daily position_scratch purge + WITHDRAWN timeout
  deploy.sh           cron: auto-deploy (every 1 min)
  migrate.php         schema migrations (idempotent)
  seed-airports.php   airport + runway threshold seeding
  scrub-hallucinations.php  data cleanup (one-shot, idempotent)
  audit-data.php      data quality report (read-only)
  tobt-analysis.php   TOBT proxy research: spawn-to-pushback stats
  import-navdata.php  generate waypoints/airways/procedures JSON from Navigraph + PMDG
  experiments/        wind-shadow.py (GRIB wind-corrected ELDT prototype)
docs/
  ARCHITECTURE.md     full design document
  GLOSSARY.md         cross-system term reference
  API.md              endpoint reference + integration guide
  AMAN-DMAN.md        aman-dman plugin operational guide for CZQM/CZQX
```

## Deferred / known TODO

- ~~CYWG runway threshold data~~ ✅ shipped v0.4.0 (operator-supplied)
- ~~`bin/rot-tracker.php` + `bin/compute-aar.php`~~ shipped v0.4.0,
  **retired v0.4.7** — see "Retired ideas" below
- Jeremy Peterson coordination for PERTI SWIM partner key — **strictly
  optional**, not blocking. PERTI runs a public SWIM v1 API; we ingest
  VATSIM directly so we don't need it.
- ~~Persist `eta_source` on flights table~~ ✅ shipped v0.5.24
- ~~Add ETA accuracy breakdown by source tier to reports page~~ ✅ shipped v0.5.24
- ~~TOBT proxy from spawn-to-movement stats~~ ✅ shipped v0.5.24
  (TOBT = max(EOBT, created_at + 20 min) — data-driven from 675 departures)
- ~~Navigation data + route resolution~~ ✅ shipped v0.5.31
  (4-layer parsing: coordinates, named fixes, airways, SID/STARs)
- ~~FIR_EET tier~~ ✅ shipped v0.5.26 (dispatch-quality ETA from ICAO EET/)
- ~~DESCENT phase~~ ✅ shipped v0.5.46 (counterpart to DEPARTED, 40–200nm)
- ~~Active config single source of truth~~ ✅ shipped v0.5.47
  (server-side active_arr_rate on airports table, all consumers read it)
- ~~Reports redesign~~ ✅ shipped v0.5.48–v0.5.51
  (dwell replaces ΔOBT, median errors, rate column, type table fixed)
- ~~Deploy runs seed~~ ✅ shipped v0.5.47
  (deploy.sh now runs seed-airports.php after migrate on every deploy)
- Phase-2 wake-mix correction for CYVR/CYYZ — needs historical aircraft mix
- ctot.html live testing with CDM plugin — needs a real session
- ~~Wind-corrected ELDT~~ ✅ shipped v0.5.66
  (pure PHP multi-level GRIB: 250mb/300mb/500mb, authoritative in ETA
  cascade as WIND_GRIB conf 92. Level selected by cruise altitude.)
- ~~TLDT accuracy validation~~ ✅ shipped v0.5.66
  (reports panel: ATOT + TLDT + ALDT flights, median error, MAE,
  % within ±3m/±5m, breakdown by source tier and airport.
  API: GET /api/v1/reports/tldt-accuracy)

## Retired ideas (don't re-propose without checking)

- **ROT measurement / data-driven AAR**. v0.4.0 built `rot-tracker` and
  `compute-aar` to derive ROT and AAR from `position_scratch` history.
  Retired in v0.4.7 because (a) measuring ROT to useful precision needs
  sub-minute ingest cadence which shared-hosting cron can't deliver
  cleanly, and (b) **the value of this system is in slot allocation
  against a declared rate, not in deriving the rate**. AAR comes from
  operator knowledge (`airports.base_arrival_rate`). The cyhz-rot-collector
  Python tool remains the right way to measure ROT precisely if that
  need ever returns.
- **OSM runway-exit detection** (proposed earlier this session). Same
  ROI argument — only matters if we revive ROT measurement.

## North Star

The valuable thing this system does is **estimate ELDT well enough
to allocate arrival slots**, vIFF / ECFMP-style. Every feature should
be evaluated against that. If a feature doesn't improve slot allocation
quality or operator situational awareness around inbound load, it
probably shouldn't ship.

## Versioning

`src/Version.php` is bumped on every push to main so deploys are
verifiable via `/api/v1/status`. Scheme: `MAJOR.MINOR.PATCH`.

| Version | Milestone | Criteria |
|---------|-----------|----------|
| **0.5.x** | ETA & prediction quality | Shipped. GRIB wind, ETA cascade, TLDT validation, reports. |
| **0.6.0** | CTOT issuance live | **Current.** Restriction creation UI on dashboard drawer with shadow-allocator preview + commit. FMP creates regulations in-browser, allocator issues real CTOTs. |
| **0.7.0** | Operational hardening | Multi-session validation, wake-mix phase 2, multi-FMP confidence. |
| **1.0.0** | Production-ready | Running reliably during a real VATCAN event — CTOTs flowing, CDM plugin consuming, no manual intervention. |

- **Patch** (0.6.1, 0.6.2…): one per push, always incremented, never skipped.
- **Minor** (0.6 → 0.7): meaningful capability milestone, agreed before bumping.
- **Major** (0.x → 1.0): "we trust it in production." Not before a successful
  live event with real controllers consuming slots.

## Conventions

- Use "Claude" as the author name when adding tracked changes / comments
- All times in UTC; JSON formatted with `format('c')` (ISO 8601 with offset)
- Never use `WidthType.PERCENTAGE` — breaks Google Docs (legacy from a
  separate skill, kept here as a general "stick to literal units" rule)
- Cron picks minutes off `:00`/`:30` to avoid fleet-wide load spikes
- **WHC deploy is automatic** via the `bin/deploy.sh` cron entry
  (every minute, fast-forward only, runs `bin/migrate.php` then
  `bin/seed-airports.php` after a real pull, silent on no-op). Pushing
  to `origin/main` reaches prod within ~60 s; no manual `git pull`
  required. If a deploy fails, cron mail surfaces it because `deploy.sh`
  exits non-zero on dirty tree, divergent history, or migration failure.
- **Single source of truth for rates**: `active_arr_rate` on the airports
  table (set by AAR page via `POST /api/v1/active-config`) is the
  preferred rate everywhere. All consumers read
  `active_arr_rate ?? base_arrival_rate`. Never store rates in
  localStorage or in-memory JS variables.

## When in doubt

- Read `docs/ARCHITECTURE.md` first
- Then `docs/GLOSSARY.md` for terminology
- Then `git log --oneline` to see recent direction
