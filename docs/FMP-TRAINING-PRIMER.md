# FMP Training Primer — briefing for the Claude session that will draft this curriculum

> **You (Claude) are being asked to write training material for VATSIM Flow
> Management Position (FMP) controllers.** This document gives you the
> domain grounding, audience constraints, and pedagogical stance the
> course should take. Read it once end-to-end before drafting anything.

---

## 1. The task

Produce training material for someone who has never done ATFM work,
aimed at VATSIM VATCAN (Canadian division) controllers and FMPs, with
the domain competence to also support adjacent US-side operators
touching Canadian flow. Output format is flexible — long-form written
material, a slide deck, or a web-based reference page — but the
**content** is the same core curriculum regardless of medium.

The material must not assume access to or knowledge of any specific
tool. It teaches the profession, then mentions tools as instances of
the profession being practiced.

---

## 2. What ATFM actually is

Air Traffic Flow Management is the discipline of reconciling **demand
for a piece of airspace or runway** with its **capacity to accept
arrivals and departures**. When demand exceeds capacity, someone is
going to be delayed — ATFM decides who, where, and by how much, in
a way that minimises total system delay and preserves predictability
for everyone else.

Three statutory / agency forms the trainee should know by name:

| Body | Role | Where it sits |
|---|---|---|
| FAA ATCSCC (Air Traffic Control System Command Center) | US national TMU | Warrenton, Virginia |
| NAV CANADA ANSOC | Canadian equivalent | Montreal |
| EUROCONTROL NMOC (Network Manager Operations Centre) | European equivalent | Brussels |

All three publish operating procedures whose concepts transfer directly
to VATSIM. Notably:
- **FAA FOA Chapter 10** — the canonical TMU handbook (free online)
- **EUROCONTROL A-CDM Implementation Manual v5.0** (March 2017) — the
  vocabulary reference for milestones (EOBT/TOBT/TSAT/CTOT/ATOT/ALDT
  etc.); quote it rather than inventing terms
- **NAV CANADA ATC MANOPS** — Canadian procedures; most relevant for
  VATCAN but restricted access

ATFM is not ATC. ATC controls individual flights tactically (vector
this one, hold that one). ATFM pre-conditions the stream so ATC doesn't
receive an impossible problem — say, 30 arrivals into a 20-capacity
airport. The boundary is operational: ATC fixes the next two minutes,
ATFM arranges the next two hours.

---

## 3. The VATSIM reality — constraints unique to the simulation

Do **not** treat VATSIM as "real ops but smaller". It has its own
dynamics that materially change what an FMP does:

- **Pilot population is heterogeneous.** A few airline-seriousness
  pilots, many hobbyist, some low-experience. Their compliance with
  CTOTs, EOBTs, and even ATC clearances varies wildly. The FMP has
  softer levers than in real life — you can issue a CTOT but there's
  no tarmac-delay penalty for non-compliance.
- **EOBT is unreliable.** Pilots file an EOBT that matches a real-world
  schedule or is pulled from SimBrief; they then spawn in whenever.
  The delta between filed EOBT and actual spawn time (our "dwell")
  ranges from 0 to several hours. FMP must assume EOBT is a guess.
- **Time acceleration.** Some pilots run their simulator at 2×/4×
  realtime through cruise. Network GS shows normal, position advances
  fast. Messes up ETA prediction.
- **Mid-connect joiners.** Pilots sometimes log into VATSIM
  mid-flight (after a crash, or deliberately). Their first-seen
  position gives a garbage baseline for any prediction.
- **Disconnects.** Pilots drop. A flight that was confirming an
  arrival 10 min ago may be gone from the network with no landing.
- **ATC coverage is sparse.** Real airports have ATC 24/7; VATSIM has
  it when a volunteer logs on. A pilot might push from a non-staffed
  airport with no controller to tell them their CTOT.
- **Events inflate demand artificially.** A "Cross-the-Pond" or a
  VATCAN Presents event compresses hours of real-world spread traffic
  into a 2-hour window. FMP is more valuable here than on a normal
  day.
- **Aircraft mix skews.** Everyone wants to fly the 737 or 787.
  Turboprops, regionals, and bizjets are under-represented.
  Separation-derived capacity ("wake mix") calculations therefore
  skew vs real-world statistics.
- **No boarding / deboarding simulation.** Pilot is "ready" almost
  as soon as they file. In real ops, a 30-min boarding window is
  scheduled — not so on the network.

