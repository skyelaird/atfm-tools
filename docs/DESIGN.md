# atfm-tools — Operational Design

**Audience**: Flow Management Position (FMP) controllers, CDM coordinators,
other operational SMEs who want to know *what this tool does and why* without
having to read PHP.

**Last updated**: 2026-04-15

For the engineering view (schema, code layout, cron, deployment) see
[ARCHITECTURE.md](ARCHITECTURE.md). For term definitions see
[GLOSSARY.md](GLOSSARY.md). This document is the plain-language design
spec — what the tool is trying to do for you, and the rules it follows.

---

## 1. What this tool does, in one paragraph

atfm-tools watches the VATSIM feed for the 7 monitored Canadian airports
(**CYHZ CYOW CYUL CYVR CYWG CYYC CYYZ**), predicts when each inbound is
going to touch down, and — when a constraint is active — hands out arrival
slots and departure times (CTOTs) that smooth the inbound stream against a
declared acceptance rate. It does not replace the FMP's judgement. It
gives you a forward-looking picture of demand vs capacity and the slot
math to back it up.

---

## 2. The three times that matter

Everything in the allocator is built on three ICAO A-CDM concepts. If you
understand these three, you understand the tool:

| Term | What it is | When we set it |
|------|------------|----------------|
| **ELDT** | *Estimated* Landing. Our live prediction, updates every 2 min. | From the ingestor, every cycle, for any flight we can reasonably predict. |
| **TLDT** | *Target* Landing. A committed slot against the runway. Immovable once set. | At the **freeze horizon** — 90 minutes before predicted touchdown. |
| **CTOT** | *Calculated* Take-Off. A departure time that makes the flight hit its TLDT. | Only for ground flights within the CTOT scope (see §5). |

The mental model: **ELDT is a guess, TLDT is a promise, CTOT is the
enforcement mechanism.**

**One-line summary of the loop**: *observe → predict ELDT → at T-90m,
freeze ELDT into TLDT → if the target airport is constrained and the
flight can still be held on the ground, issue a CTOT that backs TLDT out
through expected taxi and flight time.*

---

## 3. ELDT — the live prediction

ELDT is computed from position, filed route, aircraft type, and a
standard descent profile. It is *not* meteorological — no winds, no GRIB,
no atmosphere. It is geometric.

A 5-tier cascade picks the best available input:

1. **FILED** — flight is on the ground, use filed enroute time + taxi.
2. **OBSERVED_POS** — flight is airborne, use current position and
   groundspeed.
3. **CALC_FILED_TAS** — airborne but observed GS is unusable, fall back
   to filed cruise TAS.
4. **CALC_TYPE_TAS** — use typical TAS for the aircraft type.
5. **CALC_DEFAULT** — last-resort 430 kt.

All five tiers use the same **descent-aware** distance/speed model:
standard 3° glidepath, 250 kt below FL100, 220 kt inside 20 nm, typed IAS
above FL100.

**Eligibility rule**: ELDT is only computed once a flight is at cruise
(within 2000 ft of filed altitude **or** vertical rate < 500 fpm above
FL100) **or** already descending. Flights in FILED / CLIMBOUT / FLS-NRA
show no ELDT. Rationale: climbing flights are a moving target with too
much noise to predict usefully.

Validated accuracy against real VATSIM ALDTs (clean dataset, n=7):
mean error +0.4 min, absolute error 10.1 min, 71% within ±10 min.

---

## 4. TLDT — the committed slot

### 4.1 The freeze horizon: T-90m

At **90 minutes before predicted touchdown**, we stop revising ELDT and
snapshot it as TLDT. From that point forward, the allocator treats TLDT
as a physics commitment — the aeroplane *will* be on the runway at that
time, give or take a few minutes of en-route variance we can't affect.

**Why 90 min and not 2 h or 40 min?**
- It matches the CTOT scope (§5). A flight with ETE ≤ 1:30 is the only
  kind of flight that can still be held on the ground *and* is within
  striking distance of the arrival airport. One clock, not two.
