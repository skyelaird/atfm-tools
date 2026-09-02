# 2026-09-02 — Attributing the ELDT early bias

## The number

14 days, n=1475, sim-accelerated flights excluded: **TLDT median error
+5.6 min** — the aircraft lands 5.6 min after we said it would, i.e. we
predict early. (Sign convention project-wide: `error = actual − predicted`.
This was unified in v0.7.45; earlier notes and API responses carried the
opposite sign on some panels.) Stable for months;
previously logged as "documented, sample building, no correction applied".

## What it is not

- **Not cruise speed or wind.** The error is flat across flight time
  (+4.8 at 0–45 min, +6.7 at 10 h+) and across distance (+7.7 under
  200 nm, +6.6 over 3200 nm). A cruise-phase error would scale.
- **Not unmodellable real-world delay.** The pilots' own filed ETE has a
  **median error of 0.0** over the same population. The time we are missing
  is time a dispatcher predicts correctly.
- **Not traffic-dependent vectoring.** Flat across UTC hour blocks
  (+4.1 to +6.5), including the 00–03Z VATSIM peak.
- **Not the freeze horizon.** TLDT freezes at a fixed T-90 for every
  flight, which is *why* a flat offset is diagnostic rather than confusing:
  every prediction covers the same 90-minute horizon.

## What it is

**1.1 min — ALDT is stamped late.** `Phase::compute` never returns
ON_RUNWAY or VACATED (those belonged to the ROT tracker, retired in
v0.4.7), so ALDT lands on the first ingest cycle where the aircraft is
already below the 50 kt airborne threshold inside the 5 nm geofence:
touchdown, plus rollout, plus up to one 2-minute quantum. Measured at
median 1.1 min (mean 1.0, deciles 0.3–1.6) via `/api/v1/debug/landing-lag`,
projecting the last observed airborne sample (median 403 ft AGL, 2.2 nm,
140 kt) forward to the field. Uniform across airports, 0.8–1.4 — so it
does **not** explain the per-airport spread.

**~4.5 min — the last 40 nm.** `/api/v1/debug/terminal-time` measures
observed transit from the 40 nm and 100 nm rings to the ALDT stamp:

| Airport | 40nm→wheels | vs model | 100nm→wheels vs model | TLDT median err |
|---|---|---|---|---|
| CYUL | 16.7 | +5.8 | +3.2 | +5.0 |
| CYYZ | 15.9 | +5.0 | +1.7 | +5.7 |
| CYVR | 15.6 | +4.7 | +2.6 | +7.3 |
| CYHZ | 14.5 | +3.6 | −0.3 | +3.5 |
| CYOW | 14.2 | +3.3 | −0.2 | +3.7 |
| CYWG | 13.6 | +2.7 | +2.7 | +3.9 |
| CYYC | 13.0 | +2.1 | −0.6 | +3.0 |

The 100→40 nm segment is right (≈0 at the simple airports). The terminal
excess rank-orders with the per-airport bias. Aircraft cross 40 nm at a
median **360 kt GS** and take ~15 min to fly the last 40 nm — average
~155 kt. The model flies it at 250 kt below FL100 / 220 kt inside 20 nm,
averaging ~220 kt, on a direct track with no allowance for the STAR,
downwind, base leg or vectors.

CYVR is the one airport the terminal excess under-explains (+7.3 total
against +4.7 terminal). Worth a second look once a correction lands.

## Why this was invisible for months

The reports page showed the bias as a single median and the natural
suspects (wind, TAS, route resolution) are all enroute. Nobody looked at
where in the flight the minutes were lost, because nothing measured
segments. Two read-only diagnostic endpoints built from `position_scratch`
answered it in one afternoon; both are cheap and should stay.
