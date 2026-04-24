"""
Emit a GeoJSON FeatureCollection of CZQM + CZQX sectors suitable for
3D rendering (Kepler / deck.gl / Mapbox GL fill-extrusion / Cesium).

Each sector is a 2D Polygon with altitude bounds carried in properties
— this is the convention every modern 3D GIS viewer expects. The
viewer extrudes the polygon between `floor_m` and `ceiling_m` (or
`floor_ft` / `ceiling_ft` if it reads feet).

GeoJSON doesn't have a native volume primitive; a Polygon with 3D
vertices describes a slanted 2D surface, not a prism. The widely-used
ergonomic is:

  - 2D coordinates (lon, lat)
  - `height` / `base` properties in meters (for deck.gl / Mapbox)
  - `floor_ft` / `ceiling_ft` for aviation-native tools

Output is also usable as-is by 2D viewers (geojson.io, QGIS) — the
altitude properties simply go unused.
"""

import json
import os
import re
import sys
from collections import defaultdict

from shapely.geometry import Polygon
from shapely.validation import make_valid

# Reuse the ESE parser from the gap-diagnosis script
import importlib.util
_spec = importlib.util.spec_from_file_location(
    'gaps', os.path.join(os.path.dirname(__file__), '26e-ese-gaps.py'))
_m = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_m)

parse_ese = _m.parse_ese
chain_border = _m.chain_border
to_polygon = _m.to_polygon

SRC = r'D:\GitHub\atfm-tools\data\26E\airspace.ese.local'
OUT = r'D:\GitHub\atfm-tools\data\26E\airspace-3d.geojson'

# Export CZQM + CZQX + CZQXO (Gander Oceanic — hosts the CTP-A..G event
# sectors at FL295-600 which are operationally core during 26E). Skip
# neighbours (CZUL, ZBW, ZWY, BGGL, BIRD) — they'd just be visual noise
# in Joel's 3D view.
MY_FIRS = {'CZQM', 'CZQX', 'CZQXO'}

# Feet-to-meters for the extrusion hints the 3D viewers read
FT_TO_M = 0.3048

# Sector classification band — derived from floor, for the `band` property
# so viewers can color by altitude quickly.
def band_of(floor_ft, ceiling_ft):
    if floor_ft < 28500 and ceiling_ft <= 28500:
        return 'LOW'       # surface to FL285
    if floor_ft >= 28500:
        return 'HIGH'      # FL285+ enroute
    return 'SPLIT'         # straddles the FL285 boundary


def extract_fir_and_name(sector_full):
    """
    Sector name in ESE is `FIR·POSITION·FLOOR·CEILING`, e.g.
    `CZQM·MONCTON-HI1·285·600`. Split into FIR + position name.
    """
    parts = sector_full.split('\u00b7')
    if len(parts) >= 2:
        return parts[0], parts[1]
    return '', sector_full


