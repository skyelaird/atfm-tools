# Transition to the Maestro web pipeline — guidance for Code

Written 2026-11-20 from Joel's `MAESTROsettings.txt`, the Maestro Developer
Guide v1.1, and read-only GETs against `https://maestro.joelmorin.com`.
Companion to `docs/AMAN-FRAMEWORK.md` (read that first). Nothing here was
tested against a live EuroScope session.

**Credentials:** the settings file contains the upload username and password
for the Maestro web server. They are deliberately **not** reproduced here or
anywhere in the repo. Nothing in P0-P2 below needs them: downloads need no
auth, and P2 publishes from our own host.

## 1. What already exists (verified)

- `maestro.joelmorin.com` (Apache, CORS `*`) serves a static viewer
  ("MAESTRO — CYHZ Arrival Sequence", polls every 30 s, stale after 600 s) and
  one JSON file **per airport**: `MAESTRO_sequence_data_<ICAO>.json`
  (`_CYHZ` and `_KBOS` exist; the unsuffixed `MAESTRO_sequence_data.json`
  currently mirrors CYHZ).
- Joel's Maestro runs master mode: `System_Web_Path_UL` = that host,
  `System_SaveInterval_Web=30`, upload auth on, download auth off. Slaves use
  `System_Web_Path_DL` with `System_LoadInterval_Web=30`.
- The live CYHZ file matches Developer Guide §2.2 exactly (`data`, `feeders`
  incl. a `"*"` floating feeder, `runways` with `active`/`rate`, `sequence`).
  `data.source` was `CZQM_CTR`, last written 2026-11-17 01:32Z, all rows
  `lost: true` (session ended).
- **The file is overwritten on every upload — no history is kept.**
  Whatever scores Maestro against reality must be archived by us.
- Viewer ETA = `initial_eta + delay_total`. `eet_ades` is the current
  runway time without delay; `eet_ff`/`eet_ff_to_rwy` split it at the feeder.

## 2. Phases

**P0 — harvest Maestro as the baseline (no EuroScope work, no credentials).**
Cron every 2 min in the existing cron pattern (`bin/harvest-maestro.php`):
GET `https://maestro.joelmorin.com/MAESTRO_sequence_data_<ICAO>.json` for each
configured airport (config, not hard-coded; start with CYHZ). Skip when
`data.timestamp` is unchanged, older than 600 s, or the row has `lost: true`.
Store one row per aircraft per distinct `data.timestamp` in a new table
(`maestro_baseline`): captured_at, data_timestamp, source, ades, callsign, atyp,
ff, rwy, state, `eet_ff` (min), `eet_ff_to_rwy`, `eet_ades`, `eet_rwy`,
`initial_eta`, `delay_total`, `sta_ff`, `ttlttg_ff`. Derive and store
`eta_rwy_nodelay = data_timestamp + eet_ades` and `eta_ff = data_timestamp +
eet_ff*60`. Join to `flights` ALDT by callsign + ades for scoring, using the
repo's `actual − predicted` sign convention. 2-min sampling against a 30-s
upload is fine for 40/20/10-min horizons. Because Maestro only runs when Joel
(or another controller) runs a master session, expect data only then — say
so on the QA page rather than showing an empty panel as a failure. Needs the
repo's migration rule (`bin/migrate.php`, idempotent).

**P1 — server-side predictor PoC** per `AMAN-FRAMEWORK.md` §3b, scored against
both ALDT and the P0 baseline, as segment budgets (error at feeder fix, error
feeder fix to threshold). This is the gate for P2.

**P2 — feed Maestro from our server (only if P1 beats the baseline).**
Unsupported by the guide ("may lead to unexpected results or crashes"), so:
1. Publish a generated `MAESTRO_sequence_data_<ICAO>.json` from the atfm host
   on a **separate test path**, never over the live files on
   `maestro.joelmorin.com`.
2. A tester points a slave's `System_Web_Path_DL` at the test path in a
   **sweatbox** session first. Rollback = revert that one settings line.
3. Validate output strictly against guide §2.2 before publishing: exact key
   names, numbers as numbers, callsigns case-sensitive, fresh `timestamp`,
   no extra or missing objects. A malformed file is the crash risk.
4. Our server must then produce the sequence, not just ETAs: `rwy_seq`,
   `sta_ff`, `ttlttg_ff`, `eet_*`, `delay_total`, slots, and `state`. Reuse
   the rate-based allocator for STA and slots (**not yet read**). State
   thresholds come from the settings: Frozen at 5 min to destination,
   SuperStable 6 min to feeder, Stable 15 min, otherwise Unstable.
5. **Unknown, test first:** whether slave mode recomputes ETAs from its own ES
   data or only displays the fields it receives. If it recomputes, P2 changes
   nothing and the plugin route (thin ES client) is back on the table.

## 3. Settings-file defects (for Joel to fix; not Code's job)

1. `RUNWAY:CYYT:23:120:*:10` sits under `[CYHZ]`; it should be `CYHZ`. The
   live JSON confirms the effect: CYHZ runway 23 shows the default rate of
   150 s instead of 120.
2. `RUNWAY:KBOS22R:80:*:10` is missing a colon after `KBOS`. Malformed data
   can crash ES per the guide; the plugin reports it in the "MAESTROplugin"
   chat tab at load.
3. The upload credentials sit in `MAESTROsettings.txt`, the shared
   common-settings file. The guide provides `MAESTROsettingsLocal.txt` for
   personal settings; move them there so a package refresh or a copy to
   another controller cannot carry them. They were also pasted into a chat
   upload, so consider rotating the password.

## 4. Weak early datapoint (do not act on it)

In the 2026-11-17 file, `eet_ff_to_rwy` for CYHZ runs 585-795 s (about
10-13 min) across seven aircraft. Our observed 40 nm ring to wheels at CYHZ is
about 13.1 min (lag-adjusted). Different start points and n=7, so this is not a
bias estimate. P0 data replaces it.
