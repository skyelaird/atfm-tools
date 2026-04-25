#!/usr/bin/env python
"""
Wind-snapshotter for demand-curve uncertainty modelling.

Goal: NOT high-precision wind forecasting.  We just need to know the
envelope around a sector-demand curve that wind-forecast uncertainty
introduces, so the planner can read "predicted ±N flights" instead of
"predicted N flights" at a given lead time.

Approach: daily cron, capture GFS forecast at the four lead times that
matter for ATFM planning:

  T+0    = analysis at fetch hour (becomes the verifier for older snaps)
  T+24   = D-1, what we'll have day-before-event when issuing CTOTs
  T+72   = D-3, typical CTP-planning lead time (matches what 26E used)
  T+168  = D-7, booking-window-opening lead time

Verification is post-hoc: forecast at T+N from cycle X is paired to
forecast at T+0 from cycle X+N (the verifying analysis at that hour).
A separate verifier (TBD) does the pairing once enough data has
accumulated.  The eventual output is a re-runnable CTP sim with the
older forecast → demand-curve delta vs the actual-wind sim → confidence
band per lead time.

Once-daily cron is plenty for that purpose; sub-daily resolution
(every-6h) would buy precision we don't need.

Output:
  data/wind-archive/snapshots/{fetch_iso}.json — one file per day
"""

import json
import math
import os
import sys
import time
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from urllib.error import URLError

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ARCHIVE_DIR = os.path.join(REPO_ROOT, 'data', 'wind-archive', 'snapshots')

# Same grid as the CTP sim — covers NAT + Canadian airspace + EU coast
LAT_MIN, LAT_MAX, LAT_STEP = 25, 70, 5
LON_MIN, LON_MAX, LON_STEP = -100, 20, 5

# Pressure levels.  Open-Meteo serves 200 / 250 / 300 hPa via wind_speed_*
# and wind_direction_* fields.  Cruise altitudes:
#   200 hPa ≈ FL385
#   250 hPa ≈ FL340
#   300 hPa ≈ FL300
LEVELS = (200, 250, 300)

# Lead times we sample per cycle (hours from fetch time).
# Chosen for ATFM-planning relevance, not full skill-curve resolution.
#   T+0    = analysis at fetch hour — verifier for older snapshots
#   T+24   = D-1 (day-before, when issuing CTOTs)
#   T+72   = D-3 (typical event-planning lead, what 26E used)
#   T+168  = D-7 (booking-window-opening lead)
LEAD_HOURS = (0, 24, 72, 168)

# Open-Meteo GFS goes to 384h; 8 days covers T+168 with margin.
FORECAST_DAYS = 8
CHUNK = 10  # Open-Meteo accepts up to ~10 points per multi-point call


def fetch_grid_batch(batch, fetch_dt):
    """Fetch hourly wind for one batch of (lat, lon) points."""
    lat_s = ','.join(str(p[0]) for p in batch)
    lon_s = ','.join(str(p[1]) for p in batch)
    vars_ = []
    for lvl in LEVELS:
        vars_.append(f'wind_speed_{lvl}hPa')
        vars_.append(f'wind_direction_{lvl}hPa')
    params = {
        'latitude': lat_s,
        'longitude': lon_s,
        'hourly': ','.join(vars_),
        'wind_speed_unit': 'kn',
        'forecast_days': FORECAST_DAYS,
    }
    url = 'https://api.open-meteo.com/v1/gfs?' + urllib.parse.urlencode(params)
    with urllib.request.urlopen(url, timeout=60) as r:
        data = json.loads(r.read().decode())
    if not isinstance(data, list):
        data = [data]
    return data


def to_uv(speed_kt, dir_deg):
    """Open-Meteo returns dir-from convention.  Convert to u (east), v (north)."""
    if speed_kt is None or dir_deg is None:
        return None, None
    dr = math.radians(dir_deg)
    u = -speed_kt * math.sin(dr)
    v = -speed_kt * math.cos(dr)
    return round(u, 2), round(v, 2)


