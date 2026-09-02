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
