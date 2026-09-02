# atfm-tools — Claude Context

> This file is read automatically by Claude Code when starting a session in
> this repo. It exists so a fresh chat can pick up where the previous one
> left off without re-litigating decisions. **If you're adding context that
> will be useful next session, put it here.**

## Where we are (update this at the end of every session)

**Live version:** 0.7.56 · prod auto-deploys from `main` within ~60 s ·
verify at `/api/v1/status`. Last re-baselined **2026-09-02** after a
summer with no attention.

**Three workstreams, one trunk.** Everything lands on `main`: research
validates concepts that feed the tools, QA finds gaps to repair, and
branches would only delay integration for a solo operator. The gate on
anything touching `src/` is shadow mode or a QA side-column, not a branch.

| Stream | State | Where it lives |
|---|---|---|
| **Core ATFM** (0.7.x) | operational hardening. CTOT issuance works; not yet validated against a live CDM plugin session | `src/`, `public/dashboard.html`, `public/aar.html` |
| **Non-event CTOT portal** (v0.7.0+) | shipped, open for validation. 4 slots/hr/ADES, OAuth + manual CID fallback | `docs/DESIGN-NONEVENT-CTOT.md`, `docs/USAGE-NONEVENT-CTOT.md` |
| **26E / research** | post-event analyses published; wind-skill corpus analysed | `bin/26e-*.py`, `public/26e-*.html`, `docs/wind-skill-2026-spring.md` |

**Shipped since the 0.6 line:**
- v0.7.0 non-event CTOT portal — public flow management for CTP overflow
- CDM plugin v2.28 contract compliance
- 26E CTP airspace load estimator (CZQM/CZQX), live sector-occupancy map,
  post-event analysis pages, per-flight Monte Carlo wind-uncertainty viz
- `docs/nat-fl-allocation.md` — 5/3/5 FL stack, CTP 20/hr/track cap is binding
- `docs/wind-skill-2026-spring.md` — D-3 GFS 250 mb RMSE 18.3 kt → ±3.7
  flights at sector peak; morning-of refresh adds nothing actionable
- v0.7.34 OBSERVED_POS forced for ARRIVING/DESCENT
- v0.7.36 DISCONNECTED excluded from status counts (pill read 1713 vs 25)
- v0.7.37 PERTI retired, its page repurposed as ELDT QA
- v0.7.43 **CDM payload bug** — the penalising-regulation key moved twice; any
  plugin built after 2026-05-01 was silently discarding every CTOT we
  published. No earlier informal test could have worked.
- v0.7.45 one error sign convention across the API (`actual − predicted`)
- v0.7.46 `public/cdm-atc.html` — CDM plugin walkthrough for controllers
- v0.7.49 vIFF constraint mirror (off by default)
- v0.7.55 portal showed a refiling pilot their DISCONNECTED flight
- v0.7.56 pilot TOBT adopted from the VDGS (off by default)

**Open / next:**
- **CDM plugin round-trip still untested against a live EuroScope session —
  the last real gate on 0.7 → 1.0.** Note the payload bug fixed in v0.7.43:
  any plugin built after 2026-05-01 was discarding every CTOT we published,
  so no earlier informal test could have worked.

**Pilot TOBT is readable from vIFF (proven live 2026-09-02).**
`GET /ifps/depAirport?airport=XXXX` is public and per-airport, and carries
`tobt`/`obt`/`reqTobt` plus `cdmData.reqTobtType` = PILOT | ATC. A TOBT the
pilot sets on `vats.im/vdgs` shows up there within a cycle. Shipped as
`bin/ingest-viff-tobt.php` (v0.7.56), **disabled** until
`VIFF_PILOT_TOBT_ENABLED=true`. Without it the two systems silently disagree:
the VDGS told a pilot his start-up window was open while we still had him 13
minutes out, because he had declared 1750 and we were using our 1735 proxy.

**CTOT delivery to pilots — two channels, by design:**
- **Staffed airports: via ATC.** The CDM plugin puts the CTOT in the
  controller's tag (TSAT = CTOT − taxi) and the controller issues start-up.
