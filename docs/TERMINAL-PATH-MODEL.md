# Terminal path model — design

**Status:** designed, not built. Recorded 2026-09-02.

Replaces the geometric terminal model (`fl100Dist = 10000/318`, a straight line
flown on a fixed speed ladder) with the path an aircraft is actually going to
fly: the STAR for the landing runway, closed onto the approach, at the published
speeds and altitudes.

Origin: `docs/sessions/2026-09-02_star-truncation.md` and
`docs/sessions/2026-09-02_fal57-live-eta-comparison.md`. The short version is
that the last 40 nm is where the ELDT bias lives, and we were modelling it as a
straight line at invented speeds while the real geometry sat unparsed on disk.

## What we now hold

`bin/import-procedures.py` → `data/procedures-v2.json`, keyed by airport:

```json
{"CYYZ": {
  "stars":      {"RAGID6": {"variants": {"05": {...}, "23": {...}, "06B": {...}},
                            "transitions": {"TUKIR": ["TUKIR","LIBEM","IGSAP","UDNOX"]}}},
  "approaches": {"ILS06R": {"variants": {"-": {"fixes":[...], "end":{"rnw":"06R"}}},
                            "transitions": {"LESOD": ["LESOD"]}}},
  "fixes":      {"LOBKO": [43.xxxx, -79.xxxx]},
  "runways":    ["05","06L","06R", ...]
}}
```

Each variant is `{"fixes": [{name, alt?, alt_min?, alt_max?, speed?, hold?}, ...],
"end": {trk?, vectors?, rnw?}}`.

Across the seven airports: **216 runway variants, 138 transitions, 111 approach
variants, 990 terminal fixes, 391 speed and 485 altitude constraints.**

## Building the path

1. **Pick the runway.** From the ATIS (`PRIMARY IFR APPROACH ILS RUNWAY 06
   RIGHT`), which is in the VATSIM datafeed we already poll every 2 min and do
   not currently read. Falls back to the declared active config, then to the
   filed STAR's own variant.
2. **Select the STAR variant** for that runway, and the approach the ATIS names.
3. **Close the discontinuity**, in this order of preference:

   | case | rule | coverage |
   |---|---|---|
   | **direct** | STAR's last fix *is* the approach's first fix → concatenate | 59 / 212 |
   | **transition** | STAR's last fix is a named approach transition → splice it in | 29 / 212 |
   | **open** | synthesise a base leg (below) | 124 / 212 |

   So **42% of terminal paths are exact, built only from published data.**
   (That figure is a floor — the survey matched `06B` against `06L`/`06R` by
   string prefix, so some variants are probably misfiled as open.)

4. **Synthesise the base leg** for the open case. These end at a downwind fix
   with an explicit `TRK nnn VECTORS`, e.g. `CABOT5.32` ends at KIXIT on track
   143 with no matching 32-approach transition. The construction is bounded, not
   invented: fly the published track from the last fix, turn roughly 90°, and
   intercept the final approach course inbound to the FAF, whose position and
   altitude the approach gives us.

5. **Speeds and altitudes come from the constraints**, replacing the invented
   ladder. RAGID6 publishes 250 kt at LERAT (≤15000/≥11000) and 210 at KEVNO
   crossing 8000; today the model guesses 250/220/220/180/140 on a straight line
   regardless of procedure.

## Why this ordering

Steps 2–3 are **exact** and cover 42% immediately, with no modelling judgement
at all. Step 4 is where judgement lives and it deserves separate validation
against observed tracks — `/api/v1/debug/terminal-time` already measures real
transit from `position_scratch` and is unaffected by model changes, so it stays
the independent yardstick.

Doing them in that order means the exact part can ship and be measured before
the synthesised part introduces a new source of error.

## What this fixes

- **Defect 2** from the truncation log: speed bands allocated by `alt/318`
  geometry rather than by position in the procedure. This is the proper fix.
- **The runway dimension.** `RAGID6.23` is four fixes (RAGID DENKA DUGDA CALVY);
  `RAGID6.06B` is nine and runs the far side of the field. On 2026-09-02 a live
  flight was re-cleared 23 → 06R sixteen minutes before landing and its FMS
  moved 1947 → 1955. **The 8-minute penalty is exactly the difference between
  two variants we hold.**

## What it does not fix

The **vectored segment** after `VECTORS`, when ATC is actually vectoring. That
is genuinely unpredictable and belongs in the ATC-conditional term measured from
`had_atc_at_arrival` (populated since v0.7.59) and `arrival_runway` (recorded
since v0.7.61). The published path is the floor; controller intervention is the
variable part on top.

Expect the correction to be smaller for uncontrolled arrivals — the same flight
measured ~3.3 min of procedure geometry uncontrolled, and ~11 min once the
airport turned around.

## Caution carried forward

Validate on **segment budgets**, not the final ETA. FAL57 showed two wrong
components cancelling into a right-looking total: cruise optimism from filed TAS
against a GRIB headwind stronger than reality, agreeing at 1947 while both were
wrong. A single-number KPI cannot see that.
