# ECFMP — Research Reference

> Research document for ECFMP integration with atfm-tools.
> Sources: local forks of `ECFMP/flow`, `ECFMP/ECFMP-plugin-sdk`,
> `ECFMP/ecfmp-protobuf`; ECFMP user-facing documentation (`.ecfmp-ref/`);
> OpenAPI v1 spec (`api-spec-v1.json`).

---

## 1. What is ECFMP?

**ECFMP** = European Collaboration & Flow Management Project. It is VATSIM
Europe's centralised system for authoring, publishing, and distributing
**flow measures** — traffic restrictions that controllers across the network
implement to manage congestion.

Key facts:

- **Web app** at `ecfmp.vatsim.net`, built on **Laravel** (PHP).
- **Auth**: VATSIM Connect (OAuth2). Users log in with their VATSIM CID.
- **Permission tiers**: Normal User (read-only), Event Manager (create
  events + participant lists), Flow Manager (issue flow measures for their
  FIR), Network Management Team (regional measures, grant permissions).
- **Does NOT compute CTOTs.** ECFMP is strictly a flow-measure authoring
  and publishing platform. Slot allocation is left to downstream tools
  (vIFF, atfm-tools, etc.).
- **Discord integration**: flow measure lifecycle notifications
  (notified/active/expired/withdrawn) posted to the ECFMP Discord server
  and optionally to Division/vACC Discords via webhooks.
- **Source**: `github.com/ECFMP/flow` (GPL-3.0).

---

## 2. How ECFMP Publishes Flow Measures

### 2.1 REST API (primary integration surface)

ECFMP exposes a **public, unauthenticated REST API** at:

```
https://ecfmp.vatsim.net/api/v1/
```

No API key or token is required. The only request is reasonable polling
frequency (see acceptable use below).

