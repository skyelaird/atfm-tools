#!/usr/bin/env python3
"""
Wind-shadow ETA model — experimental comparison of geometric vs
wind-corrected ELDT predictions against ALDT.

Downloads 1-degree GFS 250mb U/V winds from NOAA NOMADS, interpolates
wind at each airborne flight's position and heading, computes a
wind-corrected ETA, and compares:
  - Our production ELDT (geometric, TAS-based)
  - Wind-corrected ELDT (this model)
  - PERTI ELDT (when available)
against ALDT when the flight lands.

Results are stored in a local SQLite DB for analysis.

Usage:
    python bin/experiments/wind-shadow.py [--once] [--db path/to/results.db]

    --once    Run a single snapshot (default: loop every 2 minutes)
    --db      SQLite path (default: bin/experiments/wind-shadow.db)

Requires: cfgrib, xarray, requests, numpy, sqlite3 (stdlib)
"""

import argparse
import json
import math
import os
import sqlite3
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

API_BASE = "https://atfm.momentaryshutter.com"
NOMADS_URL = "https://nomads.ncep.noaa.gov/cgi-bin/filter_gfs_1p00.pl"

# Grid bounds (1-degree)
LAT_MIN, LAT_MAX = 40, 65
LON_MIN, LON_MAX = -130, -30  # west negative

# GFS cycles (UTC hours)
GFS_CYCLES = [0, 6, 12, 18]

# Descent model constants (mirror PHP Geo::etaMinutesWithDescent)
DESCENT_FPM = 318  # ft per nm on 3-degree glidepath
EARTH_NM = 3440.065

# ---------------------------------------------------------------------------
#  GRIB fetching and wind interpolation
# ---------------------------------------------------------------------------

def latest_gfs_cycle(now_utc: datetime) -> tuple[str, str]:
    """Return (YYYYMMDD, HH) for the most recent GFS cycle,
    with a 4-hour delay for publication lag."""
    adjusted = now_utc - timedelta(hours=4)
    cycle_hour = max(h for h in GFS_CYCLES if h <= adjusted.hour)
    date_str = adjusted.strftime("%Y%m%d")
    return date_str, f"{cycle_hour:02d}"


def fetch_grib(date_str: str, cycle: str, cache_dir: Path) -> Path:
    """Download the GFS 250mb U/V GRIB2 file, cached by cycle."""
    cache_file = cache_dir / f"gfs_{date_str}_{cycle}z_250mb.grib2"
    if cache_file.exists():
        age_min = (time.time() - cache_file.stat().st_mtime) / 60
        if age_min < 360:  # 6 hours
            return cache_file

    url = (
        f"{NOMADS_URL}?"
        f"dir=%2Fgfs.{date_str}%2F{cycle}%2Fatmos"
        f"&file=gfs.t{cycle}z.pgrb2.1p00.anl"
        f"&var_UGRD=on&var_VGRD=on&lev_250_mb=on"
        f"&subregion=&toplat={LAT_MAX}&leftlon={LON_MIN}"
        f"&rightlon={LON_MAX}&bottomlat={LAT_MIN}"
    )
    print(f"[wind] Fetching GFS {date_str}/{cycle}z 250mb winds...")
    resp = requests.get(url, timeout=30)
    resp.raise_for_status()
    cache_file.write_bytes(resp.content)
    print(f"[wind] Downloaded {len(resp.content)} bytes -> {cache_file.name}")
    return cache_file


