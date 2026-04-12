# atfm-tools — Glossary

**Purpose**: a single authoritative reference mapping terms across the multiple
flow-management systems atfm-tools touches. If you're reading the code or the
architecture document and a term feels ambiguous, the answer is here.

Terms are grouped by subject area. Synonyms and cross-system equivalences are
called out explicitly. Each entry gives **the system(s) that use it** so you
can tell at a glance whether "CTOT" means what ECFMP means by it, what PERTI
means by it, or what we mean internally (they are, in this case, all the same
thing — but many terms are not).

For the overall system design, see [ARCHITECTURE.md](ARCHITECTURE.md).

---

## 1. A-CDM time milestones

These are the canonical A-CDM milestones as defined in the **EUROCONTROL
*Airport CDM Implementation Manual*, version 5.0, 31 March 2017** (the
ICAO Doc 9971 reference text in everyday use). Definitions and explanations
quoted below are from that document's Abbreviations & Acronyms section
(pp. XI–XVIII). atfm-tools uses these as **internal vocabulary** — the
`flights` table has columns named after them. External adapters translate
at the HTTP edge.

The naming convention is consistent across the manual:

| Prefix | Meaning | Examples |
|---|---|---|
| `S*` | **Scheduled** — published timetable, the planning baseline | SOBT, SIBT |
| `E*` | **Estimated** — expected at filing time, refined as data improves | EOBT, ELDT, EIBT, ETOT, EXOT, EXIT |
| `T*` | **Target** — what the system intends, after coordination with constraints | TOBT, TSAT, TTOT, TLDT |
| `C*` | **Calculated** — issued by central flow management as a regulation | CTOT, CTA |
| `A*` | **Actual** — observed/measured | AOBT, ASAT, ATOT, ALDT, AIBT, AXOT, AXIT |

### EOBT — Estimated Off-Block Time
Earliest filed estimate of when an aircraft will push back from its stand.
Set by the aircraft operator in the flight plan. First of the chain.

- **Set by**: flight plan filer (pilot on VATSIM)
- **Source**: `data.vatsim.net` `pilots[].flight_plan.deptime` (HHMM format)
- **In atfm-tools**: `flights.eobt`
- **In PERTI**: `flights.fp_deptime`
- **See also**: TOBT (the monitored, confirmed version)

### TOBT — Target Off-Block Time
A-CDM's *monitored and confirmed* off-block time. At a CDM airport, the ground
handler actively confirms when the aircraft will actually be ready for
push-back. For non-CDM airports, TOBT is defined (per CDM plugin v2.27 release
notes) to equal EOBT.

- **Set by**: ground handler (CDM airport) or derived from EOBT (non-CDM)
- **In atfm-tools**: `flights.tobt` (= `eobt` for all non-CDM airports in our
  scope)
- **Precondition for**: the A-CDM predeparture sequencer
- **ICAO 9971 definition**: "this point marks the completion of all ground
  handling processes: the aircraft doors are closed, the passenger boarding
  bridges have been removed from the aircraft, which is ready to receive
  start-up approval and push-back/taxi clearance"

### TSAT — Target Start-Up Approval Time
EUROCONTROL: *"The time provided by ATC taking into account TOBT, CTOT and/or
the traffic situation that an aircraft can expect start up / push back
approval. Note: The actual start up approval (ASAT) can be given in advance
of TSAT."* DMAN output for CDM airports. For non-CDM airports we default
`tsat = tobt`.

- **Computed by**: predeparture sequencer / DMAN (real A-CDM) or = TOBT (us)
- **In atfm-tools**: `flights.tsat`
- **Feeds**: TTOT calculation

### ETOT — Estimated Take-Off Time
EUROCONTROL: *"The estimated take off time taking into account the
EOBT plus EXOT."* I.e. **`ETOT = EOBT + EXOT`**.

The "estimated" version of the take-off time, anchored to the *filed*
off-block (EOBT), used by the Network Manager / ETFMS for slot planning
before any TOBT/TSAT refinement happens.

- **Computed by**: ETFMS / Network Manager
- **In atfm-tools**: not stored as a separate column — for our non-CDM
  airports where `tobt = eobt`, ETOT and TTOT are mathematically identical
  and we expose the result as `flights.ttot`.

### TTOT — Target Take-Off Time
EUROCONTROL: *"The Target Take Off Time taking into account the TOBT/TSAT
plus the EXOT."* I.e. **`TTOT = TOBT + EXOT`** (≡ `TSAT + EXOT` once DMAN
has set TSAT).

The "target" version of the take-off time, anchored to the *target*
off-block. TTOT is what DMAN refines as TOBT/TSAT updates flow in.
**TTOT is not a regulation** — it's the airport's local plan. The
regulated equivalent is CTOT.