**Endpoints** (from the OpenAPI v1 spec):

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/plugin` | GET | **Combined endpoint** for plugins. Returns events, FIRs, and flow measures (notified + active + recently finished) in a single response. This is what the C++ SDK polls. |
| `/api/v1/flow-measure` | GET | All flow measures. Supports `?active=1`, `?notified=1`, `?deleted=1` query filters. Default: measures starting in next 24h through 24h after end. |
| `/api/v1/flow-measure/{id}` | GET | Single flow measure by ID. |
| `/api/v1/event` | GET | All events. Supports `?active=1`, `?upcoming=1`, `?finished=1`, `?deleted=1`. |
| `/api/v1/event/{id}` | GET | Single event by ID. |
| `/api/v1/flight-information-region` | GET | All FIRs. |
| `/api/v1/flight-information-region/{id}` | GET | Single FIR by ID. |
| `/api/v1/airport-group` | GET | Airport groups (e.g. "London TMA Group"). |
| `/api/v1/airport-group/{id}` | GET | Single airport group by ID. |

### 2.2 Acceptable Use

From ECFMP documentation:

> We don't restrict how often you can call our API, however we ask that
> you don't use it excessively. We consider it reasonable to call our flow
> measures endpoint up to **once every minute**. FIRs and events don't
> change commonly — re-calling them every minute is not necessary.

The C++ plugin SDK uses a **90-second poll interval** against `/api/v1/plugin?deleted=1`.

### 2.3 Protobuf / gRPC

The `ecfmp-protobuf` repo contains proto definitions for **internal ECFMP
microservices only** — specifically a Discord notification service
(`discord.proto`) and a health check service (`health.proto`). These are
**not part of the public API**. External consumers use the REST API
exclusively. The protobuf channel is for ECFMP's own backend-to-backend
communication (sending Discord embeds for flow measure lifecycle events).

### 2.4 Server-side Caching

The ECFMP Laravel app caches the `/api/v1/plugin` response for **1 minute**
(`Cache::remember('plugin_api_response', Carbon::now()->addMinutes(1), ...)`).

---

## 3. Flow Measure Data Model

### 3.1 `/api/v1/plugin` Response Shape

```json
{
  "events": [ ... ],
  "flight_information_regions": [ ... ],
  "flow_measures": [ ... ]
}
```

This is the same shape atfm-tools already mirrors at its own
`/api/v1/plugin` endpoint.

### 3.2 Flow Measure Object

From the OpenAPI spec and Laravel resource serializer:

```json
{
  "id": 10,
  "ident": "EGTT25B",
  "event_id": 44,
  "reason": "Due runway capacity",
  "starttime": "2022-04-18T13:15:30Z",
  "endtime": "2022-04-18T13:15:30Z",
  "withdrawn_at": null,
  "measure": {
    "type": "per_hour",
    "value": 5
  },
  "filters": [
    { "type": "ADEP", "value": ["EGKK", "EGLL", "EGSS"] },
    { "type": "ADES", "value": ["EH**"] },
    { "type": "level", "value": [230, 240] }
  ],
  "notified_flight_information_regions": [1, 2, 42]
}
```

### 3.3 Measure Types

| Type string | Value type | Unit | Description |
|-------------|-----------|------|-------------|
| `minimum_departure_interval` | int | seconds | Min time between departures |
| `average_departure_interval` | int | seconds | Avg interval over 3 departures |
| `per_hour` | int | flights/hr | Rate cap per hour |
| `miles_in_trail` | int | NM | Spacing in trail |
| `max_ias` | int | knots | Max indicated airspeed |
| `max_mach` | int | hundredths | Max Mach (82 = M0.82) |
| `ias_reduction` | int | knots | Reduce IAS by N kt |
| `mach_reduction` | int | hundredths | Reduce Mach by N (5 = M0.05) |
| `prohibit` | null | - | Prohibit matching traffic |
| `ground_stop` | null | - | No departures permitted |
| `mandatory_route` | string[] | route strings | Required routing (OR logic between entries) |

### 3.4 Filter Types

Filters define which traffic a flow measure applies to. Multiple filter
types are joined by **AND**; multiple values within the same filter type
are joined by **OR**.

| Filter type | Value type | Description |
|-------------|-----------|-------------|
| `ADEP` | string[] | Departure airports. Supports wildcards (`EI**`). Mandatory. |
| `ADES` | string[] | Arrival airports. Supports wildcards. Mandatory. |
| `waypoint` | string[] | Route strings (e.g. `SASKI L608 LOGAN`). OR between entries. |
| `level_above` | int | Flight level (inclusive) and above. |
| `level_below` | int | Flight level (inclusive) and below. |
| `level` | int[] | Exact flight levels. Cannot combine with level_above/below. |
| `member_event` | object | Event participants only (by CID list or VATCAN code). |
| `member_not_event` | object | Non-event participants only. |
| `range_to_destination` | int | Applicable when within N NM of destination. |

### 3.5 Flow Measure Status Lifecycle

```
NOTIFIED  ──(start_time reached)──>  ACTIVE  ──(end_time reached)──>  EXPIRED
                                       │
                                  (soft-delete)
                                       │
                                       v
                                   WITHDRAWN
```

- **Notified**: created but not yet active (start_time in the future).
  Notifications go out 24h before activation, or immediately if created
  within 24h.
- **Active**: currently in effect (now between start_time and end_time).
- **Expired**: end_time has passed. Visible in API for several hours after.
- **Withdrawn**: soft-deleted before expiry. `withdrawn_at` timestamp set.

Edits to active measures append a revision suffix to the identifier
(e.g. `EGTT06A` becomes `EGTT06A-2`).

### 3.6 Event Object

```json
{
  "id": 10,
  "name": "Heathrow Overload",
  "date_start": "2022-04-18T13:15:30Z",
  "date_end": "2022-04-18T13:15:30Z",
  "flight_information_region_id": 1,
  "vatcan_code": "abcd",
  "participants": [
    { "cid": 1203533, "origin": "EGKK", "destination": "EHAM" }
  ]
}
```

### 3.7 Flight Information Region Object

```json
{
  "id": 10,
  "identifier": "EGTT",
  "name": "London"
}
```

### 3.8 Airport Group Object

```json
{
  "id": 10,
  "name": "London TMA Group",
  "airports": ["EGLL", "EGKK", "EGLC"]
}
```

---

## 4. ECFMP Plugin SDK (C++ / EuroScope)

The ECFMP plugin SDK (`github.com/ECFMP/ECFMP-plugin-sdk`) is a C++
library designed for EuroScope plugins. It handles polling the ECFMP API
and provides an in-process event bus for flow measure lifecycle events.

### 4.1 Architecture

```
EuroScope plugin  <──>  ECFMP SDK  <──HTTP GET──>  ecfmp.vatsim.net/api/v1/plugin
                           │
                     Event bus (in-process)
                           │
                     ┌─────┴─────────────────────────────┐
                     │  FlowMeasureNotifiedEvent          │
                     │  FlowMeasureActivatedEvent         │
                     │  FlowMeasureExpiredEvent            │
                     │  FlowMeasureWithdrawnEvent          │
                     │  FlowMeasureReissuedEvent           │
                     │  FlowMeasuresUpdatedEvent           │
                     │  EventsUpdatedEvent                 │
                     └───────────────────────────────────┘
