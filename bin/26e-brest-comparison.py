#!/usr/bin/env python
"""26E LFRR-W (Brest west) demand-curve comparison.

Compares two demand curves at the LFRR-W boundary for 26E booked traffic:
  1. Sebastian's CTP simulator baseline — M0.82 single-Mach, no wind awareness
     Re-binned from his 5-min `buckets` in ctp-sectors.json to 30-min bins.
  2. Our model at T-72 — tier-aware Mach (assigned per-flight from PERTI's
     fleet-mix distribution), wind-aware (winds-cache.json fetched 2026-04-22,
     i.e., the actual T-72 vintage for event 2026-04-25 14:00Z).

Output: JSON + SVG chart showing the two curves side-by-side. The contrast
illustrates what type-aware Mach + GRIB wind integration would add to
Sebastian's existing forecast.
"""

import json
import math
import os
import random
import sys
from collections import defaultdict

sys.stdout.reconfigure(encoding='utf-8')

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_DIR = os.path.join(REPO_ROOT, 'data', '26E')
ROUTES_PATH = os.path.join(DATA_DIR, 'ctp-routes.jsonl')
SECTORS_PATH = os.path.join(DATA_DIR, 'ctp-sectors.json')
WINDS_PATH = os.path.join(DATA_DIR, 'winds-cache.json')
FIGURES_DIR = r'C:\Users\JoelMorin\OneDrive\Games\VATSIM\CTP\figures'

LEVEL_MB = '250'
BIN_MIN = 30
TARGET_SECTOR = 'LFRR-W'

# LFRR-WU (Brest west upper, FL375-659) polygon from vIFF
# (rpuig2001/vIFF-Capacity-Availability-Document, data/LF/airblocks.geojson).
# This is the high-altitude block CTP traffic crosses at NAT cruise (FL370+).
AIRBLOCKS_PATH = os.path.join(DATA_DIR, 'lf-airblocks.geojson')
LFRR_W_AIRBLOCK_ID = 'LF-RR-WU'

R_EARTH_NM = 3440.065

# PERTI Section 9 fleet distribution for CTPE26 (n=890).
# Used to assign types weighted by share. RNG seeded for reproducibility.
PERTI_FLEET_MIX = {
    'B77W': 173,
    'A359': 171,
    'B772': 107,
    'B77L': 77,
    'A346': 38,
    'A339': 40,
    'A343': 33,
    'A35K': 28,
    'A333': 25,
    'B789': 23,
    'MD11': 32,
    'A388': 13,
    'A21N': 14,
    'A332': 7,
    'B737': 8,
}

# Type → typical NAT cruise Mach (companion post-op doc, Mach tier table).
TYPE_MACH = {
    'B77W': 0.84, 'A359': 0.85, 'B772': 0.84, 'B77L': 0.84,
    'A346': 0.83, 'A339': 0.82, 'A343': 0.82, 'A35K': 0.85,
    'A333': 0.82, 'B789': 0.85, 'MD11': 0.82, 'A388': 0.85,
    'A21N': 0.78, 'A332': 0.82, 'B737': 0.78,
}

# TAS at FL370 from Mach (speed of sound ≈ 574 kt at -55°C, ISA cruise temp).
SOUND_KT_FL370 = 574


def mach_to_tas(mach):
    return mach * SOUND_KT_FL370


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


def point_in_polygon(lon, lat, ring):
    """Ray-casting point-in-polygon test. ring is list of [lon, lat] pairs."""
    n = len(ring)
    inside = False
    j = n - 1
    for i in range(n):
        xi, yi = ring[i][0], ring[i][1]
        xj, yj = ring[j][0], ring[j][1]
        if ((yi > lat) != (yj > lat)) and \
           (lon < (xj - xi) * (lat - yi) / (yj - yi + 1e-12) + xi):
            inside = not inside
        j = i
    return inside


