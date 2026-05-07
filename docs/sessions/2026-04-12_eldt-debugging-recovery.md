# Session recovery — ELDT debugging (2026-04-12 night ops)

Reconstructed from `D:\Claude.ai\ATFM\ATFM chat.txt` (partial transcript
of a chat that died from the 4K-screenshot dimension limit). Date inferred
from project memory of the same day.

## What shipped

Three production fixes landed:

| Commit | Fix | Severity |
|--------|-----|----------|
| `e28e4dd` | CRITICAL: wrong array keys in AOBT geofence check (lat/lon key typo froze all positions) | Critical |
| `9fc2b04` | Include TAXI_IN/ON_RUNWAY/VACATED in recent_arrivals (was only ARRIVED, leaving landed-but-taxiing flights in limbo between inbound and recent_arrivals) | High |
| `e76d364` → `231de0f` | AOBT stamping: at GS>0 (taxi roll), not at pushback (sims don't jitter, GS=0 means parked) | Medium |

Released as v0.5.12. Repo has shipped through to v0.7.31+ since;
these fixes are buried in history but their root-cause narrative is
preserved here because commit messages capture the *what*, not the
*why we got here*.

## Root-cause narratives

### The lat/lon key typo (e28e4dd)
The geofence check was reading wrong array keys, which silently froze
every aircraft's position. Symptom presented as ELDT errors of -1413 min
on flights like JZA8446 — completely garbage data because positions
weren't updating. Synthetic ALDTs got stamped at the freeze moment.
Once fixed, real position-driven ELDT calculations resumed.

### The TAXI_IN limbo (9fc2b04)
The inbound query correctly excluded ARRIVED/WITHDRAWN/DISCONNECTED/
TAXI_IN/ON_RUNWAY/VACATED. But recent_arrivals was filtered to *only*
ARRIVED. Net effect: a flight that had landed (ALDT set) and was
taxiing to gate (TAXI_IN phase) appeared in *neither* list — invisible
to the operator. Fix expanded recent_arrivals to include any phase
where landing had occurred.

### AOBT timing (e76d364 → 231de0f)
Initial implementation stamped AOBT at first detection of pushback
(GS > 0 from a stand). Refined to require GS reaching taxi-roll speed
because some sim ground-handling jitters report low non-zero GS during
pushback. The follow-up commit (231de0f) further clarified: sims don't
add the kind of GPS jitter that real aircraft do — GS=0 is genuinely
parked, GS>0 is genuinely moving. Threshold simplified back to GS>0
for the AOBT stamp.

## ELDT accuracy data captured

CYYZ inbounds during the session:

| Flight | Route | Frozen ELDT | ALDT | Error | Note |
|--------|-------|-------------|------|-------|------|
| ROU1720 | KPHX→CYYZ | 01:28 | 01:30 | +1 min | Clean post-fix |
| KPY1922 | CYXE→CYYZ | — | — | +5 min | Clean |
| ACA740 | CYVR→CYYZ | 01:35 | corrupted | ~0 min lock | Lock perfect; ALDT corrupted by pre-fix freeze bug |

Conclusion: model is solid; pre-fix infrastructure bugs were the main
source of "bad" accuracy numbers, not the prediction model itself.

## Operational findings

- **Real CYYZ taxi-in on shortest route: 4–5 min**, vs configured
  `default_exit_min` of 12 (airport-wide average). Per-runway/terminal
  distribution would tighten this.
- **VATSIM pilots commonly disconnect on the ramp** before reaching
  in-block, so AIBT often never stamps. Common pattern; not a bug.
- **VFR flights aren't tracked** (no IFR plan to a tracked airport).

## OOOI terminology (confirmed canonical)

| Acronym | Meaning | Internal name |
|---------|---------|---------------|
| Out | Off blocks (gate departure) | AOBT |
| Off | Airborne (wheels up) | ATOT |
| On | Landed (wheels down) | ALDT |
| In | In blocks (gate arrival) | AIBT |

## What was set up but didn't survive

A 5-min cron was scheduled to watch CYVR overnight inbounds (CYFG,
JZA817, CRK081). **Cron jobs scheduled by Claude do not persist past
the session that created them.** This watcher died with the session.

For unattended monitoring, use server-side scheduled tasks (the same
mechanism that drives `bin/deploy.sh` and the wind snapshot refresh),
not Claude's session cron.

## Why this session died

Claude's `screenshot` tool captured the active monitor at native
resolution. On a 4K display every shot was 3840×2160, exceeding the
2000px many-image API limit. Once one too-large image is in the
conversation history, every subsequent turn fails with *"An image in
the conversation exceeds the dimension limit"* — the session is
unrecoverable, only the transcript is readable.

**Mitigation for future screen-capture sessions:** drop the captured
monitor to 1920×1080 in Display settings before starting, or avoid
`screenshot` entirely and work from a single capture via `zoom()` for
detail.
