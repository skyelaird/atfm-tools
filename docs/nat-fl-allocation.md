# NAT eastbound FL allocation — 3/3/3 model

Tier-aware flight-level allocation for trans-Atlantic CTP traffic.
Maximises track capacity by stratifying types by Mach class to their
natural cruise altitude, keeping trail-spacing compatible within each
band.

Derived from the 26E (CTP Eastbound, 2026-04-25) fleet mix and the
post-event analysis at `docs/wind-skill-2026-spring.md` (pending).

## Aircraft tier reference

Tier is set by typical cruise Mach. Boundaries are operational, not
absolute — borderline types (A346, A332) shift between A and B based
on weight and dispatch mode.

| Type | 26E count | Typical NAT cruise | Range | Tier | Notes |
|---|---|---|---|---|---|
| B77W | 173 | M0.84 | 0.82-0.86 | **A** | Anchor type for high-Mach tracks |
| A359 | 171 | M0.85 | 0.83-0.87 | **A** | Most efficient tier; mixes freely with B77W |
| B772 | 107 | M0.84 | 0.82-0.85 | A | |
| B77L | 77 | M0.84 | 0.82-0.85 | A | |
| A346 | 38 | M0.83 | 0.80-0.85 | A/B | Boundary; M0.82 in fuel-save mode |
| A339 | 40 | M0.82 | 0.78-0.85 | **B** | A330neo |
| A343 | 33 | M0.82 | 0.78-0.85 | B | |
| A35K | 28 | M0.85 | 0.83-0.87 | A | A350-1000 — same as A359 |
| A333 | 25 | M0.82 | 0.78-0.85 | B | |
| B789 | 23 | M0.85 | 0.82-0.87 | A | 787-9; loves the top |
| MD11 | 32 | M0.82 | 0.80-0.84 | B | DC-10 lineage |
| A388 | 13 | M0.85 | 0.82-0.89 | A | A380; step-climbs full A-band |
| A21N | 14 | M0.78 | 0.72-0.80 | **C** | Narrow body |
| A332 | 7 | M0.82 | 0.78-0.85 | B | A330-200 (heavy load); A-tier when light |
| B737 | 8 | M0.78 | 0.74-0.80 | C | Narrow body |

**Fleet composition at 26E:** A-tier 75% / B-tier 22% / C-tier 3%. Universal
within ~5% across NAT events.

## Tier compatibility for NAT trail-spacing

| Lead | Trail | Compatibility |
|---|---|---|
| A | A | ✓ natural pair, no gap creation |
| A | B | △ trail slowly opens 0.02-0.03M gap (~8-12 nm/hr) |
| A | C | ✗ large opening, wastes track capacity |
| B | A | △ compression risk if A overtakes; 10-min spacing needed |
| B | B | ✓ |
| B | C | △ mild opening |
| C | A | ✗ A will catch up; eject A to higher track or flow control |
| C | C | ✓ |

## FL allocation: 5/3/5 model (full RVSM stack inside NAT-HLA)

Inside NAT-HLA (between OEP and OXP), vertical separation is RVSM
1000 ft throughout — the ICAO semicircular rule (odd thousands
eastbound) does NOT apply. All 13 RVSM levels FL290-FL410 are
allocatable in either direction. The semicircular rule re-applies
once aircraft cross OXP back into continental airspace.

VATSIM treats all aircraft as RVSM-capable; failures drop out of the
system rather than needing a non-RVSM reserve FL.

| Band | FLs (every 1000 ft) | Aircraft tier | Cruise Mach | Types |
|---|---|---|---|---|
| **High (A)** | FL370 / 380 / 390 / 400 / 410 | M0.84-0.85 | A-tier | B77W, B772, B77L, A359, A35K, B789, A332 (light), A388 |
| **Middle (B)** | FL340 / 350 / 360 | M0.82-0.83 | B-tier | A346 (boundary), A339, A343, A333, MD11, A332 (heavy) |
| **Low (C)** | FL290 / 300 / 310 / 320 / 330 | M0.78-0.80 | C-tier | A21N, B737 |

### Per-track capacity

One NAT track = one longitudinal corridor across all FLs inside
NAT-HLA. Continental segments before OEP and after OXP cap at half
this density (only odd thousands available eastbound).

| Band | # FLs | Capacity (acft/hr) | 26E peak demand* | Headroom |
|---|---|---|---|---|
| A | 5 | ~55 | ~22 | 2.5× |
| B | 3 | ~33 | ~6 | 5.5× |
| C | 5 | ~30 | ~1.5 | 20× |
| **Total per track** | **13** | **~118 acft/hr** | **~30 acft/hr** | **~3.9×** |

\* 275 trans-Atlantic pushbacks in 13Z hour across ~9 active tracks (see
PERTI 26E departure analytics, Jeremy Peterson, vATCSCC).

### Allocation rules

- **B77W early-cruise (heavy)** → FL370 bottom of A-band; step-climbs
  to FL390/FL410 as fuel burns.