- **Unstaffed: self-serve via `public/portal.html`.** Pilot looks up their
  callsign, reads TOBT/TSAT/TTOT/CTOT, can set a manual TOBT. This is our
  equivalent of VATSIM Spain's VDGS (`vdgs.vatsimspain.es`), which plays the
  same role for vIFF but gates per-pilot behind VATSIM OAuth. Ours is
  callsign lookup with no sign-in: `Auth\Gate::modifyFlight` already encodes
  the right rule (own CID, or a controller connected to that airport) but
  runs permissive until `AUTH_STRICT=true`.
- **STAR truncation — the model discards the downwind (found 2026-09-02).**
  `WindEta::alongRouteLegs()` keeps only waypoints closer to the field than the
  aircraft is, which assumes a monotonic approach. RAGID6 into CYYZ passes
  overhead at KEVNO (4.9 nm) and runs back out to DERLI (21.3 nm) for the 06
  downwind — so at KEVNO the model keeps **no fixes** and reports 4.9 nm with
  **45.2 nm left to fly**. The error grows as the aircraft approaches, which is
  the worst shape for a flow tool. Separately, speed bands are allocated by
  `alt/318` geometry rather than by position in the procedure, so STAR track
  miles get flown at cruise/descent speeds — that one biases the frozen TLDT.
  Fix needs no new data; see `docs/sessions/2026-09-02_star-truncation.md`.
  **This is the top ETA priority, ahead of observed-TAS.**
- **ELDT bias attributed (2026-09-02, n=1475 over 14 d).** Median TLDT error
  **+5.6 min** — sign convention project-wide is `error = actual − predicted`,
  so positive means the aircraft landed after our estimate, i.e. we predicted
  early. It decomposes as: **1.1 min** ALDT stamped late (rollout below 50 kt
  plus the 2-min ingest quantum — measurement, not model) and **~4.5 min**
  in the terminal segment. Cruise, wind and descent above FL100 are
  exonerated: filed ETE median error is 0.0 and the bias is flat across
  distance and flight time. Observed 40 nm → wheels is 13.0–16.7 min per
  airport against ~10.9 min implied by the descent model; the 100→40 nm
  segment matches. Aircraft cross the 40 nm ring at a median 360 kt GS and
  average ~155 kt over the last 40 nm; the model assumes ~220 kt and models
  no track-mile allowance for STAR/downwind/base/vectors.
  Measured terminal excess, which is the correction table (minutes to ADD
  to the modelled last 40 nm): CYUL +5.8, CYYZ +5.0, CYVR +4.7, CYHZ +3.6,
  CYOW +3.3, CYWG +2.7, CYYC +2.1.
  Diagnostics: `/api/v1/debug/landing-lag`, `/api/v1/debug/terminal-time`.
  **Confirmed live 2026-09-02** by an aircraft FMS on CYHZ→CYYZ: 12 min below
  FL100 against our 8.7 min budget, speed profiles agreeing within 2 kt — so
  it is track miles, not speed. Also isolated ~2 min of filed-TAS optimism
  (we use filed TAS; that flight filed M0.797 and flew M0.76). The fix must
  be **conditional on ATC presence**, not a flat constant: the same flight
  needed ~3.3 min uncontrolled and ~11 min after a late runway change. See
  `docs/sessions/2026-09-02_fal57-live-eta-comparison.md`.
  **Correction not yet applied — approach undecided (see below).**
- Phase-2 wake-mix correction for CYVR/CYYZ — needs historical aircraft mix
- Departures landing at an out-of-scope ADES revert to phase FILED
  (display-only; outbound query filters on `atot`)

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
- `docs/DESIGN.md` — rationale behind the choices, incl. CTOT scope by
  OpLevel and the slot allocation model (§6b)
- `docs/DESIGN-NONEVENT-CTOT.md` + `docs/USAGE-NONEVENT-CTOT.md` — the
  non-event portal (v0.7.0+)
- `docs/wind-skill-2026-spring.md` — GFS forecast skill vs lead time,
  and what it means for CTP staffing decisions
- `docs/nat-fl-allocation.md` — NAT FL stack + capacity model
- `docs/w27-uncertainty-model.md` — Westbound 2027 per-flight MC design
- `docs/VIFF-INTEGRATION.md` — vIFF's restriction model vs ours, their write
  path, and what to ask Roger for

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
  QA comparison on the ELDT QA page. `bin/compute-wind-eldt.php` also
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
| ALDT | Actual Landing | projected to the nearest threshold from the last airborne observation, clamped inside the observation bracket (v0.7.42). Falls back to the first on-ground cycle when that sample wasn't short final; `aldt_source` = INTERP \| CYCLE. ON_RUNWAY/VACATED are never produced — they were the retired ROT tracker's |
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

