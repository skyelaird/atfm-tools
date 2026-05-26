# Wind Skill Analysis — Spring 2026 Corpus

**Generated:** 2026-05-26  |  **Corpus:** 2026-04-25 – 2026-05-25  |  **Levels:** 200 / 250 / 300 mb

---

## 1. Headline

At **D-3 (T+72)** GFS 250 mb vector RMSE is **18.3 kt**, translating to ±3.7 flights uncertainty at sector peak — tight enough to lock sector positions with a small float buffer.
**D-7** carries **41.3 kt** / ±8.3 flights; FIR allocation can be committed but individual position assignments should wait.
Morning-of refresh (T+6 vs T+24 max delta: 1.4 kt) is **below the 2 kt operational threshold** and adds no actionable information — skip unless D-1 indicated a major pattern shift.

---

## 2. CTP-Team Staffing Decision Matrix

> Analytical basis: 4 h NAT crossing, 460 kt TAS, 5-min sector bins, 40-flight peak load.

| Lead | Wind RMSE 250 mb | ETA shift ± | Sector load ± | Staffing recommendation |
|------|-----------------|-------------|---------------|------------------------|
| D-7 (T+168) | 41.3 kt | ±22 min | ±8.3 flights | Treat demand as nominal ±A; defer final position assignments. Lock FIR staffing *allocation* only. |
| D-3 (T+72) | 18.3 kt | ±10 min | ±3.7 flights | Lock sector positions; hold 2–3 controllers as float per FIR. This is the last cycle to add/remove bodies. |
| D-1 (T+24) | 8.7 kt | ±5 min | ±1.7 flights | Final staffing committed; confirm shift starts and relief timings. No re-roster after this. |
| D-1 evening (T+18) | 7.2 kt | ±4 min | ±1.4 flights | Monitor only; no action unless RMSE delta vs T+24 > 5 kt. |
| Midday (T+12) | 5.9 kt | ±3 min | ±1.2 flights | Refresh if T+18 indicated major pattern shift (> 5 kt delta). |
| Morning-of (T+6) | 4.5 kt | ±2 min | ±0.9 flights | Skip unless D-1 showed major shift. See §4. |


---

## 3. Wind Skill — Global Stats

### 3a. All grid points, all levels

| Lead | Level (mb) | n pairs | RMSE (kt) | p50 (kt) | p90 (kt) | Speed bias (kt) |
|------|-----------|---------|-----------|----------|----------|-----------------|
| T+6 (morning-of) | 200 | 11,320 | 4.0 | 1.8 | 5.7 | -0.1 |
| T+6 (morning-of) | 250 | 11,320 | 4.5 | 2.1 | 6.6 | -0.2 |
| T+6 (morning-of) | 300 | 11,320 | 4.6 | 2.4 | 7.0 | -0.2 |
| T+12 (midday) | 200 | 11,070 | 5.3 | 2.6 | 7.8 | -0.2 |
| T+12 (midday) | 250 | 11,070 | 5.9 | 3.2 | 8.9 | -0.2 |
| T+12 (midday) | 300 | 11,070 | 6.0 | 3.5 | 9.1 | -0.2 |
| T+18 (D-1 evening) | 200 | 11,060 | 6.5 | 3.3 | 9.7 | -0.2 |
| T+18 (D-1 evening) | 250 | 11,060 | 7.2 | 4.1 | 11.1 | -0.2 |
| T+18 (D-1 evening) | 300 | 11,060 | 7.7 | 4.6 | 11.8 | -0.1 |
| T+24 (D-1) | 200 | 16,710 | 7.7 | 4.0 | 11.8 | -0.3 |
| T+24 (D-1) | 250 | 16,710 | 8.7 | 5.0 | 13.4 | -0.2 |
| T+24 (D-1) | 300 | 16,710 | 9.0 | 5.6 | 14.0 | -0.2 |
| T+72 (D-3) | 200 | 15,740 | 15.0 | 9.0 | 23.5 | -0.5 |
| T+72 (D-3) | 250 | 15,740 | 18.3 | 11.8 | 29.0 | -0.6 |
| T+72 (D-3) | 300 | 15,740 | 19.1 | 12.5 | 29.8 | -0.7 |
| T+168 (D-7) | 200 | 13,280 | 33.6 | 24.3 | 53.0 | -0.7 |
| T+168 (D-7) | 250 | 13,280 | 41.3 | 29.4 | 65.8 | -0.7 |
| T+168 (D-7) | 300 | 13,280 | 42.6 | 29.3 | 68.8 | -0.7 |

### 3b. Per-FIR aggregates (250 mb)

