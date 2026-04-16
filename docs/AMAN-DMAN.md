# aman-dman Plugin — Operational Guide for CZQM / CZQX

> EuroScope AMAN/DMAN plugin by EvenAR.  This guide maps the plugin's
> concepts to our operational thinking.  It assumes you've used EuroScope
> and understand arrival sequencing; it does **not** repeat the upstream
> wiki verbatim.

---

## 1. What It Does

aman-dman gives you a vertical timeline strip — one per runway (or per
feeder fix) — showing inbound traffic sorted by estimated threshold
time.  Each label carries TTL/TTG advisories so you (or the approach
controller) know how many minutes of delay to build or recover.

It does **not** assign CTOTs.  It does **not** talk to atfm-tools.
It is a **local EuroScope display aid** — think of it as a tactical
AMAN scope inside your radar client.

### Where it fits in our stack

| Layer | Tool | Horizon | Output |
|-------|------|---------|--------|
| Strategic | atfm-tools FSM | hours ahead | demand/capacity picture |
| Pre-tactical | atfm-tools CTOT allocator | 90 min | slot times (CTOT) |
| Tactical | **aman-dman** | **40 min** | TTL/TTG speed advisories |
| Execution | EuroScope radar | now | vectors, speed, sequences |

The 40-minute sequencing horizon is deliberate: at that range you're
past the CTOT assignment point and into "make the sequence work with
speed control."  Anything further out is noise on a VATSIM session.

---

## 2. Core Concepts

### Sequencing Horizon (PT40M)

Aircraft appear on the timeline when their estimated threshold time is
within 40 minutes.  Before that they're invisible to the plugin — the
strategic layer (FSM, CTOT) handles them.

### Locked / Frozen Horizon (PT10M)

Inside 10 minutes to threshold, the landing order is **frozen**.  Labels
lock in place; drag-to-reorder is disabled.  This matches real-world
practice: inside ~10 nm final you don't re-sequence, you absorb.

### TTL / TTG (Time to Lose / Time to Gain)

The plugin computes each aircraft's Scheduled Time Over (STO) at the
threshold versus its Estimated Time Over (ETO) from current ground
speed and track.

- **TTL +3** = aircraft is 3 minutes early → needs delay (reduce speed,
  extend downwind, add a vector)
- **TTG -2** = aircraft is 2 minutes late → needs to catch up (direct
  routing, higher speed)
- Blank = on schedule (within tolerance)

This is the primary operational output.  When you see TTL +4 on a label,
you're telling approach "slow this one down, he's 4 minutes ahead of
his slot."

### Feeder Fixes

A feeder fix is a STAR entry point or TMA boundary fix.  The plugin can
show a **feeder-fix timeline** — aircraft sorted by their time over that
fix instead of over the runway threshold.  Useful when you're metering
at a specific point (e.g., holding at EBONY for CYHZ 05 arrivals).

We haven't configured feeder fixes yet — the `feederFixBased: []` arrays
in timelines.yaml are empty.  Phase 2 if we want upstream metering
displays.

### Independent Runway Systems

For parallel operations (CYYZ, CYUL, CYVR, CYYC), the plugin needs to
know which runways are independent so it sequences them separately.
Example from CYYZ:

```yaml
independentRunwaySystems:
  - [06R, 24L]   # north parallel
  - [06L, 24R]   # south parallel
```

Aircraft landing 06R are sequenced independently from aircraft landing
06L — no stagger constraint between the two streams.  Without this
declaration, the plugin would try to deconflict across both runways as
if they were dependent.

### Drag to Reorder

Outside the frozen horizon, you can drag a label up or down the timeline
to change landing order.  The label locks at its new position (shown
with a filled square **■** symbol).  TTL/TTG recalculates for all
affected aircraft.

This is how you handle "I need to get the medevac in first" or "swap
these two, the heavy needs more final."

### Direct Routing Indicator

When you clear an aircraft direct to the IAF/IF (skipping STAR fixes):

- On the **feeder-fix timeline**: label shifts from STO to ETO position;
  TTL/TTG hides (no longer meaningful at the fix)
- On the **runway timeline**: label stays at threshold STO; TTL/TTG
  still shows
- A triangle **▶** marker appears pointing toward the label
- ETO now uses ground speed instead of profile speed

---

## 3. Our Configuration

### Files

All configs live in:
```
%APPDATA%\EuroScope\TS_Beta\plug-ins\aman-dman\config\
```

| File | Purpose |
|------|---------|
| `airports/CYHZ.yaml` | Airport location, runway thresholds, horizons |
| `airports/CYOW.yaml` | (same for each of 7 airports) |
| `airports/CYUL.yaml` | |
| `airports/CYVR.yaml` | |
| `airports/CYWG.yaml` | |
| `airports/CYYC.yaml` | |
| `airports/CYYZ.yaml` | |
| `timelines.yaml` | Timeline views — which runways, left/right split |
| `settings.yaml` | Label layouts, connection config |

### Horizons

All 7 airports use:
- **sequencingHorizon: PT40M** — 40 minutes (tactical)
- **lockedHorizon: PT10M** — 10 minutes (frozen)

### Timeline Views

Each airport has per-runway timelines for single-runway ops, plus
combined views for parallel ops.  Examples:

