"""
Detailed per-aberration diagnostic for the ESE gap report. Emits:

  data/26E/ese-gaps/
    sectors-{band}.geojson       full sectors that closed properly
    gaps-{band}.geojson           holes in the union + annotation
    chain-{sector_slug}.geojson   BORDER chain of each problem sector,
                                   each sectorline a separate LineString
                                   with sequence index + endpoints, so
                                   the break is obvious on a map
    diagnosis.md                   Markdown table: gap / offending sectors
                                   / relevant sectorline IDs, so the
                                   aberration can be chased in the sector
                                   construct tool
"""
import os
import re
import json
from collections import defaultdict

from shapely.geometry import Polygon, Point
from shapely.ops import unary_union
from shapely.validation import make_valid

# Reuse the parser from the existing script
import importlib.util
_spec = importlib.util.spec_from_file_location(
    'gaps', os.path.join(os.path.dirname(__file__), '26e-ese-gaps.py'))
_m = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_m)

parse_ese = _m.parse_ese
chain_border = _m.chain_border
to_polygon = _m.to_polygon

SRC = r'D:\GitHub\atfm-tools\data\26E\airspace.ese.local'
OUT = r'D:\GitHub\atfm-tools\data\26E\ese-gaps'
BANDS = [('LOW', 0, 28500), ('HIGH', 28500, 60000)]


def coord_str(lat, lon):
    ns = 'N' if lat >= 0 else 'S'
    ew = 'W' if lon <= 0 else 'E'
    return f'{ns}{abs(lat):.4f}° {ew}{abs(lon):.4f}°'


def sector_slug(name):
    # 'CZQX·CYQX_APP·000·285' -> 'CZQX-CYQX_APP-000-285'
    return re.sub(r'[^A-Za-z0-9_]+', '-', name).strip('-')


