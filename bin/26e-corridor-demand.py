#!/usr/bin/env python
"""26E corridor demand viz — same booked traffic, four measurement points.

For each booked CTP flight to a target arrival airport, compute arrival
times at four progressive points along the route:

  OEP      ~longitude -50W (oceanic entry, NAT-HLA west boundary)
  030W     longitude -30W (mid-Atlantic)
  OXP/EU   ~longitude -10W (oceanic exit, EU west boundary)
  arrival  destination (existing 26e-arrival behaviour)

Output a multi-series JSON per target airport, plus a small SVG showing
the four demand curves stacked. The point of the figure: as flights move
along the corridor, the demand curve at each measurement point spreads
out naturally — that natural fuzziness compounds with the wind-error
fuzziness we model elsewhere.

Cruise-only trace, no descent / no taxi. Same model as 26e-arrival-demand.py.
"""

import argparse
import json
import math
import os
import sys
from collections import defaultdict

sys.stdout.reconfigure(encoding='utf-8')

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_DIR = os.path.join(REPO_ROOT, 'data', '26E')
ROUTES_PATH = os.path.join(DATA_DIR, 'ctp-routes.jsonl')
WINDS_PATH = os.path.join(DATA_DIR, 'winds-cache.json')
FIGURES_DIR = r'C:\Users\JoelMorin\OneDrive\Games\VATSIM\CTP\figures'

CRUISE_TAS_KT = 480
LEVEL_MB = '250'
BIN_MIN = 15
SMOOTH_PASSES = 1

# Corridor measurement points (longitude in degrees, target name)
MEASUREMENT_POINTS = [
    ('OEP',     -50.0),
    ('030W',    -30.0),
    ('EU entry', -10.0),
]

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
    hr = math.radians(heading_deg)
    return u_kt * math.sin(hr) + v_kt * math.cos(hr)


def trace_with_milestones(route, wind_grid, milestones_lon):
    """Walk the route. Return:
      - total_time_sec (full route)
      - milestones: dict of {lon -> time_sec_at_crossing} for first east-crossing
    """
    total = 0.0
    crossings = {}
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
        gs = max(150.0, CRUISE_TAS_KT + atw)
        seg_sec = (d_nm / gs) * 3600

        # Check each milestone for east-crossing in this segment
        for tgt_lon in milestones_lon:
            if tgt_lon in crossings:
                continue
            # Eastbound flight: a.lon < target <= b.lon
            if a[1] < tgt_lon <= b[1]:
                frac = (tgt_lon - a[1]) / (b[1] - a[1])
                crossings[tgt_lon] = total + seg_sec * frac

        total += seg_sec

    return total, crossings


def ctot_to_sec(s):
    hh, mm = s.split(':')
    return int(hh) * 3600 + int(mm) * 60


