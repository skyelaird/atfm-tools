#!/usr/bin/env python
"""
26E arrival-demand-with-wind-uncertainty viz.

For each booked CTP flight to a target arrival airport, trace its
route through the wind cache (no descent, no taxi — just CTOT-to-
destination cruise). Compute three arrival times per flight:

  nominal  — current wind cache
  fast     — wind cache + X kt boost (faster aircraft)
  slow     — wind cache - X kt boost (slower aircraft)

X = grid wind RMSE at the chosen forecast lead (8 / 12 / 17 kt for
D-1 / D-2 / D-3 from our verifier corpus).

Output: data/26E/arrival-demand-{ICAO}.json with three binned demand
curves so the HTML viewer can render high / nominal / low lines at
the destination airport.
"""

import argparse
import json
import math
import os
import sys
from collections import defaultdict
from datetime import datetime

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_DIR = os.path.join(REPO_ROOT, 'data', '26E')
ROUTES_PATH = os.path.join(DATA_DIR, 'ctp-routes.jsonl')
WINDS_PATH = os.path.join(DATA_DIR, 'winds-cache.json')

# Cruise model — kept deliberately simple. No climb, no descent, no taxi.
CRUISE_TAS_KT = 480     # M0.84 at FL340
LEVEL_MB = '250'        # FL340 level for wind interpolation
BIN_MIN = 10            # arrival-rate bin (5-min was too spiky with peak counts of 7-8)

# Grid-wind RMSE per forecast lead (kt) — from current verifier corpus.
SIGMA_GRID_KT = {'D-1': 8, 'D-2': 12, 'D-3': 17}

R_EARTH_NM = 3440.065


def to_rad(d): return d * math.pi / 180
def to_deg(r): return r * 180 / math.pi


def gc_distance_nm(a, b):
    lat1, lon1 = to_rad(a[0]), to_rad(a[1])
    lat2, lon2 = to_rad(b[0]), to_rad(b[1])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    h = math.sin(dlat / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon / 2) ** 2
    return 2 * R_EARTH_NM * math.asin(math.sqrt(h))


def gc_interp(a, b, frac):
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
    return (to_deg(math.atan2(z, math.sqrt(x * x + y * y))),
            to_deg(math.atan2(y, x)))


def bearing_deg(a, b):
    lat1r = to_rad(a[0]); lat2r = to_rad(b[0])
    dlon = to_rad(b[1] - a[1])
    x = math.sin(dlon) * math.cos(lat2r)
    y = math.cos(lat1r) * math.sin(lat2r) - math.sin(lat1r) * math.cos(lat2r) * math.cos(dlon)
    return (to_deg(math.atan2(x, y)) + 360) % 360


def wind_at(grid_data, level_mb, lat, lon):
    """Bilinear interpolation of (u, v) wind in kt at (lat, lon)."""
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
        return g.get(f'{int(la)}_{int(lo)}', [0.0, 0.0])

    c00 = corner(la0, lo0); c01 = corner(la0, lo1)
    c10 = corner(la1, lo0); c11 = corner(la1, lo1)
    u = ((1 - fa) * ((1 - fo) * c00[0] + fo * c01[0]) +
         fa * ((1 - fo) * c10[0] + fo * c11[0]))
    v = ((1 - fa) * ((1 - fo) * c00[1] + fo * c01[1]) +
         fa * ((1 - fo) * c10[1] + fo * c11[1]))
    return (u, v)


def along_track_wind(u_kt, v_kt, heading_deg):
    """Positive = tailwind, negative = headwind."""
    hr = math.radians(heading_deg)
    return u_kt * math.sin(hr) + v_kt * math.cos(hr)


def trace_flight_time_sec(route, wind_grid, perturbation_kt):
    """Total cruise time from first waypoint to last, with wind + GS perturbation.
    perturbation_kt > 0 = aircraft goes faster (or stronger tailwind component).
    No climb, descent, or taxi — pure straight-line cruise.
    """
    total = 0.0
    for i in range(len(route) - 1):
        a = (route[i]['lat'], route[i]['lon'])
        b = (route[i + 1]['lat'], route[i + 1]['lon'])
        d_nm = gc_distance_nm(a, b)
        if d_nm < 0.5:
            continue
        mid = gc_interp(a, b, 0.5)
        u, v = wind_at(wind_grid, LEVEL_MB, mid[0], mid[1])
        brg = bearing_deg(a, b)
        atw = along_track_wind(u, v, brg)
        gs = max(150.0, CRUISE_TAS_KT + atw + perturbation_kt)
        total += (d_nm / gs) * 3600
    return total


