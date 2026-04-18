#!/usr/bin/env python3
"""
Production wind-corrected ELDT computation.

Downloads GFS 250mb winds from NOAA NOMADS, computes wind-corrected ETA
for all eligible airborne flights, and pushes eldt_wind values via the
admin API. Runs as part of the 2-min cron cycle alongside ingest/ctots.

Single-run only (no loop). GRIB data cached for 6 hours in temp dir.
No local storage — results go straight to the API.

Cron line (add to WHC crontab):
  */2 * * * * cd ~/atfm.momentaryshutter.com && python3 bin/compute-wind-eldt.py >> logs/wind.log 2>&1

Or if Python not available on WHC, run from any machine with Python 3.9+:
  python3 bin/compute-wind-eldt.py

Requires: numpy, requests
"""

import json
import math
import os
import re
import struct
import sys
import tempfile
import time
from datetime import datetime, timezone, timedelta
from pathlib import Path

import numpy as np
import requests

# ---------------------------------------------------------------------------
#  Configuration
# ---------------------------------------------------------------------------

API_BASE = os.environ.get("ATFM_API_BASE", "https://atfm.momentaryshutter.com")
NOMADS_URL = "https://nomads.ncep.noaa.gov/cgi-bin/filter_gfs_1p00.pl"

# Grid bounds (1-degree) — covers Canadian FIRs + NAT tracks + European departures
LAT_MIN, LAT_MAX = 40, 65
LON_MIN, LON_MAX = -130, -30

GFS_CYCLES = [0, 6, 12, 18]
DESCENT_FPM = 318  # ft per nm on 3° glidepath
EARTH_NM = 3440.065

AIRPORT_COORDS = {
    "CYHZ": (44.88, -63.51, 477),
    "CYOW": (45.32, -75.67, 374),
    "CYUL": (45.47, -73.74, 118),
    "CYVR": (49.19, -123.18, 14),
    "CYWG": (49.91, -97.24, 783),
    "CYYC": (51.11, -114.02, 3557),
    "CYYZ": (43.68, -79.63, 569),
}

# Eligible airborne phases — must be at cruise or descending
ELIGIBLE_PHASES = frozenset({"ENROUTE", "CRUISE", "DEPARTED", "ARRIVING", "DESCENT"})

# ---------------------------------------------------------------------------
#  GRIB fetching
# ---------------------------------------------------------------------------

def latest_gfs_cycle(now_utc: datetime) -> tuple[str, str]:
    """Return (YYYYMMDD, HH) for most recent GFS cycle (4h pub lag)."""
    adjusted = now_utc - timedelta(hours=4)
    cycle_hour = max(h for h in GFS_CYCLES if h <= adjusted.hour)
    return adjusted.strftime("%Y%m%d"), f"{cycle_hour:02d}"


def fetch_grib(date_str: str, cycle: str, cache_dir: Path) -> Path:
    """Download GFS 250mb U/V GRIB2 file, cached by cycle (6h TTL)."""
    cache_file = cache_dir / f"gfs_{date_str}_{cycle}z_250mb.grib2"
    if cache_file.exists():
        age_min = (time.time() - cache_file.stat().st_mtime) / 60
        if age_min < 360:
            return cache_file

    url = (
        f"{NOMADS_URL}?"
        f"dir=%2Fgfs.{date_str}%2F{cycle}%2Fatmos"
        f"&file=gfs.t{cycle}z.pgrb2.1p00.anl"
        f"&var_UGRD=on&var_VGRD=on&lev_250_mb=on"
        f"&subregion=&toplat={LAT_MAX}&leftlon={LON_MIN}"
        f"&rightlon={LON_MAX}&bottomlat={LAT_MIN}"
    )
    log(f"Fetching GFS {date_str}/{cycle}z 250mb winds...")
    resp = requests.get(url, timeout=30)
    resp.raise_for_status()
    cache_file.write_bytes(resp.content)
    log(f"Downloaded {len(resp.content)} bytes -> {cache_file.name}")
    return cache_file


# ---------------------------------------------------------------------------
#  Minimal GRIB2 parser (pure Python + numpy, no ecCodes needed)
# ---------------------------------------------------------------------------

