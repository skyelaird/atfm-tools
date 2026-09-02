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
| That TOBT reaching us | **No.** Not in any public feed. |

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