- **A35K full payload** → FL370 until weight drops, then FL390+.
- **A388** step-climbs through entire A-band (FL370 → 390 → 410); plan
  one A388 = two A-tier slots over the crossing.
- **A346** boundary type — fits at FL370 on its natural M0.83; drop to
  FL360 if running fuel-save M0.82.
- **A21N / B737** → C-band only; don't let them reach for FL370 and
  block A-tier capacity.

## Model evolution

Three iterations refined the allocation:

| Model | A-band | B-band | C-band | A-band capacity | Headroom at 26E peak |
|---|---|---|---|---|---|
| 2/2/2 (odds-only) | FL390, 410 | FL350, 370 | FL310, 330 | ~22/hr | 1.0× (at wall) |
| 3/3/3 (odds-only) | FL370, 390, 410 | FL340, 350, 360 | FL290, 310, 330 | ~33/hr | 1.5× |
| **5/3/5 (full RVSM)** | **FL370-410 every 1000 ft** | FL340-360 | **FL290-330 every 1000 ft** | **~55/hr** | **2.5×** |

The first jump (2/2/2 → 3/3/3) added FL370 to A-band to relieve the
bottleneck. The second jump (3/3/3 → 5/3/5) acknowledged that NAT-HLA
uses 1000-ft RVSM throughout — even thousands are available eastbound
inside the oceanic segment.

Trade-off in B-band: B-tier types fly at FL340-360 (vs their natural
FL370-390 optimum). On VATSIM the ~3-5% extra fuel burn on a 4h
crossing is invisible. In real-world ops dispatchers might push back,
but for our purposes B-tier are mostly older A330/A340/MD11 in
mid-weight cruise where ±2000 ft has minimal efficiency cost.

## Capacity-growth tolerance

Forward-looking sizing for next CTP planning, against the 5/3/5 model
(A-band capacity ~55/hr/track):

| Scenario | A-band demand | A-band headroom |
|---|---|---|
| 26E baseline (2026 spring) | 22/hr/track | 2.5× |
| Fleet drift to 85% A-tier (5-10y horizon) | 25/hr/track | 2.2× |
| Demand growth +30% over baseline | 29/hr/track | 1.9× |
| Demand growth +50% AND fleet drift | 36/hr/track | 1.5× |
| Demand growth +100% (very busy event) | 44/hr/track | 1.25× |

The 5/3/5 model has comfortable headroom across all foreseeable
scenarios. Bottleneck shifts from FL allocation to OEP entry rate
and ATC workload before A-band saturation becomes the limit.

**Rule of thumb:** when A-band demand exceeds ~45/hr per track, open
more tracks rather than compressing the FL allocation further. The
13-FL stack inside NAT-HLA is already maximal — there's nothing more
to extract vertically.

## Operational implication for CTP coordinator

- The 3/3/3 model puts the bottleneck at **A-band capacity per track**,
  where it should be — that's where high-performance traffic stacks.
- Pre-allocate aircraft to FL bands at CTOT issuance based on filed type;
  pilots accept their natural FL more readily than wrong-tier assignment.
- Need **~8-10 active tracks at peak hour** for a 26E-scale event.
- Morning-of FL re-allocation rarely useful — fleet mix is locked at
  booking time.

## Two Mach numbers — don't conflate

Three different Mach values appear in this work:

| Use | Value | Why |
|---|---|---|
| **Tier Mach (cruise only)** | M0.84-0.85 (A) / M0.82 (B) / M0.78 (C) | Drives trail-spacing compatibility within a single FL band |
| **Simulation Mach (trip-averaged)** | **M0.82** | Used in `bin/26e-sector-load.py` for ETA prediction across the whole flight |
| **Spacing baseline (in-trail at cruise)** | M0.84 (A-tier) / M0.82 (B) / M0.78 (C) | Sets the per-FL throughput rate (5-min PBCS = ~12 acft/hr per FL at A-tier) |

The **simulation Mach (M0.82)** is intentionally lower than the
A-tier cruise Mach because it averages across climb (~M0.74-0.78
for ~30 min), cruise (~M0.84-0.85), and descent (~M0.78 for ~20 min).
On a 5h transatlantic flight that weighted average lands near M0.82.

If we instead modelled at M0.84 (cruise-only), ETAs would be ~3-4
min early on average — exactly the bias we'd want to *avoid* in
a demand-curve sim, since climb/descent does take real time.

For demand-curve-to-±5-flights-at-peak tolerance, the M0.82 single-
Mach assumption is fine. Tier-aware Mach modelling would refine
this to ±2-3 min ETA precision but isn't necessary for capacity
planning.

## Longitudinal spacing assumption

Per-FL throughput rates in this doc are derived from a **conservative
5-minute in-trail PBCS planning model**. The actual operational
separation in NAT-HLA today is tighter — ADS-B-equipped traffic is
separated at **14 nm longitudinal and 15 nm lateral** (RLatSM with
ATSAW). The conservative planning number gives margin against the
worst-case mix of equipment, controller workload, and disruption.

### Actual vs. modelled