def describe_area(lat, lon):
    """Rough natural-language locator based on lat/lon."""
    # A few landmarks to anchor descriptions for the CZQM/CZQX area.
    ANCHORS = [
        (45.88, -66.53, 'CYFC (Fredericton NB)'),
        (44.88, -63.51, 'CYHZ (Halifax NS)'),
        (47.62, -52.75, 'CYYT (St John\'s NL)'),
        (48.95, -54.57, 'CYQX (Gander NL)'),
        (46.11, -60.05, 'CYQY (Sydney NS)'),
        (48.42, -71.07, 'Lac Saint-Jean QC'),
        (52.94, -66.91, 'Labrador City'),
        (50.22, -66.25, 'Sept-Îles QC'),
        (47.49, -61.79, 'Magdalen Islands'),
        (51.40, -57.15, 'Strait of Belle Isle'),
    ]
    best = min(ANCHORS, key=lambda a: (a[0]-lat)**2 + (a[1]-lon)**2)
    dlat = lat - best[0]
    dlon = lon - best[1]
    # Rough nm distance
    dnm = ((dlat*60)**2 + (dlon*60*0.6)**2) ** 0.5
    bearing_deg = (90 - (180/3.14159) * __import__('math').atan2(dlat, dlon*0.6)) % 360
    rose = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW']
    compass = rose[int((bearing_deg + 11.25) // 22.5) % 16]
    return f'~{dnm:.0f} nm {compass} of {best[2]}'


def main():
    os.makedirs(OUT, exist_ok=True)
    sectorlines, sectors = parse_ese(SRC)

    md_lines = ['# Sector gap diagnosis — CZQQ ESE',
                '',
                f'Source: `{os.path.basename(SRC)}`',
                '',
                'Each gap below is a hole in the union of sector polygons in the ',
                'given altitude band — airspace where no sector claims coverage. ',
                'Slivers < 0.005 deg² (≈ 20×15 nm) are rounding artefacts where two ',
                'FIRs\' sectorlines don\'t share exact coordinates; values above that ',
                'are meaningful.',
                '']

    # Build all sector polygons with band info
    polys_by_band = {name: [] for name, _, _ in BANDS}
    broken_sectors = []   # (name, border_ids, warnings)
    for s in sectors:
        name = s['name']
        if any(tag in name for tag in ('OCA', 'NO-CONTROL', 'TRANSITION')):
            continue
        if name.startswith('BGGL'):
            continue
        for band_name, bot, top in BANDS:
            if s['bottom'] == bot and s['top'] == top:
                ring, warns = chain_border(s['borders'], sectorlines)
                if not ring or len(ring) < 4:
                    broken_sectors.append((name, s['borders'], warns or ['empty ring']))
                    break
                poly = to_polygon(ring)
                if poly is None or poly.is_empty or not poly.is_valid:
                    broken_sectors.append((name, s['borders'], warns or ['invalid polygon']))
                    break
                polys_by_band[band_name].append({
                    'name': name,
                    'borders': list(s['borders']),
                    'poly': poly,
                    'warnings': warns,
                })
                break

    # ---- Per-band analysis ----
    for band_name, _, _ in BANDS:
        polys = polys_by_band[band_name]
        if not polys:
            continue
        geoms = [p['poly'] for p in polys]
        uni = unary_union(geoms)
        geom_list = list(uni.geoms) if uni.geom_type == 'MultiPolygon' else [uni]

        md_lines.append(f'## Band: {band_name}')
        md_lines.append('')
        md_lines.append(f'- Sectors polygonised: {len(polys)}')
        md_lines.append(f'- Total area (deg²): {sum(g.area for g in geoms):.4f}')
        md_lines.append(f'- Union area (deg²): {uni.area:.4f}')
        md_lines.append('')

        # Build per-gap diagnosis
        rows = []
        for g in geom_list:
            for hole in g.interiors:
                hp = Polygon(hole)
                # Which sector boundaries touch this hole?
                touching = []
                hole_ring = hp.boundary
                for p in polys:
                    if p['poly'].boundary.intersects(hole_ring):
                        # Shared boundary length
                        shared = p['poly'].boundary.intersection(hole_ring).length
                        if shared > 1e-4:
                            touching.append((p['name'], shared))
                touching.sort(key=lambda x: -x[1])
                # Which sectorlines are along the shared boundary? (approximate —
                # list the sectorlines of touching sectors that lie near the hole)
                sectorlines_near = set()
                for p_name, _ in touching:
                    p_info = next((q for q in polys if q['name'] == p_name), None)
                    if not p_info: continue
                    for bid in p_info['borders']:
                        if bid not in sectorlines: continue
                        pts = sectorlines[bid]
                        # Does any coord of this sectorline fall near the hole boundary?
                        for lat, lon in pts:
                            if Point(lon, lat).distance(hole_ring) < 0.002:   # ~7 nm
                                sectorlines_near.add(bid)
                                break
                rows.append({
                    'area_deg2': hp.area,
                    'centroid_lat': hp.centroid.y,
                    'centroid_lon': hp.centroid.x,
                    'bbox': hp.bounds,
                    'touching_sectors': touching,
                    'sectorlines_near': sorted(sectorlines_near, key=lambda x: (len(x), x)),
                    'poly': hp,
                })

        rows.sort(key=lambda r: -r['area_deg2'])
        if rows:
            md_lines.append('| # | Area (deg²) | Centroid | Approx location | Neighbouring sectors | Sectorlines near boundary |')
            md_lines.append('|---|---|---|---|---|---|')
            for i, r in enumerate(rows, 1):
                cs = coord_str(r['centroid_lat'], r['centroid_lon'])
                where = describe_area(r['centroid_lat'], r['centroid_lon'])
                neigh = ', '.join(f'`{n}`' for n, _ in r['touching_sectors'][:3]) or '—'
                sls = ', '.join(r['sectorlines_near'][:8]) or '—'
                md_lines.append(f'| {i} | {r["area_deg2"]:.4f} | {cs} | {where} | {neigh} | {sls} |')
        else:
            md_lines.append('*No gaps found in this band.*')
        md_lines.append('')

        # Write GeoJSON of gap polygons with enriched properties
        gaps_fc = {
            'type': 'FeatureCollection',
            'features': [
                {
                    'type': 'Feature',
                    'geometry': {
                        'type': 'Polygon',
                        'coordinates': [[[pt[0], pt[1]] for pt in r['poly'].exterior.coords]],
                    },
                    'properties': {
                        'gap_index': i,
                        'band': band_name,
                        'area_deg2': round(r['area_deg2'], 5),
                        'centroid_lat': round(r['centroid_lat'], 4),
                        'centroid_lon': round(r['centroid_lon'], 4),
                        'approx_location': describe_area(r['centroid_lat'], r['centroid_lon']),
                        'touching_sectors': [n for n, _ in r['touching_sectors']],
                        'sectorlines_near': r['sectorlines_near'],
                    },
                }
                for i, r in enumerate(rows, 1)
            ],
        }
        with open(os.path.join(OUT, f'gaps-{band_name.lower()}.geojson'), 'w') as f:
            json.dump(gaps_fc, f, indent=1)

    # ---- Broken-sector diagnosis (chain-of-sectorlines view) ----
    md_lines.append('## Sectors with non-closable BORDER chain')
    md_lines.append('')
    if not broken_sectors:
        md_lines.append('*None.*')
    else:
        md_lines.append('Each sector below has a BORDER sequence whose sectorlines ')
        md_lines.append('don\'t chain end-to-end into a closed ring. Open the linked ')
        md_lines.append('`chain-*.geojson` in a map viewer; each sectorline renders as ')
        md_lines.append('a separate LineString labelled by its position in BORDER order ')
        md_lines.append('(e.g. `#3 of 7 · SL 27`). Look for where consecutive numbered ')
        md_lines.append('segments don\'t meet — that\'s the break to fix in the ')
        md_lines.append('sector construct tool.')
        md_lines.append('')
        md_lines.append('| Sector | BORDER (in order) | Warnings | Chain GeoJSON |')
        md_lines.append('|---|---|---|---|')
        for name, borders, warns in broken_sectors:
            slug = sector_slug(name)
            chain_path = f'chain-{slug}.geojson'
            md_lines.append(f'| `{name}` | {":".join(borders)} | {"; ".join(warns[:2]) or "—"} | `{chain_path}` |')

            # Emit diagnostic GeoJSON for this sector's BORDER chain
            features = []
            for idx, bid in enumerate(borders):
                pts = sectorlines.get(bid, [])
                if not pts:
                    continue
                # LineString of the whole sectorline
                features.append({
                    'type': 'Feature',
                    'geometry': {
                        'type': 'LineString',
                        'coordinates': [[lon, lat] for lat, lon in pts],
                    },
                    'properties': {
                        'order': idx + 1,
                        'of': len(borders),
                        'sectorline_id': bid,
                        'label': f'#{idx+1} of {len(borders)} · SL {bid}',
                        'n_points': len(pts),
                    },
                })
                # Endpoint markers
                if pts:
                    features.append({
                        'type': 'Feature',
                        'geometry': {'type': 'Point', 'coordinates': [pts[0][1], pts[0][0]]},
                        'properties': {
                            'role': 'start', 'sectorline_id': bid, 'order': idx + 1,
                            'label': f'start SL {bid}',
                        },
                    })
                    features.append({
                        'type': 'Feature',
                        'geometry': {'type': 'Point', 'coordinates': [pts[-1][1], pts[-1][0]]},
                        'properties': {
                            'role': 'end', 'sectorline_id': bid, 'order': idx + 1,
                            'label': f'end SL {bid}',
                        },
                    })

            fc = {'type': 'FeatureCollection', 'features': features}
            with open(os.path.join(OUT, chain_path), 'w') as f:
                json.dump(fc, f, indent=1)

    # ---- Good-sector GeoJSON (all bands, with labels) ----
    for band_name in [n for n, _, _ in BANDS]:
        fc = {
            'type': 'FeatureCollection',
            'features': [
                {
                    'type': 'Feature',
                    'geometry': {
                        'type': 'Polygon',
                        'coordinates': [[[pt[0], pt[1]] for pt in p['poly'].exterior.coords]],
                    },
                    'properties': {
                        'name': p['name'],
                        'band': band_name,
                        'borders': ':'.join(p['borders']),
                        'warnings': '; '.join(p['warnings']) if p['warnings'] else '',
                    },
                }
                for p in polys_by_band[band_name]
            ],
        }
        with open(os.path.join(OUT, f'sectors-{band_name.lower()}.geojson'), 'w') as f:
            json.dump(fc, f, indent=1)

    with open(os.path.join(OUT, 'diagnosis.md'), 'w', encoding='utf-8') as f:
        f.write('\n'.join(md_lines))

    print(f'Wrote diagnosis.md + GeoJSON files to  {OUT}')
    print()
    print('\n'.join(md_lines))


if __name__ == '__main__':
    main()
