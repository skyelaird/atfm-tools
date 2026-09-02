# vIFF integration — what we learned, and what we'd have to ask for

**Investigated 2026-09-02** against the live vIFF Operations Dashboard
(`cdm.vatsimspain.es`), with Joel's VATCAN admin scope, and the public
capacity repo. Read-only: nothing was created, edited or saved.

## Why this is on the table

We deliver CTOTs through the CDM plugin's `customRestricted` override, which
each controller must configure by hand, and which reaches nobody who hasn't.
vIFF is the plugin's *default* server — every controller already points at it,
and it drives the VDGS panel pilots are told to watch. Delivery is the half of
our system that has never worked end to end; theirs is the half that already
does.

Joel's framing: the most seamless option is for us to write the **regulation**
into vIFF rather than issuing CTOTs ourselves.

## What their model looks like

Their airport restriction is our airport restriction. From the CYHZ editor:

| vIFF field | ours (`airport_restrictions`) |
|---|---|
| Restriction ID (`CYHZ02CZ`) | `restriction_id` — our `generateId()` already emits this format |
| Capacity | `capacity` |
| Reason (`ATC CAPACITY`) | `reason` (our default is `ATC_CAPACITY`) |
| Type (`ARR` / `DEP`) | `type` |
| Runway (DEP only) | `runway` |
| Start / End, HHMM | `start_utc` / `end_utc` |
| Delete after 24h | `expires_at` |

One constraint we don't have: **max 5 h between start and end**.