def smooth_bins(arrivals, label=''):
    """Bin arrivals to BIN_MIN bins (minute-of-day) with [1,2,1]/4 smoothing."""
    BIN_SEC = BIN_MIN * 60
    counts = defaultdict(int)
    for arr_sec in arrivals:
        # Wrap minute-of-day
        bm = int((arr_sec % 86400) // BIN_SEC) * BIN_MIN
        counts[bm] += 1
    if not counts:
        return []
    bmin = min(counts) - BIN_MIN
    bmax = max(counts) + BIN_MIN
    bins = list(range(bmin, bmax + BIN_MIN, BIN_MIN))
    vals = [float(counts.get(b, 0)) for b in bins]
    for _ in range(SMOOTH_PASSES):
        sm = vals[:]
        for i in range(len(vals)):
            left  = vals[i - 1] if i - 1 >= 0          else vals[i]
            right = vals[i + 1] if i + 1 < len(vals)   else vals[i]
            sm[i] = (left + 2 * vals[i] + right) / 4
        vals = sm
    return [{'bin_minute': b, 'count': round(v, 2)}
            for b, v in zip(bins, vals) if v > 0.05]


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

    milestones_lon = [lon for _, lon in MEASUREMENT_POINTS]

    # Per-point arrival times (CTOT + transit_to_point)
    series = {label: [] for label, _ in MEASUREMENT_POINTS}
    series['arrival'] = []

    n_full = 0
    for fr in flights:
        ctot_s = ctot_to_sec(fr['ctot'])
        total_t, crossings = trace_with_milestones(fr['route'], wind_grid, milestones_lon)
        if total_t <= 0:
            continue
        n_full += 1
        series['arrival'].append(ctot_s + total_t)
        for label, lon in MEASUREMENT_POINTS:
            if lon in crossings:
                series[label].append(ctot_s + crossings[lon])

    print(f'Traced {n_full} flights')
    for label, _ in MEASUREMENT_POINTS:
        print(f'  {label}: {len(series[label])} flights crossed')
    print(f'  arrival: {len(series["arrival"])} flights')

    # Bin each series
    binned = {label: smooth_bins(arrivals, label)
              for label, arrivals in series.items()}

    out = {
        'meta': {
            'event': '26E',
            'target_icao': target_icao,
            'n_flights': n_full,
            'cruise_tas_kt': CRUISE_TAS_KT,
            'level_mb': LEVEL_MB,
            'bin_minutes': BIN_MIN,
            'smooth_passes': SMOOTH_PASSES,
            'measurement_points': [{'label': lbl, 'longitude': lon}
                                    for lbl, lon in MEASUREMENT_POINTS]
                                  + [{'label': 'arrival', 'longitude': None}],
            'note': (
                'Same booked traffic measured at progressive corridor points. '
                'Demand curve shape evolves: tight at OEP (close to origin, '
                'less variability accumulated), wider at arrival (full route-'
                'time variability accumulated across the heterogeneous fleet).'
            ),
        },
        'series': binned,
    }
    out_path = os.path.join(DATA_DIR, f'corridor-demand-{target_icao}.json')
    with open(out_path, 'w') as f:
        json.dump(out, f, indent=1)
    public_path = os.path.join(REPO_ROOT, 'public', f'26e-corridor-{target_icao}.json')
    with open(public_path, 'w') as f:
        json.dump(out, f)
    print(f'Wrote: {out_path}')
    print(f'Wrote: {public_path}')

    # Quick stats
    print()
    print(f'{"point":>10s}  {"flights":>7s}  {"first":>8s}  {"peak":>5s}  {"peak_at":>7s}  {"last":>8s}  {"width(m)":>9s}')
    for label in [lbl for lbl, _ in MEASUREMENT_POINTS] + ['arrival']:
        rows = binned.get(label, [])
        if not rows:
            print(f'  {label:>10s}  (empty)')
            continue
        peak = max(rows, key=lambda x: x['count'])
        first = rows[0]['bin_minute']
        last = rows[-1]['bin_minute']
        width = last - first

        def fmt(b):
            return f'{(b // 60) % 24:02d}{b % 60:02d}Z'
        print(f'  {label:>10s}  {sum(r["count"] for r in rows):>7.1f}  '
              f'{fmt(first):>8s}  {peak["count"]:>5.1f}  {fmt(peak["bin_minute"]):>7s}  '
              f'{fmt(last):>8s}  {width:>9d}')

    # ---------- SVG: stacked panels ----------
    svg_path = os.path.join(FIGURES_DIR, f'corridor-demand-{target_icao}.svg')
    write_svg(svg_path, target_icao, binned, n_full)
    print(f'Wrote: {svg_path}')


def write_svg(path, target_icao, binned, n_full):
    """Stacked panels: 4 demand curves at progressive corridor stages."""
    W, H = 880, 720
    M = {'l': 70, 'r': 30, 't': 80, 'b': 60}
    PANEL_H = (H - M['t'] - M['b'] - 30) / 4  # 4 panels with small gaps

    panel_specs = [
        ('OEP',      '#7aa8ff', 'OEP — oceanic entry (~hour 2-3 from origin)'),
        ('030W',     '#5ee0ba', '030W — mid-Atlantic (~hour 5)'),
        ('EU entry', '#f5a05b', 'EU entry — oceanic exit / European domestic (~hour 7)'),
        ('arrival',  '#c44',    f'{target_icao} arrival (~hour 8)'),
    ]

    # Find common time range across all panels
    all_bins = []
    for label, _, _ in panel_specs:
        all_bins.extend(r['bin_minute'] for r in binned.get(label, []))
    if not all_bins:
        return
    minute_min = min(all_bins)
    minute_max = max(all_bins)
    # Snap to hour boundaries
    x_min = (minute_min // 60) * 60
    x_max = ((minute_max // 60) + 1) * 60

    # Common Y max across panels (use peak across all panels, with headroom)
    y_max = 1
    for label, _, _ in panel_specs:
        rows = binned.get(label, [])
        if rows:
            p = max(r['count'] for r in rows)
            y_max = max(y_max, p)
    y_max = math.ceil(y_max * 1.15)

    parts = []
    parts.append(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}">')
    parts.append(f'<style>{CSS}</style>')
    parts.append(f'<rect class="bg" x="0" y="0" width="{W}" height="{H}"/>')
    parts.append(f'<text class="title" x="{M["l"]}" y="22">26E demand curve at four corridor stages — flights bound for {target_icao}</text>')
    parts.append(f'<text class="subtitle" x="{M["l"]}" y="40">Same {n_full} booked flights measured at progressive points along the route. The curve broadens as flights move further from origin.</text>')
    parts.append(f'<text class="subtitle" x="{M["l"]}" y="56">Peak time shifts later; peak height drops as flights spread out across the available time.</text>')

    plot_w = W - M['l'] - M['r']

    def xs(t): return M['l'] + (t - x_min) / (x_max - x_min) * plot_w

    for i, (label, color, panel_title) in enumerate(panel_specs):
        rows = binned.get(label, [])
        panel_top = M['t'] + i * (PANEL_H + 10)
        panel_bot = panel_top + PANEL_H

        def ys(v):
            return panel_top + PANEL_H - (v / y_max) * PANEL_H

        # Frame
        parts.append(f'<rect class="frame" x="{M["l"]}" y="{panel_top:.1f}" width="{plot_w}" height="{PANEL_H:.1f}"/>')

        # Panel title (top-left)
        parts.append(f'<text class="legend-text" x="{M["l"]+8}" y="{panel_top+14:.1f}" font-weight="700" fill="{color}">{panel_title}</text>')

        # Y label / scale
        parts.append(f'<text class="axis-label-y" x="{M["l"]-6}" y="{panel_top+4:.1f}" text-anchor="end">{y_max}</text>')
        parts.append(f'<text class="axis-label-y" x="{M["l"]-6}" y="{panel_bot-2:.1f}" text-anchor="end">0</text>')

        # X gridlines at integer hours; labels only on bottom panel
        for h in range((x_min // 60), (x_max // 60) + 1):
            t = h * 60
            x = xs(t)
            parts.append(f'<line class="grid" x1="{x:.1f}" y1="{panel_top:.1f}" x2="{x:.1f}" y2="{panel_bot:.1f}"/>')
            if i == 3:
                parts.append(f'<text class="axis-label-x" x="{x:.1f}" y="{panel_bot+15:.1f}" text-anchor="middle">{(h%24):02d}00Z</text>')

        # Curve
        if rows:
            pts = [(xs(r['bin_minute']), ys(r['count'])) for r in rows]
            d_attr = ' '.join(f'{x:.1f},{y:.1f}' for x, y in pts)
            parts.append(f'<polyline points="{d_attr}" fill="none" stroke="{color}" stroke-width="2.5" stroke-linejoin="round"/>')
            # Stats text on right
            peak = max(rows, key=lambda x: x['count'])
            peak_t = peak['bin_minute']
            peak_v = peak['count']
            first_t = rows[0]['bin_minute']
            last_t = rows[-1]['bin_minute']
            width_min = last_t - first_t
            stat_x = M['l'] + plot_w - 6
            parts.append(f'<text class="note" x="{stat_x}" y="{panel_top+14:.1f}" text-anchor="end">peak {peak_v:.1f} @ {(peak_t//60)%24:02d}{peak_t%60:02d}Z · curve width {width_min} min</text>')

    # Bottom axis title
    parts.append(f'<text class="axis-title" x="{M["l"]+plot_w/2:.1f}" y="{H-12}" text-anchor="middle">Time of day (UTC)</text>')

    # Footer note
    parts.append(f'<text class="note" x="{M["l"]}" y="{H-32}">Wind-error fuzziness sits ON TOP of this baseline shape evolution. The natural spread shown here would persist even with perfect winds.</text>')

    parts.append('</svg>')
    with open(path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(parts))


CSS = """
.bg { fill: #ffffff; }
.frame { fill: none; stroke: #222; stroke-width: 1; }
.grid { stroke: #d8d8d8; stroke-width: 0.5; stroke-dasharray: 3 3; }
.axis-label-x, .axis-label-y { font-family: -apple-system, system-ui, sans-serif; font-size: 11px; fill: #333; }
.axis-title { font-family: -apple-system, system-ui, sans-serif; font-size: 12px; fill: #222; font-weight: 600; }
.title { font-family: -apple-system, system-ui, sans-serif; font-size: 14px; fill: #111; font-weight: 600; }
.subtitle { font-family: -apple-system, system-ui, sans-serif; font-size: 11px; fill: #666; }
.legend-text { font-family: -apple-system, system-ui, sans-serif; font-size: 11px; fill: #222; }
.note { font-family: -apple-system, system-ui, sans-serif; font-size: 10px; fill: #666; font-style: italic; }
"""


if __name__ == '__main__':
    main()