def _parse_grib2_simple(data: bytes) -> list[dict]:
    """Parse GFS 1° subregion GRIB2 — simple packing, regular lat/lon grid."""
    messages = []
    pos = 0

    while pos < len(data) - 4:
        if data[pos:pos+4] != b'GRIB':
            pos += 1
            continue
        discipline = data[pos + 6]
        edition = data[pos + 7]
        if edition != 2:
            raise ValueError(f"Expected GRIB2, got edition {edition}")
        total_len = struct.unpack_from('>Q', data, pos + 8)[0]
        msg_end = pos + total_len
        sec_pos = pos + 16

        ni = nj = 0
        lat1 = lon1 = lat2 = lon2 = dlat = dlon = 0.0
        param_cat = param_num = 0
        ref_val = 0.0
        bin_scale = dec_scale = nbits = 0
        packed_data = b''

        while sec_pos < msg_end - 4:
            sec_len = struct.unpack_from('>I', data, sec_pos)[0]
            sec_num = data[sec_pos + 4]
            if sec_len == 0:
                break

            if sec_num == 3:
                ni = struct.unpack_from('>I', data, sec_pos + 30)[0]
                nj = struct.unpack_from('>I', data, sec_pos + 34)[0]
                lat1 = struct.unpack_from('>i', data, sec_pos + 46)[0] / 1e6
                lon1 = struct.unpack_from('>i', data, sec_pos + 50)[0] / 1e6
                lat2 = struct.unpack_from('>i', data, sec_pos + 55)[0] / 1e6
                lon2 = struct.unpack_from('>i', data, sec_pos + 59)[0] / 1e6
                dlat = struct.unpack_from('>I', data, sec_pos + 67)[0] / 1e6
                dlon = struct.unpack_from('>I', data, sec_pos + 63)[0] / 1e6
            elif sec_num == 4:
                param_cat = data[sec_pos + 9]
                param_num = data[sec_pos + 10]
            elif sec_num == 5:
                ref_val = struct.unpack_from('>f', data, sec_pos + 11)[0]
                bin_scale = struct.unpack_from('>h', data, sec_pos + 15)[0]
                dec_scale = struct.unpack_from('>h', data, sec_pos + 17)[0]
                nbits = data[sec_pos + 19]
            elif sec_num == 7:
                packed_data = data[sec_pos + 5 : sec_pos + sec_len]

            sec_pos += sec_len

        npts = ni * nj
        if nbits > 0 and len(packed_data) > 0:
            vals = np.zeros(npts, dtype=np.float64)
            bit_buf = int.from_bytes(packed_data, 'big')
            total_bits = len(packed_data) * 8
            for i in range(npts):
                bit_offset = i * nbits
                shift = total_bits - bit_offset - nbits
                raw = (bit_buf >> shift) & ((1 << nbits) - 1) if shift >= 0 else 0
                vals[i] = (ref_val + raw * 2.0**bin_scale) / 10.0**dec_scale
        else:
            vals = np.full(npts, ref_val / 10.0**dec_scale)

        if discipline == 0 and param_cat == 2:
            param = 'u' if param_num == 2 else 'v' if param_num == 3 else f'c{param_cat}n{param_num}'
        else:
            param = f'd{discipline}c{param_cat}n{param_num}'

        messages.append({
            'param': param,
            'values': vals.reshape((nj, ni)),
            'ni': ni, 'nj': nj,
            'lat1': lat1, 'lon1': lon1, 'lat2': lat2, 'lon2': lon2,
        })
        pos = msg_end

    return messages


def load_wind_grid(grib_path: Path) -> dict:
    """Parse GRIB2 into numpy U/V arrays."""
    msgs = _parse_grib2_simple(grib_path.read_bytes())
    u_msg = next((m for m in msgs if m['param'] == 'u'), None)
    v_msg = next((m for m in msgs if m['param'] == 'v'), None)
    if u_msg is None or v_msg is None:
        raise ValueError(f"Expected U and V, found: {[m['param'] for m in msgs]}")

    m = u_msg
    lats = np.linspace(m['lat1'], m['lat2'], m['nj'])
    lons = np.linspace(m['lon1'], m['lon2'], m['ni'])
    u, v = u_msg['values'], v_msg['values']

    if lons.max() > 180:
        shift = lons >= 180
        lons[shift] -= 360
        idx = np.argsort(lons)
        lons, u, v = lons[idx], u[:, idx], v[:, idx]

    if lats[0] > lats[-1]:
        lats, u, v = lats[::-1], u[::-1, :], v[::-1, :]

    return {"lats": lats, "lons": lons, "u": u, "v": v}


