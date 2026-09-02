# Per-flight uncertainty model — Westbound CTP 2027

**Status:** design, not implemented. Prototypes shipped as
`public/26e-{1,2,3}.html` use the *wrong* (curve-level) model — see
"What we have today" below.

**Inputs now available:** the spring-2026 wind corpus is analysed
(`docs/wind-skill-2026-spring.md`), so the σ_grid term is no longer a
placeholder: 250 mb vector RMSE is 4.5 kt at T+6, 8.7 kt at T+24,
18.3 kt at T+72, 41.3 kt at T+168.

Migrated out of Claude's memory store 2026-09-02 — design belongs in the
repo, where it survives without me.

---

## Goal

For Westbound CTP 2027 demand-curve planning, deliver per-sector
**uncertainty envelopes** that tighten as forecast lead shrinks
(D-7 → D-3 → D-1 → event-day).  Per-flight Monte Carlo, NOT
curve-level smoothing.

## Why curve-level smoothing falls short

Current `26e-1.html / -2.html / -3.html` approximate this with a
Gaussian convolution of the predicted demand curve at a single
global σ (calibrated naively to flight-time-to-sector). The
flaws:

- Single σ across all flights misses heterogeneity (KDFW is
  4-5h flight, CYYZ is 2-3h)
- Linear σ ∝ time scaling is wrong — wind errors decorrelate
  spatially, so σ scales as √(time/correlation_time)
- Curve-level smoothing assumes Gaussian timing distribution
  for every flight individually; reality is each flight has its
  own σ
- Doesn't distinguish "correlated curve-shift" (if all flights see
  the same forecast bias) from "independent flight jitter"

## The right model

Monte Carlo per flight at sim time:

```
For each booked flight i:
    σ_i = effective_eta_sigma(
        wind_RMSE_at_lead,         # from skill curve corpus
        flight_time_to_sector,     # from filed route / CTOT
        spatial_corr_length,       # ~500 km
        cruise_GS,                 # ~460 kt
    )
    For sample k in {-σ_i, 0, +σ_i}:    # or full N=100 MC
        Trace flight i through sectors with launch shift = k
        Record (sector, bin) hits

Aggregate per (sector, bin):
    lower(s, t)   = count of flights where sample -σ_i hits (s, t)
    nominal(s, t) = count of flights where sample 0 hits (s, t)
    upper(s, t)   = count of flights where sample +σ_i hits (s, t)
```

Rendered: nominal as line, [lower, upper] as filled band per
sector per time bin.

## Effective σ formula

For each flight, the realistic ETA error at sector entry depends
on cumulative wind-error exposure along its trajectory. A
back-of-envelope:

```
σ_ETA(flight) = σ_grid_RMSE × √(t_flight × GS / L_corr) / GS × 60
              = σ_grid_RMSE × √(t_flight / (L_corr / GS)) / GS × 60 min
```

Where `L_corr / GS` is the time spent inside one spatial
correlation cell (~0.6 hr at NAT cruise). For NAT-corridor flights:

| Flight time | σ_ETA at T+72 (σ_grid 17 kt) |
|---|---|
| 2h | ~3 min |
| 3h | ~4 min |
| 4h | ~4.5 min |
| 5h | ~5 min |

Substantially smaller than the linear scaling I used in the
prototypes (which assumed full correlation across the flight,
giving 9 min for 4h). Real flights see ~5 spatial decorrelation
cells per crossing, sqrt(5) ≈ 2.2× reduction in ETA error.

## Implementation (Westbound 2027 prep)

Modify `bin/26e-sector-load.py`:

1. Add CLI args: `--lead-hours N` (forecast lead) and
   `--sigma-grid-kt X` (wind RMSE at that lead, from corpus)
2. For each flight:
   - Compute `t_flight_to_first_sector` (existing data)
   - Derive `σ_ETA_minutes` per the formula above
   - Optional: 3-sample (−σ, 0, +σ) or full MC with 100 samples
3. Each sample shifts the flight's CTOT by the sample value
4. Aggregate sector-bin counts across samples
5. Output sector-load.json with `load`, `load_low`, `load_high` arrays

HTML viewer reads the three arrays, renders nominal line + filled
[low, high] band per sector. No more single-sigma global hacks.

## What we have today (prototypes)

Already shipped (reversible): `public/26e-{1,2,3}.html` apply
curve-level Gaussian smoothing with a per-sector linear σ
calibration. Useful for visualizing the *concept* of forecast
uncertainty bands but not the right model for production.

When the per-flight MC implementation lands, these pages can
either be removed or repointed at the proper sector-load.json
output (with `load_low/load_high` arrays).

## Cross-reference

- `reference_nat_separation.md` — operational separation context
- `project_wind_skill_collection.md` — the corpus that feeds σ_grid
- `docs/nat-fl-allocation.md` — capacity model the bands inform
- May 26 analysis agent (`trig_01SFDjYk7HDQA7hojgtqzubF`) will
  produce the σ-vs-lead skill curve we need for the σ_grid input