```

### 4.2 SDK Setup

```cpp
auto sdk = ECFMP::Plugin::SdkFactory::Build()
    .WithHttpClient(std::make_unique<MyHttpClient>())
    .WithLogger(std::make_unique<MyLogger>())
    .WithCustomFlowMeasureFilter(myFilter)  // optional
    .Instance();
```

The SDK requires:
- **HttpClient**: consumer-provided HTTP implementation (the SDK doesn't
  bundle one — EuroScope plugins typically use WinHTTP).
- **Logger**: consumer-provided logging.
- **OnEuroscopeTimerTick()**: must be called every ~1 second from
  EuroScope's timer callback.

### 4.3 Poll Cycle

The SDK's `ApiDataScheduler` fires an API download every **90 seconds**.
It hits `https://ecfmp.vatsim.net/api/v1/plugin?deleted=1` (includes
withdrawn measures so the SDK can track the full lifecycle).

### 4.4 Applicability Checking

The SDK can check whether a flow measure applies to a specific aircraft:

```cpp
bool applies = flowMeasure->ApplicableToAircraft(flightplan, radarTarget);
```

This evaluates all filters (ADEP, ADES, level, waypoint, event membership,
range to destination) against the EuroScope flight plan and radar target
objects. Consumers can also inject a `CustomFlowMeasureFilter` for
additional logic.

### 4.5 Canonical Flow Measure Info

When a flow measure is edited, ECFMP creates a new record with an
incremented revision (e.g. `EGTT06A-2`). The `CanonicalFlowMeasureInfo`
class parses the identifier to extract the base identifier and revision
number, allowing the SDK to detect reissues and fire
`FlowMeasureReissuedEvent`.

---

## 5. atfm-tools Current ECFMP Relationship

### 5.1 What atfm-tools Already Does

atfm-tools **mirrors the ECFMP `/api/v1/plugin` response shape** at its
own endpoint:

```
GET /api/v1/plugin
```

This serves atfm-tools' own flow measures (from its `flow_measures` table)
in the exact JSON format ECFMP uses, so that any ECFMP-compatible plugin
could consume them. Currently returns an empty `events` array.

### 5.2 What atfm-tools Does NOT Do

- Does not **consume** ECFMP data (no inbound polling of ecfmp.vatsim.net).
- Does not **publish** to ECFMP (no write API exists at ECFMP; measures are
  authored via the ECFMP web UI only).
- Does not use the C++ plugin SDK (atfm-tools is a PHP backend, not a
  EuroScope plugin).

---

## 6. Integration Possibilities

### 6.1 Consume ECFMP Flow Measures (inbound)

**Use case**: when a European FMP issues a `per_hour` or
`minimum_departure_interval` measure with ADES matching a Canadian airport
(e.g. transatlantic events), atfm-tools could automatically apply that
rate constraint to its CTOT allocator.

**Implementation**:
1. Add a cron job that polls `https://ecfmp.vatsim.net/api/v1/flow-measure?active=1`
   every 2 minutes.
2. Filter for measures where any `ADES` filter matches one of the 7 scope
   airports.