Each TTOT on a runway is separated from other TTOT/TLDT events to
represent vortex / SID separation between aircraft.

- **Computed by**: `tobt + planned_exot_min` (per-flight) — see
  `src/Ingestion/VatsimIngestor.php`
- **In atfm-tools**: `flights.ttot`
- **See also**: CTOT (the regulated, central-flow-management version)

### CTOT — Calculated Take-Off Time
The **regulated** take-off time, issued by a flow management system when
demand exceeds capacity at a constrained resource. The pilot must depart within
the compliance window (see below) around this time.

- **Issued by**: atfm-tools CTOT allocator (us), viff-system.network (Roger's),
  Eurocontrol CFMU (real world), ETFMS (FAA real world), or PERTI (vATCSCC)
- **In atfm-tools**: `flights.ctot`
- **In PERTI**: `flights.ctd_utc` (Calculated Time of Departure)
- **FAA equivalent**: **EDCT** (Expected Departure Clearance Time)
- **CDM plugin wire field**: `ctot` (string, HHMM format)
- **Compliance window**: typically ±5 min symmetric in our config
- **See also**: EDCT (same thing, FAA terminology)

### ASRT — Actual Start-Up Request Time
EUROCONTROL: *"Time the pilot requests start up clearance."* Out-of-scope
for atfm-tools — VATSIM has no signal for this and our non-CDM airports
don't carry the field.

### ASAT — Actual Start-Up Approval Time
EUROCONTROL: *"Time that an aircraft receives its start up approval. Note:
the moment the start up approval is given can be in advance of the TSAT."*

**This is a controller event, NOT a position event.** ASAT is the moment
ATC said "cleared for start-up", which happens before the aircraft moves.
We have **no signal for ASAT from the VATSIM data feed** — pilots rarely
voice it; controllers don't broadcast it. The column exists only for
future CDM/PERTI feeds; the ingestor never stamps it.

- **Set by**: TWR controller (real A-CDM) — never stamped by atfm-tools ingest
- **In atfm-tools**: `flights.asat` (always null unless populated by an
  upstream CDM/PERTI feed)
- **NOT** equal to AOBT — see entry below

### AOBT — Actual Off-Block Time
EUROCONTROL: *"Time the aircraft pushes back / vacates the parking position.
(Equivalent to Airline / Handlers ATD – Actual Time of Departure & ACARS=OUT)"*

This **is** observable from position data: it's the first cycle in which
the aircraft is no longer at its parking position. We approximate this
as the first ingest cycle in which `phase = TAXI_OUT` *and* the previous
phase was pre-departure (PREFILE/FILED/TAXI_OUT). The previous-phase
guard is critical — without it, a flight first sighted mid-cruise would
get AOBT stamped to "now", producing garbage AXOT downstream.

- **Observed from**: phase transition into TAXI_OUT
- **In atfm-tools**: `flights.aobt`
- **Feeds**: AXOT (= ATOT − AOBT)

### ATOT — Actual Take-Off Time
EUROCONTROL: *"The time that an aircraft takes off from the runway.
(Equivalent to ATC ATD – Actual Time of Departure, ACARS = OFF)"*

- **Observed from**: phase transition into DEPARTED
- **In atfm-tools**: `flights.atot`
- **Used for**: computing AXOT (`actual_exot_min = atot - aobt`)

### ELDT — Estimated Landing Time
The planned landing time for an arriving flight. Computed by us from current
position + great-circle to destination + filed TAS.

- **Computed by**: atfm-tools' ETA estimator
- **In atfm-tools**: `flights.eldt`
- **Refreshed**: every 5 min during allocator ingest

### CTA — Calculated Time of Arrival
The regulated arrival time, issued when we allocate a slot at a
capacity-constrained airport.

- **Issued by**: atfm-tools allocator
- **In atfm-tools**: `flights.cta`
- **In PERTI**: `flights.cta_utc`
- **Relationship**: CTOT for departure + enroute time ≈ CTA for arrival (modulo
  wind and descent buffer)

### ALDT — Actual Landing Time
Observed: when the aircraft touched down. Detected when the flight crosses the
arrival runway threshold while descending through ~50 ft AGL.

- **Observed from**: ROT state machine
- **In atfm-tools**: `flights.aldt`
- **Used for**: computing delta vs ELDT (ETA accuracy analysis) and
  `actual_exit_min` (once AIBT is observed)

### EIBT — Estimated In-Block Time
When the aircraft is expected to reach its parking position. Computed as
`ALDT + EXIT` (or, pre-landing, `ELDT + EXIT`).

- **Computed by**: atfm-tools once ALDT or ELDT is set
- **In atfm-tools**: `flights.eibt` (may or may not be stored — derivable)

### AIBT — Actual In-Block Time
EUROCONTROL: *"The time that an aircraft arrives in-blocks. (Equivalent to
Airline/Handler ATA – Actual Time of Arrival, ACARS = IN)"*