def main():
    sectorlines, sectors = parse_ese(SRC)

    features = []
    skipped = []

    for s in sectors:
        name = s['name']
        fir, pos_name = extract_fir_and_name(name)
        if fir not in MY_FIRS:
            continue
        # Skip NO-CONTROL + TRANSITION procedural layers (not controllable).
        # Keep CTP-* event sectors and GANDER OCA FIR — both are valid
        # 3D volumes Joel wants to see. The plain 'OCA' catchall is the
        # full-FIR shell which redundantly overlaps the CTP-A..G splits;
        # include it anyway since it's a real airspace definition.
        if any(tag in name for tag in ('NO-CONTROL', 'TRANSITION')):
            continue

        ring, warns = chain_border(s['borders'], sectorlines)
        if not ring or len(ring) < 4:
            skipped.append((name, 'could not close BORDER ring'))
            continue
        poly = to_polygon(ring)
        if poly is None or poly.is_empty or not poly.is_valid:
            # Try to recover via make_valid; often fixes self-touching rings
            if poly is not None:
                poly = make_valid(poly)
            if poly is None or poly.is_empty:
                skipped.append((name, 'invalid polygon'))
                continue

        floor_ft = s['bottom'] * 100 if s['bottom'] < 1000 else s['bottom']
        ceiling_ft = s['top'] * 100 if s['top'] < 1000 else s['top']
        # Normalise a couple of common ESE conventions: bottom/top stored
        # as flight-level hundreds in the raw file, some entries already
        # in ft. Heuristic: if <= 1000, treat as FL × 100; else as ft.
        if s['bottom'] == 0:
            floor_ft = 0
        if s['bottom'] <= 1000:
            floor_ft = s['bottom'] * 100
        else:
            floor_ft = s['bottom']
        if s['top'] <= 1000:
            ceiling_ft = s['top'] * 100
        else:
            ceiling_ft = s['top']

        # Extract exterior ring coords (lon, lat order for GeoJSON)
        geom = poly
        if geom.geom_type == 'MultiPolygon':
            # MakeValid on a self-touching ring can produce multi; export
            # the largest component only — the smaller pieces are slivers.
            biggest = max(geom.geoms, key=lambda p: p.area)
            geom = biggest

        coords = [[pt[0], pt[1]] for pt in geom.exterior.coords]
        holes = [[[pt[0], pt[1]] for pt in r.coords] for r in geom.interiors]

        feature = {
            'type': 'Feature',
            'geometry': {
                'type': 'Polygon',
                'coordinates': [coords] + holes,
            },
            'properties': {
                # Identity
                'name': name,
                'fir': fir,
                'position': pos_name,
                # Altitude bounds — feet (native) + meters (for 3D viewers)
                'floor_ft': floor_ft,
                'ceiling_ft': ceiling_ft,
                'floor_m': round(floor_ft * FT_TO_M, 1),
                'ceiling_m': round(ceiling_ft * FT_TO_M, 1),
                'thickness_ft': ceiling_ft - floor_ft,
                # deck.gl / Mapbox GL fill-extrusion alias: `base` = bottom,
                # `height` = top. Both in meters.
                'base': round(floor_ft * FT_TO_M, 1),
                'height': round(ceiling_ft * FT_TO_M, 1),
                # Classification
                'band': band_of(floor_ft, ceiling_ft),
                # Debug / traceability
                'borders': ':'.join(s['borders']),
                'source': 'CZQQ-CZQM-CZQX ESE',
            },
        }
        features.append(feature)

    fc = {
        'type': 'FeatureCollection',
        'properties': {
            'name': '26E CZQM + CZQX sectors (3D)',
            'firs': sorted(MY_FIRS),
            'altitude_units': {'floor_ft': 'ft', 'ceiling_ft': 'ft',
                               'floor_m': 'm',  'ceiling_m': 'm',
                               'base': 'm', 'height': 'm'},
            'coordinate_order': '[lon, lat]',
            'note': 'Polygon is 2D; extrude base→height or floor_m→ceiling_m for 3D volume rendering',
            'source_file': os.path.basename(SRC),
            'skipped_sectors': [{'name': n, 'reason': r} for n, r in skipped],
        },
        'features': features,
    }

    with open(OUT, 'w', encoding='utf-8') as f:
        json.dump(fc, f, indent=1, ensure_ascii=False)

    # Summary
    print(f'Wrote {len(features)} sector features to {OUT}')
    by_fir_band = defaultdict(lambda: defaultdict(int))
    for ft in features:
        p = ft['properties']
        by_fir_band[p['fir']][p['band']] += 1
    for fir in sorted(by_fir_band):
        counts = ', '.join(f'{b}={by_fir_band[fir][b]}' for b in sorted(by_fir_band[fir]))
        print(f'  {fir}: {counts}')
    if skipped:
        print(f'Skipped {len(skipped)} (broken BORDER chain or invalid polygon):')
        for n, r in skipped:
            print(f'  - {n} ({r})')
    else:
        print('No sectors skipped.')


if __name__ == '__main__':
    # Windows console might not be UTF-8; the file write is always UTF-8
    try:
        sys.stdout.reconfigure(encoding='utf-8')
    except Exception:
        pass
    main()