| Standard | Sep | Equipment | Rate/FL/hr | Notes |
|---|---|---|---|---|
| **Operational today** | **14 nm long, 15 nm lat** | ADS-B + PBCS | **~34** | Actual NAT-HLA enforced separation |
| Distance-based RNP-4 | 30 nm long | RNP-4 + ADS-C | ~16 | Legacy modern standard |
| **5-min PBCS (modelled)** | 5 min in-trail | RNP-4 + CPDLC + ADS-C | **~12** | What this doc's capacity numbers use |
| MNPS legacy | 10 min in-trail | Basic INS-MNPS | ~6 | Pre-PBCS standard |
| Mach-difference rule | 10/15/20 min | Per ICAO Doc 4444 §5.4.2.4 | varies | Used when speeds differ |

At 14 nm / M0.84 (~480 kt GS) longitudinal sep is ~1.75 min in-trail,
so theoretical per-FL capacity is ~34/hr — nearly 3× the planning
model's 12/hr. **Capacity numbers in this doc are therefore
conservative**; operational reality has more margin than shown.

Why use the 5-min model anyway:
- Builds in headroom for non-uniform speed mix within a tier
- Tolerates step-climb requests and ATC workload variance
- Equates "demand fits" between this VATSIM context and real-world
  airline planning that uses 5-min for capacity planning
- If anything goes wrong (equipment, weather), real capacity drops
  toward the modelled value rather than collapsing further

C-tier uses 6/hr per FL — the legacy MNPS rate — because narrow bodies
have wider Mach jitter and rare appearance on NAT make tighter spacing
operationally clumsy.

### Headroom in operational terms

Apply the ADS-B 14/15 separation to the 5/3/5 stack:

| Band | # FLs | At 14 nm sep | 26E peak demand | Headroom (operational) |
|---|---|---|---|---|
| A | 5 | ~170/hr | ~22 | 7.7× |
| B | 3 | ~100/hr | ~6 | 17× |
| C | 5 | ~170/hr | ~1.5 | 100×+ |
| **Total per track** | 13 | **~440 acft/hr** | ~30 | **~14×** |

But this isn't the binding number — see next section.

## CTP planning track cap = 20 acft/hr

**The actual operational ceiling is set by CTP planning, not airspace
physics.** CTP constrains each track at **20 aircraft per hour** to
protect downstream domestic sectors (Canadian arrival sectors at
CYUL/CYYZ/etc., European arrival sectors at EHAM/EGLL/EDDP) from
being crushed by a wall of arrivals.

Hierarchy of separation constraints:

| Layer | Limit per track | Why |
|---|---|---|
| **CTP planning cap** | **20 acft/hr** | Protects domestic sectors at OXP and beyond |
| Operational ADS-B | ~440 acft/hr (theoretical) | Physical NAT-HLA capacity (14/15 nm) |
| 5-min PBCS planning | ~118 acft/hr (theoretical) | Conservative planning model |

**The 20/hr cap is what we plan against** for CTP demand sizing. The
larger numbers are useful only as "what if" headroom margin — for
example, if a track program had to absorb a sudden burst due to
a re-route, the airspace can swallow it; the domestic sectors maybe
not.

26E peak per-track was ~25-30 because CTP sized at 20 acft/hr/track
and bursts to ~30 in the peak hour are tolerated. Demand spread
across ~9-10 active tracks in the 13Z hour gives total CTP capacity
of ~180-200 acft/hr — close to the observed peak of 275 trans-Atlantic
pushbacks. Match.

### Downstream protection logic

Each NAT track terminates at a fix that feeds an arrival corridor.
Several arrival corridors converge on the major European arrival
airports (EHAM, EGLL, LFPG, EDDP, etc.). If CTP didn't cap track
flow at 20/hr:

- 13 FLs × 34/hr at ADS-B = up to 440 acft/hr could enter at OEP
- After 4-5h crossing, all of that arrives at OXP nearly simultaneously
- European TMA arrival rates are ~40-60/hr at the busiest hubs
- Without CTP cap, 7-10× over-delivery to TMA = stack-up, holding,
  diversions — the actual pain point of any uncontrolled flow

CTP's 20/hr cap distributes pain across multiple tracks and prevents
any single arrival sector from being saturated. It's a downstream
protection rule masquerading as an airspace cap.

### What this means for our FL allocation

The 5/3/5 model still matters because:

- **Within the 20/hr cap**, the FL split determines which sectors
  see the demand earliest (high-FL flights cross faster).
- **Tier compatibility within bands** keeps trail spacing clean
  inside the 20/hr flow.
- **A-band still sets the bottleneck** even at 20/hr cap — at typical
  fleet mix, ~15 of those 20 are A-tier, distributed across 5 FLs
  = ~3/FL. Comfortable.
- **B-band carries ~4 of 20** at typical mix → ~1.3/FL. Loose.
- **C-band carries <1 of 20** → 0.2/FL. Effectively empty.

The FL allocation is about *organising* the 20/hr/track flow for
clean trail-spacing and tier compatibility, not about *creating*
capacity.
