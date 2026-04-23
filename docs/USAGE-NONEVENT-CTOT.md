# Non-event CTOT portal — usage guide

Where: https://atfm.momentaryshutter.com/ctot.html

## For pilots

### What this is
A self-serve slot system for pilots flying across CTP event airspace
on a CTP event day *without* a booked event slot. It issues you a
calculated take-off time (CTOT) that threads you through the event
traffic without hogging capacity the booked flights need.

### When to use it
You're flying, and all of these are true:
- There's an active CTP event (check https://ctp.vatsim.net)
- You're NOT booked into the event
- Your departure OR arrival crosses / is near NAT corridors on
  event day (12-22Z on 2026-04-25)

If any of those don't apply, you don't need a CTOT from us.

### What you need before requesting
- **Callsign** you'll be logging in with
- **ADEP**: 4-letter origin ICAO
- **ADES**: 4-letter destination ICAO
- **EOBT**: your intended off-block time in Zulu (ISO 8601, e.g.
  `2026-04-25T14:00:00Z`)
- **Aircraft type**: ICAO code (e.g. `B738`, `A320`, `B77W`)
- **Route**: your filed route string, with SIDs/STARs stripped
  (just the enroute portion)
- **Cruise altitude**: filed FL (e.g. `370`)

### Steps
1. **Sign in** with VATSIM Connect (button at top of `/ctot.html`)
2. **Check ECFMP first** — open
   https://ecfmp.vatsim.net/dashboard in another tab. Note any
   flow measures that apply to your route (mandatory reroutes,
   level caps, etc.). If you file a route that violates an active
   measure, our system will reject your request.
3. **Fill in the form** → Submit
4. The server either:
   - **Issues a CTOT** — hold your block until the CTOT. The system
     gives you a 15-minute window; if you haven't taken off by
     CTOT + 15, your slot auto-releases.
   - **Rejects** with one of:
     - `CTP_EXCLUDED`: your ADEP or ADES is booked-traffic-only.
       Pick another airport.
     - `ECFMP_REJECT`: an active ECFMP measure blocks your route.
       The message includes the measure's `ident`, `reason`, and
       (for mandatory reroutes) the required routing text. Refile
       and try again.
     - `CANADIAN_HANDLED_ELSEWHERE`: ADEP or ADES is one of the 7
       Canadian event airports already metered by the main
       atfm-tools allocator — you don't need a non-event CTOT,
       just file and go.
5. **Wait at your CTOT** — squawk standby, don't take a TSAT
   earlier than CTOT-2 min. Taking off before CTOT can trigger
   follow-up ATC intervention.
6. **If you miss your CTOT** — submit a new request. The new
   request goes to the next free slot at ADES (usually within
   15 min).

### Things to know
- The CTOT is **stable** once issued. Subsequent wind-forecast
  updates will not shift your time.
- One active slot per callsign. If you request twice, you'll get
  two slots — but your old one expires on its own schedule.
- The system is **advisory in v1** — there's no hard enforcement
  of compliance. That said, controllers CAN see your CTOT on the
  ATCO dashboard and may ask why you're early if you jump the gun.

## For ATCOs

### What's different for you
Because you're signed in with an ATCO-rated VATSIM Connect account
(S1 / S2 / S3 / C1 / C3 / I1 / I3 / SUP / ADM), you get:
- A **live dashboard** of every active non-event CTOT across all
  airports
- A **flight-plan puller** — enter a pilot's callsign and the
  system looks up their filed plan from our ingestor cache and
  auto-fills the request form. You click Submit on their behalf.
- Ability to **cancel** any slot (e.g. if the pilot abandoned the
  flight or filed a new route)

### Typical workflow
1. Pilot calls you on text/voice asking for a CTOT (common on
   VATSIM-UK / VATCAN / VATUSA fields without a local CD)
2. You open `/ctot.html`, sign in, switch to the **ATCO** tab
3. Type the pilot's callsign in the flight-plan puller
4. System fills ADEP/ADES/EOBT/route/type/FL from our ingestor
5. Confirm with the pilot, Submit
6. Read the returned CTOT back to the pilot

### When to cancel a slot
- Pilot disconnected and isn't coming back
- Pilot refiled a different route and we issued a fresh slot for
  it (the old one is stale — releasing it frees the bin for
  someone else)

### What the dashboard shows
Columns: callsign, ADEP → ADES, CTOT, ELDT, submitted_by. Sorted
by CTOT ascending. Expired and released slots are hidden.

## Reference: ECFMP flow measure types

If you get an `ECFMP_REJECT`, the `measure.type` field will be one
of:

| Type | What it means for you |
|---|---|
| `MANDATORY_ROUTE` | You MUST file the route shown in `measure.value`. Refile and retry. |
| `PROHIBIT` | Your filed route is prohibited during the measure window. File an alternate. |
| `GROUND_STOP` | All departures matching the filter are grounded. Wait until the measure expires. |
| `MILES_IN_TRAIL`, `PER_HOUR`, `MIN_DEPARTURE_INTERVAL` | Rate / spacing restriction. Our allocator will honour it automatically; you'll see the CTOT shifted to respect the rate. |
| `MAX_IAS`, `MAX_MACH`, `IAS_REDUCTION`, `MACH_REDUCTION` | Speed restriction en route. We don't block you, but honour it in flight. |

See https://ecfmp.vatsim.net/dashboard for the full active list.

## FAQ

**Q: Why are Canadian airports blocked?**
The main atfm-tools allocator already issues CTOTs at CYHZ / CYOW /
CYUL / CYVR / CYWG / CYYC / CYYZ based on AAR. Non-event flights
to/from those airports go through the main system, not this one.

**Q: What's the difference between a CTP event CTOT and a non-event
CTOT?**
Event CTOTs are published by the CTP planning team weeks in advance
through their slot booking system. Non-event CTOTs are issued on
demand by this portal for pilots flying alongside the event.

**Q: Why 4 slots per hour?**
Light metering. 4/hr = 15-minute intervals = sustainable for any
airport without starving its normal traffic. It's a reasonable
default for non-event flow management; we can tune per-airport later.

**Q: Where's the CDM plugin output?**
Not present in this layer. Most target users (US pilots) don't run
the CDM plugin. The portal is the delivery channel.

**Q: What if I'm in Canadian airspace but my ADEP/ADES isn't a
CTP / Canadian airport?**
Flight still gets a CTOT from this system. The Canadian-airport
block only fires if your ADEP OR ADES is one of the 7 Canadian
airports we manage directly.

**Q: What if the ECFMP API is down?**
We cache the last known measure set. If the cache is also empty
(cold start + outage), we fail open — no ECFMP checks happen and
your request goes through based on the rest of the rules. A banner
on the page will show "ECFMP unavailable".

**Q: Can I see someone else's CTOT?**
Pilots see only their own. ATCOs see the full active list.