def ctot_to_sec(s):
    hh, mm = s.split(':')
    return int(hh) * 3600 + int(mm) * 60


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--arr', required=True, help='Arrival ICAO (e.g. LFPG)')
    args = ap.parse_args()
    target_icao = args.arr.upper()

    print(f'Loading wind cache: {WINDS_PATH}')
    with open(WINDS_PATH) as f:
        wind_grid = json.load(f)

    flights = []
    with open(ROUTES_PATH) as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            d = json.loads(line)
            if d.get('arr', '').upper() == target_icao:
                if d.get('route') and len(d['route']) >= 2:
                    flights.append(d)
    print(f'Found {len(flights)} flights to {target_icao}')

    # Compute arrival times: nominal + per-lead {fast, slow}
    series = {'nominal': []}
    for lead in SIGMA_GRID_KT:
        series[f'{lead}-fast'] = []
        series[f'{lead}-slow'] = []

    n_routed = 0
    for fr in flights:
        ctot_s = ctot_to_sec(fr['ctot'])
        nom_t = trace_flight_time_sec(fr['route'], wind_grid, 0)
        if nom_t <= 0:
            continue
        n_routed += 1
        nom_arr = ctot_s + nom_t
        series['nominal'].append(nom_arr)
        for lead, X in SIGMA_GRID_KT.items():
            fast_t = trace_flight_time_sec(fr['route'], wind_grid, +X)
            slow_t = trace_flight_time_sec(fr['route'], wind_grid, -X)
            series[f'{lead}-fast'].append(ctot_s + fast_t)
            series[f'{lead}-slow'].append(ctot_s + slow_t)
    print(f'Traced {n_routed} flights')

    # Bin arrivals to 5-min bins (minute-of-day)
    BIN_SEC = BIN_MIN * 60
    binned = {}
    for name, arrivals in series.items():
        counts = defaultdict(int)
        for arr_sec in arrivals:
            bm = int(arr_sec // BIN_SEC) * BIN_MIN
            counts[bm] += 1
        rows = sorted(
            [{'bin_minute': b, 'count': c} for b, c in counts.items()],
            key=lambda x: x['bin_minute']
        )
        binned[name] = rows

    out = {
        'meta': {
            'event': '26E',
            'target_icao': target_icao,
            'n_flights': n_routed,
            'cruise_tas_kt': CRUISE_TAS_KT,
            'level_mb': LEVEL_MB,
            'bin_minutes': BIN_MIN,
            'sigma_grid_kt_by_lead': SIGMA_GRID_KT,
            'note': (
                'Cruise-only trace from CTOT to destination. No climb / descent / '
                'taxi. ETD = CTOT exactly. Three series per lead time: '
                'nominal (current wind cache), fast (+X kt boost = aircraft sees '
                'less headwind / more tailwind), slow (-X kt). Each represents '
                'the WHOLE FLEET shifted that way — independent per-flight '
                'perturbation already shown in 26e-{1,2,3}.html (this is a '
                'simpler airport-level demand curve).'
            ),
        },
        'series': binned,
    }
    out_path = os.path.join(DATA_DIR, f'arrival-demand-{target_icao}.json')
    with open(out_path, 'w') as f:
        json.dump(out, f, indent=1)
    public_path = os.path.join(REPO_ROOT, 'public', f'26e-arrival-{target_icao}.json')
    with open(public_path, 'w') as f:
        json.dump(out, f)
    print(f'Wrote: {out_path}')
    print(f'Wrote: {public_path}')

    # Quick stats per series
    print()
    print(f'{"series":>12s}  {"flights":>7s}  {"first":>8s}  {"peak":>5s}  {"peak_at":>7s}  {"last":>8s}')
    for name in ['nominal'] + [f'{l}-fast' for l in SIGMA_GRID_KT] + [f'{l}-slow' for l in SIGMA_GRID_KT]:
        rows = binned.get(name, [])
        if not rows:
            print(f'  {name:>10s}  (empty)')
            continue
        peak = max(rows, key=lambda x: x['count'])
        first = rows[0]['bin_minute']
        last = rows[-1]['bin_minute']

        def fmt(b):
            return f'{b // 60:02d}{b % 60:02d}Z'
        print(f'  {name:>10s}  {sum(r["count"] for r in rows):>7d}  '
              f'{fmt(first):>8s}  {peak["count"]:>5d}  {fmt(peak["bin_minute"]):>7s}  '
              f'{fmt(last):>8s}')


if __name__ == '__main__':
    main()
