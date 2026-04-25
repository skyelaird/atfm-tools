#!/usr/bin/env python
"""
26E Live Sector Demand — live VATSIM snapshot + 90-min forecast for
the CZQM/CZQX Topsky sectors. An ATFM demand overview: what's flying
right now + what's projected to flow into the airspace over the next
90 min based on currently-airborne traffic and their filed routes.

Companion to bin/26e-sector-load.py:
  - 26e-sector-load.py simulates BOOKED CTP traffic on event day
  - 26e-live-sectors.py snapshots ACTUAL current traffic + projects forward

Binning:
  5-min bins (matches the CTP prediction view so overlays line up).
  Cron cadence is 15 min — doesn't mean empty intervening bins; each
  run writes one live snapshot bin at the current 5-min-aligned time,
  plus a full forecast curve out +90 min in 5-min steps.

Output:
  data/26E/live-load.json — rolling 12h history of observed bins
                            + forecast_load / forecast_pair_load for
                            the next 18 5-min bins.
"""

import json
import math
import os
import re
import sys
from collections import defaultdict
from datetime import datetime, timezone, timedelta
from urllib.request import Request, urlopen
from urllib.error import URLError

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_DIR = os.path.join(REPO_ROOT, 'data')
E26_DIR = os.path.join(DATA_DIR, '26E')
SECTORS_PATH = os.path.join(E26_DIR, 'sectors.json')
WAYPOINTS_PATH = os.path.join(DATA_DIR, 'waypoints.json')
OUT_PATH = os.path.join(E26_DIR, 'live-load.json')
VATSIM_URL = 'https://data.vatsim.net/v3/vatsim-data.json'

BIN_MIN = 5
ROLLING_WINDOW_HOURS = 12
FORECAST_HORIZON_MIN = 90
FORECAST_STEP_SEC = 30      # along-route position sample rate
DEFAULT_CRUISE_KT = 460     # when we can't read GS/TAS from feed

# CZQM + CZQX Topsky sectors are high-level controls — the floor is
# FL290 (29 000 ft). Pilots below that are in approach/TMA airspace
# (different controllers) and must not be counted. Polygons in
# sectors.json are 2D only, so we enforce the floor here.
SECTOR_FLOOR_FT = 29000

# Loose bbox around CZQM + CZQX. Also defines which inbound pilots are
# close enough that their 90-min projection could conceivably touch the
# polygons — so extend it wider than the sectors.
NEAR_LAT_MIN, NEAR_LAT_MAX = 30.0, 72.0
NEAR_LON_MIN, NEAR_LON_MAX = -100.0, -10.0

# Pair map must match bin/26e-sector-load.py so chart overlays align.
PAIR_MAP = {
    'QM1+QX1': ['QM1', 'QX1'],
    'QM2+QX2': ['QM2', 'QX2'],
    'QM3+QX3': ['QM3', 'QX3'],
    'QM4+QX4': ['QM4', 'QX4'],
    'QX6+QX7': ['QX6', 'QX7'],
}


# ------------------------------------------------------------------
#  Geometry
# ------------------------------------------------------------------

R_EARTH_NM = 3440.065

def _rad(d): return d * math.pi / 180.0
def _deg(r): return r * 180.0 / math.pi

def gc_distance_nm(a, b):
    lat1, lon1 = _rad(a[0]), _rad(a[1])
    lat2, lon2 = _rad(b[0]), _rad(b[1])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    h = math.sin(dlat/2)**2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon/2)**2
    return 2 * R_EARTH_NM * math.asin(math.sqrt(h))

def gc_interp(a, b, frac):
    lat1, lon1 = _rad(a[0]), _rad(a[1])
    lat2, lon2 = _rad(b[0]), _rad(b[1])
    d = gc_distance_nm(a, b) / R_EARTH_NM
    if d < 1e-9:
        return a
    A = math.sin((1 - frac) * d) / math.sin(d)
    B = math.sin(frac * d) / math.sin(d)
    x = A * math.cos(lat1) * math.cos(lon1) + B * math.cos(lat2) * math.cos(lon2)
    y = A * math.cos(lat1) * math.sin(lon1) + B * math.cos(lat2) * math.sin(lon2)
    z = A * math.sin(lat1) + B * math.sin(lat2)
    return (_deg(math.atan2(z, math.sqrt(x*x + y*y))),
            _deg(math.atan2(y, x)))