## Wind-corrected ELDT (v0.5.66+, 3-phase integration v0.6.42+)

`src/Allocator/WindEta.php` — pure PHP multi-level GRIB wind integration.
Downloads GFS 1° subregion at **3 pressure levels** (250mb ≈ FL340,
300mb ≈ FL300, 500mb ≈ FL180) from NOAA NOMADS in one call (cached 6h).

**3-phase wind model** (v0.6.42): the route from current position to the
threshold is split into four segments, each integrated per grid cell:

| Phase | Wind grid | Avg TAS | Notes |
|-------|-----------|---------|-------|
| Climb (if still climbing) | 500mb | cruise × 0.80 | climb gradient 300 ft/nm |
| Cruise | alt-selected (250/300/500) | filed or type TAS | main enroute segment |
| Descent above FL100 | 500mb | descent IAS_high × 1.30 | ~FL180-FL340 traverse |
| Below FL100 | (no wind) | 140-250 kt profile | short, low-level GRIB not representative |

Climb distance = `(cruiseAlt - curAlt) / 300` (clamped so climb + descent
can't exceed total route — short-haul sanity). TOD at `altAbove / 318.0`.
FL100 crossing at `fl100AGL / 318.0` before threshold.

Prior behavior (pre-v0.6.42): only cruise phase had wind applied; descent
used a no-wind geometric model. That created a systematic -4 to -7 min
early bias on OBSERVED_POS and ~-3 min on WIND_GRIB because jetstream
tailwinds on descent went uncounted. Short-haul flights (<90m, almost
all climb+descent) barely benefited from GRIB at all. Grid coverage:
LAT 15-70, LON -170 to +30.

**Authoritative in the ETA cascade**: `WIND_GRIB` is the top-priority
airborne tier (conf 92). Computed inline during ingest by
`WindEta::computeForFlight()`. Flights outside the grid fall to
ATOT + filed ETE (conf 90), then geometric OBSERVED_POS (conf 85).

`eldt_wind` column also written for three-way QA comparison on the
ELDT QA page (our ELDT / GRIB wind / ALDT). `bin/compute-wind-eldt.php`
available as standalone cron for batch updates.

Legacy: `bin/compute-wind-eldt.py` (Python) and `bin/experiments/wind-shadow.py`
(research prototype with SQLite) retained for reference.

## Reports page KPIs

**Dwell** = median spawn-to-pushback time: `AOBT − created_at` in minutes.
Observed 7d (v0.6.24, n=954): small hubs (CYHZ/CYOW) 14m, mid (CYYC) 16m, big
(CYVR/CYUL/CYYZ) 18-19m, outlier CYWG 20m. Weighted avg ~18m.
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
  Allocator/      CtotAllocator, NoneventCtotAllocator, EtaEstimator, WindEta,
                  AircraftTas, TaxiZones, MeteringFix, FirMap, Geo,
                  AirportCoords, Phase, FlightKey
  Api/            Kernel.php (Slim routes — single file, all endpoints),
                  FlowClient
  Auth/           Gate (permissive until AUTH_STRICT=true), VatsimOAuth
  Ingestion/      VatsimIngestor (2-min cron), VatcanEventIngestor,
                  FileImportIngestor, ViffRestrictionIngestor, EcfmpClient
  Models/         Flight, Airport, AirportRestriction, ImportedCtot,
                  NoneventSlot, EventSource, AllocationRun, RunwayThreshold,
                  PositionScratch, AuthSession, Fir, FlowMeasure
  Bootstrap.php   env + DB boot
  Version.php     Single source of truth for running version
public/
  dashboard.html  FMP view + airport detail right-docked drawer
  reports.html    per-airport KPIs + ELDT/TLDT accuracy + aircraft mix
  aar.html        wind-aware runway config + AAR calculator + MIT planner
  eldt-qa.html    ELDT QA (was perti.html; perti.html is now a redirect stub)
  fsm.html        flow situation monitor
  guide.html      FMP training manual / reference guide
  cdm-atc.html    CDM plugin walkthrough for aerodrome controllers
  portal.html     pilot self-serve: look up callsign, read CTOT, set TOBT
  nonevent.html   non-event CTOT portal (+ nonevent-guide.html)
  ctot.html       CDM plugin test page
  26e-*.html      Westbound 2026E analyses (see 26e-index.html)
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
  ingest-viff-tobt.php  cron: adopt pilot-declared TOBT from the VDGS
                      (every 2 min). No-op unless VIFF_PILOT_TOBT_ENABLED=true
  ingest-viff-restrictions.php  cron: mirror vIFF's public ARR restrictions
                      into our restriction table (every 2 min). No-op unless
                      VIFF_RESTRICTIONS_ENABLED=true
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
  DESIGN.md           rationale + CTOT scope by OpLevel (§6b)
  GLOSSARY.md         cross-system term reference
  API.md              endpoint reference + integration guide
  CDM-PLUGIN.md       wire contract, verified against plugin source
  VIFF-INTEGRATION.md vIFF's model vs ours, and the live test that settled it
  DESIGN-NONEVENT-CTOT.md / USAGE-NONEVENT-CTOT.md   non-event portal
  AMAN-DMAN.md        aman-dman plugin operational guide for CZQM/CZQX
  ECFMP.md            European flow-measure publisher notes
  RATES-VALIDATION.md rate derivation evidence
  FMP-TRAINING-PRIMER.md
  VATSIM-OAUTH-SETUP.md
  nat-fl-allocation.md      NAT FL stack + capacity model
  wind-skill-2026-spring.md GFS forecast skill vs lead time
  w27-uncertainty-model.md  Westbound 2027 per-flight MC design
  sessions/           narrative that won't survive in commits
```

## Deferred / known TODO

- ~~CYWG runway threshold data~~ ✅ shipped v0.4.0 (operator-supplied)
- ~~`bin/rot-tracker.php` + `bin/compute-aar.php`~~ shipped v0.4.0,
  **retired v0.4.7** — see "Retired ideas" below
- ~~Jeremy Peterson coordination for PERTI SWIM partner key~~ **moot** —
  PERTI is dead (see "Retired ideas" below).
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
- **Read vIFF constraints, keep our allocation** — SHIPPED v0.7.49, but
  **disabled by default**. `bin/ingest-viff-restrictions.php` mirrors vIFF's
  public `/etfms/restrictions?type=ARR` feed into our restriction table so a
  human authors the constraint once in vIFF and our allocator issues CTOTs
  against it with our ELDT. Set `VIFF_RESTRICTIONS_ENABLED=true` to turn on.
  Note a live CYHZ ARR/20 TEST row was in that feed on 2026-09-02, so
  enabling it will start regulating CYHZ immediately. Presence = active,
  absence = lifted; only touches rows it authored (`source='viff'`), never an
  FMP's own. See `docs/VIFF-INTEGRATION.md`.
- **Publish our slots to VATSIM Spain's VDGS (`vats.im/vdgs`)** — needs
  coordination with Roger Puig (rpuig2001). The CDM plugin's default pilot
  PM already points every pilot there, and their panel is per-pilot
  OAuth-gated, so it is the natural place for a pilot to read a slot. We
  would be pushing INTO their system rather than serving a pull, which is a
  new outbound integration and needs their auth + a field mapping. Until
  then, controllers must repoint `<PrivateMessage text>` at our portal or
  pilots are sent somewhere that cannot see our CTOTs.
- **Portal hardening for self-serve delivery** — the unstaffed-airport
  channel is `public/portal.html`, not a future VDGS clone: it already
  shows TOBT/TSAT/TTOT/CTOT and accepts a manual TOBT. What it lacks is
  per-pilot authorisation (`AUTH_STRICT=true` flips `Auth\Gate` from
  permissive to own-CID-or-controlling-ATC). Turn that on before any
  session where slot integrity matters.
- **Per-flight uncertainty model for Westbound 2027** — Monte Carlo per
  flight, not curve-level smoothing. Design in `docs/w27-uncertainty-model.md`;
  σ_grid inputs now real (`docs/wind-skill-2026-spring.md`).
- ~~Wind-corrected ELDT~~ ✅ shipped v0.5.66
  (pure PHP multi-level GRIB: 250mb/300mb/500mb, authoritative in ETA
  cascade as WIND_GRIB conf 92. Level selected by cruise altitude.)
- ~~TLDT accuracy validation~~ ✅ shipped v0.5.66
  (reports panel: ATOT + TLDT + ALDT flights, median error, MAE,
  % within ±3m/±5m, breakdown by source tier and airport.
  API: GET /api/v1/reports/tldt-accuracy)

## Retired ideas (don't re-propose without checking)

- **PERTI as an upstream / comparator.** Parked by its owner (Jeremy
  Peterson) in 2026-09 over hosting cost; `perti.vatcscc.org` answers
  `503 {"error":"Service suspended","mode":"freeze"}`. Removed the live
  fetch, the SWIM key, the dashboard match-rate pill, and the PERTI
  columns on the QA page (v0.7.37). `eldt_perti` stays as a historical
  column — nothing writes it any more. **Schema compatibility is a
  separate thing and still stands**: the PERTI-shaped field names on
  `/api/v1/flights` (`ctd_utc`, `cta_utc`, `deptime`) are the CDM plugin
  wire contract, not a dependency on PERTI being alive. `public/perti.html`
  keeps its path but is now our own ELDT QA page (ours vs GRIB vs ALDT,
  with SimBrief as the one remaining external comparator, advisory only).

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
| **0.6.x** | CTOT issuance live | Shipped. Restriction creation UI on dashboard drawer with shadow-allocator preview + commit. FMP creates regulations in-browser, allocator issues real CTOTs. MIT planner on AAR page (v0.6.4+). Demand distribution on dashboard + reports (v0.6.13-v0.6.17). Metering fix catalog authoritative via Navigraph (36 MFs across 7 airports). |
| **0.7.x** | Operational hardening | **Current.** Non-event CTOT portal (v0.7.0), CDM plugin v2.28 contract compliance, 26E event tooling. Remaining: live CDM plugin validation, wake-mix phase 2, multi-FMP confidence. |
| **1.0.0** | Production-ready | Running reliably during a real VATCAN event — CTOTs flowing, CDM plugin consuming, no manual intervention. |

- **Patch** (0.6.1, 0.6.2…): one per push, always incremented, never skipped.
- **Minor** (0.6 → 0.7): meaningful capability milestone, agreed before bumping.
- **Major** (0.x → 1.0): "we trust it in production." Not before a successful
  live event with real controllers consuming slots.

## Conventions

- Use "Claude" as the author name when adding tracked changes / comments
- All times in UTC; JSON formatted with `format('c')` (ISO 8601 with offset)
- **Prediction error sign is `actual − predicted`, everywhere.** Positive means
  the aircraft landed AFTER our estimate, i.e. we predicted early. Applies to
  `/api/v1/accuracy`, `/reports/summary`, `/reports/tldt-accuracy` and every
  page that renders them. Unified in v0.7.45 — before that `tldt-accuracy`
  was inverted, so the same measurement read `+9.4` on one panel and `−8.4`
  on another.
- **ELDT err and TLDT err are the same number unless a regulation is in
  force.** TLDT is the frozen ELDT, so the two columns only diverge when the
  allocator moved a flight off its frozen time to fit a slot. Don't read them
  as independent evidence.
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

## Session logging

`docs/sessions/` holds durable narrative for substantive debugging
sessions — root-cause stories that won't fit in commit messages,
validation datapoints measured live, operational findings that
contradict configured values, negative results, decisions with
abandoned alternatives.

**Discipline:** most sessions need no log entry. Mid-session, ask once:
*"Anything we've learned that won't survive in code, commits, or memory?"*
If yes, three lines into a `YYYY-MM-DD_topic.md` file in `docs/sessions/`
and move on. If no, nothing. Aim for ≤5 entries per session — more than
that means the value belongs in commits or `docs/`. Don't transcribe;
log derived value, not narration.

**Failure-mitigation reminders:**
- Session-bound cron (CronCreate) does **not** persist past the chat;
  use server-side scheduled tasks (`bin/deploy.sh` pattern) for anything
  unattended.
- Before any session that will use `screenshot` on a 4K monitor, drop
  the captured display to 1920×1080 — the 2000px many-image API limit
  kills sessions that exceed it.
- Commit untracked working-tree work before risky sessions; untracked
  files are the most fragile state.

See [docs/sessions/README.md](docs/sessions/README.md) for full convention.

## When in doubt

- Read `docs/ARCHITECTURE.md` first
- Then `docs/GLOSSARY.md` for terminology
- Then `git log --oneline` to see recent direction
- Then `docs/sessions/` for session-specific narrative not in commits