# ---------------------------------------------------------------------------
#  Wind interpolation
# ---------------------------------------------------------------------------

def interpolate_wind(grid: dict, lat: float, lon: float) -> tuple[float, float]:
    """Bilinear interpolation of U/V wind at a point. Returns (u_kt, v_kt)."""
    lats, lons = grid["lats"], grid["lons"]
    lat = max(lats[0], min(lats[-1], lat))
    lon = max(lons[0], min(lons[-1], lon))

    lat_idx = max(0, min(np.searchsorted(lats, lat) - 1, len(lats) - 2))
    lon_idx = max(0, min(np.searchsorted(lons, lon) - 1, len(lons) - 2))

    lat_frac = (lat - lats[lat_idx]) / (lats[lat_idx + 1] - lats[lat_idx])
    lon_frac = (lon - lons[lon_idx]) / (lons[lon_idx + 1] - lons[lon_idx])

    def bilerp(d):
        c0 = d[lat_idx, lon_idx] * (1 - lon_frac) + d[lat_idx, lon_idx + 1] * lon_frac
        c1 = d[lat_idx + 1, lon_idx] * (1 - lon_frac) + d[lat_idx + 1, lon_idx + 1] * lon_frac
        return c0 * (1 - lat_frac) + c1 * lat_frac

    MS_TO_KT = 1.94384
    return bilerp(grid["u"]) * MS_TO_KT, bilerp(grid["v"]) * MS_TO_KT


# ---------------------------------------------------------------------------
#  Geometry (mirrors PHP Geo class)
# ---------------------------------------------------------------------------

def gc_distance_nm(lat1, lon1, lat2, lon2):
    lat1r, lat2r = math.radians(lat1), math.radians(lat2)
    dlat, dlon = math.radians(lat2 - lat1), math.radians(lon2 - lon1)
    a = math.sin(dlat/2)**2 + math.cos(lat1r)*math.cos(lat2r)*math.sin(dlon/2)**2
    return EARTH_NM * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))


def bearing_deg(lat1, lon1, lat2, lon2):
    lat1r, lat2r = math.radians(lat1), math.radians(lat2)
    dlon = math.radians(lon2 - lon1)
    x = math.sin(dlon) * math.cos(lat2r)
    y = math.cos(lat1r) * math.sin(lat2r) - math.sin(lat1r) * math.cos(lat2r) * math.cos(dlon)
    return (math.degrees(math.atan2(x, y)) + 360) % 360


def wind_along_track(u_kt, v_kt, heading_deg):
    hdg_rad = math.radians(heading_deg)
    return u_kt * math.sin(hdg_rad) + v_kt * math.cos(hdg_rad)


def descent_segment_minutes(dist_nm, fl100_dist_nm, ias_high_kt=280):
    if dist_nm <= 0:
        return 0.0
    t, rem = 0.0, dist_nm
    for seg_nm, speed_kt in [(2, 140), (3, 180), (5, 220), (10, 220)]:
        s = min(seg_nm, rem)
        t += (s / speed_kt) * 60
        rem -= s
        if rem <= 0:
            return t
    below_fl100 = max(0, fl100_dist_nm - 20)
    s = min(below_fl100, rem)
    t += (s / 250) * 60
    rem -= s
    if rem <= 0:
        return t
    t += (rem / round(ias_high_kt * 1.3)) * 60
    return t


def eta_minutes_with_descent(dist_nm, cruise_kt, cruise_alt_ft, airport_elev_ft, descent_ias_high=280):
    alt_above = max(0, cruise_alt_ft - airport_elev_ft)
    tod_dist = alt_above / 318.0
    if dist_nm <= tod_dist:
        return (dist_nm / max(cruise_kt, 1)) * 60
    fl100_agl = max(0, 10000 - airport_elev_ft)
    fl100_dist = fl100_agl / 318.0
    return (dist_nm - tod_dist) / max(cruise_kt, 1) * 60 + descent_segment_minutes(tod_dist, fl100_dist, descent_ias_high)


# ---------------------------------------------------------------------------
#  Route resolution (4-layer, mirrors PHP Geo::parseRouteCoordinates)
# ---------------------------------------------------------------------------

_waypoint_db = None
_airway_db = None
_procedure_db = None

def _data_path(filename):
    return os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'data', filename)


