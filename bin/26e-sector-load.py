#!/usr/bin/env python
"""
26E Sector Load — CTP event airspace demand estimator.

Input:
  data/26E/bookings.csv     1070 flights with filed routes + CTOT + alt
  data/26E/airports.json    ADEP/ADES coordinates for 21 airports
  data/26E/sectors.json     11 CZQM+CZQX sector polygons (Topsky)

Model:
  For each booking, parse the filed route to an ordered list of
  waypoint coordinates. Resolvable tokens:
    - ADEP / ADES from airports.json
    - Explicit coord waypoints:  LLNlllW    (e.g. 66N050W)
    - NAT-style 4-digit waypoints: LLll N   (e.g. 5690N = 56°N 90°W)
    - 5-digit:  LLlll N                       (e.g. 55099N = 55°N 99°W)
  Other tokens (ICAO fixes, airway names, SID/STAR, DCT) dropped — the
  coordinate waypoints alone define the oceanic portion of the route
  which is what transits the QM/QX sectors we care about.

  Assume instant climb to cruise. Use flat TAS = 480 kt (wind-neutral).
  Great-circle interpolate along resolved waypoints, sample every
  minute. For each sample point, ray-cast against the 11 sector
  polygons and credit the sector for that minute.

Output:
  data/26E/sector-load.json — per-sector per-minute-bin load counts.
"""

import csv
import json
import math
import re
import os
import sys
import time
import urllib.parse
from collections import defaultdict
from datetime import datetime, timedelta, timezone

try:
    import requests as _requests
except ImportError:
    _requests = None

REPO_DATA = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'data')
DATA_DIR = os.path.join(REPO_DATA, '26E')
CTP_ROUTES = os.path.join(DATA_DIR, 'ctp-routes.jsonl')
WIND_CACHE = os.path.join(DATA_DIR, 'winds-cache.json')
DEFAULT_FL = 370  # fallback cruise alt if slot has none (CTP doesn't publish filed FL)
CRUISE_MACH = 0.82
BIN_MIN = 5         # 5-minute bins (change to 15 for coarser chart)
SAMPLE_STEP_SEC = 30  # trace position every 30 seconds for sector-edge precision
MAX_LEG_NM = 800.0   # reject a resolved named fix if it's >N nm from the prior waypoint
                     # (catches Navigraph duplicate-name collisions, e.g. LAKEY
                     # resolving to Arizona instead of UK)

# Wind grid covering NAT + North America (lat, lon box + step)
WIND_LAT_MIN, WIND_LAT_MAX, WIND_LAT_STEP = 25, 70, 5
WIND_LON_MIN, WIND_LON_MAX, WIND_LON_STEP = -100, 20, 5


# ------------------------------------------------------------------
#  Mach to TAS (ISA atmosphere)
# ------------------------------------------------------------------

def isa_temp_k(alt_ft):
    """ISA temperature at alt_ft. Troposphere below 36089 ft, stratosphere above."""
    if alt_ft < 36089:
        return 288.15 - 1.9812 * (alt_ft / 1000.0)
    return 216.65

def mach_to_tas_kt(mach, alt_ft):
    """TAS in knots for given Mach number at given altitude (ISA)."""
    t_k = isa_temp_k(alt_ft)
    # a (kt) = sqrt(gamma * R * T) in m/s, / (1852/3600) for kt.
    # Precomputed constant 38.967 kt/sqrt(K).
    a_kt = 38.967 * math.sqrt(t_k)
    return mach * a_kt


def fl_to_pressure_level(fl_ft):
    """
    Pick the nearest standard pressure level (mb) for the flight's cruise alt.
    Our Open-Meteo fetch covers 200/250/300 hPa.
      200 hPa ≈ FL385
      250 hPa ≈ FL340
      300 hPa ≈ FL300
    """
    if fl_ft >= 36000: return 200
    if fl_ft >= 30000: return 250
    return 300


# ------------------------------------------------------------------
#  Wind — Open-Meteo GFS fetch (cached)
# ------------------------------------------------------------------