def bearing_deg(a, b):
    lat1r = _rad(a[0]); lat2r = _rad(b[0])
    dlon = _rad(b[1] - a[1])
    x = math.sin(dlon) * math.cos(lat2r)
    y = math.cos(lat1r) * math.sin(lat2r) - math.sin(lat1r) * math.cos(lat2r) * math.cos(dlon)
    return (_deg(math.atan2(x, y)) + 360) % 360

def point_in_poly(lat, lon, poly):
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
#  Route parsing (same logic as 26e-sector-load.py, trimmed)
# ------------------------------------------------------------------

COORD_A = re.compile(r'^(\d{1,2})N(\d{2,3})W$')
COORD_B = re.compile(r'^(\d{2})(\d{2,3})N$')
AIRWAY = re.compile(r'^[A-Z]{1,3}\d+$')
PROC = re.compile(r'^[A-Z]+\d[A-Z]?$')

def parse_coord_waypoint(tok):
    m = COORD_A.match(tok)
    if m: return float(m.group(1)), -float(m.group(2))
    m = COORD_B.match(tok)
    if m: return float(m.group(1)), -float(m.group(2))
    return None

def is_airway_or_procedure(tok):
    if tok == 'DCT': return True
    if re.match(r'^[A-Z]\d+$', tok): return True
    if AIRWAY.match(tok) and len(tok) >= 3: return True
    if PROC.match(tok) and any(c.isdigit() for c in tok): return True
    return False

def parse_route_coords(route_str, waypoints_db):
    """Resolve route tokens -> list of (lat, lon). Best effort; skip unknowns."""
    if not route_str:
        return []
    out = []
    last = None
    for tok in route_str.split():
        c = parse_coord_waypoint(tok)
        if c is not None:
            out.append(c); last = c; continue
        if is_airway_or_procedure(tok):
            continue
        if tok in waypoints_db:
            c = tuple(waypoints_db[tok])
            # reject >800nm jump (navdata collisions across continents)
            if last is not None and gc_distance_nm(last, c) > 800:
                continue
            out.append(c); last = c
    return out


# ------------------------------------------------------------------
#  Forward projection from current position
# ------------------------------------------------------------------

def remaining_route_ahead(cur_pos, cur_hdg, route_coords):
    """
    Drop waypoints behind the current position. A waypoint is "behind"
    if the bearing from cur_pos to it differs from cur_hdg by >100°.
    If the filter leaves us with nothing, return empty and the caller
    will extrapolate along current heading.
    """
    ahead = []
    for wp in route_coords:
        brg = bearing_deg(cur_pos, wp)
        delta = abs((brg - cur_hdg + 540) % 360 - 180)
        if delta <= 100:
            ahead.append(wp)
    return ahead

def trace_forward(cur_pos, cur_hdg, gs_kt, route_ahead, horizon_sec, step_sec):
    """
    Yield (t_sec, lat, lon) at step_sec intervals for horizon_sec total
    seconds, starting from cur_pos. Follows route_ahead if provided;
    otherwise extrapolates along cur_hdg (great-circle "current rhumb").
    """
    yield (0, cur_pos[0], cur_pos[1])
    if not route_ahead:
        # Straight-line extrapolation along current heading.
        # Advance by gs_kt * dt / R_EARTH to get delta-pos.
        total_nm = gs_kt * horizon_sec / 3600.0
        # Build a single synthetic waypoint at the end of that leg
        end = _waypoint_at_bearing(cur_pos, cur_hdg, total_nm)
        route_ahead = [end]

    t = 0
    at = cur_pos
    for wp in route_ahead:
        d_nm = gc_distance_nm(at, wp)
        if d_nm < 0.5:
            continue
        leg_sec = (d_nm / max(gs_kt, 100)) * 3600.0
        # Sample along the leg
        s = step_sec
        while s < leg_sec and t + s < horizon_sec:
            frac = s / leg_sec
            p = gc_interp(at, wp, frac)
            yield (int(t + s), p[0], p[1])
            s += step_sec
        t += leg_sec
        if t >= horizon_sec:
            return
        yield (int(t), wp[0], wp[1])
        at = wp