def _parse_grib2_simple(data: bytes) -> list[dict]:
    """Minimal GRIB2 parser for GFS 1° subregion files.

    Handles only simple packing (DRT 5.0) on a regular lat/lon grid
    (GDT 3.0). That's exactly what NOMADS returns for our filtered
    request. Returns a list of messages, each with 'param', 'values',
    'ni', 'nj', 'lat1', 'lon1', 'lat2', 'lon2', 'dlat', 'dlon'.

    No external C libraries needed — pure Python + numpy.
    """
    import struct

    messages = []
    pos = 0

    while pos < len(data) - 4:
        # Section 0: Indicator — 'GRIB' magic + discipline + edition + length
        if data[pos:pos+4] != b'GRIB':
            pos += 1
            continue
        discipline = data[pos + 6]
        edition = data[pos + 7]
        if edition != 2:
            raise ValueError(f"Expected GRIB2, got edition {edition}")
        total_len = struct.unpack_from('>Q', data, pos + 8)[0]
        msg_end = pos + total_len
        sec_pos = pos + 16  # past section 0

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
                break  # end sentinel

            if sec_num == 3:
                # Grid Definition Section — template 3.0 (regular lat/lon)
                # Octet 31-34 = Ni (lon points), 35-38 = Nj (lat points)
                ni = struct.unpack_from('>I', data, sec_pos + 30)[0]
                nj = struct.unpack_from('>I', data, sec_pos + 34)[0]
                lat1 = struct.unpack_from('>i', data, sec_pos + 46)[0] / 1e6
                lon1 = struct.unpack_from('>i', data, sec_pos + 50)[0] / 1e6
                lat2 = struct.unpack_from('>i', data, sec_pos + 55)[0] / 1e6
                lon2 = struct.unpack_from('>i', data, sec_pos + 59)[0] / 1e6
                dlat = struct.unpack_from('>I', data, sec_pos + 67)[0] / 1e6
                dlon = struct.unpack_from('>I', data, sec_pos + 63)[0] / 1e6

            elif sec_num == 4:
                # Product Definition Section
                param_cat = data[sec_pos + 9]
                param_num = data[sec_pos + 10]

            elif sec_num == 5:
                # Data Representation Section — template 5.0 (simple packing)
                ref_val = struct.unpack_from('>f', data, sec_pos + 11)[0]
                bin_scale = struct.unpack_from('>h', data, sec_pos + 15)[0]
                dec_scale = struct.unpack_from('>h', data, sec_pos + 17)[0]
                nbits = data[sec_pos + 19]

            elif sec_num == 7:
                # Data Section — packed values
                packed_data = data[sec_pos + 5 : sec_pos + sec_len]

            sec_pos += sec_len

        # Decode simple packing: Y = (R + X * 2^E) / 10^D
        npts = ni * nj
        if nbits > 0 and len(packed_data) > 0:
            # Unpack bit stream
            vals = np.zeros(npts, dtype=np.float64)
            bit_buf = int.from_bytes(packed_data, 'big')
            total_bits = len(packed_data) * 8
            for i in range(npts):
                bit_offset = i * nbits
                shift = total_bits - bit_offset - nbits
                if shift >= 0:
                    raw = (bit_buf >> shift) & ((1 << nbits) - 1)
                else:
                    raw = 0
                vals[i] = (ref_val + raw * 2.0**bin_scale) / 10.0**dec_scale
        else:
            vals = np.full(npts, ref_val / 10.0**dec_scale)

        # UGRD = cat 2 num 2, VGRD = cat 2 num 3
        if discipline == 0 and param_cat == 2:
            param = 'u' if param_num == 2 else 'v' if param_num == 3 else f'c{param_cat}n{param_num}'
        else:
            param = f'd{discipline}c{param_cat}n{param_num}'

        messages.append({
            'param': param,
            'values': vals.reshape((nj, ni)),  # (lat rows, lon cols)
            'ni': ni, 'nj': nj,
            'lat1': lat1, 'lon1': lon1,
            'lat2': lat2, 'lon2': lon2,
            'dlat': dlat, 'dlon': dlon,
        })

        pos = msg_end

    return messages


def load_wind_grid(grib_path: Path) -> dict:
    """Parse GRIB2 into numpy arrays for U and V.
    Returns dict with 'lats', 'lons', 'u', 'v' arrays.

    Uses a minimal pure-Python GRIB2 parser — no ecCodes/cfgrib needed.
    Only handles the simple-packed regular lat/lon grids that NOMADS
    returns for our filtered 1° request.
    """
    raw = grib_path.read_bytes()
    msgs = _parse_grib2_simple(raw)

    u_msg = next((m for m in msgs if m['param'] == 'u'), None)
    v_msg = next((m for m in msgs if m['param'] == 'v'), None)
    if u_msg is None or v_msg is None:
        found = [m['param'] for m in msgs]
        raise ValueError(f"Expected U and V messages, found: {found}")

    m = u_msg
    # Build coordinate arrays from grid definition
    lats = np.linspace(m['lat1'], m['lat2'], m['nj'])
    lons = np.linspace(m['lon1'], m['lon2'], m['ni'])
    u = u_msg['values']  # m/s
    v = v_msg['values']  # m/s

    # Normalize longitudes to -180..180 if needed
    if lons.max() > 180:
        shift = lons >= 180
        lons[shift] -= 360
        sort_idx = np.argsort(lons)
        lons = lons[sort_idx]
        u = u[:, sort_idx]
        v = v[:, sort_idx]

    # Ensure lats are ascending for interpolation
    if lats[0] > lats[-1]:
        lats = lats[::-1]
        u = u[::-1, :]
        v = v[::-1, :]

    return {"lats": lats, "lons": lons, "u": u, "v": v}


def interpolate_wind(grid: dict, lat: float, lon: float) -> tuple[float, float]:
    """Bilinear interpolation of U/V wind at a point. Returns (u_kt, v_kt)."""
    lats, lons = grid["lats"], grid["lons"]

    # Clamp to grid bounds
    lat = max(lats[0], min(lats[-1], lat))
    lon = max(lons[0], min(lons[-1], lon))

    # Find surrounding grid indices
    lat_idx = np.searchsorted(lats, lat) - 1
    lon_idx = np.searchsorted(lons, lon) - 1
    lat_idx = max(0, min(lat_idx, len(lats) - 2))
    lon_idx = max(0, min(lon_idx, len(lons) - 2))

    # Fractional position within cell
    lat_frac = (lat - lats[lat_idx]) / (lats[lat_idx + 1] - lats[lat_idx])
    lon_frac = (lon - lons[lon_idx]) / (lons[lon_idx + 1] - lons[lon_idx])

    # Bilinear interpolation
    def bilerp(grid_data):
        c00 = grid_data[lat_idx, lon_idx]
        c01 = grid_data[lat_idx, lon_idx + 1]
        c10 = grid_data[lat_idx + 1, lon_idx]
        c11 = grid_data[lat_idx + 1, lon_idx + 1]
        c0 = c00 * (1 - lon_frac) + c01 * lon_frac
        c1 = c10 * (1 - lon_frac) + c11 * lon_frac
        return c0 * (1 - lat_frac) + c1 * lat_frac

    u_ms = bilerp(grid["u"])
    v_ms = bilerp(grid["v"])

    # Convert m/s to knots
    MS_TO_KT = 1.94384
    return u_ms * MS_TO_KT, v_ms * MS_TO_KT


