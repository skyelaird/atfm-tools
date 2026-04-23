"""
Geometric gap analysis of the CZQM/CZQX airspace .ese file.

For each altitude band of interest, builds the union of all sector
polygons in that band and looks for:
  1. Holes inside the union (true gaps — inside the FIR boundary but
     not inside any sector)
  2. Pair-wise overlaps between sectors in the same band
  3. Sectors whose polygon could not be closed (BORDER sectorlines
     don't chain properly)

Altitude bands of interest:
  LOW:  0-28500 ft      (approach/terminal sectors)
  HIGH: 28500-60000 ft  (enroute high sectors)
"""
import re
import json
from collections import defaultdict

try:
    from shapely.geometry import Polygon, MultiPolygon, Point, LineString
    from shapely.ops import unary_union, polygonize
    from shapely.validation import make_valid
except Exception as e:
    raise SystemExit(f'shapely required: {e}')

SRC = r'D:\GitHub\atfm-tools\data\26E\airspace.ese.local'


# ---------- Parsing ----------

COORD_RE = re.compile(r'^([NSEW])(\d+)\.(\d+)\.(\d+)\.(\d+)$')
def parse_coord(s):
    m = COORD_RE.match(s)
    if not m: return None
    hemi, d, mn, sec, frac = m.groups()
    deg = int(d) + int(mn)/60 + (int(sec) + int(frac)/1000)/3600
    return -deg if hemi in ('S', 'W') else deg


def parse_ese(path):
    sectorlines = {}   # id -> [(lat, lon), ...]
    sectors = []       # list of {name, bottom, top, owners, borders}
    cur_section = None
    cur_sl = None
    cur_sector = None
    with open(path, encoding='utf-8', errors='replace') as f:
        for ln in f:
            s = ln.strip()
            if not s or s.startswith(';'):
                continue
            m = re.match(r'^\[([A-Z ]+)\]$', s)
            if m:
                cur_section = m.group(1); continue
            if cur_section != 'AIRSPACE':
                continue
            head, _, rest = s.partition(':')
            if head == 'SECTORLINE':
                cur_sl = rest.strip()
                sectorlines.setdefault(cur_sl, [])
                cur_sector = None
            elif head == 'COORD' and cur_sl is not None:
                a, _, b = rest.partition(':')
                lat = parse_coord(a); lon = parse_coord(b)
                if lat is not None and lon is not None:
                    sectorlines[cur_sl].append((lat, lon))
            elif head == 'SECTOR':
                parts = rest.split(':')
                cur_sector = {
                    'name':    parts[0] if parts else '',
                    'bottom':  int(parts[1]) if len(parts)>1 and parts[1].isdigit() else 0,
                    'top':     int(parts[2]) if len(parts)>2 and parts[2].isdigit() else 0,
                    'owners':  [], 'borders': [],
                }
                sectors.append(cur_sector)
                cur_sl = None
            elif cur_sector is not None:
                if head == 'OWNER':
                    cur_sector['owners'] += [t for t in rest.split(':') if t]
                elif head == 'BORDER':
                    cur_sector['borders'] += [t for t in rest.split(':') if t]
    return sectorlines, sectors


# ---------- Sector polygon reconstruction ----------

DIST_EPS_DEG = 0.001   # ~60 m @ our latitudes, generous for endpoint match

def point_eq(p, q, eps=DIST_EPS_DEG):
    return abs(p[0]-q[0]) < eps and abs(p[1]-q[1]) < eps