Imply these in teaching, don't skate past them. The new FMP will be
confused when real-world FMP manuals describe processes that don't
map to VATSIM; tell them up front that the sim gives them fewer tools
but also fewer constraints.

---

## 4. Core concepts the trainee must internalize

In order, each built on the previous:

### 4.1 Capacity
- **Airport Acceptance Rate (AAR)**: arrivals/hour an airport is
  willing to accept in its current configuration.
- **Airport Departure Rate (ADR)**: departures/hour.
- Both are **facility-declared per configuration × weather class**
  (FAA FOA Ch. 10 §7). They are not formulas.
- For FMP purposes: the declared rate is the **upper bound** they
  aim to protect.
- Drivers: runway config (parallel / crossing / single / LAHSO),
  wake separation, wind, RVR / ceiling limits, ground constraints
  (apron, gate, deicing).
- Pertinent VATSIM quirk: rates declared for real-world ops often
  assume multi-runway parallel ops. Single-runway A/D (the default
  for many VATSIM events) delivers **~30–35 total movements/hr** —
  half the advertised.

### 4.2 Demand
- **Arrival demand**: aircraft inbound now + known departures to this
  airport arriving within the planning horizon.
- **Departure demand**: filed pushes within the horizon.
- Usually non-uniform. Most airports have 1–3 peak periods per day.
  VATSIM adds event-driven spikes.
- **Metering fix / STAR corridor**: demand arrives via geographic
  corridors, not uniformly. Understanding the spread (e.g. 50% of
  CYYZ demand via ERBUS) is what lets FMP allocate constraints
  where they help rather than everywhere.

### 4.3 Balance
- When **demand ≤ capacity**: do nothing. New FMPs overconstraint.
- When **demand > capacity** over a sustained window: intervene.
- "Sustained" means >30 min. Short peaks absorb in ATC's tactical
  separation; don't regulate a 10-min spike.
- Proportionality principle: the intervention cost (minutes of
  delay added to the system) should be smaller than the cost of
  not intervening (runway saturation, mass go-arounds, go-home
  controllers).

### 4.4 Constraints
Taught in order of preference (smallest hammer first):

1. **Miles-in-Trail (MIT)** — ATC asks the upstream sector / adjacent
   centre to space aircraft N miles apart when crossing a metering
   fix. Cheap, reversible, fine-grained by corridor. **Primary
   tool for VATSIM.**
2. **CTOTs / EDCTs** (Calculated Take-Off Time) — aircraft are given
   specific takeoff times to land in their arrival slot. Requires
   either CDM-compliant pilots or manual coordination. Effective
   but coarse.
3. **Ground Delay Program (GDP)** — formal program that assigns CTOTs
   to all inbounds over a window. Used when arrival saturation is
   forecast well in advance.
4. **Ground Stop (GS)** — halts all departures to a destination until
   further notice. Rare on VATSIM. Nuclear option.
5. **Airspace Flow Program (AFP)** — reroute around a congested
   piece of airspace. Real-world tool, rarely needed on VATSIM.

### 4.5 Coordination
- FMP is not solo. The Canadian FMP coordinates with:
  - **Adjacent FMPs** (US ZBW, ZNY, ZMP; European if relevant)
  - **Upstream towers / ground** (releasing departures to meet CTOTs)
  - **Destination ATC** (if online — they own the tactical work)
  - **Event staff** (pre-event agreements about rates)
- VATSIM has no formal phone line. Coordination is via Discord, the
  coordination voice channel, or text chat. FMP must reach out
  proactively — silence is not acceptance.

### 4.6 Judgment
The hardest skill. Templates:

| Observation | Question to ask | Typical action |
|---|---|---|
| Demand 115% of AAR for 20 min | Is it about to fall? | Wait 10m, reassess |
| Demand 150% of AAR for 45 min | Where's it coming from? | MIT on the heaviest corridor |
| Aircraft mix shift (heavies arriving) | Does this reduce effective AAR? | Revise rate, then reassess |
| Wx forecast to deteriorate | Is there pre-positioning demand? | Forecast constraint before wx hits |
| ATC about to log off | Who takes over? | Pre-brief next controller, lift if no-one |

---

## 5. Pedagogical stance

Write for adult learners with domain-adjacent knowledge (ATC ratings)
but no ATFM exposure.

- **Scenario-first, vocabulary-second.** Never open a section with
  a definition. Open with a situation, introduce vocabulary as it
  becomes needed, define it inline.
- **Lead with the worst-case outcome of getting it wrong,** not with
  the name of the process. "If you miss this, here's what happens to
  the pilots you serve" beats "This process is called X."