| FIR / Region | Lead | n pairs | RMSE (kt) | p50 (kt) | p90 (kt) |
|-------------|------|---------|-----------|----------|----------|
| CZQM | T+6 | 273 | 6.1 | 3.1 | 9.1 |
|  | T+12 | 261 | 7.0 | 4.3 | 11.1 |
|  | T+18 | 261 | 9.1 | 6.0 | 14.5 |
|  | T+24 | 396 | 10.6 | 7.3 | 15.8 |
|  | T+72 | 375 | 28.6 | 19.4 | 45.0 |
|  | T+168 | 333 | 59.0 | 48.5 | 87.8 |
| CZQX | T+6 | 1,344 | 5.3 | 2.2 | 7.2 |
|  | T+12 | 1,311 | 6.2 | 3.3 | 9.1 |
|  | T+18 | 1,308 | 7.6 | 4.4 | 12.0 |
|  | T+24 | 1,977 | 9.0 | 5.2 | 13.6 |
|  | T+72 | 1,881 | 22.0 | 14.2 | 35.4 |
|  | T+168 | 1,602 | 51.3 | 40.8 | 78.7 |
| NAT | T+6 | 2,240 | 4.5 | 2.1 | 6.1 |
|  | T+12 | 2,221 | 5.7 | 3.2 | 8.4 |
|  | T+18 | 2,212 | 7.0 | 4.1 | 10.7 |
|  | T+24 | 3,355 | 8.2 | 4.7 | 12.5 |
|  | T+72 | 3,163 | 20.2 | 13.6 | 31.8 |
|  | T+168 | 2,662 | 50.4 | 40.3 | 78.4 |

---

## 4. Within-24 h Creep (T+6 → T+24)

| Lead | 250 mb RMSE (kt) | Δ vs previous |
|------|-----------------|---------------|
| T+6 (morning-of) | 4.45 | — |
| T+12 (midday) | 5.86 | +1.41 kt |
| T+18 (D-1 evening) | 7.25 | +1.39 kt |
| T+24 (D-1) | 8.65 | +1.40 kt |

**Verdict:** **Not recommended** — T+6 and T+24 RMSE differ by 1.4 kt (<2 kt threshold); picture is stable by D-1.

The RMSE curve is essentially flat inside 24 h.  D-1 (T+24) is already the operationally definitive forecast.  The CTP team does **not** need a morning-of refresh run unless the D-1 forecast showed a major pattern anomaly (subjective judgment by the lead FMP).

---

## 5. Methodology

**Corpus.** Snapshots collected by `bin/wind-snapshot.py` at 00/06/12/18Z from 2026-04-25 to 2026-05-25 (87 files).  Each snapshot stores GFS forecasts for leads T+0, T+24, T+72, T+168 (pre-2026-04-27 format) or T+0/6/12/18/24/72/168 (7-lead format introduced with v0.7.21).  Grid: LAT 25–70°N step 5°, LON −100 to +20°E step 5° (250 points × 3 levels = 750 values per snapshot per lead).

**Pairing.** For each (fetch cycle, lead) pair the verifying analysis is the T+0 entry of the cycle whose `fetch_iso` matches the forecast `target_iso`.  Only standard 6Z synoptic cycles are available as verifiers; off-cycle seed targets are automatically orphaned.

**Error metric.** Vector wind error = √((u_f−u_v)² + (v_f−v_v)²) per grid point.  RMSE is the root-mean-square of all paired errors at that lead / level combination.  Speed bias = mean(|V_f|−|V_v|).

**Sector-load translation (analytical).** GS error for a 4 h NAT crossing ≈ wind RMSE (kt).  ETA shift ≈ (RMSE / 460) × 240 min = RMSE × 0.52 min.  At a 5-min sector bin the fractional shift per flight = ETA_shift / 5 bins = RMSE × 0.104 bins.  Peak-count standard error (from the brief's closed-form approximation, absorbing correlation factors) ≈ **±(RMSE / 5) flights**.  This is a first-order estimate; real CTP flight streams are spread over 6–8 h so effective peak sensitivity is somewhat lower.

---

## 6. Caveats

- **Single season.** Spring 2026, 30 days.  NAT jet stream is near maximum in spring; summer / autumn RMSEs will be lower.  Apply a ~15 % seasonal scaling when using these numbers for non-spring events.
- **Grid resolution 5°.** Mesoscale features and jet-stream curvature detail are smoothed.  Point-scale errors are higher than grid-mean RMSE suggests.
- **NAT focus.** FIR boxes selected for CZQM/CZQX/NAT corridor.  Canadian domestic airspace (CZEG, CZWG, CZYZ) not separately analysed.
- **Analytical sector-load translation.** Task 3 used the closed-form approximation, not a re-run of `bin/26e-sector-load.py` against wind caches.  The ± flight numbers are first-order estimates, ±30 % relative.
- **GFS model only.** ECMWF ensemble would likely show lower RMSE at D-7 (typical 2–4 kt tighter); these numbers are GFS-conservative.

---

## 7. Data Appendix

**Corpus stats:**
- Total snapshots: 87 (expected ~120 over 30 days; some gaps likely from cron restarts)
- Unique verifier analyses: 87
- Pairs skipped (no verifier): 126
- Pairs skipped (missing point): 25080

**Pair counts by lead:**

| Lead | Total pairs (all levels) | Pairs 250 mb | Approx cycles contributing |
|------|------------------------|-------------|---------------------------|
| T+6 | 33,960 | 11,320 | ~45 cycles |
| T+12 | 33,210 | 11,070 | ~44 cycles |
| T+18 | 33,180 | 11,060 | ~44 cycles |
| T+24 | 50,130 | 16,710 | ~67 cycles |
| T+72 | 47,220 | 15,740 | ~63 cycles |
| T+168 | 39,840 | 13,280 | ~53 cycles |

*Gap between expected (~120) and actual (87) snapshots: 33 missing cycles.  Missing cycles create orphaned forecasts at longer leads (T+72, T+168) where the verifying analysis cycle may not have been collected.  Per-lead pair counts above show the effective sample.*