def chain_border(border_ids, sectorlines):
    """
    Chain the given sectorline IDs into an ordered ring of (lat, lon).
    Each sectorline is a polyline; we may traverse it forward or reversed
    to keep the chain continuous. Returns (coords, warnings).
    """
    warnings = []
    segs = []
    for bid in border_ids:
        pts = sectorlines.get(bid)
        if not pts or len(pts) < 2:
            warnings.append(f'missing or empty sectorline {bid}')
            continue
        segs.append(list(pts))

    if not segs:
        return [], warnings

    out = list(segs[0])  # start with first segment as-is
    used = [False] * len(segs)
    used[0] = True

    while not all(used):
        tail = out[-1]
        best_i = -1
        best_reverse = False
        best_gap = None
        for i, seg in enumerate(segs):
            if used[i]:
                continue
            if point_eq(seg[0], tail):
                best_i = i; best_reverse = False; best_gap = 0; break
            if point_eq(seg[-1], tail):
                best_i = i; best_reverse = True; best_gap = 0; break
        if best_i < 0:
            # fall back: find nearest-endpoint segment
            min_d = None
            for i, seg in enumerate(segs):
                if used[i]: continue
                d1 = (seg[0][0]-tail[0])**2 + (seg[0][1]-tail[1])**2
                d2 = (seg[-1][0]-tail[0])**2 + (seg[-1][1]-tail[1])**2
                if d1 <= d2:
                    cand = (i, False, d1**0.5)
                else:
                    cand = (i, True, d2**0.5)
                if min_d is None or cand[2] < min_d[2]:
                    min_d = cand
            if min_d is None: break
            best_i, best_reverse, best_gap = min_d
            warnings.append(f'loose chain: jump of {best_gap:.4f}° at sectorline {[b for b,u in zip(border_ids,used) if not u][0]}')
        seg = segs[best_i]
        if best_reverse:
            seg = list(reversed(seg))
        # Skip duplicate first point
        out += seg[1:] if point_eq(seg[0], out[-1]) else seg
        used[best_i] = True

    # Ensure ring closes
    if not point_eq(out[0], out[-1]):
        gap = ((out[0][0]-out[-1][0])**2 + (out[0][1]-out[-1][1])**2) ** 0.5
        warnings.append(f'ring not closed; gap {gap:.4f}°')
        out.append(out[0])

    return out, warnings


def to_polygon(ring):
    # Shapely expects (x, y) = (lon, lat)
    if len(ring) < 4:
        return None
    poly = Polygon([(p[1], p[0]) for p in ring])
    if not poly.is_valid:
        poly = make_valid(poly)
        if poly.geom_type == 'MultiPolygon':
            # pick largest piece
            poly = max(poly.geoms, key=lambda p: p.area)
    return poly if poly.is_valid else None


# ---------- Band analysis ----------

BANDS = [
    ('LOW',   0,     28500),
    ('HIGH',  28500, 60000),
]

import os
OUT_DIR = r'D:\GitHub\atfm-tools\data\26E\ese-gaps'

def poly_to_gj_coords(poly):
    """Shapely Polygon -> GeoJSON coordinate array (lon, lat)."""
    exterior = [[pt[0], pt[1]] for pt in poly.exterior.coords]
    rings = [exterior]
    for interior in poly.interiors:
        rings.append([[pt[0], pt[1]] for pt in interior.coords])
    return rings

def feature(geom, props):
    if geom.geom_type == 'Polygon':
        return {
            'type': 'Feature',
            'geometry': {'type': 'Polygon', 'coordinates': poly_to_gj_coords(geom)},
            'properties': props,
        }
    elif geom.geom_type == 'MultiPolygon':
        return {
            'type': 'Feature',
            'geometry': {
                'type': 'MultiPolygon',
                'coordinates': [poly_to_gj_coords(p) for p in geom.geoms]
            },
            'properties': props,
        }
    return None