Observed: when the aircraft reached its parking position and stopped.
Triggered by the second consecutive ARRIVED phase observation (the
one-cycle delay is deliberate so AIBT − ALDT can yield a non-zero AXIT
on a 5-min ingest cadence).

- **Observed from**: phase transitions
- **In atfm-tools**: `flights.aibt`
- **Used for**: computing AXIT (`actual_exit_min = aibt - aldt`)

### ERZT — Estimated Ready Zero-fuel Time
De-icing coordination milestone. Out of scope for atfm-tools v1; flagged here
only so the term is recognised.

### TMAT — Target Movement Area Entry Time
Used in the FAA's surface CDM (as opposed to ICAO A-CDM). The time the aircraft
is expected to enter the movement area from its non-movement-area holding
point. Not used by atfm-tools.

---

## 2. Taxi times

The EUROCONTROL manual is **explicit** about the E*/A* split: there is one
acronym for the *estimate* and a separate acronym for the *measured actual*.
Get this wrong and you'll mislabel reports.

| Acronym | Meaning | Formula |
|---|---|---|
| **EXOT** | **Estimated** Taxi-Out Time | planned/default; an input |
| **AXOT** | **Actual** Taxi-Out Time | metric: `ATOT − AOBT` |
| **EXIT** | **Estimated** Taxi-In Time | planned/default; an input |
| **AXIT** | **Actual** Taxi-In Time | metric: `AIBT − ALDT` |

### EXOT — Estimated Taxi-Out Time
EUROCONTROL: *"The estimated taxi time between off-block and take off. This
estimate includes any delay buffer time at the holding point or remote
de-icing prior to take off."*

I.e. the input to TTOT calculation: `TTOT = TOBT + EXOT`. EXOT is a
**planning value**, not a measurement. Source can be a fixed airport-wide
default, an aircraft-type-specific table, a stand-specific value, or a
contextual model.

- **Synonyms**: XOT
- **In atfm-tools**: `airports.default_exot_min` (airport-wide fallback),
  `flights.planned_exot_min` (per-flight, copied at flight creation)
- **In CDM plugin**: `/CDM/DefaultTaxiTime/@minutes` XML config key, plus
  per-position `taxiTimesList` overrides

### AXOT — Actual Taxi-Out Time
EUROCONTROL: *"Metric ATOT − AOBT."*

The observed/measured taxi-out time. Used post-departure for KPI reporting
and to refine future EXOT defaults.

- **Computed by**: `src/Ingestion/VatsimIngestor.php` once both AOBT and
  ATOT are stamped on different ingest cycles, capped at 1–60 min
- **In atfm-tools**: stored in the legacy column `flights.actual_exot_min`
  (the column name predates the convention rationalisation; the value
  *is* AXOT). API field: `actual_exot_min`. UI label: **AXOT**.

### EXIT — Estimated Taxi-In Time
EUROCONTROL: *"The estimated taxi time between landing and in-block."*

A planning value used to predict EIBT from ELDT. Input to arrival
sequencing.

- **Synonyms**: XIT
- **In atfm-tools**: `airports.default_exit_min`, `flights.planned_exit_min`

### AXIT — Actual Taxi-In Time
EUROCONTROL: *"Metric AIBT − ALDT."*

The observed/measured taxi-in time.

- **Computed by**: `src/Ingestion/VatsimIngestor.php` once both ALDT and
  AIBT are stamped on different ingest cycles, capped at 1–60 min.
- **In atfm-tools**: stored in the column `flights.actual_exit_min`.

**Note on ROT**: the strict EUROCONTROL AXIT (`AIBT − ALDT`) includes
runway-occupancy time. atfm-tools v0.4.0 attempted to break that out
into a separate `rot_observations` table and surface ROT and post-ROT
AXIT as independent metrics, but the work was retired in v0.4.7
because measuring ROT to useful precision needs sub-minute ingest
cadence and the value didn't justify the complexity for our scope.
The bare EUROCONTROL AXIT is the only taxi-in metric we surface now.

### MTTT — Minimum Turnaround Time
Shortest plausible time between AIBT (arrival) and the next EOBT (departure)
for the same aircraft. Relevant for turn-around planning. Not used by
atfm-tools v1.

---

## 3. Flow management terms

### AAR — Airport Arrival Rate
Movements per hour an airport can accept. In ICAO 9971 Part II App II-B the
formula is:

```
AAR = ground_speed_at_threshold_kts / spacing_at_threshold_NM
```

(Round down to next whole number.) E.g. 130 kts / 3.25 NM = 40 arrivals/hour.

- **In atfm-tools**: `airports.base_arrival_rate` (fallback) and
  `airports.observed_arrival_rate` (computed from actual threshold crossings
  when sample size is sufficient)
- **Related**: `aar_calculations` table holds rolling derivations per airport
  per runway

### ADR — Airport Departure Rate
Same concept for departures. atfm-tools has `base_departure_rate` but does not
actively regulate departures in v1 — CDM plugin handles departure metering at
CDM airports via vIFF.

### GDP — Ground Delay Program
A deterministic flow regulation where aircraft are held on the ground at their
origin airports and assigned EDCTs to fit into a downstream capacity window.
Classical FAA TFMS regulation type. Uses Ration-By-Schedule (RBS).

- **In PERTI**: fully implemented in `api/gdt/programs/*` (activate, compress,
  reoptimize, ECR, revise, extend, etc.)
- **In atfm-tools**: **NOT implemented**. We run a rate-based tactical
  controller instead. See ARCHITECTURE.md §2 and §8.3 for why.

### GS — Ground Stop
A blanket halt of departures to a specific airport or from a specific airport.
More aggressive than a GDP.

- **In PERTI**: `api/tmi/gs/*`
- **In atfm-tools**: equivalent to an `airport_restrictions` row with
  `capacity = 0`

### AFP — Airspace Flow Program
FAA flow regulation for en-route capacity constraints (vs GDP for airport
constraints). Out of scope for atfm-tools (we don't do en-route).

### OpLevel — TMU Operations Level
PERTI's (and FAA TMU's) classification of the overall traffic-management
intensity across the system. Four levels:

| Level | Label | Meaning |
|---|---|---|
| **1** | **Steady State** | No active flow management; routine operations |
| **2** | **Localized Impact** | Single airport or small region affected |
| **3** | **Regional Impact** | Multiple airports or an FIR affected |
| **4** | **NAS-Wide Impact** | Large-scale disruption across the network |

- **In PERTI**: `tmi_advisories.op_level` (from the TMU OpLevel dropdown we
  saw at perti.vatcscc.org during exploration)
- **In atfm-tools**:
  - `airport_restrictions.op_level` — per-restriction tag (default 2)
  - System-level `op_level` derived at runtime and returned by
    `/api/v1/status`. Derivation rule:
    - 0 active restrictions → Level 1
    - 1-2 affected airports → Level 2
    - 3-4 affected airports → Level 3
    - 5+ affected airports  → Level 4
    - Max of the derived level and the highest per-restriction `op_level` wins.
- **Displayed on the dashboard** as a prominent colored pill in the header.

### TMI — Traffic Management Initiative
Umbrella FAA term for any active flow regulation (GDP, GS, AFP, reroute,
MIT, MINIT, STOP, etc.). vATCSCC's PERTI uses this as its top-level
regulation vocabulary.

- **In PERTI**: `tmi_log_core`, `tmi_advisories`, `tmi_flight_control`, etc.
- **In ECFMP**: "flow measure" (same concept, different name)
- **In atfm-tools**: "restriction" (same concept, our internal name)

### Flow Measure
ECFMP's term for an active traffic regulation. A flow measure is a set of
filters (ADEP, ADES, waypoint, level, event membership), a measure type
(mandatory route, per-hour rate, MDI, ground stop, prohibit, speed
restriction), and a time window.

- **In ECFMP**: `flow_measures` table; served at `/api/v1/plugin` by
  `PluginApiController`
- **In atfm-tools**: closest equivalent is `airport_restrictions`, which is
  strictly scoped to airports (no waypoint filters, no level filters, no en-
  route stuff)

### Restriction
atfm-tools' **internal term** for a traffic regulation. One row in the
`airport_restrictions` table = one active regulation. Fields match vIFF's
restriction model.

- **Scope**: always per-airport
- **Time window**: HHMM time-of-day, max 5h
- **Type**: ARR, DEP, or BOTH
- **See also**: flow measure (ECFMP), TMI (PERTI/FAA), TV (vIFF)

### TV — Traffic Volume
Eurocontrol / vIFF term for an airspace block or airport with a capacity rate
that can be regulated. At vIFF specifically, TVs are the object types you
create and edit in the Data Management UI.

- **In vIFF**: a primary data type alongside Airports and Scenarios
- **In atfm-tools**: closest equivalent is the combination of `airports` +
  `airport_restrictions`
- **Not**: the same as a FAA-style "sector count"

### MIT — Miles-in-Trail
A spacing restriction requiring aircraft to be separated by N nautical miles
at a specified transfer point. Out of scope for atfm-tools.

