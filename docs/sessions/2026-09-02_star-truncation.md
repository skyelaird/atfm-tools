# 2026-09-02 — The model discards the downwind

Found while acting on Joel's assertion that **aircraft should be assumed to fly
the STAR to the approach**. Two distinct defects, both in code, both fixable
with data we already hold.

## Defect 1 — `alongRouteLegs()` truncates the STAR

`WindEta::alongRouteLegs()` selects "waypoints ahead" as those satisfying

```php
Geo::distanceNm($wLat, $wLon, $destLat, $destLon) < $directDist   // dist(aircraft, dest)
```

That assumes a **monotonic approach**: every remaining fix must be closer to the
field than the aircraft is. Real STARs routinely violate it, because a downwind
takes you *past* the field before turning back.

RAGID6 into CYYZ, fully resolved from `procedures.json` + `waypoints.json`
(9 of 9 fixes), with distance from each fix direct to the ARP:

```
RAGID 41.7 → LERAT 29.6 → SEMTI 13.2 → KEVNO 4.9 → ERBUS 6.0
      → SELAP 12.9 → DUNOP 15.6 → LITRO 18.4 → DERLI 21.3
```

The procedure passes overhead at KEVNO (4.9 nm) and runs back out to DERLI
(21.3 nm) — the downwind for a runway 06 landing. What the filter then does:

| aircraft at | direct to ARP | model computes | STAR + final | under by |
|---|---|---|---|---|
| SEMTI | 13.2 nm | 40.1 nm | 57.1 nm | **17.0 nm** |
| ERBUS | 6.0 | 8.9 | 41.2 | **32.4** |
| **KEVNO** | **4.9** | **4.9** | **45.2** | **40.4** |

At KEVNO it keeps **no fixes at all** and reports 4.9 nm with 45 nm left to fly.

**The error grows as the aircraft approaches**, which is the worst possible
shape for a flow tool: an arrival looks nearly down while it still has twenty
minutes to run, exactly when an FMP is deciding whether the next departure
fits.

## Defect 2 — speed bands are allocated by geometry, not by procedure

This one hits the *frozen* TLDT rather than the live ELDT.

At T-90 the aircraft is ~600 nm out, so every STAR fix passes the filter and the
**total distance is correct**. But `WindEta` then splits that distance using
straight-line rules — TOD at `altAbove / 318`, terminal band at
`fl100Agl / 318 = 31.4 nm` — and applies the slow approach ladder only to the
last 31.4 nm. The STAR's extra track miles therefore get flown at **cruise and
descent speeds**.

Right length, wrong speeds. That is why the frozen value is biased early even
when the route distance is right, and it is consistent with what FAL57 showed:
its FMS budgeted **12 min below FL100** against our **8.7**, while the two speed
profiles agreed within 2 kt (217 vs 215 average; our 140 kt final against
VAPP 137).

## The fix

1. **Follow the published sequence** from the aircraft's position onward, in
   procedure order, without the closer-than-me filter. Snap to the nearest
   remaining fix rather than filtering on distance-to-destination.
2. **Allocate speed bands by position in the procedure**, not by a geometric
   altitude rule. Once on the STAR, fly STAR speeds regardless of straight-line
   distance.
3. **Add the runway transition** — the last STAR fix to the threshold. This is
   the only piece we genuinely lack: `bin/import-navdata.php` parses no runway
   transitions, though the PMDG SidStars source likely carries them. Until then
   it can be measured per (airport, runway) from `position_scratch`, the same
   way `/api/v1/debug/terminal-time` already measures the last 40 nm.

Items 1 and 2 need **no new data**. The geometry is already on disk.

## Why the filter existed

Presumably to stop a route's *departure* end being counted when a flight is
already halfway along it — `parseRouteCoordinates()` returns the whole route,
including fixes behind the aircraft. That is a real problem and the filter is a
reasonable answer to it; it just fails on any procedure that is not monotonic
toward the field. Replacing distance-filtering with **sequence position** solves
both: fixes behind you are behind you in the list, not merely farther away.

## Caution for whoever implements this

The two defects push the same direction but are **not** the same bug, and fixing
only one will look like a partial success while leaving a systematic error:

- Fixing truncation alone corrects the live ELDT near the field but leaves the
  frozen TLDT biased, because the speed bands are still wrong.
- Fixing the bands alone corrects the frozen value but still lets an arrival
  appear 5 nm out when it has 45 to fly.

Validate against **segment budgets**, not the final ETA. FAL57 demonstrated
that two wrong components can cancel to a right-looking total — its cruise
optimism and an over-strong GRIB headwind agreed at 1947 while both were wrong.
See `2026-09-02_fal57-live-eta-comparison.md`.