def _waypoint_at_bearing(origin, bearing_deg_, dist_nm):
    """Point at dist_nm along initial bearing from origin (spherical)."""
    br = _rad(bearing_deg_)
    lat1, lon1 = _rad(origin[0]), _rad(origin[1])
    ang = dist_nm / R_EARTH_NM
    lat2 = math.asin(math.sin(lat1) * math.cos(ang) +
                     math.cos(lat1) * math.sin(ang) * math.cos(br))
    lon2 = lon1 + math.atan2(math.sin(br) * math.sin(ang) * math.cos(lat1),
                             math.cos(ang) - math.sin(lat1) * math.sin(lat2))
    return (_deg(lat2), _deg(lon2))


def bin_of_utc(dt, bin_min=BIN_MIN):
    m = dt.hour * 60 + dt.minute
    return (m // bin_min) * bin_min


def fetch_vatsim():
    req = Request(VATSIM_URL, headers={'User-Agent': 'atfm-tools/26e-live-sectors'})
    with urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode('utf-8'))


# ------------------------------------------------------------------
#  Main
# ------------------------------------------------------------------

def main():
    with open(SECTORS_PATH) as f:
        sectors = json.load(f)
    sector_ids = [s['id'] for s in sectors]

    print('Loading waypoints...', flush=True)
    with open(WAYPOINTS_PATH) as f:
        waypoints = json.load(f)
    print(f'  {len(waypoints):,} fixes', flush=True)

    try:
        feed = fetch_vatsim()
    except (URLError, TimeoutError, OSError) as e:
        print(f'VATSIM feed fetch failed: {e}', file=sys.stderr)
        sys.exit(1)

    pilots = feed.get('pilots', [])
    feed_updated = feed.get('general', {}).get('update_timestamp', '')

    # --- Current-bin observed occupancy ---
    occ_by_sector = defaultdict(int)
    occ_by_pair = defaultdict(int)
    below_floor = 0
    for p in pilots:
        lat, lon = p.get('latitude'), p.get('longitude')
        if lat is None or lon is None: continue
        if not (NEAR_LAT_MIN <= lat <= NEAR_LAT_MAX and NEAR_LON_MIN <= lon <= NEAR_LON_MAX):
            continue
        alt = p.get('altitude', 0) or 0
        if alt < SECTOR_FLOOR_FT:
            # In-polygon but below the FL290 floor — approach/TMA, not ours.
            # (Still useful to know about, for a "building behind us" view,
            # but don't charge it to the high-level sector occupancy.)
            if find_sector(lat, lon, sectors):
                below_floor += 1
            continue
        sid = find_sector(lat, lon, sectors)
        if not sid: continue
        occ_by_sector[sid] += 1
        for pid, members in PAIR_MAP.items():
            if sid in members:
                occ_by_pair[pid] += 1

    # --- Forward forecast ---
    now = datetime.now(timezone.utc)
    bin_minute = bin_of_utc(now)
    forecast_bins = [bin_minute + i * BIN_MIN
                     for i in range(1, FORECAST_HORIZON_MIN // BIN_MIN + 1)]

    # For each pilot: set of (bin_minute, sector_id) pairs they occupy
    forecast_sector = {b: defaultdict(set) for b in forecast_bins}  # bin -> {sid: {cid, ...}}
    forecast_pair = {b: defaultdict(set) for b in forecast_bins}    # bin -> {pid: {cid, ...}}

    airborne = 0
    forecast_candidates = 0
    forecast_with_route = 0
    forecast_skipped_low = 0

    for p in pilots:
        lat, lon = p.get('latitude'), p.get('longitude')
        if lat is None or lon is None: continue
        gs = p.get('groundspeed', 0) or 0
        if gs < 100:
            continue  # on ground or slow — not a forecast candidate
        airborne += 1
        if not (NEAR_LAT_MIN <= lat <= NEAR_LAT_MAX and NEAR_LON_MIN <= lon <= NEAR_LON_MAX):
            continue
        forecast_candidates += 1

        hdg = p.get('heading', 0) or 0
        cid = p.get('cid') or p.get('callsign') or id(p)
        cur_alt = p.get('altitude', 0) or 0
        fp = p.get('flight_plan') or {}
        route_str = fp.get('route', '') or ''
        filed_alt_raw = str(fp.get('altitude', '') or '')
        # Parse filed altitude: "FL370", "F370", "37000", "350"
        filed_alt_ft = None
        if filed_alt_raw:
            s = filed_alt_raw.upper().lstrip('F').lstrip('L').strip()
            try:
                v = int(s)
                filed_alt_ft = v * 100 if v < 1000 else v
            except ValueError:
                pass

        # Altitude at forecast time: assume cruise-to-cruise. If currently
        # below filed cruise, assume linear climb at 1500 fpm (typical
        # late-climb/cruise-climb rate) until filed cruise is reached.
        # If no filed alt, use current alt.
        cruise_ft = filed_alt_ft if filed_alt_ft and filed_alt_ft > cur_alt else cur_alt
        climb_remaining_ft = max(0, cruise_ft - cur_alt)
        climb_min_to_cruise = climb_remaining_ft / 1500.0  # 1500 fpm avg

        coords = parse_route_coords(route_str, waypoints)
        ahead = remaining_route_ahead((lat, lon), hdg, coords)
        if ahead:
            forecast_with_route += 1

        # Sample forward. For each sample, bucket into the 5-min bin and
        # record this pilot in whatever sector they're in — BUT only if
        # their projected altitude at that time is ≥ FL290.
        for t_sec, plat, plon in trace_forward(
            (lat, lon), hdg, max(gs, DEFAULT_CRUISE_KT), ahead,
            FORECAST_HORIZON_MIN * 60, FORECAST_STEP_SEC
        ):
            minute_from_now = t_sec / 60.0
            # Projected altitude at this sample time
            if minute_from_now >= climb_min_to_cruise:
                proj_alt = cruise_ft
            else:
                frac = minute_from_now / max(climb_min_to_cruise, 0.001)
                proj_alt = cur_alt + frac * climb_remaining_ft
            if proj_alt < SECTOR_FLOOR_FT:
                forecast_skipped_low += 1
                continue
            sample_bin = ((bin_minute + int(minute_from_now)) // BIN_MIN) * BIN_MIN
            if sample_bin not in forecast_sector:
                continue
            sid = find_sector(plat, plon, sectors)
            if not sid: continue
            forecast_sector[sample_bin][sid].add(cid)
            for pid, members in PAIR_MAP.items():
                if sid in members:
                    forecast_pair[sample_bin][pid].add(cid)

    # --- Build / update output doc ---
    if os.path.exists(OUT_PATH):
        with open(OUT_PATH) as f:
            doc = json.load(f)
    else:
        doc = {
            'sectors': [
                {'id': s['id'], 'label': s['label'],
                 'freq': s.get('freq', ''), 'polygon': s['polygon']}
                for s in sectors
            ],
            'pairs': [
                {'id': pid, 'members': m, 'label': f'{m[0]}+{m[1]}'}
                for pid, m in PAIR_MAP.items()
            ],
            'load': {sid: [] for sid in sector_ids},
            'pair_load': {pid: [] for pid in PAIR_MAP},
            'forecast_load': {sid: [] for sid in sector_ids},
            'forecast_pair_load': {pid: [] for pid in PAIR_MAP},
            'events': {sid: [] for sid in sector_ids},
            'pair_events': {pid: [] for pid in PAIR_MAP},
            'flights': [],
        }

    # Ensure keys exist (forward-compat if doc is from an older run)
    doc.setdefault('load', {})
    doc.setdefault('pair_load', {})
    doc.setdefault('forecast_load', {})
    doc.setdefault('forecast_pair_load', {})

    # Real-time UTC stamp for this snapshot. Stored on each bin so the
    # rolling-window filter works against actual elapsed time, not the
    # ambiguous minute-of-day. Without this, yesterday's 22:00 bin and
    # today's 22:00 bin look identical and either persist or silently
    # overwrite each other depending on order — not what we want.
    ts_iso = now.isoformat().replace('+00:00', 'Z')
    cutoff_iso = (now - timedelta(hours=ROLLING_WINDOW_HOURS)).isoformat().replace('+00:00', 'Z')
    now_iso = ts_iso

    # Upsert current-bin observed counts at the current bin_minute, and
    # tag with this snapshot's real timestamp.
    def upsert(series, minute, count):
        # Drop any prior entry that conflicts: same bin_minute (we're
        # replacing it with fresh data) OR a stale ts (older than 12h
        # real time) OR a future-dated ts (carried over from a prior
        # day's run before this fix).
        new_series = []
        for x in series:
            xts = x.get('ts')
            if x['bin_minute'] == minute:
                continue                  # being replaced
            if xts is None:
                continue                  # old-format bin, can't trust — drop
            if xts < cutoff_iso or xts > now_iso:
                continue                  # outside rolling window
            new_series.append(x)
        new_series.append({'bin_minute': minute, 'count': count, 'ts': ts_iso})
        new_series.sort(key=lambda x: x['ts'])
        series[:] = new_series

    for sid in sector_ids:
        series = doc['load'].setdefault(sid, [])
        upsert(series, bin_minute, occ_by_sector.get(sid, 0))
    for pid in PAIR_MAP:
        series = doc['pair_load'].setdefault(pid, [])
        upsert(series, bin_minute, occ_by_pair.get(pid, 0))

    # Replace forecast wholesale — it's a fresh projection each run.
    # Forecast bins are tagged with the snapshot ts so consumers can
    # know how stale the projection is.
    for sid in sector_ids:
        doc['forecast_load'][sid] = sorted(
            [{'bin_minute': b, 'count': len(forecast_sector[b][sid]), 'ts': ts_iso}
             for b in forecast_bins if len(forecast_sector[b][sid]) > 0],
            key=lambda x: x['bin_minute'])
    for pid in PAIR_MAP:
        doc['forecast_pair_load'][pid] = sorted(
            [{'bin_minute': b, 'count': len(forecast_pair[b][pid]), 'ts': ts_iso}
             for b in forecast_bins if len(forecast_pair[b][pid]) > 0],
            key=lambda x: x['bin_minute'])

    total_in_sectors = sum(occ_by_sector.values())
    doc['meta'] = {
        'generated_utc': now.isoformat().replace('+00:00', 'Z'),
        'vatsim_feed_utc': feed_updated,
        'bin_minutes': BIN_MIN,
        'metric': 'live_snapshot_instantaneous_occupancy',
        'sector_floor_ft': SECTOR_FLOOR_FT,
        'rolling_window_hours': ROLLING_WINDOW_HOURS,
        'forecast_horizon_minutes': FORECAST_HORIZON_MIN,
        'pilots_in_feed': len(pilots),
        'airborne_pilots': airborne,
        'forecast_candidates': forecast_candidates,
        'forecast_candidates_with_route': forecast_with_route,
        'flights_in_sectors_now': total_in_sectors,
        'pilots_below_floor_in_polygon': below_floor,
        'bin_latest': bin_minute,
    }

    with open(OUT_PATH, 'w') as f:
        json.dump(doc, f, indent=1)

    # Console summary
    hh, mm = divmod(bin_minute, 60)
    print(f'Feed     : {feed_updated}', flush=True)
    print(f'Bin      : {hh:02d}{mm:02d}Z', flush=True)
    print(f'Pilots   : {len(pilots)} feed / {airborne} airborne / {forecast_candidates} near / {forecast_with_route} routed', flush=True)
    print(f'Now in   : {total_in_sectors} at/above FL290 · {below_floor} below floor inside polygon (approach/TMA)', flush=True)
    if occ_by_sector:
        for sid in sector_ids:
            c = occ_by_sector.get(sid, 0)
            if c: print(f'  now  {sid:5s} {c}', flush=True)
    # Forecast peak at +30 min
    fc_bin = bin_minute + 30
    if fc_bin in forecast_sector:
        peak = sorted(forecast_sector[fc_bin].items(), key=lambda x: -len(x[1]))[:5]
        if peak:
            print(f'Forecast +30m peak sectors:', flush=True)
            for sid, cids in peak:
                print(f'  +30  {sid:5s} {len(cids)}', flush=True)


if __name__ == '__main__':
    main()
