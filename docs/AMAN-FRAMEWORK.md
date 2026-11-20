# AMAN framework — handoff to Code

**Status:** plan only, nothing built. Written 2026-11-19 in a chat session with
Joel; read-only against the repo (docs, JSON data) — **the PHP was not read**.
Treat every statement about `src/` as a pointer to verify, not a finding.

## 1. Requirements (start here, not with candidates)

- **Who:** VATCAN controllers at the 7 scoped airports
  (`CYHZ CYOW CYUL CYVR CYWG CYYC CYYZ`), eventually; Joel (CZQM vACC Ops
  Lead) first, at CYHZ.
- **Mission:** tactical arrival metering at ~40 min horizon (`PT40M`),
  frozen at `PT10M` — the layer between CTOT slots (90 min) and radar
  (`docs/AMAN-DMAN.md` §1).
- **Gap:** ETO quality. aman-dman falls back to groundspeed extrapolation
  when no profiles are configured (`docs/AMAN-DMAN.md` §5); the older
  EuroScope AMAN plugins use EuroScope's own predictions. The measured
  problem is in the last 40 nm, not cruise
  (`CLAUDE.md` → "ELDT bias attributed").
- **Constraint:** vectoring inside the terminal area is unpredictable.
  Do not model it; calibrate it as a distribution and make it
  ATC-conditional (`had_atc_at_arrival`, `arrival_runway` already recorded).
- **North Star check (`CLAUDE.md`):** must improve slot allocation or operator
  situational awareness around inbound load. A new sequencer UI is *new
  scope* — needs Joel's explicit go-ahead; it is not assumed here.

## 2. What already exists (so we do not rebuild it)

| Need | Already in repo |
|---|---|
| Decoded STARs with per-runway variants, alt/speed constraints, vector ends | `data/procedures-v2.json` (216 runway variants, 391 speed and 485 altitude constraints), from `bin/import-procedures.py` |
| Feeder/metering fixes per airport | `data/star-catalog.json`, `src/Allocator/MeteringFix.php` (36 fixes, 7 airports) |
| Designed terminal path model | `docs/TERMINAL-PATH-MODEL.md` (designed, **not built**; fixes "defect 2") |
| Measured terminal excess, minutes to add to last 40 nm | `CLAUDE.md`: CYUL +5.8, CYYZ +5.0, CYVR +4.7, CYHZ +3.6, CYOW +3.3, CYWG +2.7, CYYC +2.1 |
| Landing detection, runway detection, ELDT QA | ingest + `RunwayThreshold`, `/api/v1/debug/landing-lag`, `/debug/terminal-time` |
| ESE parsing tooling | `bin/26e-ese-*.py` |
| Controller-side timeline display | aman-dman (EvenAR), configured for all 7 airports, **feeder fixes / profiles / sequencing areas empty** (`docs/AMAN-DMAN.md` §5) |

## 3. Decision: three tiers, stop at the first that closes the gap

**Tier A — configure, don't build.** aman-dman already has config slots for
feeder fixes, feeder-fix transit times per runway, and arrival profiles
(speed/altitude per STAR fix). Write `bin/gen-aman-config.py` to emit those
YAML sections from `procedures-v2.json` (profiles) and from observed transit
times (see §4). This gives a STAR-aware, calibrated prediction inside the
tool controllers already run, with no new application.

> **Verify first:** the YAML keys come from `docs/AMAN-DMAN.md`
> (2026-04-16), not from upstream. Read upstream aman-dman's schema and
> confirm what `arrivalProfiles` and `feederFixTransitTimesMinutes` actually
> change in its ETO before generating anything. If profiles only affect
> display, Tier A collapses to transit times alone.

**Tier B — build `TERMINAL-PATH-MODEL.md`.** Already the top ETA priority in
`CLAUDE.md`. Improves ELDT for CTOT and gives the ETO source for any later
AMAN. Do the exact part (direct + transition closures, 42% of paths) first and
measure it before the synthesised base leg.

**Tier C — own AMAN/sequencer.** Only if A+B leave a measured gap. Not
recommended now.

## 3b. Direction set by Joel (2026-11-19) — supersedes the tier order above where they conflict

The end product is an **EuroScope plugin fed by our server**. The first step is
a **proof of concept that predicts runway times, run on demand on the server**.
So:

1. **PoC = server only, no plugin.** `GET /api/v1/aman/predict?airport=XXXX`
   (name tentative). On each request: fetch the public feed (cache ~15 s —
   this gives fresh positions without a sub-minute cron, which hosting cannot
   run), select inbounds within ~200 nm, and per flight:
   - infer STAR + runway (filed route suffix such as `NUBER6/24L`, ATIS,
     declared active config — in that order),
   - build the path from `procedures-v2.json` (`TERMINAL-PATH-MODEL.md`),
   - predict time over the feeder fix and over the threshold, P50 and P80,
     using the ATC-conditional terminal correction,
   - flag off-route (cross-track vs predicted path) and fall back to
     direct-to-FF at lower confidence,
   - also compute a **baseline** that mimics EuroScope: groundspeed
     extrapolation along the filed route.
   Persist every prediction (flight, horizon, source, P50/P80) so it can be
   scored against ALDT. **That scoring table is the PoC's verdict**: error at
   40/20/10 min before landing, by airport, model vs baseline vs current ELDT,
   as segment budgets.
2. **Sequencing / TTL-TTG** reuses the rate-based allocator rather than a new
   sequencer: TTL/TTG = slot time − live ETA. Needs reading
   `CtotAllocator` and `active_arr_rate` handling — **not yet read**.
3. **Plugin = thin client.** Polls the endpoint (the CDM plugin already
   consumes this server's feeds, so there is a precedent contract), shows
   tag items (ETA, TTL/TTG, off-route flag). The one thing only a plugin does
   is put these in ES radar labels; a timeline view can be a web page in
   `public/` (vanilla JS, no build) first. Plugin is the hardest piece to test
   — the CDM plugin round trip is still the open 0.7 → 1.0 gate — so build it
   last, after the PoC shows the predictor is worth displaying.
4. **Do not feed Maestro/aman-dman.** Maestro computes its own ETAs from
   ES; no documented channel lets us substitute ours. aman-dman profiles and
   transit times (Tier A) remain a cheap interim, subject to the upstream
   schema check.

## 4. Measurement before more building

1. **Segment budgets, not final ETA** (per `TERMINAL-PATH-MODEL.md` caution):
   error at metering fix, error fix → threshold, split by phase.
2. **Cadence.** Ingest is a 2-min cron; at ~450 kt that is ~15 nm between
   samples. Sub-minute cadence was retired as undeliverable on shared hosting
   (`CLAUDE.md` → "Retired ideas"). So high-rate capture must live *outside*
   the PHP cron: `bin/aman-feed-logger.py` (this branch) polls the public feed
   every 15 s for one airport and writes daily CSVs. Run it on a PC or small
   always-on box; do not put it in cron.
3. **Retention.** `position_scratch` is purged at 48 h. Add a daily rollup
   (pattern: `compute-demand-history.php`) that keeps, per arrival: callsign,
   type class, STAR, runway, feeder-fix crossing time, ALDT, `had_atc_at_arrival`.
   Transit-time tables need weeks of this, not 48 h.
4. **Transit-time table** keyed `(airport, runway, STAR/fix, type class,
   had_atc)`: median for the nominal, P80 for the spacing buffer. Feeds Tier A
   directly.

## 5. Ordered tasks for Code

1. Read `src/Allocator/{EtaEstimator,WindEta,MeteringFix}.php`,
   `src/Ingestion/VatsimIngestor.php`, `src/Models/PositionScratch.php`;
   correct anything in this document that the code contradicts.
2. Daily rollup of feeder-fix crossing and terminal times (§4.3). Shadow/QA
   column only, per the repo's gate rule for `src/`.
3. Transit-time report: P50/P80 by the §4.4 key; compare to the
   `CLAUDE.md` terminal-excess table as a sanity check.
4. Verify aman-dman's schema upstream; write `bin/gen-aman-config.py`;
   generate for CYHZ first.
5. Tier B: terminal path model, exact closures first.
6. Re-measure. Only then decide on Tier C.

## 6. Open questions for Joel

- Is a sequencer (Tier C) in scope for atfm-tools, given "not a generalised
  flow management platform"?
- Run the logger where? (PC vs the always-on box used for other collectors.)
- Which airports after CYHZ? CYYZ has the traffic volume for calibration
  (a live snapshot showed 15 inbounds within 150 nm of CYYZ against 1 at CYHZ).

## 7. STAR source and the CZQM sector file

STAR geometry and constraints come from Navigraph/PMDG via
`procedures-v2.json`, not from the sector file. The SCT/ESE is still useful as
a **cross-check of what EuroScope believes the path is** (ESE
`[SIDSSTARS]` defines the STAR fix sequences EuroScope predicts along, and
carries no altitude/speed constraints). A diff between the ESE STARs and
`procedures-v2.json` per airport/runway shows where EuroScope-based predictions
(Maestro, euroscope-aman) diverge from the published procedure. The CZQM sector
file was not in the folder available during this session
(`CZQM-FIR/TestSectorFiles` is empty).