- At T-90m the cruise picture is stable. Earlier freezes carry more
  variance; later freezes give the allocator less time to react.
- FMPs working tactically care about the next 2 hours. Anything frozen
  more than 90 min ago is already in the bag.

**What freezing does**: the flight's ELDT prediction is snapshotted into
TLDT, and the live ELDT from that flight is no longer considered for
allocation purposes. The reports page shows the frozen value vs the
eventual ALDT so you can see how the model performed.

### 4.2 Guardrails on the freeze

Freezing is a commitment, so we only freeze flights we believe in:

- **Must be at cruise** (stable altitude + stable vertical rate).
- **Must not be in FLS-NRA** (bogus flight — no flight plan, no position
  history).
- If a flight re-climbs after a freeze (controller turned them around,
  re-routed via a hold), the climb guard clears the lock so it can
  re-freeze on the new trajectory.

### 4.3 Flights that never get a CTOT

- **Airborne long-haul** (ETE > 2 h at detection). Already committed to
  physics. TLDT comes from ELDT, full stop. You can't hold them.
- **Already descending or on short final.** Same reasoning.
- **Outside CTOT scope** (see §5).

These flights still *consume capacity* — they show up as committed
demand in the 15-minute windows — but they are not subject to
rescheduling. The allocator treats them as immovable furniture and fits
CTOT-able flights around them.

---

## 5. CTOT — who gets one and when

CTOTs are only issued for **ground flights** with **ETE ≤ 1:30** to a
constrained destination.

Scope depends on the declared **Operational Level** of the constraint:

| OpLevel | Description | Who gets a CTOT |
|---------|-------------|-----------------|
| **L1** | Steady State | Nobody. The allocator passes through. |
| **L2** | Localized | Ground flights at **adjacent** airports to the constrained one. |
| **L3** | Regional | Ground flights with ETE < 2 h to the constrained airport. |
| **L4** | NAS-Wide | L3 scope, all airports. (Reserved — not used in normal ops.) |

OpLevel is derived from FIR adjacency. It is not a knob an FMP turns —
it rises automatically as more airports come under constraint.

**Why this scope**: at L2, a CYYZ constraint pulls in CYOW, CYUL, CYHZ
ground departures. Regional airborne traffic still counts toward demand
but you can't CTOT it. L3 broadens to anything with a ground-hold
opportunity within 2 hours' ETE.

**What the CTOT is**: for a flight with a TLDT of 15:00Z, expected taxi
of 10 min, and filed ETE of 1:05, the CTOT is `15:00 − 1:05 − 0:10 =
13:45Z`. The CDM plugin receives this on its next poll and the
controller enforces it via TSAT / taxi clearance.

---

## 6. Slot allocation — how demand meets capacity

### 6.1 The bucket model

For each constrained airport we maintain **15-minute demand buckets**
against the airport's declared arrival rate. Example at CYYZ (40 per
hour → 10 per 15 min):

```
14:00–14:15  │ ACA123 TLDT 14:02  │ committed
              │ WJA456 TLDT 14:07  │ committed
              │ ...                │
              │ 8 total            │ 2 slots free
14:15–14:30  │ ...                │
```

Flights enter their bucket based on TLDT. Buckets overflow when
`count > rate/4`, and the allocator slides flights forward in time until
no bucket is overflowing. Each slide produces a new CTOT for a CTOT-able
flight, or marks the bucket as over-committed if all the candidates are
airborne (nothing to hold).

### 6.2 Immovable first, then movable

Order of bucket filling:

1. **Airborne flights with TLDTs frozen from physics.** These are the
   bedrock. They go in first, they don't move.
2. **Ground flights within CTOT scope, sorted by EOBT.**  The allocator
   assigns slots in filed-departure order, respecting minimum separation
   and bucket capacity.

