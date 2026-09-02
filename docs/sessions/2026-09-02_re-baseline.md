# 2026-09-02 — Re-baseline after a summer away

## Why this entry exists

The project ran unattended from late May to September: cron kept ingesting,
scheduled tasks kept committing wind and 26E refreshes, and nothing human
touched it. Coming back cost a full re-derivation of state from `git log`,
which is exactly what `CLAUDE.md` is supposed to prevent. Recording what
rotted, so the next gap is cheaper.

## What had rotted

- **`CLAUDE.md` version table said 0.6.0 was current.** Prod was 0.7.35 —
  364 commits and two shipped capabilities (non-event CTOT portal, 26E
  event tooling) behind. Fixed by adding a "Where we are" block at the top,
  to be updated at the end of each session.
- **Ten untracked files**, including half the 26E analysis tooling
  (`26e-corridor-demand.py`, `26e-brest-comparison.py`, `paper-figures.py`).
  Untracked work is the most fragile state in the repo and it was the exact
  workstream being resumed. Committed as `863a65c`.
- **The memory store had become a second, worse copy of the docs** — 61
  files, ~40 of them project facts that the repo already told better and
  that had silently gone stale (v0.5.24 described as "live", a wind-skill
  collection described as pending that had completed in May). Pruned to 22:
  working style, judgment calls, and pointers to things outside the repo.
  Two genuine designs were migrated into the repo rather than deleted —
  `docs/w27-uncertainty-model.md` and DESIGN.md §6b.

## The rule that came out of it

If a fact would matter to someone reading the repo without Claude, it goes
in the repo. If it only matters to how Claude should work with Joel, it goes
in the memory store. Applied consistently, the memory store stays small
enough to stay true.

## PERTI is dead

Parked by its owner over hosting cost; `perti.vatcscc.org` answers
`503 {"error":"Service suspended","mode":"freeze"}`. Never a production
dependency — we always ingested VATSIM directly — so the cost was one QA
comparison column. Removed the live fetch, the embedded SWIM key, the
dashboard match-rate pill; repurposed `public/perti.html` as our own ELDT QA
page. **Schema compatibility is a separate thing and still stands:** the
PERTI-shaped field names on `/api/v1/flights` are the CDM plugin wire
contract, not a dependency on PERTI being alive.

Negative result worth keeping: the three-way ELDT comparison (ours / GRIB /
PERTI) never changed a decision. SimBrief remains as the one external
comparator and it is advisory only — dispatch OFPs are filed hours before
pushback, and its 7-day median error is worse than ours.
