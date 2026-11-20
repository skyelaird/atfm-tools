#!/usr/bin/env python3
"""
aman_logger.py - log inbound traffic for one airport from the public VATSIM
data feed, as ground truth for AMAN prediction work (CZQM / VATCAN).

Records, every feed update (~15 s), each pilot whose filed arrival is the
target airport and who is within --radius NM of it. Output: one CSV per UTC
day in --out. Standard library only.

Usage:
    python aman_logger.py --out C:\\aman_logs --airport CYHZ
Stop with Ctrl-C. Exit code 2 = feed stale beyond --max-stale (restart me).
"""
import argparse
import csv
import json
import math
import os
import signal
import sys
import time
import urllib.request
from datetime import datetime, timezone

FEED = "https://data.vatsim.net/v3/vatsim-data.json"

# ICAO -> (lat, lon). Add airports here (or pass --lat/--lon).
AIRPORTS = {
    "CYHZ": (44.8808, -63.5086),
    "CYOW": (45.3225, -75.6692),
    "CYUL": (45.4706, -73.7408),
    "CYYZ": (43.6772, -79.6306),
    "CYVR": (49.1939, -123.1844),
    "CYYC": (51.1139, -114.0203),
}

FIELDS = [
    "utc", "feed_update", "callsign", "lat", "lon", "alt_ft", "gs_kt",
    "hdg", "dist_nm", "aircraft", "dep", "arr", "filed_alt", "route",
    "squawk", "logon_utc",
]

_stop = False


def log(msg):
    print(f"[{datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%SZ')}] {msg}",
          flush=True)


def dist_nm(lat1, lon1, lat2, lon2):
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dl = math.radians(lon2 - lon1)
    a = (math.sin((p2 - p1) / 2) ** 2
         + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2)
    return 3440.065 * 2 * math.asin(math.sqrt(a))


def fetch(timeout):
    req = urllib.request.Request(FEED, headers={"User-Agent": "aman-logger/0.1"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode("utf-8"))


def select(data, icao, lat0, lon0, radius, ground_gs, ground_nm):
    rows = []
    for p in data.get("pilots", []):
        fp = p.get("flight_plan") or {}
        if (fp.get("arrival") or "").upper() != icao:
            continue
        lat, lon = p.get("latitude"), p.get("longitude")
        if lat is None or lon is None:
            continue
        d = dist_nm(lat0, lon0, lat, lon)
        if d > radius:
            continue
        gs = p.get("groundspeed") or 0
        if gs < ground_gs and d < ground_nm:   # parked / taxiing at the field
            continue
        rows.append({
            "callsign": p.get("callsign"), "lat": round(lat, 5),
            "lon": round(lon, 5), "alt_ft": p.get("altitude"), "gs_kt": gs,
            "hdg": p.get("heading"), "dist_nm": round(d, 1),
            "aircraft": fp.get("aircraft_short"), "dep": fp.get("departure"),
            "arr": fp.get("arrival"), "filed_alt": fp.get("altitude"),
            "route": fp.get("route"), "squawk": p.get("transponder"),
            "logon_utc": p.get("logon_time"),
        })
    return rows


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", required=True, help="output directory")
    ap.add_argument("--airport", default="CYHZ")
    ap.add_argument("--lat", type=float)
    ap.add_argument("--lon", type=float)
    ap.add_argument("--radius", type=float, default=400.0, help="NM")
    ap.add_argument("--interval", type=float, default=15.0, help="poll seconds")
    ap.add_argument("--timeout", type=float, default=20.0, help="HTTP timeout s")
    ap.add_argument("--warn-stale", type=float, default=300.0)
    ap.add_argument("--max-stale", type=float, default=1800.0)
    ap.add_argument("--ground-gs", type=float, default=30.0,
                    help="skip rows with gs below this ...")
    ap.add_argument("--ground-nm", type=float, default=5.0,
                    help="... and closer than this to the field")
    a = ap.parse_args()

    icao = a.airport.upper()
    if a.lat is not None and a.lon is not None:
        lat0, lon0 = a.lat, a.lon
    elif icao in AIRPORTS:
        lat0, lon0 = AIRPORTS[icao]
    else:
        sys.exit(f"Unknown airport {icao}: pass --lat and --lon")

    os.makedirs(a.out, exist_ok=True)
    signal.signal(signal.SIGINT, lambda *_: globals().update(_stop=True))
    log(f"start: {icao} ({lat0},{lon0}) radius {a.radius} NM -> {a.out}")

    last_update = None
    last_ok = time.time()
    last_beat = 0.0
    polls = rows_total = fails = dupes = 0
    warned = False

    while not _stop:
        t0 = time.time()
        try:
            data = fetch(a.timeout)
            fails_run = 0
            last_ok = time.time()
            warned = False
            upd = (data.get("general") or {}).get("update_timestamp")
            if upd == last_update:
                dupes += 1
            else:
                last_update = upd
                polls += 1
                rows = select(data, icao, lat0, lon0, a.radius,
                              a.ground_gs, a.ground_nm)
                now = datetime.now(timezone.utc)
                path = os.path.join(
                    a.out, f"{icao}_{now.strftime('%Y%m%d')}.csv")
                new = not os.path.exists(path)
                with open(path, "a", newline="", encoding="utf-8") as f:
                    w = csv.DictWriter(f, fieldnames=FIELDS)
                    if new:
                        w.writeheader()
                    for r in rows:
                        r["utc"] = now.strftime("%Y-%m-%dT%H:%M:%SZ")
                        r["feed_update"] = upd
                        w.writerow(r)
                rows_total += len(rows)
                last_rows = len(rows)
        except Exception as e:                      # network/parse error
            fails += 1
            log(f"fetch failed ({type(e).__name__}: {e})")

        stale = time.time() - last_ok
        if stale > a.max_stale:
            log(f"FATAL: no good fetch for {stale:.0f}s - exiting (code 2)")
            sys.exit(2)
        if stale > a.warn_stale and not warned:
            log(f"WARNING: no good fetch for {stale:.0f}s")
            warned = True

        if time.time() - last_beat >= 60:
            last_beat = time.time()
            log(f"heartbeat: snapshots={polls} rows={rows_total} "
                f"dup_polls={dupes} failures={fails} "
                f"last_ok={stale:.0f}s ago")

        sleep = a.interval - (time.time() - t0)
        end = time.time() + max(sleep, 1.0)
        while time.time() < end and not _stop:
            time.sleep(0.5)

    log(f"stopped: snapshots={polls} rows={rows_total}")


if __name__ == "__main__":
    main()