If there's more movable demand than available slots in the first few
buckets, later flights get pushed to later buckets — their CTOTs slip
accordingly.

### 6.3 No buffer reservation

Earlier designs reserved slots for "jiggling" short-haul flights that
weren't yet frozen. We removed this. Rationale:

- The allocator runs **every 2 minutes**. A short-haul that freezes now
  will be in the next run's bucket within 120 seconds.
- Reserving buffer is just as harmful as a bad TLDT — you're holding
  capacity that nobody has claimed.
- Cleaner mental model: *every TLDT is real, every bucket is measured,
  every 2 minutes the whole picture is recomputed from scratch.*

### 6.4 Restrictions have lifetimes

Stale CTOTs never persist. At the start of every allocator run, every
CTOT is cleared. If the restriction is still in effect, the run
re-computes fresh CTOTs. If the restriction expired, the flights are
free. This is deliberate — no orphaned slots, no "why is this flight
still holding?"

---

## 6.5. Capacity rates — AAR and ADR

The allocator needs a number to allocate *against*. That number is the
**declared acceptance rate** — how many arrivals/departures the facility
says it can handle per hour in the current runway configuration.

Our model follows **FAA FOA Ch.10 §7 / ASPM SAER**:

- **AAR** (Airport Arrival Rate) and **ADR** (Airport Departure Rate)
  are **facility-declared**, not computed. They are the facility's own
  published statement of capacity for *(runway configuration × weather
  class)*, reviewed at least annually.
- They are **dynamic** — the facility re-declares when weather,
  configuration, or traffic mix changes.
- AAR and ADR are **coupled** at mixed-use runways. A single runway
  running interleaved A/D trades arrival slots for departure slots.
  Two-runway configs (one dedicated arrival, one dedicated departure)
  let them run independently.

**What "facility-declared" actually means.** The declared rate is not
a number pulled from a weather table. It is the facility's
**compressed judgement**, baking in everything our calculator cannot
see:

- **Runway geometry** — length, displaced thresholds, high-speed exit
  locations, intersection points with other active runways.
- **Runway relationships** — parallel spacing (dependent under 2500 ft,
  independent otherwise), intersecting runways, converging approaches,
  LAHSO eligibility.
- **Procedural dependencies** — PRM/SOIA, wake turbulence separation
  matrix, RECAT, SID/STAR interactions, missed-approach protection.
- **Airspace constraints upstream of the airport** — TRACON sector
  loading, merge point throughput, holding stack capacity, en-route
  metering already in effect.
- **Taxiway and apron geometry** — gate availability, ramp saturation,
  taxi-in/taxi-out chokepoints that cap the usable arrival rate
  regardless of what the runway could accept.
- **Equipment status** — ILS outages, approach light failures, PRM
  monitor availability, surface movement radar.
- **Staffing / controller workload** — position loading, CIC
  availability, currently vacant sectors.

The facility has already done all of this math. Our wind-aware
calculator sees exactly one dimension — *how fast can the runway
physically swallow arrivals given wake separation, approach category,
and headwind*. That is a **necessary but not sufficient** check. The
declared value dominates because it encodes all the other dimensions
at once.

- The **method** the FAA publishes for computing AAR — ground speed
  across the threshold divided by required spacing — is what our
  wind-aware AAR calculator approximates using wake separation,
  approach category, and observed headwind. Our calculator is the
  *what can physics support* sanity check. The declared rate is what
  we allocate against.

**Operational rate** = `min(declared, wind-achievable)`. The facility
will never publish above its own declaration even if weather supports
more, and will reduce below the declaration when wind drops the
achievable rate.

**Where our declared values come from:**

- `airports.base_arrival_rate` / `base_departure_rate` — the single
  headline rate for the airport. Seeded from the operator's vIFF admin
  scope (CYHZ, CYOW, CYUL, CYVR, CYWG, CYYC, CYYZ).
