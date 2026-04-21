"""
Build a clean bookings.csv + a waypoints-per-flight JSONL from CTP's
extracted slots. Each slot has 3 routeSegments (AMAS + OCA + EMEA)
whose locations concatenated (in sortOrder) give the full resolved
route with lat/lon.
"""
import csv
import json
import os

SLOTS_IN = r'D:\GitHub\atfm-tools\data\26E\ctp-slots.jsonl'
BOOKINGS_OUT = r'D:\GitHub\atfm-tools\data\26E\bookings.csv'
ROUTES_OUT = r'D:\GitHub\atfm-tools\data\26E\ctp-routes.jsonl'


def main():
    n_slots = 0
    n_with_route = 0
    with open(SLOTS_IN) as fin, \
         open(BOOKINGS_OUT, 'w', newline='', encoding='utf-8') as bout, \
         open(ROUTES_OUT, 'w') as rout:
        w = csv.writer(bout)
        w.writerow(['id', 'cid', 'dep', 'arr', 'ctot', 'alt', 'trk', 'callsign', 'selcal', 'route'])

        for line in fin:
            line = line.strip()
            if not line:
                continue
            slot = json.loads(line)
            n_slots += 1

            dep_wp = slot.get('departureAirport', {}).get('waypoint', {})
            arr_wp = slot.get('arrivalAirport', {}).get('waypoint', {})
            dep = dep_wp.get('identifier') or ''
            arr = arr_wp.get('identifier') or ''
            dep_time = slot.get('departureTime', '')
            # Convert "2026-04-25T11:49:00Z" → "11:49"
            ctot = dep_time[11:16] if 'T' in dep_time else ''

            # Concatenate route segments, preserving order (AMAS, OCA, EMEA).
            # Each segment's `locations` is already sorted by sortOrder
            # (but re-sort to be safe) and its waypoints form the route.
            segments = slot.get('routeSegments', []) or []
            # Sort segments by group order: AMAS first (domestic departure),
            # then OCA (oceanic), then EMEA (European arrival). Fall back to
            # the slot's dep/arr progression if group is missing.
            group_order = {'AMAS': 0, 'OCA': 1, 'EMEA': 2}
            segments = sorted(
                segments,
                key=lambda s: group_order.get(s.get('routeSegmentGroup', ''), 99),
            )
            route_points = []        # list of {lat, lon, name} in order
            route_tokens = []        # route string for display
            facilities_seq = []      # ordered facility list
            for seg in segments:
                locs = sorted(seg.get('locations', []) or [], key=lambda x: x.get('sortOrder', 0))
                for loc in locs:
                    wp = loc.get('waypoint', {}) or {}
                    name = wp.get('identifier') or ''
                    lat = wp.get('latitude')
                    lon = wp.get('longitude')
                    if lat is None or lon is None:
                        continue
                    # Avoid duplicates at segment boundaries
                    if route_points and route_points[-1]['name'] == name:
                        continue
                    route_points.append({'name': name, 'lat': float(lat), 'lon': float(lon)})
                    route_tokens.append(name)
                # Track facilities
                prog = seg.get('providedFacilityProgression', []) or []
                for p in prog:
                    ident = p.get('identifier')
                    if ident and (not facilities_seq or facilities_seq[-1] != ident):
                        facilities_seq.append(ident)

            if not route_points:
                continue
            n_with_route += 1

            # Write booking row
            w.writerow([
                slot.get('id'),         # slot ID
                '',                     # no VATSIM CID in this feed
                dep, arr, ctot,
                '',                     # no filed altitude in slot
                '',                     # no assigned track
                '',                     # no callsign
                '',                     # no selcal
                ' '.join(route_tokens),
            ])

            # Write full resolved route with coords for the sim
            rout.write(json.dumps({
                'slot_id': slot.get('id'),
                'dep': dep, 'arr': arr, 'ctot': ctot,
                'route': route_points,
                'facilities': facilities_seq,
            }) + '\n')

    print(f'Total slots: {n_slots}')
    print(f'With resolved route: {n_with_route}')
    print(f'Out: {BOOKINGS_OUT}')
    print(f'Out: {ROUTES_OUT}')


if __name__ == '__main__':
    main()
