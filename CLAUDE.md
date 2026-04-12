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
- Cron every 5 min: ingest, events, imports, ctots; daily cleanup

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
| **AOBT** | Actual Off-Block — *"pushes back / vacates parking position"* | first ingest cycle in TAXI_OUT, only if previousPhase ∈ {null, PREFILE, FILED, TAXI_OUT} |
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

## Tiered ETA cascade (`src/Allocator/EtaEstimator.php`)

The answer to "how do you ETA when the pilot didn't file an enroute_time?"

1. **OBSERVED_POS** (airborne) — great-circle from current position ÷ observed GS, conf 85
2. **FILED** (ground) — filed enroute_time HHMM, conf 90 (SimBrief-quality)
3. **CALC_FILED_TAS** — great-circle ÷ filed cruise_tas, conf 70
4. **CALC_TYPE_TAS** — great-circle ÷ `AircraftTas::typicalTas()` (~60 ICAO types), conf 55
5. **CALC_DEFAULT** — great-circle ÷ 430 kt, conf 40

Airborne always wins via OBSERVED_POS. Ground follows the cascade.

## OpLevel taxonomy (PERTI-compatible)

1. Steady State, 2. Localized, 3. Regional, 4. NAS-Wide.
Derived from FIR adjacency in `src/Allocator/FirMap.php`.

## Source layout

```
src/
  Allocator/      CtotAllocator, EtaEstimator, AircraftTas, FirMap, Geo, Phase, FlightKey
  Api/            Kernel.php (Slim routes — single file)
  Ingestion/      VatsimIngestor.php (5-min cron)
  Imports/        EventBookings, ImportedCtots
  Models/         Flight, Airport, AirportRestriction, ImportedCtot, EventSource, AllocationRun, RunwayThreshold, PositionScratch, AarCalculation
public/
  dashboard.html  main FMP view + airport detail right-docked drawer
  reports.html    XOT/EXIT/EOBT delay/ETA error per airport, sortable
  map.html        live map
bin/
  ingest.php      cron entry: VatsimIngestor
  allocate.php    cron entry: CtotAllocator
  events.php      cron entry: VATCAN event bookings
  imports.php     cron entry: imported CTOTs
  migrate.php     schema migrations
docs/
  ARCHITECTURE.md
  GLOSSARY.md
```

## Deferred / known TODO

- `bin/rot-tracker.php` (v0.4) — adaptive ROT state machine porting cyhz-rot-collector
- `bin/compute-aar.php` (v0.4) — daily AAR derivation from observed data
- CYWG runway threshold data (waiting on operator)
- Jeremy Peterson coordination for PERTI SWIM partner key
- Persist `eta_source` on flights table (currently computed in EtaEstimator
  output but not stored)
- Add ETA accuracy breakdown by source tier to reports page

## Conventions

- Use "Claude" as the author name when adding tracked changes / comments
- All times in UTC; JSON formatted with `format('c')` (ISO 8601 with offset)
- Never use `WidthType.PERCENTAGE` — breaks Google Docs (legacy from a
  separate skill, kept here as a general "stick to literal units" rule)
- Cron picks minutes off `:00`/`:30` to avoid fleet-wide load spikes
- WHC deploy: `git pull` on the server is enough; no build, no asset pipeline

## When in doubt

- Read `docs/ARCHITECTURE.md` first
- Then `docs/GLOSSARY.md` for terminology
- Then `git log --oneline` to see recent direction