### MINIT — Minutes-in-Trail
Temporal version of MIT. Also out of scope.

### MDI — Minimum Departure Interval
A flow measure type imposing a minimum time (e.g., 120 s) between consecutive
departures from an airport matching the filter. ECFMP supports this natively.
CDM plugin only retains measures of type `minimum_departure_interval` or
`per_hour` from ECFMP's plugin endpoint.

### CASA — Computer-Assisted Slot Allocation
The core Eurocontrol CFMU / FAA TFMS algorithm for assigning CTOTs to pilots
in a first-filed-first-served order. atfm-tools' allocator is a simplified
("CASA-light") version.

### RBS — Ration-By-Schedule
The classical FAA GDP slot-assignment algorithm. Given a published demand set
and a reduced capacity, allocates slots by filed ETA. Not what atfm-tools does.

### Slot
A fixed-duration window of runway capacity. Slot duration = 3600 / capacity.
E.g. at a 24/hr airport, slots are 150 seconds wide. A flight "occupying a
slot" means it's expected to touch down (or depart) during that window.

### Compliance window
The time range around a CTOT within which a pilot is considered "compliant"
if they take off. In atfm-tools we default to **symmetric ±5 minutes**. A flight
assigned CTOT 1432Z is compliant if it departs between 1427Z and 1437Z.
Missing the window triggers reissuance or withdrawal.

### Tier (lookahead)
The maximum time horizon over which atfm-tools will issue CTOTs to ground
flights. Flights whose unconstrained ETA is > `tier_minutes` ahead of *now*
are ignored by the allocator and re-evaluated later when they come inside the
tier. Default: **120 minutes** (2 hours).

### Frozen CTOT
Once issued, a CTOT does not move unless the flight becomes non-compliant or
withdraws. The slot is locked for the flight. Allocator runs that happen
during the frozen period leave the CTOT alone.

---

## 4. Airport surface terms

### ADEP / ADES
Filed **ad**eparture point / filed **ad**estination. ICAO-code form, e.g.
`CYHZ`. From the flight plan. Used as the primary filter for allocator
matching and data ingest scope.

### Threshold
The end of a runway where aircraft land (landing threshold) or begin their
takeoff roll (departure end). A single physical runway has **two thresholds**,
one for each direction of use.

- **In atfm-tools**: `runway_thresholds` table has one row per direction per
  runway, with `threshold_lat`/`threshold_lon` as the landing end and
  `opposite_threshold_lat`/`opposite_threshold_lon` as the other end.

### Displaced threshold
A runway threshold positioned some distance back from the paved runway end,
typically for obstacle clearance reasons. Aircraft can use the displaced
section for takeoff roll but not for landing.

- **In atfm-tools**: `runway_thresholds.displaced_threshold_ft`, default 0

### TORA / TODA / ASDA / LDA
Takeoff Run Available, Takeoff Distance Available, Accelerate-Stop Distance
Available, Landing Distance Available. The four "declared distances" of a
runway. atfm-tools doesn't track these explicitly but they're why coordinate
data sometimes disagrees with published runway lengths — one of the four
distances may be what the data actually represents.

### Final approach
The last segment of flight before landing, typically straight-in toward the
runway threshold, below 3000 ft AGL. atfm-tools defines **FINAL phase** as:
- Within 10 NM of a runway threshold
- Heading aligned ±15° with the runway heading
- Altitude < 3000 ft AGL

- **In collector**: `FlightState.FINAL` in tracker.py

### Runway in use
Which runway(s) are currently being used for arrivals and/or departures. At
real airports published via ATIS; on VATSIM, either parsed from vATIS text or
**detected by atfm-tools from flight tracks** (PERTI-style).

### Geofence
In atfm-tools, a simple circular boundary around an airport reference point
used for state-machine transitions. `airports.arrived_geofence_nm` defaults
to 5 NM. Inside the circle + groundspeed 0 + stable for 10 min = ARRIVED.

### Runway polygon
The rectangular area bounded by the two thresholds of a runway plus the
width. Used for `ON_RUNWAY` state detection via point-in-polygon test.

---

## 5. VATSIM-specific terms

### VATSIM
Virtual Air Traffic Simulation. The online network for flight simulation where
virtual pilots fly and virtual controllers control. `vatsim.net`.

### CID — Certificate ID
A unique numeric identifier for every VATSIM user. Pilots and controllers.
Stable for the user's lifetime on the network. Used by atfm-tools as part of
the `flight_key` composite identifier.

- **Example**: `810489`
- **In data.vatsim.net**: `pilots[].cid` and `controllers[].cid`

