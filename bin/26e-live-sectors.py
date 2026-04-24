#!/usr/bin/env python
"""
26E Live Sector Load — snapshots the current VATSIM feed into the
CZQM/CZQX Topsky sector polygons and appends a 15-min bin to a rolling
history file.

Companion to bin/26e-sector-load.py (which simulates booked traffic from
filed routes). This version just counts who is *actually* inside each
sector right now — no routing, no wind, just point-in-polygon against
the live position feed.

Intended to be cron'd every 15 minutes during the event window via
.github/workflows/26e-live.yml. Output is committed back and consumed
by public/26e-live.html.

Output:
  data/26E/live-load.json — schema matches data/26E/sector-load.json so
                            the viewer can reuse rendering. Rolling
                            ROLLING_WINDOW_HOURS of bins.
"""

import json
import os
import sys
from collections import defaultdict
from datetime import datetime, timezone
from urllib.request import Request, urlopen
from urllib.error import URLError

REPO_DATA = os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'data'
)
DATA_DIR = os.path.join(REPO_DATA, '26E')
SECTORS_PATH = os.path.join(DATA_DIR, 'sectors.json')
OUT_PATH = os.path.join(DATA_DIR, 'live-load.json')
VATSIM_URL = 'https://data.vatsim.net/v3/vatsim-data.json'

BIN_MIN = 15
ROLLING_WINDOW_HOURS = 12   # keep last 12h of bins; event is ~8h wide

# Loose bbox around CZQM + CZQX airspace. Point-in-polygon does the
# precise filtering; this is just a cheap pre-filter so we don't run
# 11 ray-casts against every pilot in the world.
LAT_MIN, LAT_MAX = 38.0, 68.0
LON_MIN, LON_MAX = -72.0, -35.0

# Pair map must match bin/26e-sector-load.py for the viewer to line
# predicted vs actual side-by-side.
PAIR_MAP = {
    'QM1+QX1': ['QM1', 'QX1'],
    'QM2+QX2': ['QM2', 'QX2'],
    'QM3+QX3': ['QM3', 'QX3'],
    'QM4+QX4': ['QM4', 'QX4'],
    'QX6+QX7': ['QX6', 'QX7'],
}


# ------------------------------------------------------------------
#  Point-in-polygon (ray-cast) — identical to 26e-sector-load.py
# ------------------------------------------------------------------

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


def bin_of_utc(dt):
    """UTC dt -> minute-of-day floored to the 15-min boundary."""
    m = dt.hour * 60 + dt.minute
    return (m // BIN_MIN) * BIN_MIN


def fetch_vatsim():
    req = Request(VATSIM_URL, headers={
        'User-Agent': 'atfm-tools/26e-live-sectors'
    })
    with urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode('utf-8'))


def main():
    with open(SECTORS_PATH) as f:
        sectors = json.load(f)
    sector_ids = [s['id'] for s in sectors]

    try:
        feed = fetch_vatsim()
    except (URLError, TimeoutError, OSError) as e:
        print(f'VATSIM feed fetch failed: {e}', file=sys.stderr)
        sys.exit(1)

    pilots = feed.get('pilots', [])
    feed_updated = feed.get('general', {}).get('update_timestamp', '')

    occ_by_sector = defaultdict(int)
    occ_by_pair = defaultdict(int)
    sample_pilots = defaultdict(list)   # for tooltip/debug; capped

    for p in pilots:
        lat = p.get('latitude')
        lon = p.get('longitude')
        if lat is None or lon is None:
            continue
        if not (LAT_MIN <= lat <= LAT_MAX and LON_MIN <= lon <= LON_MAX):
            continue
        sid = find_sector(lat, lon, sectors)
        if not sid:
            continue
        occ_by_sector[sid] += 1
        # Each pilot counts once per pair (union, not sum).
        for pid, members in PAIR_MAP.items():
            if sid in members:
                occ_by_pair[pid] += 1
        # Keep a handful of callsigns per sector for spot-check — not
        # surfaced in the UI (Joel asked us not to dupe EuroScope) but
        # useful if someone opens the JSON to debug.
        if len(sample_pilots[sid]) < 10:
            sample_pilots[sid].append({
                'callsign': p.get('callsign'),
                'alt': p.get('altitude'),
                'gs': p.get('groundspeed'),
            })

    now = datetime.now(timezone.utc)
    bin_minute = bin_of_utc(now)

    # Load existing history (or bootstrap a fresh shell)
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
            # events/flights kept empty — live mode doesn't derive
            # transitions or per-flight paths.
            'events': {sid: [] for sid in sector_ids},
            'pair_events': {pid: [] for pid in PAIR_MAP},
            'flights': [],
        }

    # Replace-or-append at the current bin. A second run inside the same
    # 15-min window just overwrites rather than duplicating.
    def upsert(series, minute, count):
        series[:] = [x for x in series if x['bin_minute'] != minute]
        series.append({'bin_minute': minute, 'count': count})
        series.sort(key=lambda x: x['bin_minute'])

    for sid in sector_ids:
        doc['load'].setdefault(sid, [])
        upsert(doc['load'][sid], bin_minute, occ_by_sector.get(sid, 0))
    for pid in PAIR_MAP:
        doc['pair_load'].setdefault(pid, [])
        upsert(doc['pair_load'][pid], bin_minute, occ_by_pair.get(pid, 0))

    # Trim rolling window. The main event day will only ever produce ~32
    # bins (8h * 4/h), but if the workflow is run across multiple days
    # this keeps the file compact.
    cutoff = bin_minute - ROLLING_WINDOW_HOURS * 60
    for sid in sector_ids:
        doc['load'][sid] = [x for x in doc['load'][sid] if x['bin_minute'] >= cutoff]
    for pid in PAIR_MAP:
        doc['pair_load'][pid] = [x for x in doc['pair_load'][pid] if x['bin_minute'] >= cutoff]

    total_in_sectors = sum(occ_by_sector.values())
    doc['meta'] = {
        'generated_utc': now.isoformat().replace('+00:00', 'Z'),
        'vatsim_feed_utc': feed_updated,
        'bin_minutes': BIN_MIN,
        'metric': 'live_snapshot_instantaneous_occupancy',
        'rolling_window_hours': ROLLING_WINDOW_HOURS,
        'pilots_in_feed': len(pilots),
        'flights_in_sectors_now': total_in_sectors,
        'bin_latest': bin_minute,
        'samples': {sid: sample_pilots[sid] for sid in sample_pilots},
    }

    with open(OUT_PATH, 'w') as f:
        json.dump(doc, f, indent=1)

    # Console summary
    hh, mm = divmod(bin_minute, 60)
    print(f'Feed     : {feed_updated}', flush=True)
    print(f'Bin      : {hh:02d}{mm:02d}Z', flush=True)
    print(f'Pilots   : {len(pilots)} in feed, {total_in_sectors} inside CZQM+CZQX polygons', flush=True)
    if total_in_sectors:
        print('Per sector:', flush=True)
        for sid in sector_ids:
            c = occ_by_sector.get(sid, 0)
            if c:
                print(f'  {sid:5s} {c}', flush=True)


if __name__ == '__main__':
    main()