Their airport config (CYHZ) also carries: Base Arrival Rate 22 and TAXITIME 10
— the same values we seeded from this scope — plus CDM Airport, Report Ready at
TOBT, Disable VDGS When ASRT Set, **Progressive Regulate** ("start regulating at
80% capacity"), Regulation Threshold %, and **Use ATIS Config** / **Keep latest
ATIS Config**.

Two of those are worth stealing regardless of whether we integrate:

- **ATIS-driven runway config.** Their answer to "which runway is active" is to
  read the ATIS, not to infer it from METAR wind. The VATSIM datafeed carries
  ATIS text and we already consume that feed. An ATIS *states* the runway in
  use; it exists exactly when the field is staffed, which is when config
  matters. Cheaper and better than the METAR scoring we had sketched.
- **Progressive regulation.** Their regulations can trigger on a demand ratio
  (80% of capacity) rather than being created by hand. Ours are always manual.

## What the write path actually is

- The dashboard is **PHP endpoints under `/dashboard/`** (`get_sectors.php`,
  `cdm_apps/traffic_volumes/`), session-authenticated. Nothing resembling a
  public REST API fires on page load.
- Creating a restriction is a **form POST from an authenticated session**.
- Therefore "we write the regulation into vIFF" means either a human clicks it
  in their dashboard, or **Roger exposes an endpoint and a key for us**. There
  is no third option we can build alone that isn't screen-scraping someone
  else's session.

The static layer is different and public: **`rpuig2001/vIFF-Capacity-Availability-Document`**
(the CAD), contributed by fork → edit `data/<FIR>/` → validate → PR. It holds
`airblocks.geojson` (sector volumes), `procedures.txt` and a deprecated
`profile_restrictions.txt`.

**There is no `CY` or `CZ` directory** — Canada is absent from the CAD entirely.
That is consistent with us not regulating airspace: we would only ever need
`procedures.txt`, whose format is
`<AIRPORT>:SID:<letters>:STAR:<letters>` with optional per-runway/config
designators. Which is, incidentally, the STAR-per-configuration mapping our own
terminal model wants.

## The cost of handing over the regulation

If vIFF holds the regulation, **vIFF's allocator computes the CTOTs from vIFF's
ETA**. Arrival slot allocation is ordering-by-ETA and spacing, so ETA quality
*is* slot quality. We would be handing over the one thing we have measured and
attributed (see `docs/sessions/2026-09-02_eldt-bias-attribution.md`): we know
ours runs +5.6 min early and exactly why. We know nothing about theirs.

That may still be the right trade — prediction is what works for us, delivery is
what doesn't — but it should be made with eyes open, and it can be **measured
rather than argued**: write the regulation to vIFF, keep our allocator running
in `--shadow`, and compare their issued CTOTs against ALDT and against what we
would have issued. We observe every landing already, so this costs nothing and
risks nothing.

## What to ask Roger, in order

1. **An authenticated endpoint to create/update an airport restriction** — the
   exact fields above, which mirror a form that already exists. Small, concrete,
   no model change on his side.
2. **Whether the VDGS panel can show a slot we issued** — i.e. can a
   third-party CTOT be represented, and with what attribution. This is what
   makes `vats.im/vdgs` usable for Canadian pilots, who are currently sent
   there by the plugin's default private message and would see nothing.
3. **Whether their allocator would accept an external ETA per flight.** The
   cleanest division of labour — they execute, we predict — and the largest ask.
   Park it until 1 and 2 are settled.

## Standing conflict to resolve either way

vIFF can regulate Canadian airports and so can we. In Mode A, a controller
pointed at our `customRestricted` sees our CTOTs and never sees a vIFF
regulation for the same airport. Two allocators, one runway. Whatever
integration shape wins, exactly one system has to own the slot for a given
airport at a given time, and the FMP has to be able to see which.

## Update, same day: the constraint feed is already public

`GET https://viff-system.network/etfms/restrictions?type=ARR` returns active
arrival restrictions with no authentication at all:

```json
[{"airspace":"LSZH","type":"ARR","capacity":30,"runway":""},
 {"airspace":"CYHZ","type":"ARR","capacity":20,"runway":""}, ...]
```

CYHZ was in it on 2026-09-02 at capacity 20 — the TEST restriction authored in
the vIFF dashboard minutes earlier. So the architecture worth building is
neither of the two above: **a human authors the constraint in vIFF, we read it,
and our allocator issues the CTOTs.** vIFF owns the constraint, we own the
slot, and the ELDT work we have measured stays in the loop.

Shipped as `bin/ingest-viff-restrictions.php` (v0.7.49), **disabled unless
`VIFF_RESTRICTIONS_ENABLED=true`**.

What the feed does not carry: no id, no start/end window, no reason. It states
what is active *now* — the same authoritative-list semantics as the CTOT list
we serve the plugin. So we mirror presence as active and absence as lifted,
with these deliberate properties:

- **Most restrictive wins.** An airport can appear several times (overlapping
  vIFF windows); we take the lowest capacity. Under-delivering slots is
  recoverable, over-delivering is not.
- **A failed fetch releases nothing.** An unreadable feed is not an
  instruction to lift a regulation.
- **It only touches its own rows** (`source='viff'`). An FMP regulation
  authored in our dashboard is never modified or deleted by the mirror.
- **ARR only, in-scope airports only.**

Still worth asking Roger for: whether a slot *we* issued can be represented in
the VDGS panel, since the plugin's default private message already sends every
pilot there. Reading constraints does not solve pilot-side delivery.

## Live test, 2026-09-02: FAL57 CYYZ→CYHZ

Joel filed and connected a real flight into CYHZ while a CYHZ ARR/20
restriction was active in vIFF. What each leg of the loop actually did:

| Leg | Result |
|---|---|
| Constraint authored in vIFF → readable by us | **Works.** Public, unauthenticated. |
| vIFF tracks the flight | **No.** Never appeared in `/etfms/relevant`. |
| vIFF issues its own CTOT against its own restriction | **No.** None issued. |
| VDGS identifies the pilot | **Works.** CID → callsign, EOBT off the flight plan. |
| Pilot sets TOBT on the VDGS | **Works.** 1645 accepted, window recomputed to 16:40. |
| That TOBT reaching us | **Unproven** — absent from `/etfms/relevant`, but `/ifps/depAirport` was not tested in time. See correction below. |

The VDGS badge explains most of it: **A-CDM DISCONNECTED**. vIFF was not
running the CDM process for CYYZ, so it held no TOBT/TSAT/CTOT and its
allocator never considered the flight — which means **a vIFF restriction is
inert unless vIFF is also tracking the flight.**

Two consequences.

**Good: the two-allocators conflict does not materialise in Canadian ops.**
vIFF can hold the constraint without contending for the slot, because it will
not act on a flight it is not tracking. We track every flight in scope
regardless of who is online. Constraint there, allocation here, no contention
to resolve — which is exactly the architecture we want.

**Bad: there are now two TOBT stores and no reconciliation.** The pilot did
the right thing, the VDGS confirmed it, and our allocator would still have
issued a slot against the superseded 1635. That divergence is silent on both
sides. The cheap mitigation is already known: **repoint the CDM plugin's
`<PrivateMessage text>` at our own portal** for Canadian ops, so pilots set
TOBT where the system that issues their slot can read it. Otherwise the
plugin's default message actively sends them to the wrong place.

So the ask to Roger gains a third item, and it is now the most valuable one:
**expose per-flight TOBT for flights vIFF is not running CDM for**, or include
them in `/etfms/relevant`. Without it the loop cannot close through vIFF.

### Incidental measurement

Their taxi time for CYYZ is **15 min flat** (the airport's single `TAXITIME`
config value). Ours was **8 min**, from the gate polygon the aircraft was
actually parked on (`taxizones.txt`). That difference propagates straight into
TTOT and therefore into any CTOT.

Both systems independently computed the same start-up window opening — 16:30
from EOBT 1635, and 16:40 after TOBT moved to 1645. No shared code.

### The decisive finding: A-CDM DISCONNECTED

The VDGS badge on FAL57 read **A-CDM DISCONNECTED** throughout. Their own
"Online ACDM Airports" list explains it — 10 entries, each bound to a live
controller position:

```
EBBR_GND  EDDH_APP  ELLX_DEL  ENBR_D_APP  EYKA_TWR
LEBL_GND  LEIB_GND  LEPA_GND  LROP_GND    OMDB_1_GND
```

No Canadian airport, and every entry is a controller. **vIFF's A-CDM process
runs only where a controller has claimed the airport as master in the CDM
plugin.** Without one there is no TSAT, no CTOT, no sequencing, and no
presence in `/etfms/relevant`.

Which means the CYHZ ARR/20 restriction was **inert**: a real constraint,
authored correctly, that regulated nobody, because vIFF was sequencing no
Canadian flights.

Note also that the VDGS *degrades gracefully*: EOBT, taxi time and the
start-up window all come from the flight plan plus airport config, not from
A-CDM. A Canadian pilot can therefore read a plausible-looking panel and
reasonably believe they are in a CDM process they are not in.

### What this settles

vIFF's Canadian coverage is exactly *"when a Canadian controller is plugged in
as master"*. The unstaffed case is the normal case here, and it is precisely
the case where flow management still has to work. We ingest every flight in
scope from the VATSIM feed regardless of who is online.

So the division of labour is settled on evidence, not preference:
**vIFF holds the constraint — authored once by a human, visible network-wide —
and we allocate, because we are the only one of the two that can see the
traffic when nobody is plugged in.**

It also reprioritises the asks to Roger. A write endpoint for CTOTs matters
less than it appeared, since writing slots into a system that is not
sequencing Canadian flights buys little. The valuable asks are now:

1. **Can the VDGS display a CTOT that originated outside their A-CDM?** The
   panel clearly renders without a master, so this may be cheap for him.
2. **Can we read pilot TOBT** for flights vIFF is not sequencing? Three edits
   on a live flight never surfaced in any public feed.

Until either exists, the mitigation stands and needs nobody: point the CDM
plugin's `<PrivateMessage text>` at our own portal for Canadian ops.

### Correction: the read-back is unproven, not blocked

The table above originally read "not in any public feed", based only on
`/etfms/relevant`. That was overstated. A second public endpoint exists and
carries exactly the fields in question:

```
GET https://viff-system.network/ifps/depAirport?airport=CYYZ
fields: callsign cid departure arrival eobt tobt obt reqTobt taxi ctot
        aobt atot eta mostPenalizingAirspace cdmSts informed
        latestRevisedCtot atfcmStatus atfcmData cdmData
```

It is **per airport**, which suits our seven exactly — seven cheap polls, no
network-wide filtering. It returned 7 live CYYZ departures when tested.

Whether a VDGS-set TOBT populates `tobt` / `reqTobt` there is **untested**: by
the time this endpoint was found, FAL57 had disconnected and was gone from the
VATSIM datafeed, so its absence from vIFF proved nothing. The rows that were
returned had empty `tobt`/`reqTobt`, but they were non-CDM flights that had
already departed — equally uninformative.

**The test to run**, and it needs nobody's cooperation: connect, set a TOBT on
the VDGS, then `GET /ifps/depAirport?airport=<ADEP>` and look for the callsign.
If `tobt` or `reqTobt` carries the pilot's value, the read-back leg closes
today and the third ask to Roger disappears.

---

# Capability inventory and gap analysis

Everything below about vIFF was **verified empirically on 2026-09-02** against
the live system, not taken from documentation.

## What we are trying to accomplish

Estimate ELDT well enough to allocate **arrival** slots at seven Canadian
airports, and get the resulting times in front of the people who act on them.
Two halves, and only one of them works today:

- **Prediction** — works, and is measured. GRIB-wind ELDT, route resolution,
  a committed TLDT frozen at T-90, accuracy KPIs with the error attributed.
- **Delivery** — has never once worked end to end. Until v0.7.43 our CTOT
  payload could not even be parsed by a current CDM plugin.

## What vIFF has (verified)

| capability | endpoint / surface | notes |
|---|---|---|
| Airport restrictions | `/etfms/restrictions?type=ARR`, dashboard | public; ARR/DEP, capacity, HHMM window, runway, 5 h max |
| Hourly capacity **and arrival demand** | `/etfms/airports` | public; 841 airports, 45 Canadian; `entriesCapacity` vs `entriesCount` per hour, with the flights listed. **Counts arrivals, transatlantic included** — EGLL's list held KLAX/KLAS/KJFK/VHHX inbounds. Reflects active restrictions |
| Per-flight CDM state | `/ifps/depAirport?airport=X` | public, per airport; tobt/obt/**reqTobt** + `cdmData.reqTobtType` = PILOT\|ATC |
| Network flight list | `/etfms/relevant` | only flights vIFF is sequencing |
| CTOT list | `/etfms/restricted` | what the plugin consumes |
| Status / punctuality | `/ifps/allStatus`, `/ifps/allOnTime` | public, network-wide |
| Master registry | `/airport` | icao + controller position |
| VDGS pilot panel | `vats.im/vdgs` | per-pilot OAuth; TOBT edit (3), REA, prediction tool, reroute proposals |
| Progressive regulation | airport config | auto-trigger at 80% of capacity |
| ATIS-driven config | airport config | reads the ATIS for the runway in use |

**The binding constraint — corrected 2026-09-02 20:05Z.** An earlier version of
this document said vIFF tracks nothing without a master. That is wrong.
Observed with masters `LBSF_DEL` and `EGPH_TWR` only — none Canadian — two
Canadian flights were present in `/etfms/relevant` and on the dashboard:

```
ACA421 CYUL→CYYZ eobt 2005 taxi 12 tobt '' ctot '' eta 2056 cdmSts FLS-NRA
ACA460 CYYZ→CYOW eobt 2005 taxi 15 tobt '' ctot '' eta 2053 cdmSts FLS-NRA
```

So vIFF **does track** in-scope flights and compute a planning ETA without a
master. What it does not do without one is **sequence** them: no TOBT, no TSAT
— which is what the VDGS means by *A-CDM DISCONNECTED*. A CTOT additionally
requires a regulation; the CYHZ ARR/20 restriction authored that day produced
none, because nothing was being sequenced against it.

The inclusion rule for `/etfms/relevant` is **not determined**. Earlier the same
day a live CYHZ departure was absent from it while present in
`/ifps/depAirport`, so it is neither "all flights" nor "master airports only".
Candidates: proximity to EOBT, CDM-enabled airports, regulation-affected
flights. Worth establishing before relying on it.

## What the CDM plugin has

- Reads CTOTs every ~15 s per master; the response is authoritative (omission
  releases). `customRestricted` overrides **only** that endpoint.
- Derives the controller-facing **TSAT = CTOT − taxi**, and suppresses its own
  departure sequencing for any flight carrying a CTOT.
- Tolerance CTOT+7 before the slot is treated as missed.
- Precedence: ECFMP > per-flight disable > CTOT > event slot > own sequencing.
- URL hooks for `Rates`, `Taxizones`, `Slots` (a `vatcan,callsign,dep,dest,ctot`
  text file landing as EV-SLOT) and `sidInterval`.
- Sends pilots to `vats.im/vdgs` by default, via configurable PM text.

## Two claims I got wrong, and what survives them

Recorded because both were load-bearing in the argument prepared for Roger, and
both were inferred from a single endpoint's silence rather than tested.

**Wrong: "vIFF tracks nothing without a master."** Canadian flights appear in
`/etfms/relevant` with only European masters online, carrying taxi times and
computed ETAs.

**Wrong: "oceanic arrivals are invisible to vIFF until they're close."**
`/etfms/airports` counts arrival demand per airport per hour across 841
airports, transatlantic included — EGLL's hourly lists held `BAW8DS KLAX→EGLL`,
`BAW40F KJFK→EGLL`, `CX251 VHHX→EGLL`.

**What survives, and it is the whole of the real gap:** vIFF counts those
arrivals *at a statically computed time*. Its ETA is TOBT + taxi + filed ETE and
does not respond to wind. Measured on FAL57 the same evening: vIFF held **1939**
from pushback through touchdown while the actual was **1952**, and our
wind-corrected estimate tracked 19:37 → 19:48 as the headwind built 55 → 84 kt.
A 13-minute error puts the demand in the wrong hourly bucket; on a NAT crossing
it is routinely worse.

So the contribution is not visibility. **It is better arrival times underneath a
demand picture he already keeps.** That is a smaller claim, it credits what he
built, and unlike the first two versions it is verifiable.

**Method note for next time:** absence from one endpoint is not absence from the
system. Every claim of the form "vIFF cannot X" needs a positive test before it
goes in a message to its author.

## What we have that vIFF does not

- **Unconditional coverage.** We ingest every in-scope flight from the VATSIM
  feed regardless of who is online. vIFF sequences nothing without a master,
  and the unstaffed case is the normal case in Canada.
- **Wind-corrected arrival prediction.** Multi-level GRIB integration, route
  resolution through the STAR, published procedure constraints.
- **A committed arrival slot (TLDT)** frozen at T-90 — a *reservation* of
  landing capacity, distinct from a departure CTOT.
- **Measured accuracy**, with the residual error attributed to specific model
  terms rather than assumed.

## The gap

1. **Delivery to pilots.** Roger declines to carry externally-generated CTOTs,
   so the VDGS will not show ours. Our own portal is therefore the channel, and
   the plugin's PM text must be repointed at it or pilots are sent somewhere
   that structurally cannot see their slot.
2. **Delivery to controllers** works, but only for those who set
   `customRestricted` by hand.
3. **Arrival slot reservation does not exist in vIFF at all.** This is the
   actual difference between the two systems, and it is the thing Roger has on
   his own roadmap as "bookable arrival slots".

## Reading of Roger's position (2026-09-02 exchange)

He declined two things specifically: **ad-hoc CTOTs**, and **accepting CTOTs
generated elsewhere** — on the grounds that it loses the idea behind vIFF. He
stated what *is* in scope: *"sources and types of regulations that result in
assigning CTOTs"*.

That sentence is the opening. He is not refusing inputs; he is refusing to
cede CTOT authorship. A demand forecast expressed **as a regulation** is
explicitly within the scope he described, while a CTOT is not.

So the durable split is:

- **vIFF owns the CTOT** — authored from constraints, delivered to plugin and
  VDGS. Unchanged, and it is the half we are bad at.
- **We own the arrival side** — predicting ELDT and reserving landing capacity
  ahead of time, including for long-haul already airborne before any regulation
  exists. That is the half vIFF does not attempt.

The bridge is not "here are our CTOTs". It is "here is arrival demand and the
capacity it consumes" — which vIFF's own allocator then regulates against.

See `docs/sessions/2026-09-02_viff-collaboration-framing.md` for the argument,
the vocabulary that works with him, and why "ad-hoc CTOT" caused the
misunderstanding — the short version being that VATSIM has no known population
to allocate across, so every flight is a pop-up, and vIFF is already an
all-pop-up system.

**Open question, to ask rather than assume:** what Roger means by "bookable
arrival slots". It may be pilot-initiated booking (CTP-style) rather than
system-reserved capacity based on predicted ELDT. Those are different products
and the difference decides whether our TLDT work converges with his roadmap or
runs parallel to it.
