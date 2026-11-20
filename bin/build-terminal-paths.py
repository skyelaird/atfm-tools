#!/usr/bin/env python3
"""Build terminal paths (STAR variant -> approach -> threshold) for the 7 scoped
airports, per docs/TERMINAL-PATH-MODEL.md.

Inputs : data/procedures-v2.json  (STARs, approaches, fix coordinates)
         bin/seed-airports.php    (runway threshold coordinates, parsed as text)
Output : data/terminal-paths.json (+ a coverage / sanity report on stdout)

Closure of the STAR->approach discontinuity, in order of preference:
  direct      STAR's last fix IS the approach's first fix        (exact)
  transition  STAR's last fix is a named approach transition     (exact)
  open        synthesise a bounded intercept leg (flagged)       (judgement)

The open case is bounded, not invented: from the STAR's last fix, continue on
the published track if the turn to the approach's first fix would exceed
MAX_TURN_DEG, then fly straight to that fix. Every synthetic point is flagged.

Usage: python bin/build-terminal-paths.py [--repo DIR] [--quiet]
Stdlib only. Progress lines are timestamped.
"""
import argparse
import json
import math
import os
import re
import statistics
import sys
import time
from collections import Counter, defaultdict

R_NM = 3440.065
MAX_TURN_DEG = 100.0
EXT_STEP_NM = 1.0
EXT_MAX_NM = 30.0
SPEED_DEFAULT_KT = 250.0     # before the first published speed constraint
SPEED_FINAL_KT = 150.0       # last approach fix -> threshold
APPROACH_PREF = ("ILS", "LOC", "RNAV", "RNV", "RNP", "GLS", "VOR", "NDB")

# Observed minutes from the 40 nm ring to the ALDT stamp, median per airport,
# /api/v1/debug/terminal-time (48 h window, fetched 2026-11-20). ALDT stamping
# lag is ~1.1 min. Used only as a sanity yardstick, not fitted.
OBSERVED_FROM_40NM = {"CYYZ": 15.8, "CYUL": 16.6, "CYVR": 14.1, "CYYC": 15.2,
                      "CYHZ": 14.2, "CYWG": 13.2, "CYOW": 14.3}
ALDT_LAG_MIN = 1.1

T0 = time.time()


def say(msg, quiet=False):
    if not quiet:
        print(f"[{time.strftime('%H:%M:%SZ', time.gmtime())} +{time.time()-T0:5.1f}s] {msg}",
              flush=True)


