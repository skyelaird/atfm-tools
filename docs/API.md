# atfm-tools API Reference

**Base URL**: `http://atfm.momentaryshutter.com`
**Version**: v0.5.7
**Authentication**: None (all endpoints are public)
**Format**: All responses are JSON with `Content-Type: application/json`
**Timestamps**: ISO 8601 with UTC offset (`2026-04-12T14:30:00+00:00`) except where noted

---

## Quick reference

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/health` | Health check + version |
| GET | `/api/v1/flights` | **Flight data (PERTI SWIM-compatible)** |
| GET | `/api/v1/status` | System status + OpLevel |
| GET | `/api/v1/airports` | Airport list with live traffic counts |
| GET | `/api/v1/airports/{icao}/detail` | Airport detail (drawer data) |
| GET | `/api/v1/restrictions` | Active restrictions |
| GET | `/api/v1/reports/summary` | Per-airport KPI rollup |
| GET | `/cdm/etfms/restricted` | **CDM plugin CTOT feed** |
| GET | `/cdm/airport` | CDM airport list |

---

## 1. Health & System

### `GET /api/health`

Health check. Returns the running version.

```json
{
  "status": "ok",
  "time": "2026-04-12T16:30:00+00:00",
  "version": "0.5.7"
}
```

### `GET /api/v1/status`

System dashboard rollup. Includes OpLevel, active restrictions, airport counts, last allocation run stats.

**Response fields:**

| Field | Type | Description |
|-------|------|-------------|
| `op_level` | int | 1=Steady State, 2=Localized, 3=Regional, 4=NAS-Wide |
| `op_level_label` | string | Human-readable label |
| `affected_airport_icaos` | array | Airports with active restrictions |
| `affected_firs` | array | FIRs involved (FIR adjacency-derived) |
| `last_ingest_at` | string | ISO 8601 timestamp of last VATSIM feed ingest |
| `last_allocation` | object/null | Stats from the most recent allocator run |
| `version` | string | Running version |

---

## 2. Flights API (PERTI SWIM-compatible)

### `GET /api/v1/flights`

The primary data endpoint for external consumers. Returns flights with a
schema that mirrors PERTI's SWIM v1 field naming at the top level, extended
with atfm-tools' A-CDM milestones and taxi metrics below.

**Query parameters:**

| Param | Default | Description |
|-------|---------|-------------|
| `airport` | *(all 7)* | Filter by ICAO (flights where `adep` OR `ades` matches) |
| `direction` | `both` | `inbound`, `outbound`, or `both` (relative to `airport`) |
| `active` | `1` | `1` = exclude terminal phases (ARRIVED/WITHDRAWN/DISCONNECTED) |
| `hours` | `6` | Lookback window in hours (max 48) |
| `phase` | *(all)* | Comma-separated phase filter (e.g. `ENROUTE,ARRIVING`) |

**Example requests:**

```bash
# All active flights across all 7 airports
curl http://atfm.momentaryshutter.com/api/v1/flights

# CYYZ inbounds only
curl http://atfm.momentaryshutter.com/api/v1/flights?airport=CYYZ&direction=inbound

# Completed flights in the last 24h (for cross-validation)
curl http://atfm.momentaryshutter.com/api/v1/flights?active=0&hours=24

