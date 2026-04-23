"""
ESE airspace file analyzer.

Parses the Gander/Moncton airspace.ese (EuroScope sector file) and
reports the composition: POSITIONS, SECTORs, SECTORLINEs and the
BORDER chains linking them. Identifies gaps:
  - sectors whose BORDER references a SECTORLINE that doesn't exist
  - SECTORLINEs defined but not referenced by any sector
  - sectors missing owner, altitude band, or frequency
  - positions not mapped to any sector
  - duplicate SECTORLINE IDs
"""
import re
import os
from collections import Counter, defaultdict

SRC = r'D:\GitHub\atfm-tools\data\26E\airspace.ese.local'


def parse_file(path):
    with open(path, encoding='utf-8', errors='replace') as f:
        lines = [l.rstrip() for l in f]

    current_section = None
    section = {
        'POSITIONS':      [],   # list of dicts {callsign, freq, ident, ...}
        'SIDSSTARS':      [],
        'AIRSPACE':       [],   # raw lines
        'FREETEXT':       [],
    }

    # Parse AIRSPACE items that are structured: SECTORLINE, DISPLAY, SECTOR, BORDER, COORD, OWNER, ALTOWNER, DEPAPT, ARRAPT, ACTIVE
    sectorlines = {}        # id -> list of (lat, lon)
    sectors = []            # list of dicts
    current_sectorline_id = None
    current_sector = None

    for i, ln in enumerate(lines):
        stripped = ln.strip()
        if not stripped or stripped.startswith(';'):
            continue

        # Section header
        m = re.match(r'^\[([A-Z ]+)\]\s*$', stripped)
        if m:
            current_section = m.group(1)
            continue

        if current_section == 'POSITIONS':
            # callsign:name:freq:identifier:middle1:middle2:prefix:suffix:squawk-start:squawk-end:vis-centres
            parts = stripped.split(':')
            if len(parts) >= 4:
                section['POSITIONS'].append({
                    'callsign':     parts[0],
                    'name':         parts[1] if len(parts) > 1 else '',
                    'freq':         parts[2] if len(parts) > 2 else '',
                    'ident':        parts[3] if len(parts) > 3 else '',
                    'prefix':       parts[6] if len(parts) > 6 else '',
                    'suffix':       parts[7] if len(parts) > 7 else '',
                    'line':         i + 1,
                })
            continue

        if current_section == 'SIDSSTARS':
            section['SIDSSTARS'].append(stripped)
            continue

        if current_section == 'AIRSPACE':
            # Key:value lines. Lots of types.
            key_m = re.match(r'^([A-Z]+):', stripped)
            if not key_m:
                continue
            key = key_m.group(1)
            rest = stripped[len(key) + 1:]

            if key == 'SECTORLINE':
                current_sectorline_id = rest.strip()
                if current_sectorline_id in sectorlines:
                    # Duplicate — still overwrite but flag later
                    pass
                sectorlines.setdefault(current_sectorline_id, {'coords': [], 'display': []})
                current_sector = None
                continue

            if key == 'DISPLAY' and current_sectorline_id is not None:
                sectorlines[current_sectorline_id]['display'].append(rest)
                continue

            if key == 'COORD' and current_sectorline_id is not None:
                # COORD:N043.48.00.000:W067.00.00.000
                parts = rest.split(':')
                if len(parts) >= 2:
                    sectorlines[current_sectorline_id]['coords'].append((parts[0], parts[1]))
                continue

            if key == 'SECTOR':
                # SECTOR:name:bottom:top
                parts = rest.split(':')
                current_sector = {
                    'name':    parts[0] if len(parts) > 0 else '',
                    'bottom':  parts[1] if len(parts) > 1 else '',
                    'top':     parts[2] if len(parts) > 2 else '',
                    'owners':  [],
                    'altowners': [],
                    'borders': [],
                    'dep':     [],
                    'arr':     [],
                    'active':  [],
                    'guest':   [],
                    'line':    i + 1,
                }
                sectors.append(current_sector)
                current_sectorline_id = None
                continue

            if current_sector is not None:
                if key == 'OWNER':
                    current_sector['owners'] += [s for s in rest.split(':') if s]
                    continue
                if key == 'ALTOWNER':
                    current_sector['altowners'].append(rest)
                    continue
                if key == 'BORDER':
                    current_sector['borders'] += [s for s in rest.split(':') if s]
                    continue
                if key == 'DEPAPT':
                    current_sector['dep'] += [s for s in rest.split(':') if s]
                    continue
                if key == 'ARRAPT':
                    current_sector['arr'] += [s for s in rest.split(':') if s]
                    continue
                if key == 'ACTIVE':
                    current_sector['active'].append(rest)
                    continue
                if key == 'GUEST':
                    current_sector['guest'].append(rest)
                    continue

    return section, sectorlines, sectors