def _load_json(filename):
    path = _data_path(filename)
    if os.path.exists(path):
        with open(path, 'r') as f:
            return json.load(f)
    return {}


def _load_waypoints():
    global _waypoint_db
    if _waypoint_db is None:
        _waypoint_db = _load_json('waypoints.json')
    return _waypoint_db


def _load_airways():
    global _airway_db
    if _airway_db is None:
        _airway_db = _load_json('airways.json')
    return _airway_db


def _load_procedures():
    global _procedure_db
    if _procedure_db is None:
        _procedure_db = _load_json('procedures.json')
    return _procedure_db


def expand_airway_segment(airway_id, from_fix, to_fix):
    airways = _load_airways()
    if airway_id not in airways:
        return None
    awy = airways[airway_id]
    if from_fix not in awy or to_fix not in awy:
        return None
    for idx in (2, 3):
        path, cur, visited = [], from_fix, {from_fix}
        while True:
            node = awy.get(cur)
            if node is None:
                break
            nxt = node[idx] if len(node) > idx else None
            if not nxt or nxt in visited:
                break
            if nxt == to_fix:
                path.append((awy[to_fix][0], awy[to_fix][1]))
                return path
            visited.add(nxt)
            if nxt in awy:
                path.append((awy[nxt][0], awy[nxt][1]))
            cur = nxt
    return None


def parse_route_coordinates(route):
    """4-layer route resolution: coords, named fixes, airways, procedures."""
    waypoints = _load_waypoints()
    coords, tokens = [], route.split()
    count = len(tokens)
    last_fix_name, i = None, 0

    while i < count:
        token = tokens[i].strip()
        if not token:
            i += 1
            continue

        # Strip speed/level suffix from fix (e.g. DEXIT/N0483F380 → DEXIT)
        fix_token = token.split('/')[0] if '/' in token else token

        # NAT format: 49N050W
        m = re.match(r'^(\d{2})(N|S)(\d{3})(W|E)$', fix_token)
        if m:
            lat = float(m.group(1)) * (-1 if m.group(2) == 'S' else 1)
            lon = float(m.group(3)) * (-1 if m.group(4) == 'W' else 1)
            coords.append((lat, lon))
            last_fix_name = None
            i += 1
            continue

        # ICAO DDMM: 5530N02030W
        m = re.match(r'^(\d{4})(N|S)(\d{5})(W|E)$', fix_token)
        if m:
            lat = (int(m.group(1)[:2]) + int(m.group(1)[2:]) / 60.0) * (-1 if m.group(2) == 'S' else 1)
            lon = (int(m.group(3)[:3]) + int(m.group(3)[3:]) / 60.0) * (-1 if m.group(4) == 'W' else 1)
            coords.append((lat, lon))
            last_fix_name = None
            i += 1
            continue

        # Skip standalone speed/level groups
        if re.match(r'^[NMK]\d{4}[FSAM]\d{3,4}$', token):
            i += 1
            continue

        if token == 'DCT':
            i += 1
            continue

        # Airway: 1-2 letters + 1-4 digits
        if re.match(r'^[A-Z]{1,2}\d{1,4}$', token):
            if last_fix_name and i + 1 < count:
                next_token = tokens[i + 1].strip()
                exit_fix = next_token if re.match(r'^[A-Z]{2,5}$', next_token) else None
                if exit_fix:
                    expanded = expand_airway_segment(token, last_fix_name, exit_fix)
                    if expanded:
                        coords.extend(expanded)
                        last_fix_name = exit_fix
                        i += 2
                        continue
            i += 1
            continue

        # Named fix (use fix_token to handle FIX/speed entries)
        if re.match(r'^[A-Z]{2,5}$', fix_token) and fix_token in waypoints:
            coords.append((waypoints[fix_token][0], waypoints[fix_token][1]))
            last_fix_name = fix_token
            i += 1
            continue

        # SID/STAR procedure
        if re.match(r'^[A-Z]{3,5}\d{1,2}$', token):
            procedures = _load_procedures()
            if token in procedures:
                for fix_name in procedures[token]:
                    if fix_name in waypoints:
                        coords.append((waypoints[fix_name][0], waypoints[fix_name][1]))
                        last_fix_name = fix_name
                i += 1
                continue

        i += 1

    return coords


