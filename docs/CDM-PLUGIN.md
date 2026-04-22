# atfm-tools ↔ CDM plugin wire contract

**Audience**: engineers working on `src/Api/Kernel.php` `/cdm/*` routes.

**Source of truth for this document**: `rpuig2001/CDM` (upstream), fork
at `skyelaird/CDM`. Everything below is extracted from `CDMSingle.cpp`
at HEAD `083cd56` (2026-04-15) unless otherwise noted. When in doubt,
re-read the source — the README is incomplete and sometimes stale.

---

## 1. The plugin's two server URLs

The CDM plugin reads from **two independently-configurable base URLs**:

| Setting (in `CDMconfig.xml`) | Default | What it serves |
|---|---|---|
| `<viffSystem url="…">` → `cdmServerUrl` | `https://viff-system.network` | Almost everything |
| `<customRestricted url="…">` → `customRestrictedUrl` | *empty* | **`/etfms/restricted` only** when set |

**This is the critical subtlety**: setting only `customRestricted` does
**not** redirect the whole plugin to our server. It redirects *exactly
one endpoint* — CTOT delivery. Everything else (rates, relevant flight
list, master-airport registry, etc.) still goes to whatever
`viffSystem` points at.

Two deployment modes for us:

- **Mode A — CTOT-only override.** Controller sets `<customRestricted>`
  to `https://atfm.momentaryshutter.com/cdm/etfms/restricted`, leaves
  `<viffSystem>` at default. vIFF continues to serve rates / relevant
  flights / etc.; we only inject CTOTs. Our current stubs for the other
  endpoints are unreachable in this mode but harmless.
- **Mode B — full override.** Controller sets **both** `<viffSystem>`
  and `<customRestricted>` to atfm-tools. We become the sole CDM server
  for that controller's position. Requires real payloads on
  `/cdm/etfms/restrictions`, `/cdm/etfms/relevant`, `/cdm/ifps/*`.

Default / supported mode is **A**. Mode B is feasible but not a goal
for v1.

## 2. Auth

Every plugin → server request carries:

```
x-api-key: <apikey>
```

The `apikey` is read from `CDMconfig.xml` (not in the public source).
Our server currently does **not** validate it — any request with any
header (or no header) gets data back. See §6 for the TODO.

## 3. Poll cadence

From the main event loop (`CDMSingle.cpp` ~line 1842):

```cpp
if ((timeNow - countFetchServerTime) > 15 && !refresh3) {
    refresh3 = true;
    countFetchServerTime = timeNow;
    std::thread t(&CDM::refreshActions3, this);
    ...
}
```

and `refreshActions3()` calls, in order:

1. `getCdmServerRestricted(slotList)` → `GET /etfms/restricted`
2. `getCdmServerMasterAirports()`
3. `getCdmServerRelevantFlights()` → `GET /etfms/relevant`
4. `getCdmServerOnTime()`

So each **active master controller polls every ~15 seconds**. Separate
15 s gates govern `refreshActions4` (status + off-block times). The
`<RefreshTime>` XML setting only governs the ECFMP refresh and the
slave-to-master in-plugin update cycle — **not** the server poll rate.
(Our `Kernel.php` comment claiming "every 5 min" was wrong and has been
corrected.)

**Load implication**: at 7 master airports network-wide with N active
masters, our `/cdm/etfms/restricted` endpoint receives roughly `4·N`
requests/minute. Trivial, but worth remembering when we add
auth/logging.

## 4. The four endpoints that matter

### 4.1 `GET /etfms/restricted`  *(override: `customRestricted`)*

**What the plugin does with the response**: iterates the array, and for
every flight it currently knows about that has `ecfmpRestriction == false`
and is not manually-CTOT-disabled, overwrites the in-plugin
`.ctot`, `.flowReason`, and `.hasManualCtot=true`. A flight NOT in the
response has its CTOT *cleared*. So this endpoint is **authoritative —
omitting a callsign means "release any CTOT".**

**Request**: `GET` with `x-api-key`, no query params.