# Only airborne flights heading to CYHZ
curl http://atfm.momentaryshutter.com/api/v1/flights?airport=CYHZ&direction=inbound&phase=ENROUTE,ARRIVING
```

**Response envelope:**

```json
{
  "generated_at": "2026-04-12T16:30:00+00:00",
  "version": "0.5.7",
  "source": "atfm-tools",
  "count": 42,
  "filters": {
    "airport": "CYYZ",
    "direction": "inbound",
    "active": true,
    "hours": 6,
    "phase": null
  },
  "flights": [ ... ]
}
```

**Per-flight object:**

```json
{
  // ---- PERTI SWIM v1 compatible fields ----
  // Use these if you're integrating with PERTI or need a
  // standard CDM vocabulary.
  "callsign": "ACA456",
  "cid": 1234567,
  "departure": "CYUL",           // PERTI name for ADEP
  "arrival": "CYYZ",             // PERTI name for ADES
  "aircraft_short": "A320",      // ICAO type designator
  "deptime": "1430",             // EOBT as HHMM string (PERTI convention)
  "ctd_utc": "2026-04-12T14:35:00+00:00",  // PERTI name for CTOT
  "cta_utc": "2026-04-12T15:45:00+00:00",  // PERTI name for TLDT
  "phase": "ENROUTE",
  "delay_status": "DELAYED",

  // ---- atfm-tools extensions ----

  // Identity
  "flight_key": "1234567|ACA456|CYUL|CYYZ|1430",
  "flight_rules": "I",
  "wake_category": "M",

  // Flight plan
  "fp_route": "YUL J547 RAGID ...",
  "fp_altitude_ft": 35000,
  "fp_cruise_tas": 450,
  "fp_enroute_time_min": 68,

  // A-CDM milestones (ISO 8601, null if not yet observed)
  "eobt": "2026-04-12T14:30:00+00:00",
  "tobt": "2026-04-12T14:30:00+00:00",
  "tsat": "2026-04-12T14:30:00+00:00",
  "ttot": "2026-04-12T14:50:00+00:00",
  "ctot": "2026-04-12T14:35:00+00:00",
  "aobt": "2026-04-12T14:32:00+00:00",
  "atot": "2026-04-12T14:42:00+00:00",
  "eldt": "2026-04-12T15:48:00+00:00",
  "aldt": null,
  "aibt": null,

  // ELDT prediction quality
  "eldt_locked": "2026-04-12T14:48:00+00:00",
  "eldt_locked_at": "2026-04-12T14:48:00+00:00",
  "eldt_locked_source": "OBSERVED_POS",

  // TLDT (allocator's slot decision)
  "tldt": "2026-04-12T15:45:00+00:00",
  "tldt_assigned_at": "2026-04-12T14:35:00+00:00",

  // Taxi metrics (minutes)
  "planned_exot_min": 12,
  "actual_exot_min": 10,
  "planned_exit_min": 8,
  "actual_exit_min": null,

  // Regulation
  "ctl_type": "AIRPORT_ARR_RATE",
  "ctl_element": "CYYZ",
  "ctl_restriction_id": "CYYZ11VP",
  "delay_minutes": 5,

  // Last known position
  "position": {
    "lat": 45.123,
    "lon": -74.567,
    "altitude_ft": 35000,
    "groundspeed_kts": 460,
    "heading_deg": 245,
    "updated_at": "2026-04-12T15:30:00+00:00"
  }
}
```

**PERTI field mapping:**

| PERTI field | atfm-tools equivalent | Notes |
|-------------|----------------------|-------|
| `callsign` | `callsign` | Same |
| `cid` | `cid` | VATSIM CID |
| `departure` | `adep` | ICAO code |
| `arrival` | `ades` | ICAO code |
| `aircraft_short` | `aircraft_type` | ICAO type designator |
| `deptime` | `eobt` | PERTI uses HHMM; we also provide full ISO 8601 in `eobt` |
| `ctd_utc` | `ctot` | Calculated Time of Departure |
| `cta_utc` | `tldt` | Our TLDT = PERTI's CTA (the landing slot) |
| `phase` | `phase` | Same vocabulary |
| `delay_status` | `delay_status` | ON_TIME, DELAYED, COMPLIANT_DEPARTED, NON_COMPLIANT, FLS_NRA, WITHDRAWN |

---

## 3. CDM Plugin Protocol

These endpoints serve the CDM EuroScope plugin via its `customRestricted`
URL feature. The plugin polls every 5 minutes.

### `GET /cdm/etfms/restricted`

**The primary CTOT feed.** Returns flights with active CTOTs in the format
the CDM plugin expects.

```json
[
  {
    "callsign": "ACA456",
    "ctot": "1847",
    "mostPenalizingAirspace": "CYYZ-ARR"
  }
]
```

| Field | Type | Description |
|-------|------|-------------|
| `callsign` | string | Flight callsign |
| `ctot` | string | CTOT as HHMM (e.g. "1847"), empty string if null |
| `mostPenalizingAirspace` | string | Airport ICAO + "-ARR" suffix |

**Filter logic:** Only flights where CTOT is set, CTOT >= now, and phase
is not ARRIVED/WITHDRAWN/DISCONNECTED.

### `GET /cdm/airport`

Returns the list of configured airports.

```json
[
  { "icao": "CYHZ", "name": "Halifax Stanfield International", ... },
  { "icao": "CYYZ", "name": "Toronto Lester B. Pearson International", ... }
]
```

### `GET /cdm/ifps/depAirport?airport=CYYZ`

Returns flights departing from the specified airport. Used by the CDM
plugin for departure sequencing display.

### Stub endpoints

The following endpoints exist for CDM plugin compatibility but return
empty/stub responses. The plugin requires them to be present but doesn't
use the data for our scope:

| Path | Response |
|------|----------|
| `/cdm/etfms/restrictions` | `[]` |
| `/cdm/etfms/relevant` | `[]` |
| `/cdm/ifps/cidCheck` | `{"exists": false}` |
| `/cdm/ifps/allStatus` | `[]` |
| `/cdm/ifps/allOnTime` | `[]` |
| `/cdm/ifps/dpi` | `{"ok": true}` |
| `/cdm/ifps/setCdmData` | `{"ok": true}` |
| `/cdm/airport/setMaster` | `{"ok": true}` |
| `/cdm/airport/removeMaster` | `{"ok": true}` |
| `/cdm/airport/removeAllMasterByPosition` | `{"ok": true}` |
| `/cdm/airport/removeAllMasterByAirport` | `{"ok": true}` |

---

## 4. Airport Endpoints

### `GET /api/v1/airports`

List of all 7 configured airports with live traffic counts.

```json
[
  {
    "icao": "CYYZ",
    "name": "Toronto Lester B. Pearson International",
    "base_arrival_rate": 66,
    "inbound_count": 17,
    "outbound_count": 10
  }
]
```

### `GET /api/v1/airports/{icao}`

Single airport detail.

### `GET /api/v1/airports/{icao}/detail`

Composite endpoint for the dashboard airport drawer. Returns airport
metadata, active restrictions, inbound flights (sorted by ELDT, nulls
last), outbound flights (dropped at wheels-up), recent arrivals (last
60 min), recent departures (last 60 min), hourly movement histogram
(last 24h), and rolling stats.

**Inbound flights include:**
- Full A-CDM milestone chain: EOBT, AOBT, ATOT, ELDT, TLDT, CTOT
- ELDT source indicator (live, filed, pos, tas, type, def)
- ELDT_locked snapshot for validation
- Live ELDT computed by EtaEstimator fallback if stored value is null

**Recent arrivals include:**
- ELDT_locked (the prediction we froze at T-2h)
- TLDT (the slot we assigned, if any)
- ALDT (actual)
- AIBT + AXIT

**Recent departures include:**
- EOBT, AOBT, ATOT, AXOT, EOBT delta

### `POST /api/v1/airports`

Create or update an airport. Body: JSON with airport fields.

### `GET /api/v1/airports/{icao}/restrictions`

List restrictions for one airport.

### `POST /api/v1/airports/{icao}/restrictions`

Create a restriction. Body fields:

| Field | Default | Description |
|-------|---------|-------------|
| `capacity` | airport's base_arrival_rate | Rate in movements/hr |
| `reason` | `ATC_CAPACITY` | Free text |
| `op_level` | `2` | 1-4 (Steady State to NAS-Wide) |
| `type` | `ARR` | ARR, DEP, or BOTH |
| `tier_minutes` | `120` | How far ahead to look for inbounds |
| `start_utc` | `0000` | HHMM |
| `end_utc` | `2359` | HHMM |
| `compliance_window_early_min` | `5` | CTOT compliance window |
| `compliance_window_late_min` | `5` | |
| `expires_at` | null | ISO 8601 auto-expiry |

### `DELETE /api/v1/airport-restrictions/{id}`

Delete a restriction by its restriction_id.

---

## 5. Reports

### `GET /api/v1/reports/summary`

Per-airport KPI rollup for the reports page.

**Query parameters:**

| Param | Default | Description |
|-------|---------|-------------|
| `hours` | `24` | Lookback window (max 168 = 7 days) |

**Response includes per airport:**

| Field | Description |
|-------|-------------|
| `arrivals`, `departures` | Movement counts in the window |
| `avg_exot_min`, `p90_exot_min` | AXOT (ATOT minus AOBT) |
| `avg_exit_min`, `p90_exit_min` | AXIT (AIBT minus ALDT) |
| `avg_eobt_delay_min` | Mean AOBT minus EOBT (how late pilots push vs filed) |
| `eldt_bias_min` | Mean ALDT minus ELDT_locked (prediction accuracy) |
| `eldt_p90_abs_min` | p90 absolute ELDT error |
| `eldt_within_tolerance_pct` | % of ELDT predictions within +/-3 min |
| `eldt_sample_n` | ELDT sample size |
| `tldt_bias_min` | Mean ALDT minus TLDT (slot fidelity) |
| `tldt_within_tolerance_pct` | % of TLDTs honoured within +/-3 min |
| `tldt_sample_n` | TLDT sample size |

**Also includes:**
- `tolerance_min`: the +/-N min threshold (currently 3)
- `eldt_lock_horizon_min`: when ELDT is frozen (currently 60 min)
- `airport_icaos`: ordered west-to-east for the aircraft types table
- `top_aircraft`: top 20 types with per-airport inbound counts

---

## 6. Event Sources

### `GET /api/v1/event-sources`

List configured VATCAN event sources.

### `POST /api/v1/event-sources`

Create/update an event source. Body: `event_code`, `label`, `start_utc`, `end_utc`, `active`.

### `DELETE /api/v1/event-sources/{event_code}`

Delete an event source.

---

## 7. ECFMP Plugin Mirror (Legacy)

These endpoints mirror the ECFMP API structure for compatibility with
EuroScope plugins that expect the ECFMP schema.

### `GET /api/v1/plugin`

Returns `{events, flight_information_regions, flow_measures}`.
Supports `?deleted=1` to include soft-deleted measures.

### `GET /api/v1/flight-information-region`

Array of FIRs.

### `GET /api/v1/flow-measure`

Array of flow measures. Supports `?state=active`.

---

## 8. Debug Endpoints

For development and troubleshooting. Not intended for external consumers.

| Path | Description |
|------|-------------|
| `/api/v1/debug/traffic?airport=XXXX` | Active + recent flights (limit 200) |
| `/api/v1/debug/flights/active` | Flights with CTOT assigned |
| `/api/v1/debug/allocation` | Last 20 allocation runs |
| `/api/v1/debug/imported-ctots` | Active imported CTOTs |
| `/api/v1/debug/runway-thresholds` | All runway threshold geometry |

---

## 9. Integration Guide

### For PERTI / Jeremy (vATCSCC)

Use `/api/v1/flights` as the primary data source. The top-level fields
(`callsign`, `cid`, `departure`, `arrival`, `aircraft_short`, `deptime`,
`ctd_utc`, `cta_utc`, `phase`, `delay_status`) use PERTI's SWIM v1 naming
convention. The extended fields below provide A-CDM milestones and metrics
that PERTI doesn't compute.

**Recommended polling cadence:** every 2-5 minutes.

**Cross-validation:** compare our `cta_utc` (TLDT) with PERTI's CTA for
the same flight. Our `eldt` is the live prediction; theirs may differ
because we use descent-aware estimation with type-specific speed schedules.

### For CDM EuroScope Plugin operators

Point the plugin's `customRestricted` URL at:
```
http://atfm.momentaryshutter.com/cdm/etfms/restricted
```

The plugin will poll this URL on its configured interval and display
CTOTs in the EuroScope tag for regulated flights.

**CDM airport list:** `/cdm/airport` returns our 7 configured airports.

**Departure view:** `/cdm/ifps/depAirport?airport=CYYZ` returns flights
departing from the specified airport with their CDM milestones.

### For dashboard / monitoring consumers

Use `/api/v1/status` for the system-level overview (OpLevel, restriction
count, last ingest timestamp). Use `/api/v1/airports` for the airport
grid with live counts. Use `/api/v1/airports/{icao}/detail` for the
full airport drill-down.

### For analytics / reporting consumers

Use `/api/v1/reports/summary?hours=N` for aggregated KPIs. The response
includes ELDT and TLDT accuracy metrics that indicate how well the
system's predictions and slot allocations match reality.

Use `/api/v1/flights?active=0&hours=48` for raw flight-level data
including completed arrivals with all milestones populated.

---

## 10. Conceptual Model

```
ELDT  = prediction       EtaEstimator computes this every 2 min.
                          Descent-aware with type-specific speed schedules.
                          Frozen at T-2h as eldt_locked for validation.

TLDT  = control decision  The allocator assigns a landing slot when a
                          restriction is active. TLDT is the slot time.
                          Set once, never revised.

CTOT  = enforcement       Derived from TLDT for ground-bound flights:
                          CTOT = TTOT + (TLDT delay). Only issued when
                          delay >= 5 min. Airborne flights get a TLDT
                          but no CTOT (controller must slow/vector).

ALDT  = truth             Observed landing time from the VATSIM feed.
                          Captured when the aircraft is first observed
                          on the ground at the destination airport.
```

**Validation metrics:**
- `ALDT - ELDT_locked` = how good our prediction was at the T-2h freeze point
- `ALDT - TLDT` = how well the slot allocation matched reality
- Target: +/-3 min for both

---

## 11. Scope

**Airports:** CYHZ, CYOW, CYUL, CYVR, CYWG, CYYC, CYYZ

**Data source:** VATSIM live feed (`data.vatsim.net/v3/vatsim-data.json`),
polled every 2 minutes.

**Not in scope:** winds aloft, GRIB data, real-world weather, ATIS parsing,
runway detection from track (uses heading-matching against runway_thresholds),
ADS-B/Mode-S data (VATSIM provides only position, altitude, groundspeed,
heading, squawk).