def fetch_wind_grid(event_hour_utc, force=False):
    """
    Fetch wind at (lat_min..max, lon_min..max, step) from Open-Meteo GFS.
    Returns a dict keyed by pressure level (200/250/300) → 2D array of
    {u, v} (kt). Cached to WIND_CACHE (reused if < 6h old).
    """
    if not force and os.path.exists(WIND_CACHE):
        age_hr = (time.time() - os.path.getmtime(WIND_CACHE)) / 3600
        if age_hr < 6:
            with open(WIND_CACHE) as f:
                return json.load(f)

    lats = list(range(WIND_LAT_MIN, WIND_LAT_MAX + 1, WIND_LAT_STEP))
    lons = list(range(WIND_LON_MIN, WIND_LON_MAX + 1, WIND_LON_STEP))
    points = [(la, lo) for la in lats for lo in lons]

    print(f'Fetching winds from Open-Meteo: {len(points)} grid points '
          f'(lat {WIND_LAT_MIN}..{WIND_LAT_MAX}/{WIND_LAT_STEP}°, '
          f'lon {WIND_LON_MIN}..{WIND_LON_MAX}/{WIND_LON_STEP}°)...', flush=True)

    grid = {lvl: {} for lvl in (200, 250, 300)}
    # Open-Meteo accepts up to ~10 points per call (point-list semantics).
    CHUNK = 10
    for i in range(0, len(points), CHUNK):
        batch = points[i:i+CHUNK]
        lat_str = ','.join(str(p[0]) for p in batch)
        lon_str = ','.join(str(p[1]) for p in batch)
        vars_ = ['wind_speed_300hPa', 'wind_direction_300hPa',
                 'wind_speed_250hPa', 'wind_direction_250hPa',
                 'wind_speed_200hPa', 'wind_direction_200hPa']
        params = {
            'latitude': lat_str,
            'longitude': lon_str,
            'hourly': ','.join(vars_),
            'wind_speed_unit': 'kn',
            'forecast_days': 1,
        }
        url = 'https://api.open-meteo.com/v1/gfs'
        try:
            if _requests is not None:
                r = _requests.get(url, params=params, timeout=60)
                r.raise_for_status()
                data = r.json()
            else:
                import urllib.request
                full = url + '?' + urllib.parse.urlencode(params)
                with urllib.request.urlopen(full, timeout=60) as resp:
                    data = json.loads(resp.read().decode('utf-8'))
        except Exception as e:
            print(f'  batch {i}: ERROR {e}', flush=True)
            continue
        # data is a list when multiple points given
        if not isinstance(data, list):
            data = [data]
        for pt_data, (la, lo) in zip(data, batch):
            h = pt_data.get('hourly', {})
            times = h.get('time', [])
            # Find the hour index closest to event_hour_utc
            target = event_hour_utc.strftime('%Y-%m-%dT%H:00')
            idx = 0
            for j, t in enumerate(times):
                if t == target:
                    idx = j
                    break
            for lvl in (200, 250, 300):
                spd_k = f'wind_speed_{lvl}hPa'
                dir_k = f'wind_direction_{lvl}hPa'
                if spd_k not in h or dir_k not in h or idx >= len(h[spd_k]):
                    continue
                speed_kt = h[spd_k][idx]
                dir_deg = h[dir_k][idx]
                if speed_kt is None or dir_deg is None:
                    continue
                # Wind direction is the direction FROM which the wind blows.
                # U (east component) = -speed * sin(dir), V (north) = -speed * cos(dir)
                dr = math.radians(dir_deg)
                u = -speed_kt * math.sin(dr)
                v = -speed_kt * math.cos(dr)
                grid[lvl][f'{la}_{lo}'] = [round(u, 2), round(v, 2)]
        if (i // CHUNK) % 10 == 9:
            print(f'  fetched {i+len(batch)} / {len(points)} points', flush=True)

    # Normalize level keys to strings (JSON only supports string keys on
    # disk; we keep them as strings in memory too so wind_at has a single
    # code path whether data came from live fetch or cache reload).
    grid_str = {str(k): v for k, v in grid.items()}
    out = {
        'fetched_utc': datetime.now(timezone.utc).isoformat(),
        'event_hour_utc': event_hour_utc.isoformat(),
        'lat_range': [WIND_LAT_MIN, WIND_LAT_MAX, WIND_LAT_STEP],
        'lon_range': [WIND_LON_MIN, WIND_LON_MAX, WIND_LON_STEP],
        'grids': grid_str,
    }
    with open(WIND_CACHE, 'w') as f:
        json.dump(out, f)
    return out


def wind_at(grid_data, level_mb, lat, lon):
    """
    Bilinear interpolation of (u, v) wind vector (kt) at (lat, lon) from
    the grid at the given pressure level. Returns (u_kt, v_kt) or (0, 0)
    if the point is outside the grid.
    """
    g = grid_data['grids'].get(str(level_mb))
    if g is None:
        return (0.0, 0.0)

    lat_min, lat_max, lat_step = grid_data['lat_range']
    lon_min, lon_max, lon_step = grid_data['lon_range']
    if not (lat_min <= lat <= lat_max and lon_min <= lon <= lon_max):
        return (0.0, 0.0)

    la0 = lat_min + math.floor((lat - lat_min) / lat_step) * lat_step
    lo0 = lon_min + math.floor((lon - lon_min) / lon_step) * lon_step
    la1 = min(la0 + lat_step, lat_max)
    lo1 = min(lo0 + lon_step, lon_max)
    fa = (lat - la0) / lat_step if lat_step else 0
    fo = (lon - lo0) / lon_step if lon_step else 0

    def corner(la, lo):
        k = f'{int(la)}_{int(lo)}'
        return g.get(k, [0.0, 0.0])

    c00 = corner(la0, lo0); c01 = corner(la0, lo1)
    c10 = corner(la1, lo0); c11 = corner(la1, lo1)
    u = ((1 - fa) * ((1 - fo) * c00[0] + fo * c01[0]) +
         fa * ((1 - fo) * c10[0] + fo * c11[0]))
    v = ((1 - fa) * ((1 - fo) * c00[1] + fo * c01[1]) +
         fa * ((1 - fo) * c10[1] + fo * c11[1]))
    return (u, v)


def bearing_deg(lat1, lon1, lat2, lon2):
    """Initial great-circle bearing from (lat1,lon1) to (lat2,lon2), degrees."""
    lat1r = math.radians(lat1); lat2r = math.radians(lat2)
    dlon = math.radians(lon2 - lon1)
    x = math.sin(dlon) * math.cos(lat2r)
    y = math.cos(lat1r) * math.sin(lat2r) - math.sin(lat1r) * math.cos(lat2r) * math.cos(dlon)
    return (math.degrees(math.atan2(x, y)) + 360) % 360


def along_track_wind_kt(u_kt, v_kt, heading_deg):
    """Positive = tailwind, negative = headwind."""
    hr = math.radians(heading_deg)
    return u_kt * math.sin(hr) + v_kt * math.cos(hr)


# ------------------------------------------------------------------
#  Route parsing
# ------------------------------------------------------------------

def parse_coord_waypoint(tok):
    """
    Try to parse a route token as a coordinate waypoint.
    Returns (lat, lon) or None.

    Handles:
      66N050W  -> 66°N, 50°W
      55N099W  -> 55°N, 99°W
      5690N    -> 56°N, 90°W
      5373N    -> 53°N, 73°W
      5980N    -> 59°N, 80°W
      54N100W  -> 54°N, 100°W
      5N50W    -> 5°N, 50°W  (rare)
    """
    # Explicit: LLNLLLW / LLNlllW
    m = re.match(r'^(\d{1,2})N(\d{2,3})W$', tok)
    if m:
        return float(m.group(1)), -float(m.group(2))

    # NAT-style no-hemisphere-on-lon: LLLLN / LLLLLN (4 or 5 digits + N)
    m = re.match(r'^(\d{2})(\d{2,3})N$', tok)
    if m:
        return float(m.group(1)), -float(m.group(2))

    return None


def is_airway_or_procedure(tok):
    """
    Heuristic to skip airway identifiers, procedures, and DCT.
    Airways typically start with letter followed by digits (e.g. J95, Q979, UL612).
    """
    if tok == 'DCT':
        return True
    # Single letter + digits: J95, Q979
    if re.match(r'^[A-Z]\d+$', tok):
        return True
    # Two+ letters + digits (airways): UL612, UM605, T703
    if re.match(r'^[A-Z]{1,3}\d+$', tok) and len(tok) >= 3:
        return True
    # SID/STAR-like: 7-char with digit (e.g. VERDO7, JCOBY4, MRSSH3)
    if re.match(r'^[A-Z]+\d[A-Z]?$', tok) and any(c.isdigit() for c in tok):
        return True
    return False


def parse_route(route_str, airports, waypoints, dep, arr, stats):
    """
    Parse filed route into a list of (lat, lon) pairs.
    Resolution order per token:
      1. Coordinate waypoint (5690N, 66N050W, etc.)
      2. Named fix in navdata (ELVUX -> 50.18, -96.91)
         — with sanity check: reject if >MAX_LEG_NM from last fix
      3. Airway/procedure/DCT token — skipped
      4. Unknown — skipped, counted in stats
    """
    tokens = route_str.split()
    path = []
    if dep in airports:
        path.append(tuple(airports[dep]))

    last = path[-1] if path else None
    for t in tokens:
        if t == dep or t == arr:
            continue
        # 1. Coord waypoint
        c = parse_coord_waypoint(t)
        if c is not None:
            path.append(c)
            last = c
            continue
        # 2. Airway / procedure / DCT — skip quietly
        if is_airway_or_procedure(t):
            continue
        # 3. Named fix lookup
        if t in waypoints:
            latlon = tuple(waypoints[t])
            if last is not None:
                d = gc_distance_nm(last, latlon)
                if d > MAX_LEG_NM:
                    stats['rejected_distant_fix'].append((t, d))
                    continue
            path.append(latlon)
            last = latlon
            continue
        # 4. Unknown
        stats['unresolved_tokens'][t] = stats['unresolved_tokens'].get(t, 0) + 1

    if arr in airports:
        path.append(tuple(airports[arr]))
    return path


# ------------------------------------------------------------------
#  Great-circle interpolation
# ------------------------------------------------------------------

R_EARTH_NM = 3440.065  # nm

def to_rad(d): return d * math.pi / 180.0
def to_deg(r): return r * 180.0 / math.pi

def gc_distance_nm(a, b):
    """Haversine distance in nm."""
    lat1, lon1 = to_rad(a[0]), to_rad(a[1])
    lat2, lon2 = to_rad(b[0]), to_rad(b[1])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    h = math.sin(dlat/2)**2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon/2)**2
    return 2 * R_EARTH_NM * math.asin(math.sqrt(h))