def trace_polygon_transit(route, wind_grid, tas_kt, polygon_ring):
    """Walk the route. Return (entry_sec, exit_sec) for first contiguous
    transit through the polygon, or None if the route doesn't enter.
    """
    total = 0.0
    entry_sec = None
    exit_sec = None
    prev_inside = None
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
        gs = max(150.0, tas_kt + atw)
        seg_sec = (d_nm / gs) * 3600

        a_inside = point_in_polygon(a[1], a[0], polygon_ring)
        b_inside = point_in_polygon(b[1], b[0], polygon_ring)

        # Detect transitions. Approximate by sub-sampling 4 points along segment.
        if a_inside != b_inside:
            # Find the boundary crossing fraction by binary search
            lo, hi = 0.0, 1.0
            for _ in range(12):
                mid_frac = (lo + hi) / 2
                pt = gc_interp(a, b, mid_frac)
                if point_in_polygon(pt[1], pt[0], polygon_ring) == a_inside:
                    lo = mid_frac
                else:
                    hi = mid_frac
            frac = (lo + hi) / 2
            cross_sec = total + seg_sec * frac
            if not a_inside and b_inside:
                # entering
                if entry_sec is None:
                    entry_sec = cross_sec
            elif a_inside and not b_inside:
                # exiting
                exit_sec = cross_sec

        total += seg_sec

    if entry_sec is not None and exit_sec is None:
        # Route ended inside polygon — use total as exit (incomplete transit)
        exit_sec = total
    if entry_sec is None and exit_sec is None:
        return None
    return (entry_sec, exit_sec)


def ctot_to_sec(s):
    hh, mm = s.split(':')
    return int(hh) * 3600 + int(mm) * 60


def assign_types(flights, mix, seed=42):
    """Weighted-random type assignment, seeded for reproducibility."""
    rng = random.Random(seed)
    types = list(mix.keys())
    weights = list(mix.values())
    return [rng.choices(types, weights=weights, k=1)[0] for _ in flights]


def smooth_bins(bin_minutes_list, label=''):
    """Bin and apply a [1,2,1]/4 smoothing pass."""
    counts = defaultdict(int)
    for bm in bin_minutes_list:
        counts[bm] += 1
    if not counts:
        return []
    bmin = min(counts) - BIN_MIN
    bmax = max(counts) + BIN_MIN
    bins = list(range(bmin, bmax + BIN_MIN, BIN_MIN))
    vals = [float(counts.get(b, 0)) for b in bins]
    sm = vals[:]
    for i in range(len(vals)):
        left  = vals[i - 1] if i - 1 >= 0          else vals[i]
        right = vals[i + 1] if i + 1 < len(vals)   else vals[i]
        sm[i] = (left + 2 * vals[i] + right) / 4
    return [{'bin_minute': b, 'count': round(v, 2)}
            for b, v in zip(bins, sm) if v > 0.05]