| Airport | View | Left | Right | Use case |
|---------|------|------|-------|----------|
| CYHZ | HZ 05 | — | 05 | Single-runway 05 ops |
| CYHZ | HZ 23 | — | 23 | Single-runway 23 ops |
| CYYZ | YZ 24 ALL | 24R, 23 | 24L | West-flow parallel + crosswind |
| CYYZ | YZ 06 ALL | 06L, 05 | 06R | East-flow parallel + crosswind |
| CYYZ | YZ 24L | — | 24L | Primary arrival only |
| CYUL | UL 24 ALL | 24R | 24L | Montréal west-flow split |
| CYVR | VR 08 ALL | 08L | 08R | Vancouver east-flow split |
| CYWG | WG 36+13 | 13 | 36 | Winnipeg dual-runway |
| CYYC | YC 35 ALL | 35R | 35L | Calgary north-flow split |

Left/right controls which side of the timeline strip labels appear on.
For parallel ops, put departures (or the secondary runway) on the left
and arrivals on the right — visually separates the streams.

### Label Layout (canadaArr / canadaDep)

Arrival labels show: `RWY | STAR (3 char) | CALLSIGN | TYPE | WAKE | TTL/TTG | DIST BEHIND`

Departure labels show: `RWY | CALLSIGN | TYPE | WAKE`

The STAR field is truncated to 3 characters — enough to distinguish
arrival direction (e.g., "RIP" for RIPAM, "EBO" for EBONY).

---

## 4. Operational Use

### Scenario: CYHZ 23 Single Runway

1. Open the **HZ 23** timeline
2. Inbounds appear as they enter the 40-min window
3. If two aircraft are bunched (both showing TTL +0), drag the lower-
   priority one down — the plugin recalculates TTL for both
4. Pass TTL/TTG to approach: "ACA841, reduce to 210 knots, you have
   3 minutes to lose"
5. Inside 10 min (frozen), sequence is locked — execute it with vectors

### Scenario: CYYZ 24 Direction Parallel Ops

1. Open **YZ 24 ALL** — left side shows 24R/23, right side shows 24L
2. 24L arrivals sequence independently from 24R traffic
3. Watch for wake turbulence across the parallel streams visually —
   the plugin doesn't enforce wake separation between independent
   systems (that's your job)
4. If you need to move an arrival from 24L to 24R (e.g., runway change),
   update the assigned runway in EuroScope — the label moves to the
   other side of the timeline

### Scenario: Rate Restriction Active (CTOT in play)

When atfm-tools has assigned CTOTs:
- Departures at the origin get their slot time
- Arrivals at your airport have an ELDT from the allocator
- aman-dman shows the **actual** ETO from the aircraft's current state
- If an aircraft is 5 min early vs its CTOT-derived slot, the TTL will
  show +5 — that's your cue to slow it down
- The two systems are complementary: CTOTs set the macro spacing,
  aman-dman fine-tunes the micro spacing

---

## 5. What's Not Configured Yet

| Item | Status | Notes |
|------|--------|-------|
| Feeder fixes | Empty | Need STAR entry points per airport if we want upstream metering |
| Arrival profiles | Not set | Speed/altitude profiles per STAR for better ETO prediction |
| Sequencing areas | Not set | Polygon boundaries for sequencing/frozen zones |
| Departure sequencing | Minimal | Labels show but no DMAN logic beyond timeline display |

### Adding Feeder Fixes (future)

To add feeder-fix metering for CYHZ, you'd add to `CYHZ.yaml`:

```yaml
feederFixes:
  - "EBONY"
  - "TUSKY"

feederFixTransitTimesMinutes:
  "05":
    EBONY: 12
    TUSKY: 15
  "23":
    EBONY: 14
    TUSKY: 12
```

Then add feeder-fix timelines to `timelines.yaml`:

```yaml
feederFixBased:
- timelineTitle: "HZ EBONY/TUSKY"
  timelineId: "<uuid>"
  left:
  - "EBONY"
  right:
  - "TUSKY"
  arrivalLabelLayoutId: "canadaArr"
```

Transit times are the expected minutes from fix to threshold — the
plugin uses them to correlate feeder-fix STO with runway STO.

### Adding Arrival Profiles (future)

Profiles give the plugin expected speed/altitude at each fix along a
STAR, improving ETO accuracy.  Example for CYHZ:

```yaml
arrivalProfiles:
  "23":
    - arrivalName: "EBONY*"
      fixes:
        - fix: "EBONY"
          altitude: 11000
          speed: 280
          role: "IAF"
        - fix: "OLAND"
          altitude: 6000
          speed: 250
        - fix: "VEDOL"
          altitude: 4000
          speed: 210
          role: "IF"
```

Without profiles, the plugin uses ground speed extrapolation — adequate
for our 40-min horizon but less accurate during descent.

---

## 6. Key Differences from Real-World AMAN

| Real AMAN (MAESTRO etc.) | aman-dman |
|--------------------------|-----------|
| Connected to FDP, radar, CFMU | Local EuroScope only |
| Computes optimal sequence considering wake, SID/STAR constraints | Simple time-sorted sequence |
| Sends advisories to controller display | **Is** the controller display |
| Enforces wake separation in sequence | No wake logic — visual check |
| Integrated with CTOT/ATFM slot | No CTOT awareness |
| Hundreds of parameters | ~20 config fields |

The plugin is a **display tool**, not a sequencing engine.  It shows
you the picture; you make the decisions.  That's fine for VATSIM — we
don't have the traffic density that demands automated optimal sequencing.

---

*Last updated: 2026-04-16.  Configs: 7 airports, PT40M sequencing,
PT10M frozen, canadaArr/canadaDep label layouts.*