- **Show decisions, not procedures.** An FMP's value is choosing
  among options, not executing a single path. Give students
  decision trees and ambiguous scenarios.
- **Proportionality is a first-class topic.** Teach the cost of
  over-regulation explicitly. Most new FMPs overcorrect.
- **Use VATSIM screenshots and real (sanitized) scenario data.**
  Abstract slide-ware conveys less than one screenshot of a live
  demand spike.
- **Each module should include a 5–10 min exercise** the student can
  actually perform (even a tabletop "given these numbers, what
  would you do?" works).
- **Assessment is judgment-based.** Multiple-choice tests memorisation.
  Short-answer scenarios test whether the student can reason
  operationally. Favor the latter.

Avoid:
- Exhaustive real-world detail that doesn't apply on VATSIM (e.g.
  TRSA coordination letters, civil-military airspace procedures)
- Pretending VATSIM ops are inferior — they have their own validity
- Overloading with acronyms before the concepts land
- Heavy math when coarse heuristics serve (MIT is a bucket, not a
  control loop)

---

## 6. Suggested curriculum outline

A 5-part progression, roughly 6–8 hours of material total:

**Part 1 — Setup (why FMP exists, 1 hour)**
- What problem FMP solves
- Where FMP sits vs ATC
- The profession worldwide (FAA, NAV CANADA, Eurocontrol)
- VATSIM's version of the problem

**Part 2 — Capacity (2 hours)**
- Runway physics (wind, wake, config)
- AAR / ADR declaration
- How capacity changes (wx, config, events)
- VATSIM-specific quirks (aircraft mix, single-RWY A/D)

**Part 3 — Demand (1.5 hours)**
- Flight lifecycle on VATSIM (EOBT → AOBT → ATOT → ELDT → ALDT)
- Predictability of demand (peaks, patterns, events)
- Metering fixes / STAR corridors
- Why pilot-set TOBT matters

**Part 4 — Constraints (2 hours)**
- MIT — primary tool; fair-share allocation
- CTOTs & GDPs — when they apply
- Ground Stops — rare; misuse common
- Coordination — upstream, downstream, adjacent centres

**Part 5 — Judgment (1.5 hours)**
- Three case-study scenarios with ambiguous data
- Common new-FMP pitfalls
- Proportionality and the cost of over-regulation
- How to brief a relief

Each module should end with: 2-3 self-check questions, a 5-min
exercise, and a pointer to the real-world reference (FOA Ch. 10,
A-CDM manual, etc.) for deeper study.

---

## 7. Tone & voice

- Direct. "FMP does X. Pilots do Y. When Z, act." No filler.
- Operational. The reader is going to sit down at a position and
  make calls. Write like a colleague briefing them on a shift, not
  an academic introducing a field.
- Respectful of the simulation. VATSIM is a valid context for
  learning this work — some of the best real-world FMPs started
  there.
- British / Commonwealth spelling is consistent with VATCAN docs
  (metering, colour, organisation) — optional but preferred.

---

## 8. Things explicitly out of scope

- Airspace design and sector planning (that's ATC instructor work)
- TFM national programs specific to real-world FAA/EASA
- Economics of delay (airline cost models, real-world slot auctions)
- ICAO document memorisation
- Aircraft systems knowledge beyond wake category

---

## 9. Anchor references (cite, don't quote extensively)

- EUROCONTROL *Airport CDM Implementation Manual* v5.0 (2017-03-31)
  — **the** A-CDM vocabulary source
- FAA FOA Chapter 10 — TMU procedures
- FAA ASPM (Aviation System Performance Metrics) docs — real-world
  AAR declaration practice
- ICAO Doc 9971 *Manual on Collaborative ATFM*
- NAV CANADA ATC MANOPS — Canadian-specific (restricted)
- VATCAN controller handbook (current version)
- For VATSIM-specific realities: the atfm-tools project docs at
  `github.com/skyelaird/atfm-tools/tree/main/docs` — particularly
  GLOSSARY.md and ARCHITECTURE.md

---

## 10. Starting point for the drafting Claude

Pick **Part 1 Module 1 ("What problem FMP solves")** to draft first.
It sets voice and depth. A reasonable target is ~2,000 words + one
concrete VATSIM scenario + one exercise + three self-check questions.
Show it to the reviewer (the project owner) before drafting Part 2 so
tone adjustments propagate.

Work iteratively. Don't try to ship all modules in one pass.