### Callsign
The identifier a pilot uses on a specific flight, e.g. `ACA123`, `JZA456`.
Not unique across sessions (another pilot can use the same callsign on a
different flight). Used with CID as part of `flight_key`.

### data.vatsim.net
The live network data feed. JSON snapshot of all connected pilots and
controllers, updated every ~15 seconds. Free, public, no auth required.

- **URL**: `https://data.vatsim.net/v3/vatsim-data.json`
- **Size**: ~2-3 MB
- **Contents**: pilots array, controllers array, general metadata (update
  timestamp, version info)
- **atfm-tools usage**: ingested every 5 min by `bin/ingest-vatsim.php`

### stats.vatsim.net
Per-CID **aggregate statistics** service (hours flown, hours controlled,
ratings, tours, awards). Not a track history archive. No per-flight position
data available.

- **atfm-tools usage**: none, for now. Considered once, rejected as a data
  source because it doesn't carry what we need.

### status.vatsim.net
The legacy v2 service-discovery endpoint that old plugins (like VATCAN's
Slots-Plugin) used to find the current data feed URL. Deprecated in favor of
the direct v3 URL. Mentioned here because older plugin code still references
it — we don't.

### Pilot rating (P0-P6)
VATSIM-issued pilot training ratings. P0 = no specific training, P1-P6 =
progressively more formal pilot training. Not retained by atfm-tools (we
don't need it for CTOT computation).

### Flight plan
On VATSIM, a pilot's filed IFR or VFR flight plan. Fields include departure,
arrival, alternate, aircraft type, cruise TAS, altitude, route, deptime
(EOBT), enroute time, fuel time, remarks. Subset of a real ICAO flight plan
form.

- **atfm-tools uses**: departure, arrival, deptime, aircraft_short, cruise_tas,
  enroute_time, flight_rules (I/V)

### vATIS
A third-party tool used by VATSIM controllers to publish ATIS broadcasts.
Integrates with some systems (e.g., PERTI has `integrations/vatis/src/
RunwayCorrelator.php`) to provide structured runway-in-use data.

- **atfm-tools usage**: none directly. We infer runway config from tracks, not
  from ATIS.

### VATCAN
VATSIM Canada. The regional sub-org for Canadian airspace. Operates
`bookings.vatcan.ca` (event slot booking system) and other community services.

### VATUSA
VATSIM USA. The US equivalent. Operates vATCSCC which runs PERTI.

### vATCSCC
Virtual Air Traffic Control System Command Center — the VATUSA flow management
sub-org that runs PERTI.

### CANOC
Canadian NOC (Network Operations Cell). The CANOC-prefixed advisory integration
within PERTI, covering all seven Canadian FIRs (CZEG, CZQM, CZQX, CZVR, CZWG,
CZYZ, CZUL). This is where Joel Morin's admin scope lives on
`perti.vatcscc.org`.

### FIR — Flight Information Region
A geographic block of airspace managed by a single ATC authority. In Canada,
CZQM (Moncton, includes CYHZ), CZYZ (Toronto, includes CYYZ/CYOW), CZUL
(Montreal, includes CYUL), CZWG (Winnipeg, includes CYWG), CZEG (Edmonton,
includes CYYC), CZVR (Vancouver, includes CYVR), CZQX (Gander, oceanic).

---

## 6. Systems and services

### atfm-tools
This project. Lightweight Canadian-scoped CTOT allocator and data collector
running on WHC shared hosting at `atfm.momentaryshutter.com`.

### PERTI
**vATCSCC's traffic management platform**, built by Jeremy Peterson and team.
Runs at `perti.vatcscc.org`. Implements full ATFM + A-CDM + CDM + GDP + TMI
functionality for VATSIM-USA (and Canadian) airspace. 74 API endpoints,
FIXM-aligned SWIM v1 public API, VATSIM Connect OAuth for user auth, bearer
token API keys for programmatic access.

- **Repo**: `vATCSCC/PERTI` on GitHub
- **Primary language**: T-SQL (SQL Server backend), with PHP HTTP layer
- **atfm-tools relationship**: schema-compatible (we mirror column names from
  PERTI's `adl_flights_history`), independent consumer (we don't hit PERTI's
  live API for data — we go direct to VATSIM), eventual partner (we want a
  SWIM partner API key once coordinated with Jeremy)

### SWIM — System Wide Information Management
FAA's data-sharing framework. PERTI's public API is labeled "SWIM v1" and is
FIXM-aligned, serving flight data in a standards-friendly shape. Our term
"VATSWIM" refers specifically to PERTI's SWIM v1 implementation.

- **Base URL**: `https://perti.vatcscc.org/api/swim/v1/`
- **Auth**: `Authorization: Bearer swim_[pub|dev|par|sys]_*`
- **Key tiers**: PUBLIC (100 req/min), DEVELOPER (300/min), PARTNER (3k/min,
  requires approval), SYSTEM (30k/min, requires approval)

### FIXM — Flight Information Exchange Model
International standard (maintained by ICAO + FAA + Eurocontrol) for
representing flight data in machine-readable form. JSON / XML schemas.

- **atfm-tools relationship**: we use FIXM-aligned field names
  (`departure_runway`, `arrival_gate`, etc.) in our schema to match PERTI's
  schema and eventual interop

### ECFMP
**European Common Flow Management Program**. The VATSIM Europe equivalent of
vATCSCC for flow management. Runs a Laravel app for authoring flow measures
and publishes them via a plugin API consumed by CDM-compatible plugins.

- **Repo**: `ECFMP/flow` on GitHub
- **URL**: (varies; the API endpoint their polling daemon hits)
- **Does NOT compute CTOTs** — they're strictly a flow-measure authoring and
  publishing system. CTOT computation is left to client tools like Roger's
  viff-system.
- **atfm-tools relationship**: we mirror ECFMP's plugin API response shape at
  `/api/v1/plugin` for compatibility, but we don't consume ECFMP data.

### CDM plugin
**Roger Puig's EuroScope plugin** (`rpuig2001/CDM`), written in C++. Lets
VATSIM controllers display and manage CTOTs, TOBTs, TSATs for flights at
airports they're working. Consumes CTOT data from `viff-system.network/etfms/
restricted` by default; as of v2.27 can be pointed at any URL via the
`customRestricted` CDMConfig.xml setting.

- **atfm-tools relationship**: we serve at `/cdm/etfms/restricted` in the
  exact JSON shape CDM expects, so users can point their `customRestricted`
  at `https://atfm.momentaryshutter.com/cdm/etfms/restricted` and see our
  CTOTs in their plugin.

### vIFF / viff-system.network
Roger Puig's **VATSIM flow management backend**, hosted as a web UI at
`cdm.vatsimspain.es/atm/index.php` and an API at `viff-system.network`. Runs
the CDM plugin's server side: TOBT/TSAT tracking, slot allocation, per-airport
capacity configuration. Proprietary / closed-source; Joel has admin scope for
CY/CZ airports on the UI.

- **Our relationship**: we read vIFF's airport configuration values (base
  arrival rates, taxi times) and mirror them into `airports.base_arrival_rate`
  as fallbacks. We do not call vIFF's API programmatically.

