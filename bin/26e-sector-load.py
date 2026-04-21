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
from collections import defaultdict
from datetime import datetime, timedelta, timezone

DATA_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'data', '26E')
CRUISE_TAS = 480.0  # kt, flat — no wind for v1
BIN_MIN = 5         # 5-minute bins (change to 15 for coarser chart)
SAMPLE_STEP_SEC = 30  # trace position every 30 seconds for sector-edge precision


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


def parse_route(route_str, airports, dep, arr):
    """
    Parse filed route into a list of (lat, lon) pairs.
    Starts at DEP coords, ends at ARR coords, with all resolvable
    coordinate waypoints in between (in order).
    """
    tokens = route_str.split()
    path = []
    if dep in airports:
        path.append(tuple(airports[dep]))
    for t in tokens:
        # Skip the dep/arr tokens themselves
        if t == dep or t == arr:
            continue
        c = parse_coord_waypoint(t)
        if c is not None:
            path.append(c)
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


def trace_path(path, speed_kt, step_sec):
    """
    Yield (t_sec_from_start, lat, lon) samples along the multi-leg path.
    Samples every step_sec seconds, advancing along each leg at speed_kt.
    """
    t = 0.0
    for i in range(len(path) - 1):
        a = path[i]
        b = path[i + 1]
        d_nm = gc_distance_nm(a, b)
        if d_nm < 0.01:
            continue
        leg_sec = (d_nm / speed_kt) * 3600.0
        # Yield at 0-leg if this is the first leg
        if i == 0:
            yield (t, a[0], a[1])
        # Step through this leg
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
    with open(os.path.join(DATA_DIR, 'airports.json')) as f:
        airports = json.load(f)
    with open(os.path.join(DATA_DIR, 'sectors.json')) as f:
        sectors = json.load(f)

    # Output: per-sector per-bin count
    sector_bins = {s['id']: defaultdict(int) for s in sectors}
    # Also track per-flight sector-entry records for debugging/detail
    flight_records = []
    flights_in_any_sector = 0

    n_parsed = 0
    n_no_path = 0
    unresolved_deps = set()

    with open(os.path.join(DATA_DIR, 'bookings.csv'), encoding='utf-8') as f:
        rows = list(csv.DictReader(f))

    for row in rows:
        dep = row['dep']
        arr = row['arr']
        ctot = row['ctot']
        callsign = row['callsign']

        if dep not in airports:
            unresolved_deps.add(dep)
            continue

        path = parse_route(row['route'], airports, dep, arr)
        if len(path) < 2:
            n_no_path += 1
            continue

        ctot_sec = ctot_to_dt(ctot)

        # Trace, recording sector transitions
        cur_sector = None
        entry_t = None
        touched = {}
        for t_sec, lat, lon in trace_path(path, CRUISE_TAS, SAMPLE_STEP_SEC):
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
            # Bin: for each sector the flight was in, credit every bin that
            # contains at least one second of that flight being present.
            for sec_id, intervals in touched.items():
                for a, b in intervals:
                    bin_a = int(a) // 60 // BIN_MIN * BIN_MIN
                    bin_b = int(b) // 60 // BIN_MIN * BIN_MIN
                    bn = bin_a
                    while bn <= bin_b:
                        sector_bins[sec_id][bn] += 1
                        bn += BIN_MIN

        n_parsed += 1

    # Prepare output
    output = {
        'meta': {
            'generated_utc': datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z'),
            'bin_minutes': BIN_MIN,
            'cruise_tas_kt': CRUISE_TAS,
            'flights_total': len(rows),
            'flights_parsed': n_parsed,
            'flights_without_path': n_no_path,
            'flights_touching_sector': flights_in_any_sector,
            'unresolved_deps': sorted(unresolved_deps),
        },
        'sectors': [
            {
                'id': s['id'],
                'label': s['label'],
                'freq': s['freq'],
                'polygon': s['polygon'],
            } for s in sectors
        ],
        'load': {
            sid: sorted([{'bin_minute': b, 'count': c} for b, c in bins.items()],
                        key=lambda x: x['bin_minute'])
            for sid, bins in sector_bins.items()
        },
        'flights': flight_records
    }

    out_path = os.path.join(DATA_DIR, 'sector-load.json')
    with open(out_path, 'w') as f:
        json.dump(output, f, indent=1)

    print(f'Parsed   : {n_parsed} / {len(rows)}')
    print(f'No path  : {n_no_path}')
    print(f'Touching : {flights_in_any_sector}')
    print(f'Unknown deps: {sorted(unresolved_deps)}')
    print(f'Output   : {out_path}')
    # Print peak per sector
    print()
    print(f'Sector peaks (count of concurrent flights per {BIN_MIN}m bin):')
    for sid in sorted(sector_bins):
        if not sector_bins[sid]:
            print(f'  {sid}: (no flights)')
            continue
        peak_bin = max(sector_bins[sid].items(), key=lambda x: x[1])
        hh = peak_bin[0] // 60
        mm = peak_bin[0] % 60
        print(f'  {sid}: peak {peak_bin[1]} @ {hh:02d}{mm:02d}Z')


if __name__ == '__main__':
    main()