def along_route_legs(cur_lat, cur_lon, dest_lat, dest_lon, route_coords):
    """Build legs from aircraft through ahead-waypoints to destination."""
    if not route_coords:
        return [(cur_lat, cur_lon, dest_lat, dest_lon)]

    direct_dist = gc_distance_nm(cur_lat, cur_lon, dest_lat, dest_lon)
    ahead = [(wlat, wlon) for wlat, wlon in route_coords
             if gc_distance_nm(wlat, wlon, dest_lat, dest_lon) < direct_dist]

    if not ahead:
        return [(cur_lat, cur_lon, dest_lat, dest_lon)]

    legs = [(cur_lat, cur_lon, ahead[0][0], ahead[0][1])]
    for j in range(1, len(ahead)):
        legs.append((ahead[j-1][0], ahead[j-1][1], ahead[j][0], ahead[j][1]))
    legs.append((ahead[-1][0], ahead[-1][1], dest_lat, dest_lon))

    total = sum(gc_distance_nm(*leg) for leg in legs)
    if total < direct_dist or total > direct_dist * 1.30:
        return [(cur_lat, cur_lon, dest_lat, dest_lon)]

    return legs


def _grid_cell_segments(lat1, lon1, lat2, lon2):
    """Break a leg into sub-segments at 1° grid boundaries."""
    dlat, dlon = lat2 - lat1, lon2 - lon1
    crossings = [0.0, 1.0]

    if abs(dlon) > 1e-9:
        lo, hi = (lon1, lon2) if lon1 < lon2 else (lon2, lon1)
        for lon_int in range(int(math.ceil(lo)), int(math.floor(hi)) + 1):
            frac = (lon_int - lon1) / dlon
            if 0 < frac < 1:
                crossings.append(frac)

    if abs(dlat) > 1e-9:
        lo, hi = (lat1, lat2) if lat1 < lat2 else (lat2, lat1)
        for lat_int in range(int(math.ceil(lo)), int(math.floor(hi)) + 1):
            frac = (lat_int - lat1) / dlat
            if 0 < frac < 1:
                crossings.append(frac)

    crossings.sort()
    segments = []
    for k in range(len(crossings) - 1):
        f1, f2 = crossings[k], crossings[k + 1]
        if f2 - f1 < 1e-12:
            continue
        segments.append((lat1 + f1*dlat, lon1 + f1*dlon, lat1 + f2*dlat, lon1 + f2*dlon))

    return segments if segments else [(lat1, lon1, lat2, lon2)]


# ---------------------------------------------------------------------------
#  Wind-corrected ETA computation
# ---------------------------------------------------------------------------

def compute_wind_eta(flight: dict, grid: dict) -> dict | None:
    """Compute wind-corrected ETA for an eligible airborne flight."""
    pos = flight.get("position") or {}
    lat = pos.get("lat") or flight.get("last_lat")
    lon = pos.get("lon") or flight.get("last_lon")
    alt = pos.get("altitude_ft") or flight.get("fp_altitude_ft")
    heading = pos.get("heading_deg")
    ades = flight.get("arrival") or flight.get("ades")
    phase = flight.get("phase", "")
    tas = flight.get("fp_cruise_tas")
    gs = pos.get("groundspeed_kts") or flight.get("last_groundspeed_kts")
    fp_route = flight.get("fp_route") or ""

    if phase not in ELIGIBLE_PHASES:
        return None
    if lat is None or lon is None or heading is None:
        return None
    if ades not in AIRPORT_COORDS:
        return None

    dest_lat, dest_lon, dest_elev = AIRPORT_COORDS[ades]

    # TAS selection — the API already has corrected fp_cruise_tas from
    # the ingestor (step-climb parsing + sanity gate). Trust it.
    if tas and 120 <= tas <= 650:
        cruise_kt = tas
    elif gs and gs > 100:
        cruise_kt = gs
    else:
        cruise_kt = 450

    cruise_alt = alt if alt and alt > 10000 else 35000

    # Route resolution + along-route distance
    route_coords = parse_route_coordinates(fp_route)
    legs = along_route_legs(lat, lon, dest_lat, dest_lon, route_coords)
    dist_nm = sum(gc_distance_nm(*leg) for leg in legs)

    if dist_nm < 50 or dist_nm > 4000:
        return None

    # Wind-corrected ETA: integrate wind per grid cell along route
    alt_above = max(0, cruise_alt - dest_elev)
    tod_dist = alt_above / 318.0
    cruise_remaining = max(0, dist_nm - tod_dist)

    wind_cruise_min = 0.0
    dist_accum = 0.0

    for from_lat, from_lon, to_lat, to_lon in legs:
        cells = _grid_cell_segments(from_lat, from_lon, to_lat, to_lon)
        for c_lat1, c_lon1, c_lat2, c_lon2 in cells:
            cell_nm = gc_distance_nm(c_lat1, c_lon1, c_lat2, c_lon2)
            if cell_nm < 0.1:
                continue
            cruise_in_cell = min(cell_nm, max(0, cruise_remaining - dist_accum))
            if cruise_in_cell <= 0:
                break

            mid_lat, mid_lon = (c_lat1 + c_lat2) / 2, (c_lon1 + c_lon2) / 2
            u_kt, v_kt = interpolate_wind(grid, mid_lat, mid_lon)
            cell_bearing = bearing_deg(c_lat1, c_lon1, c_lat2, c_lon2)
            w_along = wind_along_track(u_kt, v_kt, cell_bearing)

            eff_kt = max(150, min(cruise_kt + w_along, 700))
            wind_cruise_min += (cruise_in_cell / eff_kt) * 60
            dist_accum += cell_nm

        if dist_accum >= cruise_remaining:
            break

    # Descent (no wind correction — 250mb wind not representative below FL100)
    fl100_agl = max(0, 10000 - dest_elev)
    fl100_dist = fl100_agl / 318.0
    descent_min = descent_segment_minutes(tod_dist, fl100_dist)
    wind_min = wind_cruise_min + descent_min

    now_epoch = time.time()

    return {
        "callsign": flight.get("callsign"),
        "wind_eldt_epoch": int(now_epoch + wind_min * 60),
    }