def main():
    section, sectorlines, sectors = parse_file(SRC)

    positions = section['POSITIONS']
    print(f'=== File: {os.path.basename(SRC)} ===')
    print(f'  POSITIONS:       {len(positions)}')
    print(f'  SECTORLINEs:     {len(sectorlines)}')
    print(f'  SECTORs:         {len(sectors)}')
    print()

    # --- Positions summary ---
    print('POSITIONS by prefix (ATC position type):')
    by_prefix = Counter(p['prefix'] for p in positions)
    for pref, n in by_prefix.most_common():
        print(f'  {pref or "(none)":10s}  {n}')
    print()
    unique_freqs = Counter(p['freq'] for p in positions)
    shared = {f: c for f, c in unique_freqs.items() if c > 1 and f}
    if shared:
        print('Shared frequencies (same freq on multiple positions — usually expected):')
        for f, c in sorted(shared.items(), key=lambda x: -x[1])[:8]:
            print(f'  {f}  × {c}')
        print()

    # --- Sector stats ---
    # Group sectors by the left-most ":" prefix (e.g. CZQX·GANDER-HI3)
    by_fir = Counter()
    sector_ids = Counter()
    for s in sectors:
        name = s['name']
        # e.g. "CZQX·GANDER-HI3·285·600" or "QM3"
        if '·' in name:
            by_fir[name.split('·')[0]] += 1
        else:
            by_fir['(short-name)'] += 1
        sector_ids[name] += 1

    print('SECTOR count by prefix:')
    for fir, n in by_fir.most_common():
        print(f'  {fir:20s}  {n}')
    print()
    dups = {k: v for k, v in sector_ids.items() if v > 1}
    if dups:
        print(f'WARNING: {len(dups)} SECTOR names appear more than once:')
        for n, c in list(dups.items())[:10]:
            print(f'  {n}  × {c}')
        print()

    # --- Sectorline cross-check ---
    # Every BORDER id must exist as a SECTORLINE key.
    missing_sl = defaultdict(list)
    used_ids = set()
    for s in sectors:
        for b in s['borders']:
            used_ids.add(b)
            if b not in sectorlines:
                missing_sl[b].append(s['name'])

    unused_sl = [sid for sid in sectorlines if sid not in used_ids]

    print(f'BORDER -> SECTORLINE consistency:')
    print(f'  distinct SECTORLINE ids referenced:  {len(used_ids)}')
    print(f'  distinct SECTORLINE ids defined:     {len(sectorlines)}')
    print(f'  references to MISSING sectorlines:   {sum(len(v) for v in missing_sl.values())}')
    if missing_sl:
        print('  Missing sectorline ids (referenced but not defined):')
        for sid, used_by in list(missing_sl.items())[:10]:
            print(f'    {sid}  -> referenced by {len(used_by)} sector(s), first: {used_by[0]}')
        if len(missing_sl) > 10:
            print(f'    ... and {len(missing_sl)-10} more')
    print(f'  UNUSED sectorlines (defined but never referenced): {len(unused_sl)}')
    if unused_sl:
        print(f'    first few: {unused_sl[:8]}')
    print()

    # --- Sectorline coord quality ---
    empty_sl = [sid for sid, sl in sectorlines.items() if not sl['coords']]
    print(f'Sectorlines with zero COORD lines: {len(empty_sl)}')
    if empty_sl:
        print(f'  first few: {empty_sl[:10]}')
    # Each SECTORLINE should be closed? No — they're segments. But pairs of coords expected.
    too_short = [(sid, len(sl['coords'])) for sid, sl in sectorlines.items() if 0 < len(sl['coords']) < 2]
    if too_short:
        print(f'Sectorlines with fewer than 2 COORDs (can\'t form a line):')
        for sid, n in too_short[:10]:
            print(f'  {sid}  (n={n})')
    print()

    # --- Sector completeness ---
    no_border = [s for s in sectors if not s['borders']]
    no_owner  = [s for s in sectors if not s['owners']]
    no_active = [s for s in sectors if not s['active']]
    bad_alt = [s for s in sectors if not s['bottom'] or not s['top']]
    print('Sector completeness:')
    print(f'  SECTORs with no BORDER lines: {len(no_border)}')
    if no_border:
        for s in no_border[:5]:
            print(f'    {s["name"]}  (line {s["line"]})')
    print(f'  SECTORs with no OWNER:        {len(no_owner)}')
    if no_owner:
        for s in no_owner[:5]:
            print(f'    {s["name"]}  (line {s["line"]})')
    print(f'  SECTORs with no ACTIVE rule:  {len(no_active)}')
    print(f'  SECTORs with missing alt band: {len(bad_alt)}')
    print()

    # --- Position -> Sector mapping ---
    position_idents = {p['ident'] for p in positions}
    # Sector owners reference identifiers (e.g. "QX6", "QX7", "QM1", "QM2", ...)
    referenced_owners = set()
    for s in sectors:
        for o in s['owners']:
            referenced_owners.add(o)
        for ao in s['altowners']:
            for token in ao.split(':'):
                if token: referenced_owners.add(token)
    unmapped_positions = position_idents - referenced_owners
    unknown_owners = referenced_owners - position_idents
    print('Position ↔ Sector mapping:')
    print(f'  POSITION idents: {len(position_idents)}')
    print(f'  SECTOR owner idents: {len(referenced_owners)}')
    print(f'  Positions never owning a sector: {len(unmapped_positions)}')
    if unmapped_positions:
        print(f'    first few: {sorted(unmapped_positions)[:15]}')
    print(f'  Owner idents not matching any POSITION: {len(unknown_owners)}')
    if unknown_owners:
        print(f'    first few: {sorted(unknown_owners)[:15]}')
    print()

    # --- Altitude band sanity ---
    print('Altitude bands:')
    bands = Counter((s['bottom'], s['top']) for s in sectors)
    for (bot, top), n in bands.most_common(10):
        print(f'  {bot:>6s} .. {top:>6s}   × {n}')

    # --- QM / QX sector set from Topsky map cross-check ---
    expected_topsky = {'QM1','QM2','QM3','QM4','QX1','QX2','QX3','QX4','QX5','QX6','QX7'}
    mapped_short = set()
    for s in sectors:
        for o in s['owners']:
            mapped_short.add(o)
    missing = expected_topsky - mapped_short
    print()
    print('Topsky QM/QX sectors vs ESE:')
    if missing:
        print(f'  Missing OWNER idents for: {sorted(missing)}')
    else:
        print('  All 11 QM/QX sector idents present in ESE OWNER entries ✓')

if __name__ == '__main__':
    main()