def main():
    os.makedirs(ARCHIVE_DIR, exist_ok=True)

    # Fetch time floored to the most recent GFS cycle (00/06/12/18Z).
    # GFS publishes about 4-5h after the cycle, so by the time we run
    # at +5 of cycle (e.g. cron at 05/11/17/23Z) the data is ready.
    now = datetime.now(timezone.utc)
    cycle_hour = (now.hour // 6) * 6
    fetch_dt = now.replace(hour=cycle_hour, minute=0, second=0, microsecond=0)
    fetch_iso = fetch_dt.isoformat().replace('+00:00', 'Z')

    target_isos = {}
    for lh in LEAD_HOURS:
        target_isos[lh] = (fetch_dt + timedelta(hours=lh)).strftime('%Y-%m-%dT%H:00')

    print(f'Fetch cycle: {fetch_iso}', flush=True)
    print(f'Lead times:  {", ".join(str(h) + "h" for h in LEAD_HOURS)}', flush=True)
    print(f'Grid:        lat {LAT_MIN}..{LAT_MAX} step {LAT_STEP}, '
          f'lon {LON_MIN}..{LON_MAX} step {LON_STEP}', flush=True)

    lats = list(range(LAT_MIN, LAT_MAX + 1, LAT_STEP))
    lons = list(range(LON_MIN, LON_MAX + 1, LON_STEP))
    points = [(la, lo) for la in lats for lo in lons]

    # leads -> { 'lvl_str' -> { 'lat_lon_str' -> [u, v] } }
    snapshot = {lh: {str(lvl): {} for lvl in LEVELS} for lh in LEAD_HOURS}

    fetched = 0
    for i in range(0, len(points), CHUNK):
        batch = points[i:i + CHUNK]
        try:
            data = fetch_grid_batch(batch, fetch_dt)
        except (URLError, TimeoutError, OSError) as e:
            print(f'  batch {i}: ERROR {e}', file=sys.stderr)
            continue
        for pt_data, (la, lo) in zip(data, batch):
            h = pt_data.get('hourly', {})
            times = h.get('time', [])
            t_index = {t: idx for idx, t in enumerate(times)}
            for lh in LEAD_HOURS:
                target = target_isos[lh]
                idx = t_index.get(target)
                if idx is None:
                    continue
                for lvl in LEVELS:
                    sk = f'wind_speed_{lvl}hPa'
                    dk = f'wind_direction_{lvl}hPa'
                    if sk not in h or idx >= len(h[sk]):
                        continue
                    spd = h[sk][idx]
                    dr = h[dk][idx]
                    u, v = to_uv(spd, dr)
                    if u is None:
                        continue
                    snapshot[lh][str(lvl)][f'{la}_{lo}'] = [u, v]
        fetched += len(batch)
        if (i // CHUNK) % 10 == 9:
            print(f'  fetched {fetched} / {len(points)} points', flush=True)
        # Be polite — Open-Meteo's free tier is generous but not infinite
        time.sleep(0.1)

    out = {
        'fetch_iso': fetch_iso,
        'lat_range': [LAT_MIN, LAT_MAX, LAT_STEP],
        'lon_range': [LON_MIN, LON_MAX, LON_STEP],
        'levels_mb': list(LEVELS),
        'lead_hours': list(LEAD_HOURS),
        'targets': target_isos,
        'forecast_days': FORECAST_DAYS,
        'source': 'Open-Meteo GFS (api.open-meteo.com/v1/gfs)',
        'leads': {
            str(lh): {
                'target_iso': target_isos[lh] + 'Z',
                'levels': snapshot[lh],
            }
            for lh in LEAD_HOURS
        },
    }

    # File name = fetch cycle in compact form
    file_stamp = fetch_dt.strftime('%Y%m%dT%H')
    out_path = os.path.join(ARCHIVE_DIR, f'{file_stamp}.json')
    with open(out_path, 'w') as f:
        json.dump(out, f, separators=(',', ':'))
    size_kb = os.path.getsize(out_path) / 1024.0
    print(f'\nWrote: {out_path}  ({size_kb:.1f} KB)', flush=True)
    # Quick sanity: how many grid points populated per lead/level
    print('Coverage:')
    for lh in LEAD_HOURS:
        per_lvl = ' '.join(
            f'{lvl}={len(snapshot[lh][str(lvl)])}' for lvl in LEVELS
        )
        print(f'  T+{lh:>3}h ({target_isos[lh]}): {per_lvl}')


if __name__ == '__main__':
    main()