# ---------------------------------------------------------------------------
#  API interaction
# ---------------------------------------------------------------------------

def fetch_flights() -> list[dict]:
    """Fetch active flights from atfm-tools API."""
    resp = requests.get(f"{API_BASE}/api/v1/flights", timeout=15, verify=False)
    resp.raise_for_status()
    data = resp.json()
    return data.get("flights", data) if isinstance(data, dict) else data


def push_wind_eldts(predictions: list[dict]) -> int:
    """Push wind-corrected ELDTs to the server."""
    updates = []
    for p in predictions:
        if p.get("wind_eldt_epoch"):
            dt = datetime.fromtimestamp(p["wind_eldt_epoch"], tz=timezone.utc)
            updates.append({
                "callsign": p["callsign"],
                "eldt_wind": dt.strftime("%Y-%m-%dT%H:%M:%SZ"),
            })
    if not updates:
        return 0
    try:
        resp = requests.post(
            f"{API_BASE}/api/v1/admin/wind-eldt",
            json={"updates": updates},
            timeout=10, verify=False,
        )
        resp.raise_for_status()
        return resp.json().get("updated", 0)
    except Exception as e:
        log(f"Push failed: {e}", error=True)
        return 0


# ---------------------------------------------------------------------------
#  Logging
# ---------------------------------------------------------------------------

def log(msg, error=False):
    ts = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    out = sys.stderr if error else sys.stdout
    print(f"[wind] {ts} {msg}", file=out, flush=True)


# ---------------------------------------------------------------------------
#  Main
# ---------------------------------------------------------------------------

def main():
    import urllib3
    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

    cache_dir = Path(tempfile.gettempdir()) / "atfm-grib-cache"
    cache_dir.mkdir(exist_ok=True)

    now = datetime.now(timezone.utc)
    date_str, cycle = latest_gfs_cycle(now)

    try:
        grib_path = fetch_grib(date_str, cycle, cache_dir)
        grid = load_wind_grid(grib_path)
    except Exception as e:
        log(f"GRIB fetch/parse failed: {e}", error=True)
        sys.exit(1)

    try:
        flights = fetch_flights()
    except Exception as e:
        log(f"API fetch failed: {e}", error=True)
        sys.exit(1)

    predictions = []
    for f in flights:
        p = compute_wind_eta(f, grid)
        if p:
            predictions.append(p)

    if predictions:
        pushed = push_wind_eldts(predictions)
        log(f"Computed {len(predictions)} wind ELDTs, pushed {pushed}")
    else:
        log("No eligible flights for wind correction")


if __name__ == "__main__":
    main()