def wind_component_along_track(u_kt: float, v_kt: float, heading_deg: float) -> float:
    """Compute wind component along aircraft track (positive = tailwind).
    Heading is magnetic/true degrees clockwise from north."""
    hdg_rad = math.radians(heading_deg)
    # Aircraft track vector: (sin(hdg), cos(hdg)) = (east, north)
    # Wind vector: (u, v) = (east, north)
    return u_kt * math.sin(hdg_rad) + v_kt * math.cos(hdg_rad)


# ---------------------------------------------------------------------------
#  Great-circle and ETA computation (mirrors PHP Geo class)
# ---------------------------------------------------------------------------

def gc_distance_nm(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Great-circle distance in nautical miles."""
    lat1r, lat2r = math.radians(lat1), math.radians(lat2)
    dlat = math.radians(lat2 - lat1)
    dlon = math.radians(lon2 - lon1)
    a = math.sin(dlat/2)**2 + math.cos(lat1r)*math.cos(lat2r)*math.sin(dlon/2)**2
    return EARTH_NM * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))


def bearing_deg(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Initial bearing (true north) from point 1 to point 2, in degrees."""
    lat1r, lat2r = math.radians(lat1), math.radians(lat2)
    dlon = math.radians(lon2 - lon1)
    x = math.sin(dlon) * math.cos(lat2r)
    y = math.cos(lat1r) * math.sin(lat2r) - math.sin(lat1r) * math.cos(lat2r) * math.cos(dlon)
    return (math.degrees(math.atan2(x, y)) + 360) % 360


_waypoint_db: dict[str, list[float]] | None = None
_airway_db: dict[str, dict[str, list]] | None = None
_procedure_db: dict[str, list[str]] | None = None

def _load_waypoints() -> dict[str, list[float]]:
    """Load waypoint database from data/waypoints.json (lazy, once per process)."""
    global _waypoint_db
    if _waypoint_db is not None:
        return _waypoint_db
    wpt_file = os.path.join(os.path.dirname(__file__), '..', '..', 'data', 'waypoints.json')
    if os.path.exists(wpt_file):
        with open(wpt_file, 'r') as f:
            _waypoint_db = json.load(f)
    else:
        _waypoint_db = {}
    return _waypoint_db


def _load_airways() -> dict[str, dict[str, list]]:
    """Load airway adjacency graph from data/airways.json (lazy, once per process)."""
    global _airway_db
    if _airway_db is not None:
        return _airway_db
    awy_file = os.path.join(os.path.dirname(__file__), '..', '..', 'data', 'airways.json')
    if os.path.exists(awy_file):
        with open(awy_file, 'r') as f:
            _airway_db = json.load(f)
    else:
        _airway_db = {}
    return _airway_db


def _load_procedures() -> dict[str, list[str]]:
    """Load SID/STAR procedure fix sequences from data/procedures.json."""
    global _procedure_db
    if _procedure_db is not None:
        return _procedure_db
    proc_file = os.path.join(os.path.dirname(__file__), '..', '..', 'data', 'procedures.json')
    if os.path.exists(proc_file):
        with open(proc_file, 'r') as f:
            _procedure_db = json.load(f)
    else:
        _procedure_db = {}
    return _procedure_db


def expand_airway_segment(airway_id: str, from_fix: str, to_fix: str) -> list[tuple[float, float]] | None:
    """Walk airway linked list from from_fix to to_fix, return intermediate coords.

    Excludes from_fix, includes to_fix. Returns None if can't resolve.
    """
    airways = _load_airways()
    if airway_id not in airways:
        return None
    awy = airways[airway_id]
    if from_fix not in awy or to_fix not in awy:
        return None

    # Try next (index 2) and prev (index 3)
    for idx in (2, 3):
        path = []
        cur = from_fix
        visited = {from_fix}
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


def parse_route_coordinates(route: str) -> list[tuple[float, float]]:
    """Extract coordinate, named-fix, and airway-segment waypoints from a filed route.

    Handles:
    - NAT format: DDN/SDDDW/E (e.g. 49N050W, 60N020W)
    - ICAO DDMM format: DDMMN/SDDDMME/W (e.g. 5530N02030W)
    - Named fixes resolved via data/waypoints.json (e.g. TONNY, YEE, FIORD)
    - Airway segments expanded via data/airways.json (e.g. TONNY J501 YEE)

    Mirrors PHP Geo::parseRouteCoordinates().
    """
    waypoints = _load_waypoints()
    coords = []
    tokens = route.split()
    count = len(tokens)
    last_fix_name: str | None = None
    i = 0

    while i < count:
        token = tokens[i].strip()
        if not token:
            i += 1
            continue

        # NAT format: 2-digit lat, 3-digit lon (49N050W)
        m = re.match(r'^(\d{2})(N|S)(\d{3})(W|E)$', token)
        if m:
            lat = float(m.group(1)) * (-1 if m.group(2) == 'S' else 1)
            lon = float(m.group(3)) * (-1 if m.group(4) == 'W' else 1)
            coords.append((lat, lon))
            last_fix_name = None
            i += 1
            continue

        # ICAO DDMM format: 4-digit lat, 5-digit lon (5530N02030W)
        m = re.match(r'^(\d{4})(N|S)(\d{5})(W|E)$', token)
        if m:
            lat_d, lat_m = int(m.group(1)[:2]), int(m.group(1)[2:])
            lon_d, lon_m = int(m.group(3)[:3]), int(m.group(3)[3:])
            lat = (lat_d + lat_m / 60.0) * (-1 if m.group(2) == 'S' else 1)
            lon = (lon_d + lon_m / 60.0) * (-1 if m.group(4) == 'W' else 1)
            coords.append((lat, lon))
            last_fix_name = None
            i += 1
            continue

        # Skip speed/level groups (N0450F350, M082F390)
        if re.match(r'^[NMK]\d{4}[FSAM]\d{3,4}$', token):
            i += 1
            continue

        # Skip DCT
        if token == 'DCT':
            i += 1
            continue

        # Airway identifier: 1-2 letters + 1-4 digits (J501, V300, UN601)
        if re.match(r'^[A-Z]{1,2}\d{1,4}$', token):
            if last_fix_name and i + 1 < count:
                next_token = tokens[i + 1].strip()
                exit_fix = next_token if re.match(r'^[A-Z]{2,5}$', next_token) else None
                if exit_fix:
                    expanded = expand_airway_segment(token, last_fix_name, exit_fix)
                    if expanded:
                        coords.extend(expanded)
                        last_fix_name = exit_fix
                        i += 2  # skip airway + exit fix
                        continue
            i += 1
            continue

        # Named fix lookup — 2-5 uppercase alpha chars
        if re.match(r'^[A-Z]{2,5}$', token) and token in waypoints:
            coords.append((waypoints[token][0], waypoints[token][1]))
            last_fix_name = token
            i += 1
            continue

        # SID/STAR procedure expansion (e.g. BOXUM7, RAGID6, CANUC6)
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


def along_route_legs(
    cur_lat: float, cur_lon: float,
    dest_lat: float, dest_lon: float,
    route_coords: list[tuple[float, float]],
) -> list[tuple[float, float, float, float]]:
    """Build list of (from_lat, from_lon, to_lat, to_lon) legs from
    aircraft position through ahead-waypoints to destination.

    Mirrors PHP Geo::alongRouteDistanceNm() filtering logic:
    keep only waypoints closer to dest than the aircraft is.
    """
    if not route_coords:
        return [(cur_lat, cur_lon, dest_lat, dest_lon)]

    direct_dist = gc_distance_nm(cur_lat, cur_lon, dest_lat, dest_lon)

    # Filter waypoints still ahead (closer to dest than aircraft)
    ahead = []
    for wlat, wlon in route_coords:
        wpt_dist = gc_distance_nm(wlat, wlon, dest_lat, dest_lon)
        if wpt_dist < direct_dist:
            ahead.append((wlat, wlon))

    if not ahead:
        return [(cur_lat, cur_lon, dest_lat, dest_lon)]

    legs = [(cur_lat, cur_lon, ahead[0][0], ahead[0][1])]
    for i in range(1, len(ahead)):
        legs.append((ahead[i-1][0], ahead[i-1][1], ahead[i][0], ahead[i][1]))
    legs.append((ahead[-1][0], ahead[-1][1], dest_lat, dest_lon))

    # Sanity: total along-route distance bounded to [direct, 1.3*direct]
    total = sum(gc_distance_nm(*leg) for leg in legs)
    if total < direct_dist or total > direct_dist * 1.30:
        return [(cur_lat, cur_lon, dest_lat, dest_lon)]

    return legs


def descent_segment_minutes(dist_nm: float, fl100_dist_nm: float, ias_high_kt: int = 280) -> float:
    """Mirror of Geo::descentSegmentMinutes — speed schedule from TOD to threshold."""
    if dist_nm <= 0:
        return 0.0
    t = 0.0
    rem = dist_nm

    for seg_nm, speed_kt in [(2, 140), (3, 180), (5, 220), (10, 220)]:
        s = min(seg_nm, rem)
        t += (s / speed_kt) * 60
        rem -= s
        if rem <= 0:
            return t

    # Below FL100 at 250 kt
    below_fl100 = max(0, fl100_dist_nm - 20)
    s = min(below_fl100, rem)
    t += (s / 250) * 60
    rem -= s
    if rem <= 0:
        return t

    # Above FL100 at type IAS * 1.3 (TAS correction)
    gs_high = round(ias_high_kt * 1.3)
    t += (rem / gs_high) * 60
    return t


def eta_minutes_with_descent(dist_nm: float, cruise_kt: int, cruise_alt_ft: int,
                              airport_elev_ft: int, descent_ias_high: int = 280) -> float:
    """Mirror of Geo::etaMinutesWithDescent."""
    alt_above = max(0, cruise_alt_ft - airport_elev_ft)
    tod_dist = alt_above / 318.0

    if dist_nm <= tod_dist:
        return (dist_nm / max(cruise_kt, 1)) * 60

    fl100_agl = max(0, 10000 - airport_elev_ft)
    fl100_dist = fl100_agl / 318.0
    descent_min = descent_segment_minutes(tod_dist, fl100_dist, descent_ias_high)
    cruise_nm = dist_nm - tod_dist
    cruise_min = (cruise_nm / max(cruise_kt, 1)) * 60
    return cruise_min + descent_min


# ---------------------------------------------------------------------------
#  Flight data from our API
# ---------------------------------------------------------------------------

AIRPORT_COORDS = {
    "CYHZ": (44.88, -63.51, 477),
    "CYOW": (45.32, -75.67, 374),
    "CYUL": (45.47, -73.74, 118),
    "CYVR": (49.19, -123.18, 14),
    "CYWG": (49.91, -97.24, 783),
    "CYYC": (51.11, -114.02, 3557),
    "CYYZ": (43.68, -79.63, 569),
}


def fetch_flights() -> list[dict]:
    """Fetch active flights from our API."""
    resp = requests.get(f"{API_BASE}/api/v1/flights", timeout=15, verify=False)
    resp.raise_for_status()
    data = resp.json()
    return data.get("flights", data) if isinstance(data, dict) else data


def compute_wind_eta(flight: dict, grid: dict) -> dict | None:
    """For an airborne flight at cruise, compute wind-corrected ETA.

    Uses along-route distance when filed route contains coordinate
    waypoints (NAT tracks, ICAO lat/lon fixes). Integrates wind
    along each route segment rather than a single-point sample —
    this captures the varying jet-stream headwind/tailwind along
    the actual flight path.

    Returns dict with prediction details, or None if not applicable.
    """
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

    # Only airborne flights at cruise
    if phase not in ("ENROUTE", "ARRIVING", "DEPARTED"):
        return None
    if lat is None or lon is None or heading is None:
        return None
    if ades not in AIRPORT_COORDS:
        return None

    dest_lat, dest_lon, dest_elev = AIRPORT_COORDS[ades]

    # Need a usable TAS
    if tas and 120 <= tas <= 650:
        cruise_kt = tas
    elif gs and gs > 100:
        cruise_kt = gs
    else:
        cruise_kt = 450

    cruise_alt = alt if alt and alt > 10000 else 35000

    # Parse route waypoints and build legs
    route_coords = parse_route_coordinates(fp_route)
    legs = along_route_legs(lat, lon, dest_lat, dest_lon, route_coords)
    dist_nm = sum(gc_distance_nm(*leg) for leg in legs)

    # Skip if too close (already in approach) or too far (not meaningful)
    if dist_nm < 50 or dist_nm > 4000:
        return None

    # --- Geometric ETA (our production model, along-route distance) ---
    geo_min = eta_minutes_with_descent(dist_nm, cruise_kt, cruise_alt, dest_elev)

    # --- Wind-corrected ETA: integrate wind along each route segment ---
    #
    # For each leg, sample wind at the midpoint, decompose along the
    # leg bearing, compute effective groundspeed, accumulate cruise time.
    # Descent segment uses the standard no-wind model (wind below FL100
    # is much weaker and less predictable from 250mb data).
    alt_above = max(0, cruise_alt - dest_elev)
    tod_dist = alt_above / 318.0
    cruise_remaining = max(0, dist_nm - tod_dist)

    wind_cruise_min = 0.0
    total_wind_along = 0.0
    total_wind_weight = 0.0
    leg_dist_accum = 0.0

    for from_lat, from_lon, to_lat, to_lon in legs:
        leg_nm = gc_distance_nm(from_lat, from_lon, to_lat, to_lon)
        if leg_nm < 0.1:
            continue

        # How much of this leg is in the cruise segment?
        cruise_in_leg = min(leg_nm, max(0, cruise_remaining - leg_dist_accum))
        if cruise_in_leg <= 0:
            break  # rest is descent

        # Sample wind at leg midpoint
        mid_lat = (from_lat + to_lat) / 2
        mid_lon = (from_lon + to_lon) / 2
        u_kt, v_kt = interpolate_wind(grid, mid_lat, mid_lon)

        # Leg bearing for wind decomposition
        leg_bearing = bearing_deg(from_lat, from_lon, to_lat, to_lon)
        wind_along = wind_component_along_track(u_kt, v_kt, leg_bearing)

        # Effective speed for this segment
        eff_kt = max(150, min(cruise_kt + wind_along, 700))
        wind_cruise_min += (cruise_in_leg / eff_kt) * 60

        # Weighted average wind for reporting
        total_wind_along += wind_along * cruise_in_leg
        total_wind_weight += cruise_in_leg

        leg_dist_accum += leg_nm

    # Add descent time (no wind correction — 250mb wind not representative
    # below FL100, and the descent speed schedule dominates)
    fl100_agl = max(0, 10000 - dest_elev)
    fl100_dist = fl100_agl / 318.0
    descent_min = descent_segment_minutes(tod_dist, fl100_dist)
    wind_min = wind_cruise_min + descent_min

    # Weighted average wind component along route (for display)
    avg_wind_along = total_wind_along / total_wind_weight if total_wind_weight > 0 else 0
    # Wind at current position (for reporting)
    u_cur, v_cur = interpolate_wind(grid, lat, lon)
    wind_speed = math.sqrt(u_cur**2 + v_cur**2)

    now_epoch = time.time()

    return {
        "callsign": flight.get("callsign"),
        "ades": ades,
        "lat": lat,
        "lon": lon,
        "alt_ft": cruise_alt,
        "heading": heading,
        "dist_nm": round(dist_nm, 1),
        "tas_kt": cruise_kt,
        "gs_kt": gs,
        "u_kt": round(u_cur, 1),
        "v_kt": round(v_cur, 1),
        "wind_along_kt": round(avg_wind_along, 1),
        "wind_speed_kt": round(wind_speed, 1),
        "geo_eta_min": round(geo_min, 1),
        "wind_eta_min": round(wind_min, 1),
        "delta_min": round(wind_min - geo_min, 1),
        "our_eldt": flight.get("eldt") or flight.get("tldt"),
        "perti_eldt": flight.get("eldt_perti"),
        "aldt": flight.get("aldt"),
        "geo_eldt_epoch": int(now_epoch + geo_min * 60),
        "wind_eldt_epoch": int(now_epoch + wind_min * 60),
        "observed_at": datetime.now(timezone.utc).isoformat(),
        "route_legs": len(legs),
    }


# ---------------------------------------------------------------------------
#  SQLite persistence
# ---------------------------------------------------------------------------

def init_db(db_path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(db_path)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS predictions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            callsign TEXT NOT NULL,
            ades TEXT NOT NULL,
            observed_at TEXT NOT NULL,
            lat REAL, lon REAL, alt_ft INTEGER, heading REAL,
            dist_nm REAL,
            tas_kt INTEGER, gs_kt INTEGER,
            u_kt REAL, v_kt REAL,
            wind_along_kt REAL, wind_speed_kt REAL,
            geo_eta_min REAL, wind_eta_min REAL, delta_min REAL,
            our_eldt TEXT, perti_eldt TEXT, aldt TEXT,
            geo_eldt_epoch INTEGER, wind_eldt_epoch INTEGER,
            -- Filled in when ALDT is observed (post-landing update):
            aldt_epoch INTEGER,
            geo_error_min REAL,
            wind_error_min REAL,
            our_error_min REAL,
            perti_error_min REAL,
            UNIQUE(callsign, observed_at)
        )
    """)
    conn.execute("""
        CREATE INDEX IF NOT EXISTS idx_pred_callsign ON predictions(callsign)
    """)
    conn.execute("""
        CREATE INDEX IF NOT EXISTS idx_pred_aldt ON predictions(aldt_epoch)
    """)
    conn.commit()
    return conn


def store_prediction(conn: sqlite3.Connection, p: dict):
    conn.execute("""
        INSERT OR REPLACE INTO predictions
        (callsign, ades, observed_at, lat, lon, alt_ft, heading, dist_nm,
         tas_kt, gs_kt, u_kt, v_kt, wind_along_kt, wind_speed_kt,
         geo_eta_min, wind_eta_min, delta_min,
         our_eldt, perti_eldt, aldt, geo_eldt_epoch, wind_eldt_epoch)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    """, (
        p["callsign"], p["ades"], p["observed_at"],
        p["lat"], p["lon"], p["alt_ft"], p["heading"], p["dist_nm"],
        p["tas_kt"], p["gs_kt"],
        p["u_kt"], p["v_kt"], p["wind_along_kt"], p["wind_speed_kt"],
        p["geo_eta_min"], p["wind_eta_min"], p["delta_min"],
        p["our_eldt"], p.get("perti_eldt"), p["aldt"],
        p["geo_eldt_epoch"], p["wind_eldt_epoch"],
    ))


def backfill_aldt(conn: sqlite3.Connection, flights: list[dict]):
    """For flights that have landed, compute error metrics."""
    landed = {f["callsign"]: f for f in flights
              if f.get("aldt") and f.get("phase") in ("ARRIVED", "TAXI_IN")}

    if not landed:
        return 0

    updated = 0
    rows = conn.execute("""
        SELECT id, callsign, geo_eldt_epoch, wind_eldt_epoch, our_eldt, perti_eldt
        FROM predictions
        WHERE aldt_epoch IS NULL AND callsign IN ({})
    """.format(",".join("?" * len(landed))), list(landed.keys())).fetchall()

    for row in rows:
        pid, cs, geo_epoch, wind_epoch, our_eldt_str, perti_str = row
        f = landed[cs]
        aldt_str = f["aldt"]

        try:
            aldt_dt = datetime.fromisoformat(aldt_str.replace("Z", "+00:00"))
            aldt_epoch = int(aldt_dt.timestamp())
        except (ValueError, AttributeError):
            continue

        geo_err = (geo_epoch - aldt_epoch) / 60 if geo_epoch else None
        wind_err = (wind_epoch - aldt_epoch) / 60 if wind_epoch else None

        our_err = None
        if our_eldt_str:
            try:
                our_dt = datetime.fromisoformat(our_eldt_str.replace("Z", "+00:00"))
                our_err = (our_dt.timestamp() - aldt_epoch) / 60
            except (ValueError, AttributeError):
                pass

        perti_err = None
        if perti_str:
            try:
                perti_dt = datetime.fromisoformat(perti_str.replace("Z", "+00:00"))
                perti_err = (perti_dt.timestamp() - aldt_epoch) / 60
            except (ValueError, AttributeError):
                pass

        conn.execute("""
            UPDATE predictions SET
                aldt_epoch = ?, geo_error_min = ?, wind_error_min = ?,
                our_error_min = ?, perti_error_min = ?
            WHERE id = ?
        """, (aldt_epoch, geo_err, wind_err, our_err, perti_err, pid))
        updated += 1

    if updated:
        conn.commit()
    return updated


# ---------------------------------------------------------------------------
#  Reporting
# ---------------------------------------------------------------------------

def print_snapshot(predictions: list[dict]):
    """Print a summary of current predictions."""
    if not predictions:
        print("[wind] No airborne flights with wind data")
        return

    print(f"\n[wind] {len(predictions)} airborne flights with wind predictions:")
    print(f"{'Callsign':<10} {'ADES':<5} {'Dist':>6} {'Legs':>4} {'TAS':>4} {'Wind':>6} "
          f"{'OurTLDT':>8} {'GribTLDT':>9} {'Delta':>6}")
    print("-" * 73)

    for p in sorted(predictions, key=lambda x: x["dist_nm"], reverse=True):
        wind_dir = "tail" if p["wind_along_kt"] > 0 else "head"
        # Show our production ELDT time if available
        our_str = "  --:--"
        if p.get("our_eldt"):
            try:
                dt = datetime.fromisoformat(p["our_eldt"].replace("Z", "+00:00"))
                our_str = dt.strftime(" %H:%M")
            except (ValueError, AttributeError):
                pass
        # GRIB-corrected ETA as absolute time
        grib_dt = datetime.fromtimestamp(
            time.time() + p["wind_eta_min"] * 60, tz=timezone.utc
        )
        grib_str = grib_dt.strftime(" %H:%M")
        legs = p.get("route_legs", 1)
        legs_str = f"{legs:>3}L" if legs > 1 else "  GC"

        print(f"{p['callsign']:<10} {p['ades']:<5} {p['dist_nm']:>5.0f}nm "
              f"{legs_str} {p['tas_kt']:>4} {p['wind_along_kt']:>+5.0f}{wind_dir[0]} "
              f"{our_str}z {grib_str}z "
              f"{p['delta_min']:>+5.1f}m")

    deltas = [p["delta_min"] for p in predictions]
    winds = [p["wind_along_kt"] for p in predictions]
    print(f"\nMean delta (wind - geo): {sum(deltas)/len(deltas):+.1f} min")
    print(f"Mean wind component:     {sum(winds)/len(winds):+.1f} kt")
    headwinds = [w for w in winds if w < 0]
    tailwinds = [w for w in winds if w > 0]
    if headwinds:
        print(f"Headwinds: {len(headwinds)} flights, avg {sum(headwinds)/len(headwinds):.0f} kt")
    if tailwinds:
        print(f"Tailwinds: {len(tailwinds)} flights, avg {sum(tailwinds)/len(tailwinds):.0f} kt")


def print_accuracy(conn: sqlite3.Connection):
    """Print accuracy report: our TLDT vs GRIB-corrected vs PERTI, all vs ALDT.

    Uses the LAST prediction for each callsign (closest to landing = most
    meaningful). Positive error = predicted later than actual landing.
    """
    # One row per flight — last observation (closest to ALDT)
    rows = conn.execute("""
        SELECT p.callsign, p.ades, p.dist_nm, p.wind_along_kt,
               p.our_error_min, p.wind_error_min, p.perti_error_min,
               p.our_eldt, p.aldt, p.geo_error_min
        FROM predictions p
        INNER JOIN (
            SELECT callsign, MAX(observed_at) as max_obs
            FROM predictions
            WHERE aldt_epoch IS NOT NULL
            GROUP BY callsign
        ) latest ON p.callsign = latest.callsign AND p.observed_at = latest.max_obs
        WHERE p.aldt_epoch IS NOT NULL
        ORDER BY p.dist_nm DESC
    """).fetchall()

    if not rows:
        print("\n[accuracy] No landed flights with predictions yet")
        return

    # Per-flight detail table
    print(f"\n[accuracy] {len(rows)} landed flights — error vs ALDT (min, + = late):")
    print(f"{'Callsign':<10} {'ADES':<5} {'Dist':>5} {'Wind':>5} "
          f"{'OurTLDT':>8} {'GRIB':>8} {'PERTI':>8}")
    print("-" * 60)

    our_errs = []
    wind_errs = []
    perti_errs = []
    hw_our = []
    hw_wind = []
    tw_our = []
    tw_wind = []

    for r in rows:
        cs, ades, dist, wind_kt, our_err, wind_err, perti_err, _, _, geo_err = r
        our_str = f"{our_err:>+7.1f}" if our_err is not None else "    n/a"
        wind_str = f"{wind_err:>+7.1f}" if wind_err is not None else "    n/a"
        perti_str = f"{perti_err:>+7.1f}" if perti_err is not None else "    n/a"
        print(f"{cs:<10} {ades:<5} {dist:>5.0f} {wind_kt:>+5.0f} "
              f"{our_str} {wind_str} {perti_str}")

        if our_err is not None:
            our_errs.append(our_err)
        if wind_err is not None:
            wind_errs.append(wind_err)
        if perti_err is not None:
            perti_errs.append(perti_err)

        # Headwind/tailwind buckets
        if wind_kt is not None and wind_kt < -10:
            if our_err is not None:
                hw_our.append(our_err)
            if wind_err is not None:
                hw_wind.append(wind_err)
        elif wind_kt is not None and wind_kt > 10:
            if our_err is not None:
                tw_our.append(our_err)
            if wind_err is not None:
                tw_wind.append(wind_err)

    # Summary stats
    def stats(errs, label):
        if not errs:
            print(f"  {label:<22} (no data)")
            return
        mean = sum(errs) / len(errs)
        mae = sum(abs(e) for e in errs) / len(errs)
        rmse = math.sqrt(sum(e**2 for e in errs) / len(errs))
        print(f"  {label:<22} n={len(errs):>3}  "
              f"mean={mean:>+6.1f}  MAE={mae:>5.1f}  RMSE={rmse:>5.1f}")

    print(f"\n[accuracy] Summary statistics:")
    stats(our_errs, "Our TLDT (production)")
    stats(wind_errs, "GRIB-corrected TLDT")
    stats(perti_errs, "PERTI ETA")

    # Headwind / tailwind breakdown
    if hw_our or hw_wind:
        print(f"\n  Headwind flights (>10kt headwind):")
        if hw_our:
            print(f"    Our TLDT mean error:  {sum(hw_our)/len(hw_our):>+6.1f} min (n={len(hw_our)})")
        if hw_wind:
            print(f"    GRIB-corr mean error: {sum(hw_wind)/len(hw_wind):>+6.1f} min (n={len(hw_wind)})")

    if tw_our or tw_wind:
        print(f"\n  Tailwind flights (>10kt tailwind):")
        if tw_our:
            print(f"    Our TLDT mean error:  {sum(tw_our)/len(tw_our):>+6.1f} min (n={len(tw_our)})")
        if tw_wind:
            print(f"    GRIB-corr mean error: {sum(tw_wind)/len(tw_wind):>+6.1f} min (n={len(tw_wind)})")


# ---------------------------------------------------------------------------
#  Main loop
# ---------------------------------------------------------------------------

def push_wind_eldts(predictions: list[dict]):
    """Push wind-corrected ELDTs to the server for three-way comparison."""
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
        result = resp.json()
        return result.get("updated", 0)
    except Exception as e:
        print(f"[wind] Push failed: {e}", file=sys.stderr)
        return 0


def run_once(conn: sqlite3.Connection, grid: dict) -> int:
    """Run one prediction cycle. Returns count of predictions made."""
    flights = fetch_flights()
    predictions = []

    for f in flights:
        p = compute_wind_eta(f, grid)
        if p:
            predictions.append(p)
            store_prediction(conn, p)

    conn.commit()

    # Push wind ELDTs to the server for PERTI page comparison
    pushed = push_wind_eldts(predictions)

    # Backfill ALDT for landed flights
    landed = backfill_aldt(conn, flights)

    print_snapshot(predictions)
    if pushed:
        print(f"\n[wind] Pushed {pushed} wind ELDTs to server")
    if landed:
        print(f"\n[wind] Backfilled {landed} landed flights with error metrics")
    print_accuracy(conn)

    return len(predictions)


def main():
    parser = argparse.ArgumentParser(description="Wind-shadow ETA experiment")
    parser.add_argument("--once", action="store_true", help="Single run, no loop")
    parser.add_argument("--db", default="bin/experiments/wind-shadow.db",
                        help="SQLite database path")
    args = parser.parse_args()

    # Suppress SSL warnings for self-signed cert
    import urllib3
    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

    db_path = args.db
    conn = init_db(db_path)
    cache_dir = Path(tempfile.gettempdir()) / "atfm-grib-cache"
    cache_dir.mkdir(exist_ok=True)

    print(f"[wind] Wind-shadow model starting")
    print(f"[wind] DB: {db_path}")
    print(f"[wind] GRIB cache: {cache_dir}")

    now = datetime.now(timezone.utc)
    date_str, cycle = latest_gfs_cycle(now)
    grib_path = fetch_grib(date_str, cycle, cache_dir)
    grid = load_wind_grid(grib_path)
    print(f"[wind] Loaded {date_str}/{cycle}z grid: "
          f"lats {grid['lats'][0]:.0f}-{grid['lats'][-1]:.0f}, "
          f"lons {grid['lons'][0]:.0f}-{grid['lons'][-1]:.0f}")

    if args.once:
        run_once(conn, grid)
    else:
        while True:
            try:
                # Refresh GRIB if cycle changed
                now = datetime.now(timezone.utc)
                new_date, new_cycle = latest_gfs_cycle(now)
                if new_date != date_str or new_cycle != cycle:
                    date_str, cycle = new_date, new_cycle
                    grib_path = fetch_grib(date_str, cycle, cache_dir)
                    grid = load_wind_grid(grib_path)
                    print(f"[wind] Refreshed grid: {date_str}/{cycle}z")

                run_once(conn, grid)
                print(f"\n[wind] Sleeping 120s...\n{'='*65}")
                time.sleep(120)
            except KeyboardInterrupt:
                print("\n[wind] Stopped")
                break
            except Exception as e:
                print(f"[wind] Error: {e}", file=sys.stderr)
                time.sleep(30)

    conn.close()


if __name__ == "__main__":
    main()