- `data/runway-configs.json` — per-configuration overrides. Each named
  config (e.g. CYYZ "06 Direction", CYVR "08 Direction") has its own
  `declared_arr_rate` and `declared_dep_rate`. This is how we model
  the FAA idea of "one declared rate per *(config × weather class)*".
- `airport_restrictions.capacity_arr_per_hour` — the **active
  override**. When a flow measure imports from vIFF, it takes
  precedence over the declared baseline for as long as the restriction
  is active. This is the rate the allocator slots against.

**Red/yellow/green comparison** on the AAR page: displayed AAR is
`achievable/declared` when the wind has driven achievable below the
declared value. Red gap ≥ 8/hr, yellow 4–7, green in tolerance.

---

## 7. How this interacts with vIFF and PERTI

- **vIFF (CDM plugin)** is the consumer. It polls our
  `/cdm/etfms/restricted` endpoint and reads CTOTs as if we were the
  upstream ETFMS. It does not know or care that we're not PERTI.
- **PERTI** is a parallel system operated by Jeremy Peterson for
  vATCSCC. We ingest its `/api/v1/flights` endpoint read-only for
  three-way ELDT comparison (ours vs PERTI vs SimBrief). We do **not**
  publish to PERTI, and we do **not** depend on it for any production
  function.
- **The FMP** — you — sees our dashboard, our reports page, and (if you
  choose) imports restrictions from vIFF or creates them locally. The
  allocator doesn't care which.

---

## 8. What the dashboard is showing you

- **Monitored airports** (W→E): live inbound count, declared rate,
  current over/under, next-hour forecast.
- **Inbounds drawer**: every flight headed to the selected airport with
  phase, ELDT, TLDT, CTOT (if any), route, and a frozen-lock indicator.
- **Reports page**: historical ELDT/TLDT/ALDT accuracy, grouped W→E,
  with per-airport breakdowns.
- **AAR calculator**: fleet-mix-aware achievable rate from wake
  separation, approach category, and observed wind.

Anything with a red frozen-lock icon has a committed TLDT. Anything with
a yellow clock has an active CTOT. Anything else is live-predicted and
still floating.

---

## 9. Prime directive — never fabricate milestones

This is the rule that overrides all others:

> **If we did not observe it, we do not write it.**

No synthetic ALDTs. No fake AOBTs. If a flight disconnects before we
could record the actual, we mark it DISCONNECTED and the milestone stays
NULL forever. Historical accuracy reports exclude DISCONNECTED flights
by design.

This is why the ingestor has so much ceremony around re-connection,
stale reconnect detection, and climb guards — the cost of a wrong ALDT
contaminating a month of reports is much higher than the cost of
marking a flight DISCONNECTED and losing one data point.

---

## 10. What's deliberately NOT in this design

- **Winds / GRIB / atmospheric modelling.** Geometric only.
- **Ration-By-Schedule GDP.** We run rate-based tactical, not strategic
  GDP. CTOTs recompute every 2 min instead of being issued once per
  program.
- **Waypoint-based flow measures.** Airports only. ADEP/ADES filters.
- **Authoring UI for restrictions.** vIFF / PERTI do that.
- **ROT measurement / data-driven AAR.** Retired in v0.4.7. AAR comes
  from operator knowledge, not statistical derivation. Shared hosting
  can't deliver the ingest cadence required for useful ROT.
- **Persistent CTOTs across restriction changes.** Clean slate each run.

---

## 11. Version history of the design

- **v0.3** — rate-based tactical allocator, 2-min cycle, vIFF
  compatibility.
- **v0.4** — AAR calculator (operator-declared), MATS runway advisor.
- **v0.5** — TLDT as committed slot, T-2h → T-90m freeze horizon,
  three-way ELDT comparison (ours/PERTI/SimBrief), descent-aware ETA,
  climb guard, prime-directive enforcement in the ingestor.

The T-90m freeze horizon is the current consensus. If it moves, this
document moves with it.