### bookings.vatcan.ca
**VATSIM Canada's event slot booking system**. Laravel app. Exposes an API at
`/api/event/{event_code}` that returns `[{cid, slot}]` JSON arrays of booked
pilots for a specific event. Event codes are opaque strings minted when an
event is created (e.g., `xXpFB`). Used by VATCAN's Slots-Plugin for CDM.

- **atfm-tools usage**: polled by `bin/ingest-events.php` every 5 min for
  every row in `event_sources` with `active = true`

### CDM plugin Slots-Plugin (VATCAN)
The older, separate `VATSIMCanada/Slots-Plugin` EuroScope plugin that displays
pilot event-slot bookings. Last updated 2022-03; code uses obsolete VATSIM v2
API. Reference implementation for how the `bookings.vatcan.ca/api/event/`
endpoint is parsed, even if the plugin itself may not work on current VATSIM.

### cyhz-rot-collector
Joel's existing SQLite-based Python tool (`skyelaird/cyhz-rot-collector`) for
collecting CYHZ runway occupancy time data from VATSIM. Uses adaptive polling
(10 min idle, 15 s bursts during critical phases), state machine per flight,
great-circle geometry. **Reference implementation for atfm-tools' ROT
tracker** — we're porting its state machine and adaptive polling logic into
PHP as `bin/rot-tracker.php`.

---

## 7. atfm-tools internal terms

### flight_key
Our composite flight identifier, matching PERTI's `adl_flight_core` format:

```
flight_key = cid + "|" + callsign + "|" + adep + "|" + ades + "|" + deptime
```

E.g. `810489|ACA456|CYUL|CYHZ|1830`. Five pipe-separated fields. Deterministic
from observed flight plan data; changes whenever any of the five fields
change (which naturally creates a new flight record on refiling).

- **NOT a UUID** — deliberately a composite string to allow stateless upsert
  from the VATSIM feed without needing to track minted IDs
- **Stability**: survives disconnect/reconnect if all five fields stay the
  same; breaks (= new flight) if any changes

### restriction
atfm-tools' primary regulation type. One row in `airport_restrictions` =
one active regulation. See §3 for naming rationale.