# ---------------------------------------------------------------- geometry
def dist_nm(a, b):
    p1, p2 = math.radians(a[0]), math.radians(b[0])
    dl = math.radians(b[1] - a[1])
    h = math.sin((p2 - p1) / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * R_NM * math.asin(math.sqrt(h))


def bearing(a, b):
    p1, p2 = math.radians(a[0]), math.radians(b[0])
    dl = math.radians(b[1] - a[1])
    y = math.sin(dl) * math.cos(p2)
    x = math.cos(p1) * math.sin(p2) - math.sin(p1) * math.cos(p2) * math.cos(dl)
    return (math.degrees(math.atan2(y, x)) + 360) % 360


def project(a, brg, nm):
    d, th = nm / R_NM, math.radians(brg)
    p1, l1 = math.radians(a[0]), math.radians(a[1])
    p2 = math.asin(math.sin(p1) * math.cos(d) + math.cos(p1) * math.sin(d) * math.cos(th))
    l2 = l1 + math.atan2(math.sin(th) * math.sin(d) * math.cos(p1),
                         math.cos(d) - math.sin(p1) * math.sin(p2))
    return (math.degrees(p2), (math.degrees(l2) + 540) % 360 - 180)


def angdiff(a, b):
    return abs((a - b + 180) % 360 - 180)


# ------------------------------------------------------------------ inputs
def parse_thresholds(php_path):
    """Return {icao: {runway: (lat, lon)}} from the runwayLines in seed-airports.php."""
    def dms(s):
        m = re.match(r"^([NSEW])(\d{2,3})\.(\d{2})\.(\d{2})\.(\d{3})$", s)
        h, d, mi, se, ms = m.groups()
        v = int(d) + int(mi) / 60 + (int(se) + int(ms) / 1000) / 3600
        return -v if h in "SW" else v

    out = defaultdict(dict)
    pat = re.compile(r"'(\S+)\s+(\S+)\s+\d+\s+\d+\s+([NS]\S+)\s+([EW]\S+)\s+"
                     r"([NS]\S+)\s+([EW]\S+)\s+(CY[A-Z]{2})'")
    for line in open(php_path, encoding="utf-8"):
        m = pat.search(line)
        if not m:
            continue
        r1, r2, la1, lo1, la2, lo2, icao = m.groups()
        out[icao][r1] = (dms(la1), dms(lo1))
        out[icao][r2] = (dms(la2), dms(lo2))
    return out


def runways_for_variant(key, thr):
    """STAR variant key -> runway idents. '06B' -> 06L,06R ; '06' -> exact, else 06L/06R."""
    if key in thr:
        return [key]
    if key == "-":                      # STAR with one variant for every runway
        return list(thr)
    m = re.match(r"^(\d{2})([LRCB]?)$", key)
    if not m:
        return []
    num, suf = m.groups()
    if suf == "B":
        return [r for r in thr if r.startswith(num) and r[2:] in ("L", "R")]
    return [r for r in thr if r.startswith(num)]


def pick_approach(approaches, rwy):
    cands = []
    for name, ap in approaches.items():
        for vk, var in ap["variants"].items():
            if (var.get("end") or {}).get("rnw") == rwy:
                rank = next((i for i, p in enumerate(APPROACH_PREF) if name.upper().startswith(p)),
                            len(APPROACH_PREF))
                cands.append((rank, name, ap, var))
    cands.sort(key=lambda c: (c[0], c[1]))
    return cands


# ------------------------------------------------------------------- build
def mkpt(fix, coords, kind):
    p = {"name": fix["name"], "lat": round(coords[0], 6), "lon": round(coords[1], 6), "kind": kind}
    for k in ("alt", "alt_min", "alt_max", "speed", "hold"):
        if k in fix:
            p[k] = fix[k]
    return p


def clean(fl):
    """Drop the importer's OVERFLY pseudo-fix (a flag token, not a waypoint)."""
    return [f for f in fl if f["name"] != "OVERFLY"]


def synth_intercept(P, trk, I, tpos):
    """Bounded intercept from the STAR's last fix P to the approach's first fix I.

    Search (L, D): continue L nm on the published track, then fly straight to a
    point X on the extended final course, D nm from the threshold (D >= I's own
    distance). Constraints: turn at the end of the extension <= MAX_TURN_DEG;
    intercept angle to the final course <= 90 deg. Pick the shortest total path.
    Returns (points, flags) where points are synthetic waypoints to insert.
    """
    Hf = bearing(I, tpos)
    dI = dist_nm(I, tpos)
    Ls = [0.0] + ([float(x) for x in range(1, int(EXT_MAX_NM) + 1)] if trk is not None else [])
    best = None
    for L in Ls:
        E = P if L == 0 else project(P, trk, L)
        for k in range(0, 11):
            D = dI + 2.0 * k
            X = I if k == 0 else project(tpos, (Hf + 180) % 360, D)
            leg = dist_nm(E, X)
            if leg < 1.0:
                continue
            brg = bearing(E, X)
            if trk is not None and L > 0 and angdiff(brg, trk) > MAX_TURN_DEG:
                continue
            if trk is not None and L == 0 and angdiff(brg, trk) > MAX_TURN_DEG:
                continue
            if angdiff(brg, Hf) > 90:
                continue
            cost = L + leg + D
            if best is None or cost < best[0]:
                best = (cost, L, E, k, X)
    if best is None:
        return [], ["intercept_unresolved_direct_to_IF"]
    _, L, E, k, X = best
    pts, flags = [], ["synthetic_intercept"]
    if L > 0:
        pts.append({"name": f"EXT{int(L)}", "lat": round(E[0], 6), "lon": round(E[1], 6), "kind": "synthetic"})
        flags.append(f"extended_{int(L)}nm")
    if k > 0:
        pts.append({"name": "INT", "lat": round(X[0], 6), "lon": round(X[1], 6), "kind": "synthetic"})
        flags.append(f"intercept_{2*k}nm_outside_IF")
    return pts, flags


def build_one(icao, star, vkey, rwy, svar, appr, fixes, thr, mfix):
    flags = []
    tpos = thr.get(rwy)
    if not tpos:
        return None, "no threshold coordinates"
    if not appr:
        return None, "no approach for runway"
    sf = clean(svar["fixes"])
    missing = [f["name"] for f in sf if f["name"] not in fixes]
    if missing:
        return None, f"STAR fixes without coordinates: {missing}"
    _, ap_name, ap, avar = appr[0]
    afix = clean(avar["fixes"])
    miss_a = [f["name"] for f in afix if f["name"] not in fixes]
    if miss_a:
        return None, f"approach {ap_name} fixes without coordinates: {miss_a}"

    # The importer keeps every FIX token on the approach line, including the
    # missed-approach and hold fixes AFTER the runway. Cut after the fix
    # nearest the threshold.
    if afix:
        ds = [dist_nm(fixes[f["name"]], tpos) for f in afix]
        k = ds.index(min(ds))
        if k < len(afix) - 1:
            flags.append(f"approach_cut_{len(afix) - 1 - k}_post_threshold_fixes")
            afix = afix[:k + 1]

    pts = [mkpt(f, fixes[f["name"]], "star") for f in sf]
    last = sf[-1]["name"]
    trans = ap.get("transitions", {})
    if afix and last == afix[0]["name"]:
        closure = "direct"
        pts.pop()
    elif last in trans and all(n in fixes for n in trans[last]):
        closure = "transition"
        pts.pop()
        for n in trans[last]:
            if not any(f["name"] == n for f in afix):
                pts.append({"name": n, "lat": round(fixes[n][0], 6),
                            "lon": round(fixes[n][1], 6), "kind": "transition"})
    else:
        closure = "open"
        P = (pts[-1]["lat"], pts[-1]["lon"])
        trk = (svar.get("end") or {}).get("trk")
        if trk is None and len(pts) > 1:
            trk = bearing((pts[-2]["lat"], pts[-2]["lon"]), P)
        syn, fl = synth_intercept(P, trk, fixes[afix[0]["name"]], tpos)
        pts.extend(syn)
        flags.extend(fl)
    for f in afix:
        pts.append(mkpt(f, fixes[f["name"]], "approach"))
    pts.append({"name": f"RW{rwy}", "lat": round(tpos[0], 6), "lon": round(tpos[1], 6),
                "kind": "threshold"})

    # Start the path at the metering (feeder) fix, dropping en-route entry fixes.
    ff = mfix.get((icao, star))
    names = [p["name"] for p in pts]
    if ff and ff in names:
        i0 = names.index(ff)
        if i0:
            flags.append(f"entry_fixes_dropped_{i0}")
        pts = pts[i0:]
    else:
        flags.append("no_catalog_feeder")
        ff = pts[0]["name"]

    cum, spd, t = 0.0, SPEED_DEFAULT_KT, 0.0
    tcum, dthr = [0.0], [dist_nm((pts[0]["lat"], pts[0]["lon"]), tpos)]
    for i, p in enumerate(pts):
        if i:
            seg = dist_nm((pts[i-1]["lat"], pts[i-1]["lon"]), (p["lat"], p["lon"]))
            cum += seg
            v = SPEED_FINAL_KT if (pts[i-1]["kind"] == "approach" and p["kind"] == "threshold") else spd
            t += seg / v * 60.0
            tcum.append(t)
            dthr.append(dist_nm((p["lat"], p["lon"]), tpos))
        p["cum_nm"] = round(cum, 2)
        if "speed" in p:
            spd = float(p["speed"])
    # time from the 40 nm straight-line ring (comparable to /debug/terminal-time)
    j = next((i for i, d in enumerate(dthr) if d <= 40.0), None)
    if j is None:
        t_ring = None
    elif j == 0:
        t_ring = t
    else:
        fr = (dthr[j-1] - 40.0) / (dthr[j-1] - dthr[j]) if dthr[j-1] != dthr[j] else 0.0
        t_ring = t - (tcum[j-1] + fr * (tcum[j] - tcum[j-1]))
    return {"airport": icao, "star": star, "variant": vkey, "runway": rwy,
            "approach": ap_name, "feeder_fix": ff, "closure": closure, "flags": flags,
            "total_nm": round(cum, 2),
            "time_min_published_speeds": round(t, 1),
            "time_min_from_40nm_ring": None if t_ring is None else round(t_ring, 1),
            "points": pts}, None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--repo", default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    ap.add_argument("--quiet", action="store_true")
    a = ap.parse_args()
    q = a.quiet

    proc = json.load(open(os.path.join(a.repo, "data", "procedures-v2.json"), encoding="utf-8"))
    thr_all = parse_thresholds(os.path.join(a.repo, "bin", "seed-airports.php"))
    cat = json.load(open(os.path.join(a.repo, "data", "star-catalog.json"), encoding="utf-8"))
    mfix = {}
    for icao, lst in cat.get("metering_fixes", {}).items():
        for e in lst:
            for st in e.get("stars", []):
                mfix[(icao, st)] = e["fix"]
    say(f"loaded procedures for {len(proc)} airports; thresholds for "
        f"{sum(len(v) for v in thr_all.values())} runway ends", q)

    paths, skipped = [], []
    for icao, v in proc.items():
        thr = thr_all.get(icao, {})
        fixes = v.get("fixes", {})
        n0 = len(paths)
        for star, sd in v["stars"].items():
            for vkey, svar in sd["variants"].items():
                rwys = runways_for_variant(vkey, thr)
                if not rwys:
                    skipped.append((icao, star, vkey, "variant key matches no runway"))
                    continue
                for rwy in rwys:
                    # Prefer an approach that closes exactly (direct/transition);
                    # otherwise fall back to the most-preferred approach (open).
                    cands = pick_approach(v.get("approaches", {}), rwy)
                    chosen, why = None, "no approach for runway"
                    for c in cands:
                        p, w = build_one(icao, star, vkey, rwy, svar, [c], fixes, thr, mfix)
                        if p is None:
                            why = w
                            continue
                        if p["closure"] != "open":
                            chosen = p
                            break
                        if chosen is None:
                            chosen = p
                    if chosen:
                        paths.append(chosen)
                    else:
                        skipped.append((icao, f"{star}.{vkey}", rwy, why))
        say(f"{icao}: {len(paths)-n0} paths", q)

    meta = {"generated": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "source": ["data/procedures-v2.json", "bin/seed-airports.php"],
            "max_turn_deg": MAX_TURN_DEG, "paths": len(paths), "skipped": len(skipped)}
    out = os.path.join(a.repo, "data", "terminal-paths.json")
    json.dump({"_meta": meta, "paths": paths}, open(out, "w", encoding="utf-8"), separators=(",", ":"))
    say(f"wrote {out} ({os.path.getsize(out)//1024} KB)", q)

    # ---- report
    print("\n== Closure coverage ==")
    byap = defaultdict(Counter)
    for p in paths:
        byap[p["airport"]][p["closure"]] += 1
    tot = Counter()
    for icao in proc:
        c = byap[icao]; tot.update(c)
        print(f"{icao}: direct {c['direct']:3d}  transition {c['transition']:3d}  open {c['open']:3d}  (n={sum(c.values())})")
    n = sum(tot.values())
    print(f"ALL : direct {tot['direct']}  transition {tot['transition']}  open {tot['open']}  "
          f"-> exact {100*(tot['direct']+tot['transition'])/n:.0f}% of {n} runway paths")
    print(f"flags: extended={sum(1 for p in paths if any(f.startswith('extended') for f in p['flags']))}, "
          f"turn unresolved={sum(1 for p in paths if 'turn_gt_limit_unresolved' in p['flags'])}")
    print(f"skipped: {len(skipped)}")
    for s in skipped[:12]:
        print("  ", s)
    if len(skipped) > 12:
        print(f"   ... {len(skipped)-12} more")

    print("\n== Sanity: published-speed time vs observed 40 nm ring -> wheels ==")
    print("(model: constraint speeds, no wind, no vectoring. observed: median from /debug/terminal-time minus ~1.1 min ALDT lag)")
    print(f"{'apt':5} {'n':>3} {'ring nm p50':>11} {'model min p50':>13} {'observed-lag':>12} {'gap min':>8}")
    for icao in proc:
        ps = [p for p in paths if p["airport"] == icao and p["time_min_from_40nm_ring"] is not None]
        if not ps:
            continue
        T = statistics.median(p["time_min_from_40nm_ring"] for p in ps)
        L = statistics.median(p["total_nm"] for p in ps)
        obs = OBSERVED_FROM_40NM.get(icao, float("nan")) - ALDT_LAG_MIN
        print(f"{icao:5} {len(ps):3d} {L:11.1f} {T:13.1f} {obs:12.1f} {obs-T:8.1f}")

    print("\n== Flag counts ==")
    fc = Counter()
    for p in paths:
        for f in p["flags"]:
            fc[re.sub(r"_?\d+", "", f)] += 1
    for k, v in fc.most_common():
        print(f"  {k}: {v}")
    print("\n== Paths whose length from the ring exceeds 70 nm (check these) ==")
    for p in paths:
        if p["time_min_from_40nm_ring"] is not None and p["time_min_from_40nm_ring"] > 24:
            print(f"  {p['airport']} {p['star']}.{p['variant']} rwy {p['runway']} {p['closure']}: "
                  f"{p['total_nm']} nm, {p['time_min_from_40nm_ring']} min from ring {p['flags']}")


if __name__ == "__main__":
    main()