def main():
    sectorlines, sectors = parse_ese(SRC)
    os.makedirs(OUT_DIR, exist_ok=True)

    for band_name, bot, top in BANDS:
        print('=' * 66)
        print(f'BAND {band_name} — sectors strictly inside')
        print('=' * 66)

        polys = []
        skipped = 0
        warn_sectors = []
        for s in sectors:
            if s['bottom'] != bot or s['top'] != top:
                continue
            # Skip sectors that are labeled OCA/transition/uncontrolled
            name = s['name']
            if 'OCA' in name or 'NO-CONTROL' in name or 'TRANSITION' in name:
                continue
            # Skip BGGL (Greenland) — not ours
            if name.startswith('BGGL'):
                continue
            ring, warns = chain_border(s['borders'], sectorlines)
            if not ring:
                skipped += 1
                warn_sectors.append((name, 'no ring'))
                continue
            poly = to_polygon(ring)
            if poly is None or poly.is_empty:
                skipped += 1
                warn_sectors.append((name, 'invalid polygon'))
                continue
            polys.append((name, poly, warns))
            if warns:
                warn_sectors.append((name, '; '.join(warns)))

        print(f'  sectors in band:    {len(polys)}')
        print(f'  skipped (OCA/bad): {skipped}')
        if warn_sectors:
            print(f'  per-sector warnings: {len(warn_sectors)}')
            for n, w in warn_sectors[:8]:
                print(f'    {n}  — {w}')
        if not polys:
            continue

        # Build union + look for internal holes (= gaps)
        geoms = [p for _, p, _ in polys]
        uni = unary_union(geoms)

        sum_area = sum(g.area for g in geoms)
        uni_area = uni.area
        overlap_area = sum_area - uni_area

        print(f'  sum of sector areas (deg²):  {sum_area:.4f}')
        print(f'  union area (deg²):           {uni_area:.4f}')
        print(f'  overlap area (deg²):         {overlap_area:.4f}   '
              f'({100*overlap_area/sum_area:.2f}% of sum)')

        # Holes in union
        geom_list = list(uni.geoms) if uni.geom_type == 'MultiPolygon' else [uni]
        total_holes = 0
        hole_details = []
        for g in geom_list:
            for hole in g.interiors:
                total_holes += 1
                hole_poly = Polygon(hole)
                # Sample coords (as lat/lon) near hole centroid
                c = hole_poly.centroid
                hole_details.append({
                    'area_deg2': hole_poly.area,
                    'centroid_lat': c.y,
                    'centroid_lon': c.x,
                    'bbox_lat': (hole_poly.bounds[1], hole_poly.bounds[3]),
                    'bbox_lon': (hole_poly.bounds[0], hole_poly.bounds[2]),
                })
        print(f'  INTERNAL HOLES (true gaps): {total_holes}')
        for h in sorted(hole_details, key=lambda x: -x['area_deg2']):
            print(f'    area {h["area_deg2"]:.4f} deg² '
                  f'at {h["centroid_lat"]:.3f}N, {abs(h["centroid_lon"]):.3f}W  '
                  f'(bbox lat {h["bbox_lat"][0]:.2f}..{h["bbox_lat"][1]:.2f}, '
                  f'lon {h["bbox_lon"][0]:.2f}..{h["bbox_lon"][1]:.2f})')

        # Pairwise overlap by sector
        pairs_overlap = []
        for i in range(len(polys)):
            for j in range(i+1, len(polys)):
                a = polys[i][1]; b = polys[j][1]
                inter = a.intersection(b)
                if inter.area > 1e-6:
                    pairs_overlap.append((polys[i][0], polys[j][0], inter.area))
        pairs_overlap.sort(key=lambda x: -x[2])
        if pairs_overlap:
            print(f'  overlapping sector pairs: {len(pairs_overlap)}')
            for n1, n2, a in pairs_overlap[:10]:
                print(f'    {n1}  vs  {n2}  — {a:.4f} deg²')

        # ---- GeoJSON emission ----
        sectors_fc = {
            'type': 'FeatureCollection',
            'features': [
                feature(p, {
                    'name':   name,
                    'bottom': bot, 'top': top,
                    'band':   band_name,
                    'warnings': '; '.join(warns) if warns else '',
                })
                for (name, p, warns) in polys if p is not None
            ],
        }
        gaps_features = []
        for g in geom_list:
            for hole in g.interiors:
                hp = Polygon(hole)
                gaps_features.append({
                    'type': 'Feature',
                    'geometry': {
                        'type': 'Polygon',
                        'coordinates': [[[pt[0], pt[1]] for pt in hp.exterior.coords]],
                    },
                    'properties': {
                        'band':    band_name,
                        'area_deg2': hp.area,
                        'centroid_lat': hp.centroid.y,
                        'centroid_lon': hp.centroid.x,
                    },
                })
        gaps_fc = {'type': 'FeatureCollection', 'features': gaps_features}

        slug = band_name.lower()
        with open(os.path.join(OUT_DIR, f'sectors-{slug}.geojson'), 'w') as f:
            json.dump(sectors_fc, f, indent=1)
        with open(os.path.join(OUT_DIR, f'gaps-{slug}.geojson'), 'w') as f:
            json.dump(gaps_fc, f, indent=1)
        print(f'  wrote  sectors-{slug}.geojson  ({len(sectors_fc["features"])} features)')
        print(f'  wrote  gaps-{slug}.geojson     ({len(gaps_features)} features)')
        print()

if __name__ == '__main__':
    main()