**Response**: bare JSON array. Each element we emit:

```json
{
  "callsign": "ACA123",
  "ctot": "1445",                            // HHMM 4-digit UTC
  "atfcmData": {                             // v2.28+ — British spelling
    "mostPenalisingRegulation": "CYYZ-ARR"
  },
  "mostPenalizingAirspace": "CYYZ-ARR"       // legacy (pre-v2.28) — safe to include
}
```

**Contract change in rpuig2001/CDM v2.28 (2026-04-18)**: the plugin
now reads the penalising-regulation string from the **nested**
`atfcmData.mostPenalisingRegulation` key (British spelling — `-sing-`
not `-zing-`). Pre-v2.28 plugins read the flat top-level
`mostPenalizingAirspace` (American spelling). We emit **both** during
the transition: new plugins ignore unknown top-level keys, old plugins
ignore unknown nested keys. Drop the legacy field once the fleet is on
≥v2.28.

Fields the plugin ignores (but vIFF sends anyway): `delayMin`,
`airspaceList`, `regulationReason`. Safe to send, safe to omit.

**Parser gotchas (`CDMSingle.cpp` ~9253)**:

- CTOT must be **exactly 4 characters** or the plugin silently discards
  it (`if (ctot.size() == 4)`). 3-digit times like `"945"` → dropped.
  Always zero-pad.
- `callsign` + `ctot` are always required. On v2.28+ the plugin needs
  `atfcmData.mostPenalisingRegulation`; on older plugins it needs
  `mostPenalizingAirspace`. Emitting both handles both.
- The parser strips `"` and `\n` manually (legacy JsonCpp quirk). Avoid
  embedding either.

### 4.2 `GET /etfms/restrictions?type=DEP`  *(override: `viffSystem`)*

Dynamic per-runway departure-rate overrides. Merged into the plugin's
`rate.txt` at runtime — a match `(airport, runway)` replaces the rate
loaded from file.

**Request**: `GET ?type=DEP`, `x-api-key` header.

**Response**: JSON array. Each element needs:

```json
{
  "type": "DEP",
  "airspace": "CYYZ",   // ICAO
  "runway": "24R",
  "capacity": "22"      // string, departures/hour
}
```

Only processed if `airspace` matches one of the plugin's currently-
registered master airports. Other rows ignored.

We currently return `[]` (stub). Populating this is how we'd push
"reduced-rate due to weather" to CDM-served airports in Mode B —
*without* needing to construct full CTOTs. Not in v1 scope.

### 4.3 `GET /etfms/relevant`  *(override: `viffSystem`)*

Drives the "relevant flights" panel the FMP sees in EuroScope. Rich
payload. The plugin **requires all 16 fields present** (`isMember`
checks are AND-ed) or the row is silently dropped:

```json
{
  "callsign": "ACA123",
  "cid": "1234567",
  "departure": "CYOW",
  "arrival": "CYYZ",
  "eobt": "1430",
  "tobt": "1432",
  "taxi": "12",
  "ctot": "1450",
  "aobt": "",
  "atot": "",
  "eta": "1605",
  "mostPenalizingAirspace": "CYYZ-ARR",
  "atfcmStatus": "",
  "informed": "false",
  "isCdm": "true",
  "atfcmData": {
    "excluded": "false",
    "isRea": "false",
    "SIR": "false"
  }
}
```

We currently return `[]` (stub). Populating this is the heaviest piece
of Mode B work.

### 4.4 `GET /ifps/depAirport?airport=XXXX`  *(override: `viffSystem`)*

Departure list for one airport. We serve a minimal implementation
(`callsign, cid, adep, ades, eobt, ctot, phase`). The plugin uses this
to populate its queue display for the master airport. Exact field
requirements not yet validated against source — **TODO verify against
`CDMSingle.cpp` getDepAirportPlanes() around line 10094**.

## 5. Endpoints the plugin calls but we stub with empty/OK

All return `[]` or `{"ok": true}`:

| Path | Plugin consumer | What happens if wrong |
|---|---|---|
| `/ifps/cidCheck` | `getCdmServerCidCheck` | plugin thinks a CID isn't registered — prompts user |
| `/ifps/allStatus` | `getCdmServerAllStatus` | empty status panel |
| `/ifps/allOnTime` | `getCdmServerOnTime` (part of the 15s loop!) | no on-time state |
| `/ifps/dpi` | | DPI message push swallowed |
| `/ifps/setCdmData` | | CDM-data push swallowed |
| `/airport/setMaster` | `setMasterAirport` | plugin shows master-set failure |
| `/airport/removeMaster` | | same |
| `/airport/removeAllMasterByPosition` | controller swap flow | slave→master swap might not clear state |
| `/airport/removeAllMasterByAirport` | | same |

In Mode A these are **unreachable** (they hit `viffSystem`, not us). In
Mode B they'd need to actually track state. Parked.

## 6. Known gaps in our implementation

1. ~~Kernel.php comment says "every 5 min"~~ — **fixed this session**; the real cadence is ~15 s per master.
2. **No `x-api-key` validation.** Any client gets CTOTs. Low risk for
   now (data is already PII-clean and public-equivalent), but we should
   at minimum log missing/unexpected keys so we can close the gap when
   we need to.
3. **`/cdm/etfms/restricted` response uses `ctl_element . '-ARR'` for
   `mostPenalizingAirspace`.** Free-text, but worth double-checking it
   renders sensibly in the EuroScope tag.
4. **`/cdm/ifps/depAirport` not source-verified.** Our payload includes
   `adep/ades` but the plugin may expect `departure/arrival` (as it
   does in `/etfms/relevant`). TODO verify.
5. **No telemetry.** We should count requests / unique `x-api-key`
   values / missing-field skips per route, to confirm the plugin is
   actually consuming what we send.

## 7. vIFF feature deltas that affect this contract

From the vIFF changelog of 2026-04-15:

- **"Destination airport calculations now use 20-min windows starting at
  80% of capacity, progressively increasing."** This is vIFF's *internal*
  regulation sizing math. It changes the numeric `capacity` value vIFF
  publishes on its own `/etfms/restrictions?type=DEP`. **We do not
  re-implement this math.** If we ever consume vIFF restrictions into
  our allocator (mode not yet implemented), the `capacity` field is
  already ramped and should be used as-is. One-line note in DESIGN.md §6.
- **"Penalising restriction statistics (AVG/min/max delay, compliance)
  on the map and FMP stats page."** Client-side vIFF feature. Doesn't
  touch our wire contract. If we ever want to show these values on the
  atfm-tools dashboard, we compute them ourselves from `allocation_runs`.
- **"Report Ready TOBT" airport flag + VDGS instructions.** Pure
  client-side UX in the CDM plugin + vIFF. Produces ASRT events we
  cannot observe on VATSIM anyway (per the prime directive, we don't
  fabricate ASRT). No contract change.
- **Traffic Volumes / airspace timeline on map.** En-route flow
  visualisation. Out of scope for atfm-tools by design (§2 non-goals
  in ARCHITECTURE.md).

None of these require us to change Kernel.php or the DB schema.

## 8. How to keep this document honest

When investigating a CDM plugin behaviour:

1. `gh api -X POST repos/skyelaird/CDM/merge-upstream -f branch=master`
   to sync our fork from `rpuig2001/CDM`.
2. Pull `CDMSingle.cpp` to `.cdm-ref/` (gitignored):
   `gh api repos/skyelaird/CDM/contents/CDMSingle.cpp -H "Accept: application/vnd.github.raw" > .cdm-ref/CDMSingle.cpp`
3. Search for the endpoint path (`/etfms/`, `/ifps/`, etc.) in that
   file. Every plugin → server call is an explicit `curl_easy_setopt(...
   CURLOPT_URL ...)` with the path literal.
4. Update this doc with findings and the HEAD SHA it was verified
   against. Don't trust the plugin README — it's incomplete.
