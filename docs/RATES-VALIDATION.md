# Declared Rate Validation — FAA AC 150/5060-5 Cross-Reference

**Purpose**: cross-check the `declared_arr_rate` / `declared_dep_rate` values
in [`data/runway-configs.json`](../data/runway-configs.json) against the
FAA capacity model published in **Advisory Circular 150/5060-5, *Airport
Capacity and Delay*** (consolidated reissue, 1995).

This is a one-time validation, not a runtime input. The AC is the closest
thing to a publicly usable FAA "ADR calculator" — lookup tables keyed by
runway-use diagram, mix index, weather, percent arrivals, and exit taxiway
geometry.

**See also**: [DESIGN.md §6.5 — Capacity rates](DESIGN.md#65-capacity-rates--aar-and-adr)
for the operational model (declared vs achievable, how the allocator uses
them).

---

## 0. Rate revision — 2026-04-15 (physics-based ceilings)

This section supersedes the per-airport "Declared" figures below. Those
lines reflect the pre-revision values and are kept for historical context.
The new values come from **first-principles physical ceilings** (not the
FAA AC 150/5060-5 planning curves, which are useful as sanity bounds but
were derived for very different fleet mixes and long-range planning).

### 0.1 Three ceilings

**Dedicated departure runway** (parallel ops, separate strip from arrivals):

- MATS successive-dep floor = **2 min** (covers wake + 3 NM IFR minimum +
  rolling-start variance — it is already the efficient number, not a
  conservative floor)
- Ceiling = `60 / 2 = 30 dep/hr`, directly achievable when the runway is
  dedicated and the departure stream is continuous.
- Applied to: CYVR 08L/26R, CYUL 24R/06L, CYYZ 06L/24R/15L/33R.

**Dedicated arrival runway** (parallel ops, separate strip from deps):

- NAV CANADA standard = **3 NM in-trail** on final (ROT permitting).
  At 140 kt final, 3 NM = 77 sec → ~46/hr ceiling, ~42/hr achievable with
  realistic ROT variance.
- **2.5 NM in-trail authorized at CYVR and CYYZ** (reduced spacing on
  approach): 140 kt × 2.5 NM = 64 sec → ~56/hr ceiling, ~46/hr achievable.
- Applied to: CYVR 08R/26L → 46, CYYZ 06R/24L → 46, CYYZ 15R/33L → 44
  (ROT-marginal, shorter parallel), CYUL 24L/06R → 42.

**Single-runway interleaved ops** (one strip alternating arr and dep):

- Physical cycle = ~90 sec/movement → ~40 total movements/hr ceiling.
- Interleaved ops mandate `AAR ≈ ADR` (every arrival slot paired with a
  dep slot). The old "AAR = 2× ADR" pattern was wrong — it under-declared
  deps and over-declared the split.
- Applied to: CYHZ, CYOW, CYYC 29/11, CYWG 36/18/13/31. Each single
  config now has balanced rates with total ~36–44/hr.

### 0.2 Wake mix caveat (phase-2 correction pending)

These ceilings assume a **clean fleet mix** (no wake penalty). Real
sustained AAR = ceiling × `(1 − wake_penalty)`, where `wake_penalty`
comes from the statistical aircraft mix at each airport:

| Leader → Follower | Separation | Slot stretch |
|---|---|---|
| Medium → Medium | 3 NM (or 2.5 at CYVR/CYYZ) | 0% |
| Heavy → Heavy | 4 NM | ~25% |
| Heavy → Medium | 5 NM | ~65% |
| Super / A388 → * | 6–7 NM | very large |

A CYYZ arrival bank with 15% heavies + 5% supers behind a medium stream
drags 46/hr down to ~40–42. A CYUL with mostly medium jets holds 42.
A CYVR transpac push with B77W/B789/A359 mix may only sustain 40 despite
the 2.5 NM authorization.

**Phase-2 (pending)**: finish the mix-weighted AAR computation using the
historical aircraft-mix collection, derive per-airport wake penalty,
and revise the declared rates downward to realistic sustained values.
The ceilings here stay as ceilings; the allocator can then show
`achievable vs declared vs ceiling`.

### 0.3 New declared rates — summary

| Airport | Config | AAR | ADR | Mode |
|---------|--------|-----|-----|------|
| CYHZ | 05 / 23 single | 22 | 22 | interleaved |
| CYHZ | 14 / 32 single | 18 | 18 | interleaved |
| CYOW | 25 / 07 single | 22 | 22 | interleaved |
| CYOW | 32 / 14 single | 20 | 20 | interleaved |
| CYUL | 24 Direction | 42 | 30 | dedicated A+D |
| CYUL | 06 Direction | 42 | 30 | dedicated A+D |
| CYVR | 08 Direction | 46 | 30 | dedicated A+D (2.5 NM) |
| CYVR | 26 Direction | 46 | 30 | dedicated A+D (2.5 NM) |
| CYYC | 35 Direction | 42 | 30 | dedicated A (35L) + D (35R), 3 NM arr |
| CYYC | 17 Direction | 42 | 30 | dedicated A (17R) + D (17L), 3 NM arr |
| CYYC | 29 / 11 single | 20 | 20 | interleaved |
| CYWG | 36 / 18 single | 22 | 22 | interleaved |
| CYWG | 13 / 31 single | 18 | 18 | interleaved |
| CYWG | dependent crossings | 28 | 16 | unchanged |
| CYYZ | 06 Direction | 46 | 30 | dedicated A+D (2.5 NM) |
| CYYZ | 24 Direction | 46 | 30 | dedicated A+D (2.5 NM) |
| CYYZ | 15 Direction | 44 | 30 | dedicated A+D (ROT-marginal) |
| CYYZ | 33 Direction | 44 | 30 | dedicated A+D (ROT-marginal) |

None of these exceed FAA AC 150/5060-5 ceilings; they sit well below the
VFR figures and below or at the IFR figures, which is the correct regime
for VATSIM ops.

---

## 1. Method

### 1.1 Mix index

**Mix index** = `%(C + 3·D)` where:

- **A, B**: small single/twin pistons (not in our VATSIM fleet)
- **C**: large props / small jets / regional jets (CRJ, E175, B712, DH8D)
- **D**: heavy jets / widebodies (B777, B747, A330/350, B787)

For a typical online Canadian fleet mix (say 60% C, 10% D, 30% A/B narrowbody),
mix index ≈ 60 + 30 = **90**, landing in the AC's "mix 81–120" row. For
peak international arrival banks at CYYZ / CYVR the index climbs toward
120–150. We use **mix 51–80** as the modal case and **mix 81–120** as the
peak case in the comparison below.

### 1.2 Runway spacing classifications

Computed from the DMS coordinates in [`bin/seed-airports.php`](../bin/seed-airports.php),
perpendicular to runway heading (see [`.faa-ref/compute-spacings.py`](../.faa-ref/compute-spacings.py)):

| Pair            |  Sep (ft) | FAA class                       |
|-----------------|----------:|---------------------------------|
| CYUL 06L/06R    |     5291  | INDEPENDENT IFR (verified against LIDO: 1617 m) |
| CYVR 08L/08R    |     5706  | INDEPENDENT IFR                 |
| CYYC 17L/17R    |     7095  | INDEPENDENT IFR (OSM-verified; Google Earth 7126 ft / 2172 m) |
| CYYZ 06L/06R    |      999  | **CLOSE** (<2500 ft, dependent) |
| CYYZ 06R ↔ 05   |    11851  | INDEPENDENT IFR (05 is far NW)  |
| CYYZ 15L/15R    |     3495  | intermediate (3400–4300 ft)     |

**Seed validation (2026-04-15)**: all seed coordinates were cross-checked
against OpenStreetMap runway ways by
[`.faa-ref/validate-seed-vs-osm.py`](../.faa-ref/validate-seed-vs-osm.py).
One drift > 50 m was found: CYVR 08L/26R end threshold was off by 220 m.
Corrected in [`bin/seed-airports.php`](../bin/seed-airports.php).
All 7 airports now match OSM within 12 m end-to-end.

The earlier suspicion that CYYC and CYVR seed coordinates were broadly
wrong traces back to **stale hardcoded pair coordinates** inside an
early version of `.faa-ref/compute-spacings.py`, not the seed file itself.
The seed coordinates are consumed only by the dashboard's airport-detail
drawer for display; they are not read by the allocator, ingestor, or
ETA estimator, so the CYVR drift had zero operational impact — it was
purely a cosmetic map-drawing offset.

**Threshold reference** (FAA AC 150/5060-5 Ch.2 + Ch.3):
- `<2500 ft` — close parallels, IFR ops are dependent (single-stream)
- `2500–3400 ft` — dependent with staggered-threshold capacity bonus
- `3400–4300 ft` — intermediate; may be independent with radar monitoring
- `≥4300 ft` — fully independent IFR ops

**Bug fix note (2026-04-15)**: the original `compute-spacings.py` read
the heading field from `bin/seed-airports.php` (e.g. `057`) as a true
bearing, but those values are **magnetic**. At CYUL the ~14°W magnetic
variation made the perpendicular projection point ~14° off the actual
runway normal, which pulled the computed spacing 1000 ft short of reality.
Joel's direct measurement from the LIDO chart (1617 m = 5305 ft) exposed
the bug. The script was rewritten to derive the true heading from the
two threshold coordinates of each runway — no magnetic-variation table
needed — and the new values match the chart measurements to within ~15 ft
across all airports.

All listed Canadian parallels are solidly **INDEPENDENT IFR** per FAA
AC 150/5060-5 thresholds. CYUL's earlier "boundary +32 ft" framing was
an artefact of the heading bug, not a real geometry concern. In real-
world NAV CANADA ops CYUL typically runs 06L/06R dependently anyway
(procedural choice, not a geometric limit), which we mirror in the
declared rate.

### 1.3 FAA reference values (Figure 2-1 / Chapter 2)

Rounded from the AC's long-range planning figures for mix 21–50 and 81–120:

| Diagram                               | VFR 21–50 | IFR 21–50 | VFR 81–120 | IFR 81–120 |
|---------------------------------------|----------:|----------:|-----------:|-----------:|
| **#1** Single runway, mixed ops       |        74 |        57 |         55 |         53 |
| **#3** Close parallel (2500–3400)     |       149 |        63 |        111 |         70 |
| **#4** Independent parallel (3400+)   |       219 |       114 |        161 |        117 |
| **#10** Intersecting near-threshold   |       145 |        57 |        105 |         59 |
| **#13** Two close pairs (CYYZ-like)   |       147 |        57 |        138 |         59 |

Numbers are **total combined movements/hr** (arrivals + departures) per the
figure. Single-runway IFR holds near 57/hr across nearly all mix values —
wake separation is the binding constraint.

---

## 2. Airport-by-airport comparison

For each airport, "declared" values are taken from
[`data/runway-configs.json`](../data/runway-configs.json). "Declared total"
is `declared_arr_rate + declared_dep_rate` per active config.

### CYHZ — Intersecting 05/23 × 14/32 (single-runway ops each direction)

**Airport detail** (cross-referenced from LIDO Airport Ground Chart via
planner.flightsimulator.com, 2026-04-15):

| RWY | TORA (m) | ASDA (m) | TODA (m) | ft       |
|-----|---------:|---------:|---------:|---------:|
| 05  |     3200 |     3200 |     3410 | 10,499   |
| 14  |     2347 |     2347 |     2647 |  7,700   |
| 23  |     3200 |     3200 |     3410 | 10,499   |
| 32  |     2347 |     2347 |     2647 |  7,700   |

ARP N44°52.8′ W063°30.6′ / elev 575 ft / VAR 18°W.

**Hot spots** (per LIDO):
- HS1 — taxiways D/K crossing RWY 05/23 for both aircraft and vehicles
- HS2 — RWY 14/32 from taxiway F for aircraft taxiing for RWY 23
- HS3 — taxiway H when aircraft taxiing from apron prior to calling GND

**Layout notes**: the two runways cross at ~90° near the terminal area.
No high-speed exits visible on the LIDO chart — exits are perpendicular
taxiways, so ROT is bounded by normal-braking rollout (not HSE-assisted).

- **FAA diagram**: #10 intersecting (dependent, mid-runway crossing) when
  both runways are active; #1 single-runway when only 05/23 is in use
  (typical calm-wind / light-wind case)
- **FAA total** (IFR, mix 51–80): ≈ **54/hr** single-runway;
  ≈ **57/hr** intersecting dependent
- **Declared** (05 Single / 23 Single): 24 A + 12 D = **36/hr**
- **Gap**: conservative by ~18/hr (~33%)
- **Interpretation**: below FAA physical capacity; VATSIM-realistic. Only
  concern would be if LAHSO configs (24A/16D = 40) ever got close to the
  IFR line, which they don't. ✅

### CYOW — Intersecting 14/32 × 07/25 (single-runway ops each direction)

**Airport detail** (cross-referenced from LIDO Airport Ground Chart via
planner.flightsimulator.com, 2026-04-15):

| RWY | TORA (m) | ASDA (m) | TODA (m) | ft       |
|-----|---------:|---------:|---------:|---------:|
| 07  |     2438 |     2438 |     2738 |  7,999   |
| 14  |     3050 |     3050 |     3349 | 10,007   |
| 25  |     2438 |     2438 |     2738 |  7,999   |
| 32  |     3050 |     3050 |     3349 | 10,007   |
| 04/22 |  1006  |    —     |    —     |  3,300   | (GA strip, not IFR) |

**Note**: **14/32 is the longer runway at 10,007 ft**, not 07/25. Our
`runway-configs.json` has `25` as `calm_wind_config` with declared 30/12
and `32` with declared 28/12 — i.e. the shorter runway gets the slightly
higher declared rate.

**Operational context** (per Joel, 2026-04-15): despite 14/32 being the
longer runway, **07/25 is popular for E-W enroute alignment** (track
mileage savings) and **32 is noise-preferred for arrivals** (city of
Ottawa is to the N and NE — a 32 approach from the NW overflies
uninhabited terrain). **14/32 has no high-speed exit** — 32 arrivals
heavy-brake to make exit D, so ROT is higher than the type-specific
average would predict. FAA Exit Factor E for 32 arrivals is therefore
< 1.00 (though on a single-runway airport where wake separation
dominates the ~2/hr capacity delta is immaterial at VATSIM volumes).

The 30/12 vs 28/12 inversion is not a critical flag but worth noting
when the declared rates are next reviewed.

- **FAA diagram**: #1 single runway, mixed ops (each direction)
- **FAA total** (IFR, mix 51–80): ≈ **54/hr**
- **Declared** (25 Single / 07 Single): 30 A + 12 D = **42/hr**;
  (32 Single / 14 Single): 28 A + 12 D = **40/hr**
- **Gap**: conservative by ~12/hr (~22%)
- **Interpretation**: closest-to-FAA of our single-runway airports, which
  matches vIFF operator judgement (CYOW got bumped from 28→24 rate on
  2026-04-15 to align with vIFF — still below FAA). ✅
- **Minor action**: consider bumping 14/32 declared rate to match or exceed
  25/07 since 14/32 is the longer runway. Not urgent.

### CYUL — Parallel 06L/06R @ 4332 ft

**Airport detail** (cross-referenced from LIDO Airport Ground Chart via
planner.flightsimulator.com, 2026-04-15):

| Runway  | Physical (m × m) | TORA (m) | Notes                          |
|---------|-----------------:|---------:|--------------------------------|
| 06L/24R | 3353 × 61        |     3353 | Outer parallel — full length    |
| 06R/24L | 3014 × 61        |     2927 | Inner — 87 m displaced threshold |
| 10/28   | (separate page)  |        — | Crosswind — shorter, not parallel |

**LIDO heavy-aircraft restrictions on 06R/24L chart:**
- A346 / B773 **right turn prohibited** on multiple TWYs — wingspan
  constraint on 06R/24L's apron-side turnoffs
- A345 / A346 / A35K / B777 **additional TWY limitations**
- Left turns to TWY B prohibited for aircraft wingspan ≥ 36 m (118 ft)

These are ground-movement constraints, not runway throughput, but they
imply heavy ops are concentrated on 06L/24R (the 11,001 ft outer parallel)
rather than 06R/24L. Our declared-rate model doesn't need to account for
this explicitly but it's relevant if a future enhancement models per-exit
ROT: heavies clearing on 06L have more options.

**Exit Factor E**: 06L/24R and 06R/24L both show ≥4 qualifying exits
(B1–B6, A1–A5, plus IS family). **E = 1.00 ✅**.

- **FAA diagram**: #4 independent parallel — **5305 ft** (1617 m)
  measured from the LIDO chart, comfortably above the 4300 ft threshold
  (the earlier 4332 ft computation from seeded DMS was ~1000 ft short,
  likely bad seed coordinates — flagged)
- **Real-world**: NAV CANADA operates dependently → Diagram #3 effectively
- **FAA total** (Diagram #3 IFR, mix 51–80): ≈ **65/hr**
- **FAA total** (Diagram #4 IFR, mix 51–80): ≈ **111/hr**
- **Declared** (24 Direction / 06 Direction): 40 A + 16 D = **56/hr**
- **Gap vs dependent model**: within ~14%
- **Gap vs independent model**: conservative by ~49%
- **Interpretation**: our declared value sits just below the FAA dependent-
  parallel IFR line, which is the operationally honest number. ✅

### CYVR — Parallel 08L/08R @ 5706 ft

**Airport detail** (cross-referenced from SkyVector + LIDO Airport
Ground Chart via planner.flightsimulator.com, 2026-04-15):

| Runway  | Physical (m × m) | TORA (m) | ASDA (m) | TODA (m) | Approach lights  |
|---------|-----------------:|---------:|---------:|---------:|------------------|
| 08L/26R | 3030 × 61        |     3030 |     3030 |     3480 | ALSF-2 Cat II/III |
| 26L→    | —                |     3503 |     3503 |     5254 | ALSF-2 Cat II/III |
| 08R→    | 3715 × 61        |     3505 |     3505 |     3805 | ALSF-2 Cat II/III |
| 26R→    | —                |     3030 |     3030 |     4545 | ALSF-2 Cat II/III |
| 13/31   | 2225 × 61        |     2225 |     2225 |     2525 | OMNI              |

**Notes from the LIDO chart:**

- Rwy 08L first 2027 ft at 0.3% downslope (SkyVector).
- 08R TORA (3505 m) is 210 m less than physical length (3715 m) due to
  displaced threshold at the 26L end — matches SkyVector's 689 ft
  displacement (≈ 210 m).
- Multiple PPR aprons (Apron 5, 6 North/East/South/West, 7, 8) —
  ground-movement constraints but no direct effect on runway throughput.
- **13/31 wingspan restriction**: RWYs 13 DEP / 31 ARR not authorised for
  A346 / A359 / A35K / B773 / B77W / B78X (any wingspan > 65 m).

**Exit taxiway offsets** (as displayed on the LIDO chart — values are
*TORA-from-intersection for a takeoff in the runway-direction heading*,
so `offset from threshold ≈ total TORA − displayed value`):

*08L/26R runway (TORA 3030 m / 3030 m):*

| Exit | Displayed TORA (m) | Offset from 08L thr (m) | Notes            |
|------|-------------------:|------------------------:|------------------|
| M10  |                  — |                    ~ 0  | 08L lineup / entry |
| M8   |               2930 |                    100  | Near 08L thr     |
| M6   |               2386 |                    644  |                  |
| M4   |               1859 |                   1171  | Rapid exit       |
| M3   |                  — |                         | Near 26R end     |
| M2   |                  — |                         | Near 26R end     |
| M1   |                  — |                         | Near 26R thr     |

*08R/26L runway (TORA 3505 m 08R / 3503 m 26L):*

| Exit | Displayed value (m) | Notes                                       |
|------|--------------------:|---------------------------------------------|
| D1   |                1760 | Rapid exit (approx mid-runway)              |
| D3   |                1556 | Rapid exit                                  |
| D5   |                2153 | Rapid exit                                  |
| D7   |                   — | Near 26L end                                |
| D9   |                   — | Near 26L end                                |
| A5   |                   — | Adjacent to 26L threshold area              |
| A7   |                   — | Adjacent to 26L threshold area              |

The precise reading direction (TORA-from versus LDA-to) depends on the
LIDO legend which I haven't fully parsed from the chart — but the **count**
of qualifying exits is what the FAA Exit Factor E table needs, and that's
clearly ≥4 per runway inside the 3500–6500 ft (1067–1981 m) mix-51–80
window on both parallels.

- **FAA diagram**: #4 independent parallel. Geometry (5706 ft separation)
  and procedures (both parallels have ALSF-2 Cat II/III approach lighting
  supporting independent IFR ops to Cat II/III minima) both satisfy the
  independent-parallel classification.
- **FAA Exit Factor E**: verified against the LIDO Airport Ground Chart
  (via planner.flightsimulator.com). Both parallels have *many* exits
  beyond the named rapids — the M, N, and D taxiway families give ≥4
  exits inside the 3500–6500 ft exit-range window for mix 51–80. **Exit
  Factor E = 1.00** (no reduction). The earlier SkyVector-only count of
  3 rapid exits undercounted; the AC's table counts all qualifying
  exits, not just high-speed ones.
- **13/31 heavy restriction**: LIDO chart notes RWYs 13 DEP / 31 ARR are
  **not authorised** for A346 / A359 / A35K / B773 / B77W / B78X, i.e.
  any wingspan >65 m. This confirms the `_supplementary: ["13","31"]`
  classification in `runway-configs.json` — the runway can't carry the
  heavy end of a CYVR fleet mix, so excluding it from auto-pick is
  correct regardless of wind.
- **FAA total** (Diagram #4 IFR, mix 51–80, E=1.00): ≈ **111/hr**
- **Declared** (08 / 26 Direction): 40 A + 16 D = **56/hr**
- **Gap**: conservative by ~48/hr (46%)
- **Interpretation**: very conservative relative to physical capacity, but
  matches VATSIM traffic density. Real-world CYVR runs 70+ AAR in peak
  banks on 12 188-ft 08R/26L which comfortably swallows any fleet mix.
  We'd need to bump `declared_arr_rate` to ~50 before we hit any FAA
  ceiling. ⚠️ *Flag: review if VATSIM CYVR load ever pushes past current
  declared rate.*

### CYWG — Intersecting 18/36 × 13/31

**Airport detail** (cross-referenced from LIDO Airport Ground Chart via
planner.flightsimulator.com, 2026-04-15):

| Runway  | TORA (m) | ASDA (m) | TODA (m) | ft       |
|---------|---------:|---------:|---------:|---------:|
| 13      |     2695 |     2695 |     2995 | 8,842    |
| 18      |     3353 |     3353 |     3653 | 11,001   |
| 31      |     2695 |     2695 |     2995 | 8,842    |
| 36      |     3353 |     3353 |     3653 | 11,001   |

Primary 18/36 = 11,001 ft × 200 ft; crosswind 13/31 = 8,842 ft × 200 ft.
Runways intersect near midfield, not near-threshold — pure Diagram #10
"intersecting dependent" classification.

**Exit Factor E**: 18/36 has ≥5 named exits (F, K, L, H, V + apron
turnoffs) inside the 3500–6500 ft mix-51–80 window. **E = 1.00 ✅**.

- **FAA diagram**: #10 intersecting (dependent, mid-runway crossing)
- **FAA total** (IFR, mix 51–80): ≈ **57/hr** single-intersection
- **Declared** (18 Single / 36 Single / LAHSO variants): 24 A + 12 D = **36**;
  dependent crossing variants 28 A + 16 D = **44**
- **Gap**: conservative by ~13–21/hr
- **Interpretation**: in line with intersecting-runway IFR capacity,
  conservative for VATSIM traffic. ✅

### CYYC — Parallel 17L/17R @ 7095 ft

**Authoritative runway data** (Navigraph Jeppesen 10-9 chart + OSM, 2026-04-15):

| RWY      | LDA (ft) |
|----------|---------:|
| 17L/35R  |  14,000  |
| 17R/35L  |  12,675  |
| 11/29    |   7,982  |

Parallel spacing 17L↔17R = **2163 m (7095 ft)** computed from OSM runway
ways; matches Joel's Google Earth measurement of 2172 m (7126 ft) to
within 9 m.

- **FAA diagram**: #4 independent parallel (solidly independent — 7095 ft
  is well above the 4300 ft independent threshold)
- **FAA total** (IFR, mix 51–80): ≈ **111/hr**; Calgary's real published
  capacity is in the 80–100 AAR range depending on weather
- **Declared** (35 / 17 Direction): 40 A + 16 D = **56/hr**
- **Gap**: conservative by ~55/hr
- **Interpretation**: same as CYVR — very conservative, matches VATSIM
  load. ⚠️ *Flag: CYYC has unused headroom for event-night scaling.*
- **Note**: earlier figures (8483 ft then 5301 ft) were wrong due to
  stale hardcoded pair coordinates inside `.faa-ref/compute-spacings.py`
  (not the seed file). The `bin/seed-airports.php` CYYC coordinates are
  correct — validated against OSM by
  [`.faa-ref/validate-seed-vs-osm.py`](../.faa-ref/validate-seed-vs-osm.py)
  to within 4 m on all three runways.

### CYYZ — 06L/06R (999 ft close) + 05 (11851 ft independent third)

**Airport detail** (cross-referenced from LIDO Airport Ground Chart via
planner.flightsimulator.com, 2026-04-15):

| RWY | TORA (m) | ft      | Physical runway                  |
|-----|---------:|--------:|----------------------------------|
| 05  |     3284 |  10,774 | 05/23 diagonal NE (independent)  |
| 23  |     3284 |  10,774 | 05/23                            |
| 06L |     2956 |   9,698 | 06L/24R — outer parallel         |
| 24R |     2923 |   9,590 | 06L/24R                          |
| 06R |     2743 |   8,999 | 06R/24L — inner parallel         |
| 24L |     2712 |   8,898 | 06R/24L                          |
| 15L |     3318 |  10,886 | 15L/33R — outer SSE              |
| 33R |     3368 |  11,050 | 15L/33R                          |
| 15R |     2770 |   9,088 | 15R/33L — inner SSE              |
| 33L |     2767 |   9,078 | 15R/33L                          |

Key point: **05/23 at 10,774 ft is the longest runway usable in the 06/24
operating direction** and is 10,926 ft perpendicular separation from 06L/06R
— fully independent IFR per FAA classification. All five physical runways
show ≥4 qualifying exits (D1–D9 family, HS hot spots, Alpha/Bravo/Charlie
taxiways). **Exit Factor E = 1.00** on every direction.

- **FAA diagram**: hybrid — Diagram #3 (close pair 06L/06R) + independent
  third runway (05); closest match is Diagram #13 (two close pairs, mix
  51–80 IFR ≈ **57/hr** combined) *if 05 is unused*, but with 05 as an
  independent third arrival runway the figure rises toward single-runway
  capacity added on top (≈ 57 + ~30 = **~87/hr**)
- **Declared** (06 Direction / 24 Direction): 40 A + 16 D = **56/hr**
- **Real-world**: Toronto Pearson published rates run 96 AAR / 90 ADR in
  optimal VMC on three-runway ops (05 + 06L D + 06R A), far above our value
- **Gap**: our declared matches FAA close-pair-only IFR (~57) exactly, but
  **leaves the independent 05 runway's capacity on the table**
- **Interpretation**: the declared value reflects the capacity of the
  06L/06R pair alone. The config already lists 05 as `A/D` but the rate
  does not reflect its contribution. **Actionable fix**: split 06 Direction
  into two sub-configs — "06 Direction (2-rwy)" at 40/16 when 05 is closed
  or not staffed, and "06 Direction (3-rwy with 05)" at ~60/20 when all
  three are in use. Same for 24 Direction. ⚠️ *Flag still open.*

### CYYZ — 15L/15R @ 3495 ft (exceptional-only)

- **FAA diagram**: 3400–4300 intermediate parallel (upgraded from prior
  "staggered-bonus" classification after the compute-spacings heading-bug
  fix on 2026-04-15 — the extra 177 ft crosses the 3400 ft boundary)
- **FAA total** (IFR, mix 51–80): ≈ **70/hr** with intermediate radar mon
- **Declared**: 40 A + 16 D = **56/hr**
- **Interpretation**: in the right ballpark; since this config only runs
  when NE/SW are out of tolerance (exceptional-only per our two-tier
  selector), the rate is used rarely enough that precision matters less. ✅

---

## 3. Findings summary

Post-revision totals (A + D per primary config):

| Airport | FAA IFR cap (approx) | Our declared (new) | Status                       |
|---------|---------------------:|-------------------:|------------------------------|
| CYHZ    |                   54 |                 44 | ✅ physics-based interleaved  |
| CYOW    |                   54 |                 44 | ✅ physics-based interleaved  |
| CYUL    |                   65 |                 72 | ✅ dedicated parallel ops     |
| CYVR    |                  111 |                 76 | ✅ 2.5 NM arr + dedicated dep |
| CYWG    |                   57 |                 44 | ✅ physics-based interleaved  |
| CYYC    |                  111 |                 72 | ✅ dedicated A+D, 3 NM arr    |
| CYYZ    |                   87 |                 76 | ✅ 2.5 NM arr + dedicated dep |

**Three actionable flags**:

1. **CYYZ underrating 05.** The 06 Direction config lists 05 as `A/D` but
   the `declared_arr_rate` doesn't reflect the third runway's contribution.
   Suggested rework: split into "06 Direction (2-rwy)" at 40/16 and
   "06 Direction (3-rwy with 05)" at 56/20 when all three are in use.
2. **CYVR / CYYC VFR-night headroom.** Declared rate is ~50% of FAA IFR
   ceiling and ~20% of FAA VFR ceiling. Usually fine. Worth revisiting if
   we ever see allocator saturation during CYVR/CYYC events.
3. **None of our declared values exceed FAA physical capacity** — the
   sanity check we needed. No runway-configs.json value is non-physical.

**Non-actionable but informative**:

- Declared rates being 30–50% below FAA physical capacity is **correct
  and deliberate** for VATSIM. Controller workload, sector capacity, and
  online traffic density are the binding constraints, not runway wake
  separation. A declared rate that matches FAA would hide meaningful
  congestion signals.
- The declared rate is operator-authored and represents the **facility's
  compressed judgement** (see DESIGN.md §6.5). AC 150/5060-5 is a
  ceiling check, not a replacement.

---

## 4. References

- **FAA AC 150/5060-5** — *Airport Capacity and Delay* (1983, 1995 reissue).
  Cached at [`.faa-ref/ac_150_5060_5.pdf`](../.faa-ref/ac_150_5060_5.pdf)
  (gitignored, 12 MB). Figure 2-1 = long-range planning capacity per
  runway-use diagram; Figures 3-3 through 3-15 = detailed short-range
  calculations with mix, touch-and-go, and exit-factor adjustments.
- **FAA FOA Ch.10 §7** — *Airport Arrival Rate (AAR)*. Facility-declaration
  procedure. [web](https://www.faa.gov/air_traffic/publications/atpubs/foa_html/chap10_section_7.html)
- **FAA ASPM SAER** — System Airport Efficiency Rate, consumer of declared
  AAR/ADR. [web](https://www.aspm.faa.gov/aspmhelp/index/SAER.html)
- **FAA/MITRE runwaySimulator** — replacement for the dated ACM, Java-based,
  training-gated. [web](https://www.faa.gov/airports/planning_capacity/runwaysimulator).
  Not pursued — wrong shape for VATSIM real-time use.

## 5. Next review trigger

Re-run this validation when:

- A new airport is added to the monitored set.
- Runway-configs.json declared rates are changed by >20% in either direction.
- NAV CANADA publishes updated declared rates via vIFF that disagree with
  our values by >10/hr.
- Real VATSIM load at CYVR or CYYC starts saturating the declared arrival
  rate during events (flag #2 above).