def gc_interp(a, b, frac):
    """Intermediate point on great circle from a to b at fraction frac (0..1)."""
    lat1, lon1 = to_rad(a[0]), to_rad(a[1])
    lat2, lon2 = to_rad(b[0]), to_rad(b[1])
    d = gc_distance_nm(a, b) / R_EARTH_NM
    if d < 1e-9:
        return a
    A = math.sin((1 - frac) * d) / math.sin(d)
    B = math.sin(frac * d) / math.sin(d)
    x = A * math.cos(lat1) * math.cos(lon1) + B * math.cos(lat2) * math.cos(lon2)
    y = A * math.cos(lat1) * math.sin(lon1) + B * math.cos(lat2) * math.sin(lon2)
    z = A * math.sin(lat1) + B * math.sin(lat2)
    lat = math.atan2(z, math.sqrt(x*x + y*y))
    lon = math.atan2(y, x)
    return (to_deg(lat), to_deg(lon))


def trace_path(path, tas_kt, step_sec, wind_grid, press_mb):
    """
    Yield (t_sec_from_start, lat, lon) samples along the multi-leg path.
    Uses per-leg along-track wind to compute effective GS; samples every
    step_sec seconds of wall-clock.
    """
    t = 0.0
    for i in range(len(path) - 1):
        a = path[i]; b = path[i + 1]
        d_nm = gc_distance_nm(a, b)
        if d_nm < 0.01:
            continue
        # Wind at leg midpoint — good enough for legs of the length we see
        mid = gc_interp(a, b, 0.5)
        u, v = wind_at(wind_grid, press_mb, mid[0], mid[1])
        brg = bearing_deg(a[0], a[1], b[0], b[1])
        wind_along = along_track_wind_kt(u, v, brg)
        gs = max(150.0, min(tas_kt + wind_along, 700.0))
        leg_sec = (d_nm / gs) * 3600.0
        if i == 0:
            yield (t, a[0], a[1])
        s = step_sec
        while s < leg_sec:
            frac = s / leg_sec
            p = gc_interp(a, b, frac)
            yield (t + s, p[0], p[1])
            s += step_sec
        t += leg_sec
        yield (t, b[0], b[1])