3. For `per_hour` measures: translate to an arrival rate override on the
   matching airport restriction.
4. For `ground_stop` measures: pause CTOT allocation for matching
   departures.
5. For speed/MIT measures: informational display only (atfm-tools doesn't
   control airborne traffic).

**Relevance**: low in practice. ECFMP is European-centric. Transatlantic
flow measures targeting Canadian ADES are extremely rare on VATSIM. Worth
having the plumbing for completeness but not a priority.

### 6.2 Publish to ECFMP (outbound)

**Feasibility**: **not possible** via API. ECFMP has no write/create API
for flow measures. Measures are authored exclusively through the ECFMP web
UI by authenticated Flow Managers. There is no programmatic way for
atfm-tools to push its restrictions into ECFMP.

If ECFMP ever adds a write API, atfm-tools could publish its airport
restrictions as ECFMP flow measures so that European departures to Canada
see them in their EuroScope plugin.

### 6.3 Serve as an ECFMP-Compatible Source (current approach)

This is what atfm-tools already does: it mirrors the `/api/v1/plugin`
response shape so that any tool expecting ECFMP-format data can consume
atfm-tools' flow measures. The CDM plugin (via `customRestricted`) uses
a different endpoint, but the ECFMP-compatible endpoint is available for
any future integration.

---

## 7. Auth Requirements Summary

| Endpoint | Auth | Notes |
|----------|------|-------|
| `ecfmp.vatsim.net/api/v1/*` | **None** | Public, unauthenticated |
| ECFMP web UI (create/edit measures) | VATSIM Connect OAuth2 | Requires Flow Manager role |
| ECFMP Discord webhooks | Webhook URL (configured by ECFMP team) | Division requests setup |

---

## 8. Key Technical Details

- **Base URL**: `https://ecfmp.vatsim.net/api/v1`
- **Response format**: JSON
- **Times**: UTC, ISO 8601 format with `Z` suffix
- **Soft deletes**: withdrawn measures have `withdrawn_at` set; pass
  `?deleted=1` to include them
- **Server cache**: plugin endpoint cached for 1 minute server-side
- **SDK poll interval**: 90 seconds
- **Recommended client poll**: no more than once per minute
- **FIR list**: sourced from Eurocontrol; modifications only at local FIR
  request + system team agreement
- **Airport groups**: system-team or NMT defined (e.g. "London TMA Group")

---

## 9. Open Questions / Still Unknown

1. **Canadian FIRs in ECFMP**: are CZUL, CZYZ, CZVR, etc. registered as
   FIRs in the ECFMP system? If not, ECFMP flow measures cannot be
   formally "tagged" to Canadian FIRs. The `/api/v1/flight-information-region`
   endpoint would confirm this. (Likely no — ECFMP is European-focused.)

2. **ECFMP write API roadmap**: no public information on whether ECFMP
   plans to add a write API for programmatic flow measure creation.

3. **Live data sample**: the live `/api/v1/plugin` response would confirm
   current schema version and any undocumented fields. WebFetch was
   unavailable during this research session; a manual `curl` to
   `https://ecfmp.vatsim.net/api/v1/plugin` would fill this gap.

4. **ECFMP v2**: no information found on a v2 API. The OpenAPI spec is
   labeled "v1" and appears stable.

---

## 10. Source References

| Source | Location |
|--------|----------|
| ECFMP flow app (Laravel) | `vendor-forks/atfm-flow/` (fork of `ECFMP/flow`) |
| ECFMP plugin SDK (C++) | `vendor-forks/atfm-plugin-sdk/` (fork of `ECFMP/ECFMP-plugin-sdk`) |
| ECFMP protobuf definitions | `vendor-forks/atfm-protobuf/` (fork of `ECFMP/ecfmp-protobuf`) |
| ECFMP user documentation | `.ecfmp-ref/flow-docs/` |
| OpenAPI v1 specification | `.ecfmp-ref/api-spec-v1.json` |
| atfm-tools ECFMP mirror | `src/Api/Kernel.php` line 119 (`registerEcfmpPluginMirror`) |
| atfm-tools glossary entry | `docs/GLOSSARY.md` line 632 |
