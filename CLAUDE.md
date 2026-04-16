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

- Never compute winds, pressure, atmosphere — geometric ETA only
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

5-tier cascade, all using **descent-aware** computation (v0.5.6+):

1. **FILED** (ground) — filed enroute_time + taxi, conf 90
2. **OBSERVED_POS** (airborne) — descent-aware ETA from current position, conf 85
3. **CALC_FILED_TAS** — descent-aware from filed cruise_tas, conf 70
4. **CALC_TYPE_TAS** — descent-aware from `AircraftTas::typicalTas()`, conf 55
5. **CALC_DEFAULT** — descent-aware from 430 kt, conf 40

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

## OpLevel taxonomy (PERTI-compatible)

1. Steady State, 2. Localized, 3. Regional, 4. NAS-Wide.
Derived from FIR adjacency in `src/Allocator/FirMap.php`.

## Source layout

```
src/
  Allocator/      CtotAllocator, EtaEstimator, AircraftTas, TaxiZones,
                  FirMap, Geo, Phase, FlightKey
  Api/            Kernel.php (Slim routes — single file, all endpoints)
  Ingestion/      VatsimIngestor.php (2-min cron)
  Imports/        EventBookings, ImportedCtots
  Models/         Flight, Airport, AirportRestriction, ImportedCtot,
                  EventSource, AllocationRun, RunwayThreshold, PositionScratch
  Version.php     Single source of truth for running version
public/
  dashboard.html  FMP view + airport detail right-docked drawer
  reports.html    per-airport KPIs + ELDT/TLDT accuracy + aircraft mix
  map.html        live map
data/
  taxizones.txt   apron polygons x runway -> taxi time (from vIFF CDM config)
  rates.txt       runway-config arrival/departure rates
bin/
  ingest-vatsim.php   cron: VatsimIngestor (every 2 min)
  compute-ctots.php   cron: CtotAllocator (every 2 min)
  ingest-events.php   cron: VATCAN event bookings (every 2 min)
  ingest-imports.php  cron: imported CTOTs (every 2 min)
  cleanup.php         cron: daily position_scratch purge + WITHDRAWN timeout
  deploy.sh           cron: auto-deploy (every 1 min)
  migrate.php         schema migrations (idempotent)
  seed-airports.php   airport + runway threshold seeding
  scrub-hallucinations.php  data cleanup (one-shot, idempotent)
  audit-data.php      data quality report (read-only)
docs/
  ARCHITECTURE.md     full design document
  GLOSSARY.md         cross-system term reference
  API.md              endpoint reference + integration guide
```

## Deferred / known TODO

- ~~CYWG runway threshold data~~ ✅ shipped v0.4.0 (operator-supplied)
- ~~`bin/rot-tracker.php` + `bin/compute-aar.php`~~ shipped v0.4.0,
  **retired v0.4.7** — see "Retired ideas" below
- Jeremy Peterson coordination for PERTI SWIM partner key — **strictly
  optional**, not blocking. PERTI runs a public SWIM v1 API; we ingest
  VATSIM directly so we don't need it.
- Persist `eta_source` on flights table (currently computed in EtaEstimator
  output but not stored)
- Add ETA accuracy breakdown by source tier to reports page

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

## Conventions

- Use "Claude" as the author name when adding tracked changes / comments
- All times in UTC; JSON formatted with `format('c')` (ISO 8601 with offset)
- Never use `WidthType.PERCENTAGE` — breaks Google Docs (legacy from a
  separate skill, kept here as a general "stick to literal units" rule)
- Cron picks minutes off `:00`/`:30` to avoid fleet-wide load spikes
- **WHC deploy is automatic** via the `bin/deploy.sh` cron entry
  (every minute, fast-forward only, runs `bin/migrate.php` after a real
  pull, silent on no-op). Pushing to `origin/main` reaches prod within
  ~60 s; no manual `git pull` required. If a deploy fails, cron mail
  surfaces it because `deploy.sh` exits non-zero on dirty tree, divergent
  history, or migration failure.

## When in doubt

- Read `docs/ARCHITECTURE.md` first
- Then `docs/GLOSSARY.md` for terminology
- Then `git log --oneline` to see recent direction