# ------------------------------------------------------------------
#  Point in polygon (ray-cast)
# ------------------------------------------------------------------

def point_in_poly(lat, lon, poly):
    """
    Ray-cast algorithm. poly is list of [lat, lon].
    Horizontal ray: count longitude crossings at the lat of our point.
    """
    n = len(poly)
    inside = False
    j = n - 1
    for i in range(n):
        lat_i, lon_i = poly[i]
        lat_j, lon_j = poly[j]
        if (lat_i > lat) != (lat_j > lat):
            x_intersect = (lon_j - lon_i) * (lat - lat_i) / (lat_j - lat_i) + lon_i
            if lon < x_intersect:
                inside = not inside
        j = i
    return inside


def find_sector(lat, lon, sectors):
    for s in sectors:
        if point_in_poly(lat, lon, s['polygon']):
            return s['id']
    return None


# ------------------------------------------------------------------
#  Main
# ------------------------------------------------------------------

def ctot_to_dt(s):
    """CTOT "10:13" -> seconds-of-day int."""
    hh, mm = s.split(':')
    return int(hh) * 3600 + int(mm) * 60


def main():
    # Load inputs
    with open(os.path.join(DATA_DIR, 'sectors.json')) as f:
        sectors = json.load(f)

    # Fetch current wind grid for event midpoint hour (13Z — middle of
    # the 10Z-16Z event window).
    event_hour = datetime.now(timezone.utc).replace(hour=13, minute=0, second=0, microsecond=0)
    wind_grid = fetch_wind_grid(event_hour)
    print(f'  wind grids: {len(wind_grid["grids"].get("300", {}))} '
          f'points @ 300mb, {len(wind_grid["grids"].get("250", {}))} @ 250mb, '
          f'{len(wind_grid["grids"].get("200", {}))} @ 200mb', flush=True)

    # Per-minute occupancy: count of flights instantaneously in each sector
    # at each whole minute of the event window. 5-min chart bin takes
    # the MAX across the 5 minutes (peak instantaneous occupancy).
    # Each flight's [entry, exit] interval credits every minute it spans.
    sector_minute = {s['id']: defaultdict(int) for s in sectors}
    # Combined-pair occupancy: count of flights in (QMn ∪ QXn) at each minute
    # A flight counts once per minute even if it straddles both sectors.
    pair_map = {
        'QM1+QX1': ['QM1', 'QX1'],
        'QM2+QX2': ['QM2', 'QX2'],
        'QM3+QX3': ['QM3', 'QX3'],
        'QM4+QX4': ['QM4', 'QX4'],
    }
    pair_minute = {pid: defaultdict(int) for pid in pair_map}
    # Also track per-flight sector-entry records for debugging/detail
    flight_records = []
    flights_in_any_sector = 0

    n_parsed = 0
    n_no_path = 0

    # Consume CTP-resolved routes — each line = {slot_id, dep, arr, ctot, route: [{name, lat, lon}], facilities}
    rows = []
    with open(CTP_ROUTES) as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            rows.append(json.loads(line))

    for row in rows:
        dep = row['dep']; arr = row['arr']; ctot = row['ctot']
        callsign = f"slot_{row['slot_id']}"
        route_points = row['route']
        if len(route_points) < 2:
            n_no_path += 1
            continue
        # Build a list of (lat, lon) tuples in order
        path = [(p['lat'], p['lon']) for p in route_points]

        ctot_sec = ctot_to_dt(ctot)
        # CTP slot export doesn't publish filed FL; use DEFAULT_FL.
        alt_ft = DEFAULT_FL * 100
        tas = mach_to_tas_kt(CRUISE_MACH, alt_ft)
        press_mb = fl_to_pressure_level(alt_ft)

        # Trace, recording sector transitions
        cur_sector = None
        entry_t = None
        touched = {}
        for t_sec, lat, lon in trace_path(path, tas, SAMPLE_STEP_SEC, wind_grid, press_mb):
            abs_sec = ctot_sec + t_sec
            sec = find_sector(lat, lon, sectors)
            if sec != cur_sector:
                if cur_sector is not None and entry_t is not None:
                    touched.setdefault(cur_sector, []).append((entry_t, abs_sec))
                cur_sector = sec
                entry_t = abs_sec if sec else None
        # Close any open sector at end of path
        if cur_sector is not None and entry_t is not None:
            # Use the last sample's abs_sec as exit
            touched.setdefault(cur_sector, []).append((entry_t, abs_sec))

        if touched:
            flights_in_any_sector += 1
            flight_records.append({
                'callsign': callsign,
                'dep': dep, 'arr': arr, 'ctot': ctot,
                'sectors': {k: [[int(a), int(b)] for a, b in v] for k, v in touched.items()}
            })
            # Instantaneous occupancy: for each minute this flight's sector
            # interval spans, +1 to that sector's minute count.
            # Also track which minutes the flight is in each pair (union).
            flight_minutes_per_pair = {pid: set() for pid in pair_map}
            for sec_id, intervals in touched.items():
                for a, b in intervals:
                    m_start = int(a) // 60
                    m_end = int(b) // 60
                    for m in range(m_start, m_end + 1):
                        sector_minute[sec_id][m] += 1
                        # Pair membership: flight in pair at minute m if in
                        # either sector. Track set so we don't double-count.
                        for pid, pair_sectors in pair_map.items():
                            if sec_id in pair_sectors:
                                flight_minutes_per_pair[pid].add(m)
            for pid, minute_set in flight_minutes_per_pair.items():
                for m in minute_set:
                    pair_minute[pid][m] += 1

        n_parsed += 1

    # Aggregate per-minute counts into 5-min bins using MAX (peak
    # instantaneous occupancy in the bin).
    def bin_max(minute_map):
        out = defaultdict(int)
        for m, c in minute_map.items():
            bin_key = (m // BIN_MIN) * BIN_MIN
            if c > out[bin_key]:
                out[bin_key] = c
        return out

    sector_bins = {sid: bin_max(mm) for sid, mm in sector_minute.items()}
    pair_bins = {pid: bin_max(mm) for pid, mm in pair_minute.items()}

    # Prepare output
    output = {
        'meta': {
            'generated_utc': datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z'),
            'bin_minutes': BIN_MIN,
            'metric': 'instantaneous_occupancy_peak_in_bin',
            'cruise_mach': CRUISE_MACH,
            'cruise_fl_default': DEFAULT_FL,
            'route_source': 'CTP simulator-data (revision 141)',
            'wind_source': 'Open-Meteo GFS',
            'wind_event_hour_utc': wind_grid.get('event_hour_utc'),
            'flights_total': len(rows),
            'flights_parsed': n_parsed,
            'flights_without_path': n_no_path,
            'flights_touching_sector': flights_in_any_sector,
        },
        'sectors': [
            {
                'id': s['id'],
                'label': s['label'],
                'freq': s['freq'],
                'polygon': s['polygon'],
            } for s in sectors
        ],
        'pairs': [
            {'id': pid, 'members': members, 'label': f'{members[0]}+{members[1]}'}
            for pid, members in pair_map.items()
        ],
        'load': {
            sid: sorted([{'bin_minute': b, 'count': c} for b, c in bins.items()],
                        key=lambda x: x['bin_minute'])
            for sid, bins in sector_bins.items()
        },
        'pair_load': {
            pid: sorted([{'bin_minute': b, 'count': c} for b, c in bins.items()],
                        key=lambda x: x['bin_minute'])
            for pid, bins in pair_bins.items()
        },
        'flights': flight_records
    }

    out_path = os.path.join(DATA_DIR, 'sector-load.json')
    with open(out_path, 'w') as f:
        json.dump(output, f, indent=1)

    print(f'Parsed   : {n_parsed} / {len(rows)}')
    print(f'No path  : {n_no_path}')
    print(f'Touching : {flights_in_any_sector}')
    print(f'Output   : {out_path}')
    # Print peak per sector + pair
    print()
    print(f'Sector peaks (instantaneous occupancy, max per {BIN_MIN}m bin):')
    for sid in sorted(sector_bins):
        if not sector_bins[sid]:
            print(f'  {sid}: (no flights)')
            continue
        peak_bin = max(sector_bins[sid].items(), key=lambda x: x[1])
        hh = peak_bin[0] // 60
        mm = peak_bin[0] % 60
        print(f'  {sid}: peak {peak_bin[1]} @ {hh:02d}{mm:02d}Z')
    print()
    print(f'Pair peaks (QMn + QXn combined, occupancy):')
    for pid in sorted(pair_bins):
        if not pair_bins[pid]:
            print(f'  {pid}: (no flights)')
            continue
        peak_bin = max(pair_bins[pid].items(), key=lambda x: x[1])
        hh = peak_bin[0] // 60
        mm = peak_bin[0] % 60
        print(f'  {pid}: peak {peak_bin[1]} @ {hh:02d}{mm:02d}Z')


if __name__ == '__main__':
    main()