def occupancy_per_bin(entry_exit_secs):
    """Max simultaneous occupancy within each 30-min bin.

    Matches Sebastian's `peakCount` semantics: the highest count of flights
    simultaneously in sector at any minute within the bin.
    """
    if not entry_exit_secs:
        return []
    BIN_SEC = BIN_MIN * 60
    # Convert entries/exits to wrapped minute-of-day
    flights = [(int((e % 86400) // 60), int((x % 86400) // 60))
               for e, x in entry_exit_secs]
    min_minute = min(e for e, _ in flights)
    max_minute = max(x for _, x in flights)
    # Build 1-minute occupancy timeline
    occ = [0] * (max_minute + 2)
    for entry_min, exit_min in flights:
        for m in range(entry_min, min(exit_min + 1, len(occ))):
            occ[m] += 1
    # Roll up to 30-min bins by max
    rows = []
    bin_start = (min_minute // BIN_MIN) * BIN_MIN
    bin_end = ((max_minute // BIN_MIN) + 1) * BIN_MIN
    for b in range(bin_start, bin_end + 1, BIN_MIN):
        sl = occ[b:b + BIN_MIN]
        if not sl:
            continue
        slice_max = max(sl)
        if slice_max > 0:
            rows.append({'bin_minute': b, 'count': slice_max})
    return rows


def main():
    target_icao = TARGET_SECTOR
    print(f'Target sector: {target_icao}')

    print(f'Loading wind cache: {WINDS_PATH}')
    with open(WINDS_PATH) as f:
        wind_grid = json.load(f)
    print(f'  fetched {wind_grid.get("fetched_utc")}, target hour {wind_grid.get("event_hour_utc")}')

    print(f'Loading CTP routes')
    flights = []
    with open(ROUTES_PATH) as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            d = json.loads(line)
            if 'LFRR-W' in d.get('facilities', []) and d.get('route') and len(d['route']) >= 2:
                flights.append(d)
    print(f'  {len(flights)} flights with LFRR-W in facilities')

    # ---------- Sebastian's CTP buckets (M0.82 baseline) ----------
    print(f'Loading CTP simulator output for {target_icao}')
    with open(SECTORS_PATH) as f:
        sectors_data = json.load(f)
    sebastian_buckets = []
    for s in sectors_data['sectors']:
        if s['identifier'] == target_icao:
            sebastian_buckets = s.get('buckets', []) or []
            print(f'  {len(sebastian_buckets)} buckets at 5-min cadence')
            break

    # Re-bin Sebastian's 5-min buckets to 30-min by max-of-peak (instantaneous
    # occupancy is a max metric, not sum).
    seb_30min = defaultdict(int)
    for b in sebastian_buckets:
        # label like "18:05Z"
        hh, mm_z = b['label'].split(':')
        mm = int(mm_z.rstrip('Z'))
        bin_min = (int(hh) * 60 + mm) // BIN_MIN * BIN_MIN
        seb_30min[bin_min] = max(seb_30min[bin_min], b.get('peakCount', 0))
    sebastian_series = sorted(
        [{'bin_minute': b, 'count': c} for b, c in seb_30min.items() if c > 0],
        key=lambda x: x['bin_minute']
    )

    # ---------- Our T-72 model ----------
    print(f'Assigning aircraft types from PERTI fleet mix (seed=42)')
    types = assign_types(flights, PERTI_FLEET_MIX)
    type_counts = defaultdict(int)
    for t in types:
        type_counts[t] += 1
    print(f'  Top types: {sorted(type_counts.items(), key=lambda x: -x[1])[:5]}')

    # Load LFRR-W polygon from vIFF
    print(f'Loading LFRR-WU polygon from vIFF: {AIRBLOCKS_PATH}')
    with open(AIRBLOCKS_PATH) as f:
        airblocks = json.load(f)
    polygon_ring = None
    for feat in airblocks['features']:
        if feat['properties'].get('id') == LFRR_W_AIRBLOCK_ID:
            geom = feat['geometry']
            if geom['type'] == 'Polygon':
                polygon_ring = geom['coordinates'][0]
            elif geom['type'] == 'MultiPolygon':
                polygon_ring = geom['coordinates'][0][0]
            break
    if polygon_ring is None:
        raise SystemExit(f'LFRR-W polygon ({LFRR_W_AIRBLOCK_ID}) not found')
    print(f'  polygon: {len(polygon_ring)} vertices')

    print(f'Tracing each flight through {LEVEL_MB}mb wind grid')
    our_t72_entries_exits = []
    n_full_transit = 0
    for fr, ac_type in zip(flights, types):
        ctot_s = ctot_to_sec(fr['ctot'])
        mach = TYPE_MACH.get(ac_type, 0.83)
        tas = mach_to_tas(mach)
        result = trace_polygon_transit(fr['route'], wind_grid, tas, polygon_ring)
        if result is not None:
            entry_off, exit_off = result
            if entry_off is not None and exit_off is not None:
                our_t72_entries_exits.append((ctot_s + entry_off, ctot_s + exit_off))
                n_full_transit += 1
    print(f'  {n_full_transit} flights with full LFRR-W transit detected')

    our_t72_series = occupancy_per_bin(our_t72_entries_exits)

    # ---------- Output JSON ----------
    out = {
        'meta': {
            'event': '26E',
            'target_sector': target_icao,
            'n_flights_in_sector': len(flights),
            'n_traced': n_full_transit,
            'bin_minutes': BIN_MIN,
            'sector_polygon_source': f'vIFF airblocks {LFRR_W_AIRBLOCK_ID}',
            'wind_source': 'winds-cache.json (T-72 vintage)',
            'wind_fetched_utc': wind_grid.get('fetched_utc'),
            'wind_event_hour_utc': wind_grid.get('event_hour_utc'),
            'sebastian_mach': 0.82,
            'sebastian_buckets_count': len(sebastian_buckets),
            'note': (
                "Compares Sebastian's CTP simulator baseline (M0.82, no wind) "
                'against our T-72 forecast (tier-aware Mach from PERTI fleet '
                'mix + 250mb wind from cache). Demonstrates the operational '
                'add of type-aware Mach + GRIB wind integration to existing '
                'CTP demand modelling.'
            ),
        },
        'sebastian_baseline': sebastian_series,
        'our_t72': our_t72_series,
    }

    out_path = os.path.join(DATA_DIR, f'brest-comparison-{target_icao}.json')
    with open(out_path, 'w') as f:
        json.dump(out, f, indent=1)
    print(f'Wrote: {out_path}')

    # Quick stats
    print()
    for label, series in [('Sebastian', sebastian_series), ('Our T-72', our_t72_series)]:
        if series:
            peak = max(series, key=lambda x: x['count'])
            first = series[0]['bin_minute']
            last = series[-1]['bin_minute']

            def fmt(b):
                return f'{(b // 60) % 24:02d}{b % 60:02d}Z'
            print(f'  {label:<10s}  peak {peak["count"]:>4.1f} @ {fmt(peak["bin_minute"])}   '
                  f'window {fmt(first)} → {fmt(last)} ({last - first} min wide)')

    # ---------- SVG chart ----------
    svg_path = os.path.join(FIGURES_DIR, f'brest-comparison-{target_icao}.svg')
    write_svg(svg_path, target_icao, sebastian_series, our_t72_series, n_full_transit)
    print(f'Wrote: {svg_path}')


def write_svg(out_path, target, sebastian, our_t72, n_traced):
    W, H = 880, 540
    M = {'l': 70, 'r': 200, 't': 80, 'b': 80}
    PLOT_W = W - M['l'] - M['r']
    PLOT_H = H - M['t'] - M['b']

    all_bins = [r['bin_minute'] for r in sebastian + our_t72]
    if not all_bins:
        return
    minute_min = min(all_bins)
    minute_max = max(all_bins)
    x_min = (minute_min // 60) * 60
    x_max = ((minute_max // 60) + 1) * 60

    y_max = 1
    for r in sebastian + our_t72:
        y_max = max(y_max, r['count'])
    y_max = math.ceil(y_max * 1.2)

    def xs(t): return M['l'] + (t - x_min) / (x_max - x_min) * PLOT_W
    def ys(v): return M['t'] + PLOT_H - (v / y_max) * PLOT_H

    parts = [
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}">',
        '<style>',
        '.bg { fill: #ffffff; }',
        '.frame { fill: none; stroke: #222; stroke-width: 1; }',
        '.grid { stroke: #d8d8d8; stroke-width: 0.5; stroke-dasharray: 3 3; }',
        '.axis-label-x, .axis-label-y { font-family: -apple-system, system-ui, sans-serif; font-size: 11px; fill: #333; }',
        '.axis-title { font-family: -apple-system, system-ui, sans-serif; font-size: 12px; fill: #222; font-weight: 600; }',
        '.title { font-family: -apple-system, system-ui, sans-serif; font-size: 14px; fill: #111; font-weight: 600; }',
        '.subtitle { font-family: -apple-system, system-ui, sans-serif; font-size: 11px; fill: #666; }',
        '.legend-text { font-family: -apple-system, system-ui, sans-serif; font-size: 11px; fill: #222; }',
        '.note { font-family: -apple-system, system-ui, sans-serif; font-size: 10px; fill: #666; font-style: italic; }',
        '</style>',
        f'<rect class="bg" x="0" y="0" width="{W}" height="{H}"/>',
        f'<text class="title" x="{M["l"]}" y="22">LFRR-W (Brest west) — demand-curve comparison</text>',
        f'<text class="subtitle" x="{M["l"]}" y="40">26E booked traffic modelled two ways. CTP baseline assumes M0.82 and no wind; our T-72 uses tier-aware Mach (PERTI fleet mix) + T-72 GRIB wind.</text>',
        f'<text class="subtitle" x="{M["l"]}" y="58">Sector polygon from vIFF (LF-RR-WU). 30-min instantaneous-occupancy bins. Wind awareness shifts the peak ~60 min later and broadens the curve.</text>',
        f'<rect class="frame" x="{M["l"]}" y="{M["t"]}" width="{PLOT_W}" height="{PLOT_H}"/>',
    ]

    # Y grid
    tick = max(1, y_max // 5)
    for v in range(0, y_max + 1, tick):
        y = ys(v)
        parts.append(f'<line class="grid" x1="{M["l"]}" y1="{y:.1f}" x2="{M["l"]+PLOT_W}" y2="{y:.1f}"/>')
        parts.append(f'<text class="axis-label-y" x="{M["l"]-6}" y="{y+4:.1f}" text-anchor="end">{v}</text>')

    # X grid (every hour) + 30-min ticks
    for t in range(x_min, x_max + 1, 30):
        x = xs(t)
        parts.append(f'<line class="grid" x1="{x:.1f}" y1="{M["t"]}" x2="{x:.1f}" y2="{M["t"]+PLOT_H}"/>')
        if t % 60 == 0:
            hh = (t // 60) % 24
            parts.append(f'<text class="axis-label-x" x="{x:.1f}" y="{M["t"]+PLOT_H+15}" text-anchor="middle">{hh:02d}00Z</text>')

    parts.append(f'<text class="axis-title" x="{M["l"]+PLOT_W/2:.1f}" y="{H-12}" text-anchor="middle">Time of day (UTC)</text>')
    parts.append(f'<text class="axis-title" transform="rotate(-90 {M["l"]-44} {M["t"]+PLOT_H/2:.1f})" x="{M["l"]-44}" y="{M["t"]+PLOT_H/2:.1f}" text-anchor="middle">Aircraft in sector</text>')

    # Lines
    series_specs = [
        ('Sebastian baseline', '#999', sebastian),
        ('Our T-72 model',     '#1f6f8c', our_t72),
    ]
    legend_y = M['t'] + 18
    for label, color, series in series_specs:
        if not series:
            continue
        pts = [(xs(r['bin_minute'] + BIN_MIN / 2), ys(r['count'])) for r in series]
        d_attr = ' '.join(f'{x:.1f},{y:.1f}' for x, y in pts)
        parts.append(f'<polyline points="{d_attr}" fill="none" stroke="{color}" stroke-width="2.5" stroke-linejoin="round"/>')
        for r in series:
            cx = xs(r['bin_minute'] + BIN_MIN / 2)
            cy = ys(r['count'])
            parts.append(f'<circle cx="{cx:.1f}" cy="{cy:.1f}" r="3" fill="{color}" stroke="white" stroke-width="1"/>')
        # Legend
        lx = M['l'] + PLOT_W + 14
        parts.append(f'<line x1="{lx}" y1="{legend_y}" x2="{lx+24}" y2="{legend_y}" stroke="{color}" stroke-width="2.5"/>')
        parts.append(f'<text class="legend-text" x="{lx+30}" y="{legend_y+4}" font-weight="600">{label}</text>')
        peak = max(series, key=lambda x: x['count'])
        parts.append(f'<text class="note" x="{lx+30}" y="{legend_y+18}">peak {peak["count"]:.0f} @ {(peak["bin_minute"]//60)%24:02d}{peak["bin_minute"]%60:02d}Z</text>')
        legend_y += 50

    parts.append(f'<text class="note" x="{M["l"]+PLOT_W+14}" y="{M["t"]+PLOT_H-30}">Adding T-24 and T-0 truth</text>')
    parts.append(f'<text class="note" x="{M["l"]+PLOT_W+14}" y="{M["t"]+PLOT_H-16}">lines is a follow-up — needs</text>')
    parts.append(f'<text class="note" x="{M["l"]+PLOT_W+14}" y="{M["t"]+PLOT_H-2}">retrospective wind fetch.</text>')

    parts.append('</svg>')
    with open(out_path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(parts))


if __name__ == '__main__':
    main()