### tier / tier_minutes
The lookahead horizon for CTOT issuance. Flights whose ETA is more than
`tier_minutes` in the future are skipped by the allocator. Default 120.
Per-restriction override supported.

### compliance window
The symmetric time window around a CTOT in which departure is considered
compliant. Default ±5 min.

### frozen CTOT
A CTOT that was previously issued and has not been released. Allocator runs
leave it alone unless it becomes non-compliant. See §3.

### DISCONNECTED
Transient non-terminal flight state meaning the pilot has disappeared from
the VATSIM feed but hasn't formally arrived and hasn't timed out yet. Can
re-animate if the same `flight_key` reappears. See ARCHITECTURE.md §7.

### WITHDRAWN
Terminal admin state triggered when a flight has been DISCONNECTED for
more than 10 hours. See ARCHITECTURE.md §7.

### Allocation run
One execution of `bin/compute-ctots.php`. Recorded in `allocation_runs` with
summary metrics (flights evaluated, CTOTs frozen/issued/released/reissued,
elapsed time).

### Imported CTOT
A CTOT loaded from an external source (file upload, VATCAN event booking API)
rather than computed by our allocator. Held in `imported_ctots`. Takes priority
over allocator output when `priority` is lower (numerically).

### Observed arrival rate
Capacity value computed from real threshold-crossing observations via the
ICAO 9971 Part II App II-B formula. Used in preference to `base_arrival_rate`
when sample size crosses the threshold (default 100 observations).

### Base arrival rate
Fallback capacity value from operator configuration (typically copied from
vIFF). Used when observed data isn't yet available.

### position_scratch
Short-retention (48 h) raw-position history table that feeds the ROT state
machine. Not long-term archival storage.

---

## 8. Cross-system equivalences at a glance

| Concept | ICAO | FAA / PERTI | Eurocontrol / ECFMP | vIFF | atfm-tools |
|---|---|---|---|---|---|
| Regulated take-off time | CTOT | CTD / EDCT | CTOT | CTOT | **CTOT** (ctot column) |
| Regulated arrival time | CTA | CTA | — | — | **CTA** |
| Active regulation | — | TMI | Flow Measure | Restriction | **Restriction** |
| Capacity reduction type | — | GDP / GS / AFP | per-hour, MDI, MIT, GS, prohibit | Restriction | — (just `capacity` int) |
| Flight ID (stable) | GUFI | `flight_key` composite | — | — | **`flight_key`** (composite, matches PERTI) |
| Slot | Slot | Slot | Slot | Slot | **Slot** (derived, not stored) |
| Pop-up flight handling | — | reserves + compression | popup queue | — | **None** — one queue, strict EOBT-CASA |
| Capacity source | dynamic/measured | static + ECR | static | static | **observed AAR → fallback to base** |
| Runway-in-use source | ATIS | vATIS + track detection | ATIS | manual | **track detection only** |

---

## 9. Where each system writes to where in our schema

| External | In atfm-tools |
|---|---|
| `data.vatsim.net/v3/vatsim-data.json` pilots | `flights` (upsert by flight_key), `position_scratch` |
| `data.vatsim.net` controllers | *(not stored — fetchable post-facto from stats)* |
| `bookings.vatcan.ca/api/event/{code}` | `imported_ctots` (via `bin/ingest-events.php`) |
| `storage/imports/ctots/*.json` / `*.csv` | `imported_ctots` (via `bin/ingest-imports.php`) |
| PERTI SWIM (future) | `flights` direct upsert (schema already compatible) |
| Runway thresholds (NAV CANADA) | `runway_thresholds` (one-time seed) |
| FMP manual restrictions | `airport_restrictions` |
| FMP event codes | `event_sources` |

---

## 10. Pronouns / conventions

- **"We"** = the atfm-tools codebase behavior.
- **"They"** = the external system we're referring to at that moment.
- **"The allocator"** = `bin/compute-ctots.php`.
- **"The ingestor"** = `bin/ingest-vatsim.php` specifically, or collectively
  the set of ingest-* scripts.
- **"The tracker"** = `bin/rot-tracker.php`.
- **"The plugin"** (unqualified) = the CDM plugin (`rpuig2001/CDM`).
- **"A-CDM"** = the philosophy and protocol, as defined by ICAO 9971 Part III.
- **"CDM" (plugin)** = Roger's EuroScope plugin specifically.
- **"CDM" (airport)** = an airport that participates in the full A-CDM
  protocol with TOBTs/TSATs/DPIs. Separate from whether a CDM plugin is in
  use there.
- **"CDM" (framework)** = the Eurocontrol Collaborative Decision Making
  framework from which the other two derive their name.

When in doubt, the disambiguator is which of the three meanings is in play,
and the glossary entries for CDM plugin and CDM airport each note their
distinction.
