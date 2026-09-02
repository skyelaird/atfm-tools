# 2026-09-02 — FAL57: one flight, four predictors, three model defects

A real A320 flown CYHZ→CYYZ specifically to compare our ELDT against
independent predictors, with the pilot reporting instrument readings live.
The morning's bias attribution (`2026-09-02_eldt-bias-attribution.md`) was
derived from 1,475 landings; this is the same conclusion reached from one
flight by a completely different route, plus three things the fleet data
could not have shown.

## The flight

```
FAL57  A320  CYHZ→CYYZ  696 nm  ATOT 17:44:03  ALDT 19:52:36
flight time 129 min (filed 122)     had_atc true     aldt_source INTERP
```

| predictor | estimate | error vs ALDT |
|---|---|---|
| aircraft FMS (post runway change) | 19:55 | **−2.4** |
| filed ETE (ATOT + filed enroute time) | 19:46 | **+6.6** |
| vIFF (TTOT + filed ETE, static) | 19:39 | +13.6 |
| **our TLDT** (frozen 18:08) | 19:38:49 | **+13.8** |

Sign convention as everywhere: `actual − predicted`, positive = we predicted
early.

## What the model got wrong, each measured separately

**Terminal track miles — ~3.3 min.** The decisive observation. The FMS
plan had FL100 (its LIM line) at 1935 and touchdown at 1947: **12 minutes
below FL100**. Our model budgets **8.7 min** for that band.

The speed profile is not the problem. Our ladder averages **217 kt** across
the band; the FMS worked out to **215**. Our final segment uses 140 kt; the
aircraft's VAPP was **137**. Two independently-built approach models agreeing
within a few knots, repeatedly.

The error is **distance**. We compute the FL100 crossing as `10000 / 318 =
31.4 nm` — the straight-line distance for a 3° path. The actual arrival
covers about **43 nm of track** in that altitude band. **~12 nm of unmodelled
track miles**, worth 3.3 min at our own speeds.

**Filed TAS optimism — ~2 min.** Filed N0462; actual TAS 443–444 all
flight. Decomposed against the reported SAT of −51 (ISA+6, a = 581 kt):

- SimBrief filed 462 = **M0.797**, against a planned M0.78 → **+10 kt**
- pilot cruised **M0.76–0.766** against that plan → **−8 kt**

So roughly half filing artifact, half pilot reality — and the cause does not
matter, because both push the same way and neither is knowable from the
flight plan. Our cascade prefers filed TAS over groundspeed deliberately
("wind-neutral"), which is correct reasoning and the wrong input.

**Below-FL100 wind — ~0.5 min.** We apply no wind below FL100. The
aircraft's descent-wind page showed 272/33 at 10,000 decaying to 270/06 at
the surface, and destination MAG WIND 230/5. Real but small, and smaller than
the ~1 min first estimated.

**TOD rule — acquitted.** An early rough report suggested the FMS planned
TOD 39 nm earlier than our `altitude/318`. The F-PLN put it at **~120 nm**
against our **113**. Our 318 ft/nm is fine; the hypothesis is dropped.

## What no model could have helped with

**A runway change worth 8 minutes.** CYYZ tower came online 16 minutes
before landing and changed the arrival from **23 to 06R** — a reciprocal.
The FMS moved 1947 → 1955 immediately. Arriving from the northeast, 06R
means flying past the field and coming back.

At 19:37, 31 nm out at 11,000 ft: **our ELDT said 7 minutes, the FMS said
18.** The frozen TLDT expired sixty seconds later with the aircraft still
31 miles out.

Total terminal deficit therefore decomposes as **~3.3 min procedure +
~8 min runway change**, matching the 11-minute gap observed at 31 nm and the
+13.8 final error once the cruise terms are added.

## The behaviour that made the diagnosis possible

The gap between our live ELDT and the FMS **closed through cruise and
reopened at TOD**:

```
18:02  541 nm   ours 19:37   FMS 1944   gap 7
18:28  452 nm   ours 19:42   FMS 1946   gap 4
18:48  327 nm   ours 19:45   FMS 1946   gap 1
19:21  TOD      ours 19:43   FMS 1947   gap 4   ← reopened
```

That sequence separates the two error classes cleanly. The **speed** term is
proportional to remaining cruise and expires at TOD. The **geometry** term is
fixed and only bites in the last band. During cruise they cancelled — our
filed-TAS optimism against a GRIB headwind stronger than the aircraft
actually experienced — and the totals agreed at 1947 while both components
were wrong.

**Compensating errors are the trap here.** Agreement on a total is not
evidence the model is right, and a single-number KPI cannot distinguish the
two. Any future validation should compare *segment* budgets, not just the
final ETA.

## Sanity checks that passed

- Live ELDT tracked wind honestly: headwind 55 → 84 kt over 40 min, ELDT
  walked 19:37 → 19:48, one cycle behind each change.
- Wind data currency is worth ~1 min: an FMS wind uplink moved its ETA by a
  single minute, which is quiet reassurance for our 6-hour GRIB cache.
- Our AOBT stamped 17:34 against first observed movement at 17:33:39.
- The reported wind reconciled with TAS − GS to within 2 kt every time.

## Bugs this flight found

1. **Portal served a refiling pilot their DISCONNECTED flight** (v0.7.55).
   Ordered by `last_updated_at` and excluded only WITHDRAWN, so a stale
   record outranked a live one until the next ingest cycle.
2. **Runway detection compared true against magnetic** (v0.7.58).
   `runway_thresholds.heading_deg` is magnetic — verified against the stored
   threshold coordinates: CYHZ +18.0, CYOW +13.7, exactly the local
   variation. Replaced with a track-vs-threshold-bearing test, both true.
3. **That replacement referenced `$lat`/`$lon`, which are not in scope**
   inside `interpolateTouchdown()` (v0.7.61). PHP reads them as null, the
   guard was always false, and `arrival_runway` was silently never recorded.
   **A fix that silently does nothing is worse than the bug it replaced**,
   and it only surfaced within the hour because a flight was being watched
   where the answer was known.

Also confirmed working on first live test: **ALDT interpolation**
(`aldt_source: INTERP`) and **`had_atc_at_arrival`**, populated for the first
time since the column was created in v0.5.13.

## What this changes

**The terminal correction must be conditional, not a constant.** A flat
per-airport value would have been wrong in both directions on this single
flight: too large for the uncontrolled arrival originally planned, far too
small once ATC turned the airport around. `had_atc_at_arrival` now records
the split; `arrival_runway` (once 0.7.61 accumulates data) records the other
half.

**The ATIS is the missing input, and it is already in the feed we poll.**
During the flight, `CYYZ_ATIS` read:

```
PRIMARY IFR APPROACH ILS RUNWAY 06 RIGHT.
SECONDARY IFR APPROACH RNAV YANKEE OR ILS RUNWAY 05.
SIMULTANEOUS PARALLEL APPROACHES IN EFFECT.
DEPARTURE RUNWAY 05.
```

Arrival runway, departure runway and the parallel-approach flag that sets the
AAR — machine-parseable, free, refreshed every 2 minutes, and unread. It
would have told us about the runway change before the aircraft was told.
This is the same conclusion reached independently from vIFF's "Use ATIS
Config" airport option (`docs/VIFF-INTEGRATION.md`).

**And `err_reasons` returned empty** on a 13.8-minute error it should have
had plenty to say about. The reason logic inspects lock source, freeze lead,
wind tier, aircraft type and route quality — none of which were wrong here.
It has no concept of terminal geometry or a runway change. Worth extending
now that `had_atc` exists.
