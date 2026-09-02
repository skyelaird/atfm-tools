"""Quick T-N skill check against the wind-archive corpus.

SUPERSEDED by bin/wind-skill-analysis.py — kept for reference only.
This spot-checker has a units bug: snapshot grid values are ALREADY
[u, v] components (kt), but the loop below re-applies a
speed/direction->u,v conversion via vec(), so its RMSE figures are
garbage. Use wind-skill-analysis.py for any real number.

For each pair of snapshots where a forecast and a verifying analysis
exist (same target time), compute vector wind RMSE per pressure level.
Aggregates across all matched pairs to give a current best estimate of
T-24 / T-72 / T-168 GFS skill at our grid resolution.

Run before the May 26 dedicated analysis agent fires — useful for
spot-checking corpus evolution as snapshots accumulate.
"""
import json
import math
import os
import sys
from collections import defaultdict
from datetime import datetime, timezone

sys.stdout.reconfigure(encoding='utf-8')

ARCHIVE = r'D:\GitHub\atfm-tools\data\wind-archive\snapshots'

# Index snapshots by their target time at lead=0 (analysis ≡ truth proxy)
# and by (fetch, lead) for forecasts.
truths = {}      # target_iso -> snapshot file
forecasts = []   # list of (target_iso, lead_h, snap_file)

for fn in sorted(os.listdir(ARCHIVE)):
    if not fn.endswith('.json'):
        continue
    path = os.path.join(ARCHIVE, fn)
    with open(path) as f:
        d = json.load(f)
    for lead_str, payload in d.get('leads', {}).items():
        lead_h = int(lead_str)
        target_iso = payload['target_iso']
        if lead_h == 0:
            truths[target_iso] = (path, payload)
        else:
            forecasts.append((target_iso, lead_h, path, payload))

print(f'Snapshots: {len(os.listdir(ARCHIVE))}')
print(f'Truth (lead=0) targets indexed: {len(truths)}')
print(f'Forecast (lead>0) entries: {len(forecasts)}')
print()

LEVELS = ['200', '250', '300']

def vec(speed_kt, dir_deg_from):
    """Direction-from convention: u = -spd*sin(dir), v = -spd*cos(dir)."""
    r = math.radians(dir_deg_from)
    return -speed_kt * math.sin(r), -speed_kt * math.cos(r)

# Bucket squared errors per lead, per level
sq = defaultdict(lambda: defaultdict(list))   # sq[lead][level] -> list of squared vector errors
n_pairs = defaultdict(int)

for target_iso, lead_h, fc_path, fc_payload in forecasts:
    if target_iso not in truths:
        continue
    truth_path, truth_payload = truths[target_iso]
    n_pairs[lead_h] += 1
    for lvl in LEVELS:
        fc_grid = fc_payload['levels'].get(lvl, {})
        tr_grid = truth_payload['levels'].get(lvl, {})
        if not fc_grid or not tr_grid:
            continue
        for k in fc_grid:
            if k not in tr_grid:
                continue
            spd_f, dir_f = fc_grid[k]
            spd_t, dir_t = tr_grid[k]
            uf, vf = vec(spd_f, dir_f)
            ut, vt = vec(spd_t, dir_t)
            sq[lead_h][lvl].append((uf - ut) ** 2 + (vf - vt) ** 2)

# Report
print(f'{"Lead":>5s}  {"Level":>5s}  {"pairs":>6s}  {"points":>8s}  {"RMSE_kt":>8s}  {"~mins/4h":>9s}')
for lead_h in sorted(sq.keys()):
    for lvl in LEVELS:
        errs = sq[lead_h][lvl]
        if not errs:
            continue
        rmse = math.sqrt(sum(errs) / len(errs))
        # ETA shift over 4h flight at 480 kt: rmse_kt × 4 / 480 × 60 min
        mins_per_4h = rmse * 4 / 480 * 60
        print(f'  T+{lead_h:>3d}  {lvl:>5s}  {n_pairs[lead_h]:>6d}  {len(errs):>8d}  '
              f'{rmse:>8.2f}  {mins_per_4h:>9.2f}')

print()
print('Note: "truth" is the GFS analysis at lead=0 from the snapshot taken AT')
print('the target time. RMSE is vector (u,v) wind error, not speed-only.')
