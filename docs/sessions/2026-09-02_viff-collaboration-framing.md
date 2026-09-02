# 2026-09-02 — Framing the vIFF collaboration with Roger Puig

Working out how to position atfm-tools alongside vIFF after an exchange in which
two proposals were declined. The technical inventory is in
`docs/VIFF-INTEGRATION.md`; this is the argument and the vocabulary, which is
the part that would otherwise live only in chat.

Roger Puig operates vIFF and the CDM plugin. Real-world Lufthansa dispatcher —
which matters: he knows CASA, tolerance windows, most-penalising regulation and
XMAN/E-AMAN, so the right register is correct ATFM vocabulary with no
explanation, and no invented terminology of ours.

## What he declined, and what he did not

Declined: **ad-hoc CTOTs**, and **vIFF carrying CTOTs generated elsewhere** —
*"then the main idea behind vIFF is lost"*.

Not declined, and stated positively: *"I will always prefer to add **sources and
types of regulations** that result in assigning CTOTs."*

He is refusing to cede **CTOT authorship**, not refusing inputs. That distinction
is the whole opening. A demand forecast expressed as a regulation is inside the
scope he named; a CTOT is not.

## The term that caused the confusion

"Ad-hoc CTOT" carries at least four meanings, and the two parties used different
ones:

| | meaning | status |
|---|---|---|
| 1 | **Manual CTOT** — a controller types a time into the tag | exists; plugin-level, session-local |
| 2 | **Unsourced CTOT** — a time injected with no regulation behind it | **what Roger heard and refused.** No `mostPenalisingRegulation` to name, and the plugin drops rows lacking that key |
| 3 | **Demand-triggered regulation** — created when demand exceeds capacity, producing CTOTs normally | vIFF already does a simple version: *Progressive Regulate, start regulating at 80% capacity* |
| 4 | **Non-GDP allocation** — reactive, because there is no known population to allocate across | **what Joel meant** |

Meaning 4 is the real one and it is the sharpest framing available:

> A GDP allocates slots across a **known population** — schedules and long-lead
> flight plans. VATSIM provides neither; plans arrive minutes before pushback.
> In FAA terms every flight is a **pop-up**, so slots must be issued reactively
> as demand materialises rather than distributed once at program start.

**And that dissolves the disagreement, because vIFF is already an all-pop-up
system.** Roger has no GDP concept either — a vIFF regulation meters whatever
shows up. Asking for "ad-hoc CTOTs" therefore sounded like a request for
something *outside* his model, when it was a description of his model in
GDP-relative language.

## The genuinely additive part

In an all-pop-up system there is exactly **one long-lead demand signal: traffic
already airborne.** A long-haul arrival is known six to eight hours out, its
arrival time is wind-dominated, and it consumes capacity a reactive allocator
cannot see coming until it is nearly overhead.

That is what atfm-tools has built, and the right name for it in his vocabulary
is an **E-AMAN horizon** — not our internal ELDT/TLDT terms, which would make
him translate. Extended arrival management is a concept he already holds.

## The offer

**Arrival demand as a DCB input**, not CTOTs: per time window, how much of the
declared capacity is already consumed by arrivals we are confident about. vIFF
computes and issues the CTOTs exactly as now.

**Per window rather than per slot, deliberately.** The plugin's tolerance is
CTOT+7 (real-world −5/+10), and at a 46/hr rate the nominal spacing is ~78 s —
so roughly ten aircraft's tolerance windows overlap and sequence within that
span is not preserved. Add our own measured arrival scatter (MAE 7.6 min) and
the honest object is **capacity in a 15–20 minute bucket**, not a discrete slot.
Slot spacing finer than the arrival uncertainty is precision theatre.

This also maps onto machinery vIFF already has: restrictions carry `capacity`
with HHMM windows, and `/etfms/airports` publishes hourly capacity that already
reflects active restrictions. The open question is granularity — their published
capacity is hourly, so 20-minute modulation may need short restrictions or a
finer field.

## Where it helps him rather than only us

**Oceanic arrivals into European airports.** Six to eight hours out,
wind-dominated, and invisible to vIFF until they are close — the same structural
blind spot, on his own turf. Backed by a measured number rather than a claim:
from the 30-day GFS corpus (`docs/wind-skill-2026-spring.md`), the 250 mb wind
error gives roughly **±3.7 flights at a sector peak at D-3** and **±1.7 at D-1**.

Framed as: we run it and publish a feed, nothing for him to build or maintain.
That answers the objection underneath both refusals, which is scope and burden.

## Deliberately left out of the message

- **The `customRestricted` and `<Slots url>` channels.** They would let us reach
  controllers without him, but raising them now reads as "I will route around
  you". A card for later, if ever.
- **The VDGS ask.** Declined twice already. Better revisited once something is
  actually flowing.

## Open question put to him

What **"bookable arrival slots"** means — pilots booking a slot themselves, or
the system reserving landing capacity from predicted arrival times. We have
built the second. The answer decides whether the TLDT work converges with his
roadmap or runs beside it, and it is worth asking rather than assuming: the
natural reading of "bookable" is pilot-initiated, which would be a different
product from ours.
