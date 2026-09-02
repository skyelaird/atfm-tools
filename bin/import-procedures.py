"""Parse PMDG SidStars files into a procedure model that keeps the constraints.

The previous importer (the SID/STAR half of bin/import-navdata.php) kept only
fix NAMES and collapsed every runway variant into "the longest one". For CYYZ
that turned 74 STAR lines into 10 entries, discarded every published speed and
altitude, and lost the runway dimension entirely — which is why RAGID6 and
UDNOX5 ended up with identical fix lists and UDNOX5 did not even start at UDNOX.

What the source actually carries, per STAR line:

    STAR RAGID6.05 FIX RAGID FIX LERAT AT OR BELOW 15000 AT OR ABOVE 11000
         SPEED 250 FIX SEMTI FIX KEVNO 8000 SPEED 210 FIX ERBUS ... FIX DERLI
         TRK 237 VECTORS
      TRANSITION OTNIK FIX GOPAK FIX NAKAL FIX OTNIK

    APPROACH ILS06R FIX LOBKO AT OR ABOVE 3000 FIX SAVOS 2010 RNW 06R
         HDG 057 UNTIL 1100 HDG 132 INTERCEPT RADIAL 102 TO FIX TUDOG ...

So: runway transitions (the suffix after the dot), per-fix altitude windows and
speed limits, and an explicit `TRK nnn VECTORS` terminator saying where the
published procedure stops and radar vectoring begins.

Writes data/procedures-v2.json (rich) and regenerates data/procedures.json
(legacy flat name -> fix-name list) so existing consumers keep working
unchanged until they are migrated.

Usage:  python bin/import-procedures.py [--src DIR] [--dry-run]
"""

import argparse
import json
import os
import re
import sys

AIRPORTS = ["CYHZ", "CYOW", "CYUL", "CYVR", "CYWG", "CYYC", "CYYZ"]
DEFAULT_SRC = r"D:\data\SidStars"

# Tokens that terminate a fix's constraint run. Anything not in here that
# follows a fix name is parsed as a constraint belonging to that fix.
NEXT_FIX = "FIX"


def parse_fix_run(tokens, i):
    """Parse `FIX NAME [constraints...]` starting at tokens[i] == 'FIX'.

    Returns (fix_dict, next_index). Constraints recognised:
        AT OR BELOW <alt>   -> alt_max
        AT OR ABOVE <alt>   -> alt_min
        <bare number>       -> alt (a hard crossing altitude)
        SPEED <kt>          -> speed
    Anything else ends the fix's constraint run and is left to the caller.
    """
    fix = {"name": tokens[i + 1]}
    j = i + 2
    while j < len(tokens):
        t = tokens[j]
        if t == NEXT_FIX:
            break
        if t == "AT" and tokens[j:j + 3] == ["AT", "OR", "BELOW"] and j + 3 < len(tokens):
            fix["alt_max"] = int(tokens[j + 3]); j += 4; continue
        if t == "AT" and tokens[j:j + 3] == ["AT", "OR", "ABOVE"] and j + 3 < len(tokens):
            fix["alt_min"] = int(tokens[j + 3]); j += 4; continue
        if t == "SPEED" and j + 1 < len(tokens) and tokens[j + 1].isdigit():
            fix["speed"] = int(tokens[j + 1]); j += 2; continue
        if t.isdigit():
            fix["alt"] = int(t); j += 1; continue
        # Not a fix constraint — belongs to the trailing clause (TRK/VECTORS/
        # HDG/INTERCEPT/HOLD/...). Stop here and let the caller handle it.
        break
    return fix, j


def parse_proc_line(line):
    """Parse one STAR/SID/APPROACH line into {name, variant, fixes, end}."""
    tokens = line.split()
    kind = tokens[0]
    full = tokens[1] if len(tokens) > 1 else ""
    base, _, variant = full.partition(".")

    fixes = []
    end = {}
    i = 2
    while i < len(tokens):
        t = tokens[i]
        if t == NEXT_FIX and i + 1 < len(tokens):
            fix, i = parse_fix_run(tokens, i)
            fixes.append(fix)
            continue
        if t == "TRK" and i + 1 < len(tokens) and tokens[i + 1].isdigit():
            end["trk"] = int(tokens[i + 1]); i += 2; continue
        if t == "VECTORS":
            end["vectors"] = True; i += 1; continue
        if t == "RNW" and i + 1 < len(tokens):
            end["rnw"] = tokens[i + 1]; i += 2; continue
        i += 1

    # Approach lines mention the same fix several times — once in the leg, once
    # inside `INTERCEPT ... TO FIX X`, once in `HOLD AT FIX X`. Collapse
    # consecutive repeats into one entry, keeping every constraint seen.
    merged = []
    for f in fixes:
        if merged and merged[-1]["name"] == f["name"]:
            for k, v in f.items():
                merged[-1].setdefault(k, v)
        else:
            merged.append(f)

    if "HOLD" in tokens:
        held = tokens[tokens.index("HOLD"):]
        for k, t in enumerate(held):
            if t == NEXT_FIX and k + 1 < len(held):
                for f in merged:
                    if f["name"] == held[k + 1]:
                        f["hold"] = True
                break

    return {
        "kind": kind,
        "base": base,
        "variant": variant or None,
        "fixes": merged,
        "end": end,
    }


def parse_airport(path):
    out = {"stars": {}, "sids": {}, "approaches": {}, "fixes": {}, "runways": []}
    current = None  # (collection, base, variant) for attaching TRANSITION lines

    with open(path, encoding="utf-8", errors="replace") as fh:
        for raw in fh:
            line = raw.rstrip("\n")
            stripped = line.strip()
            if not stripped or stripped.startswith("//"):
                continue

            # FIX <NAME> LATLON N 43 32.085334 W 79 48.050833
            if stripped.startswith("FIX ") and " LATLON " in stripped:
                m = re.match(
                    r"FIX (\S+) LATLON ([NS]) (\d+) ([\d.]+) ([EW]) (\d+) ([\d.]+)",
                    stripped,
                )
                if m:
                    name, ns, dlat, mlat, ew, dlon, mlon = m.groups()
                    lat = int(dlat) + float(mlat) / 60.0
                    lon = int(dlon) + float(mlon) / 60.0
                    if ns == "S":
                        lat = -lat
                    if ew == "W":
                        lon = -lon
                    out["fixes"][name] = [round(lat, 6), round(lon, 6)]
                continue

            if stripped.startswith("RNW ") and len(stripped.split()) == 2:
                out["runways"].append(stripped.split()[1])
                continue

            if stripped.startswith(("STAR ", "SID ", "APPROACH ")):
                p = parse_proc_line(stripped)
                coll = {"STAR": "stars", "SID": "sids", "APPROACH": "approaches"}[p["kind"]]
                entry = out[coll].setdefault(p["base"], {"variants": {}, "transitions": {}})
                key = p["variant"] or "-"
                entry["variants"][key] = {"fixes": p["fixes"], "end": p["end"]}
                current = (coll, p["base"])
                continue

            # Indented child of the preceding procedure.
            if stripped.startswith("TRANSITION ") and current is not None:
                toks = stripped.split()
                tname = toks[1]
                tfixes = [toks[k + 1] for k, t in enumerate(toks) if t == NEXT_FIX and k + 1 < len(toks)]
                coll, base = current
                out[coll][base]["transitions"][tname] = tfixes
                continue

    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", default=DEFAULT_SRC)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    repo = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    rich = {}
    legacy = {}
    missing = []

    for icao in AIRPORTS:
        path = os.path.join(args.src, icao + ".txt")
        if not os.path.exists(path):
            missing.append(icao)
            continue
        rich[icao] = parse_airport(path)

        # Legacy shape: base name -> longest variant's fix names. Preserved so
        # Geo::parseRouteCoordinates() keeps working until it is migrated.
        for base, entry in rich[icao]["stars"].items():
            best = max(entry["variants"].values(), key=lambda v: len(v["fixes"]), default=None)
            if best and best["fixes"]:
                legacy[base] = [f["name"] for f in best["fixes"]]
        for base, entry in rich[icao]["sids"].items():
            best = max(entry["variants"].values(), key=lambda v: len(v["fixes"]), default=None)
            if best and best["fixes"]:
                legacy.setdefault(base, [f["name"] for f in best["fixes"]])

    # Report
    for icao, a in rich.items():
        nstar = sum(len(e["variants"]) for e in a["stars"].values())
        napp = sum(len(e["variants"]) for e in a["approaches"].values())
        ntr = sum(len(e["transitions"]) for e in a["stars"].values())
        spd = sum(1 for e in a["stars"].values() for v in e["variants"].values()
                  for f in v["fixes"] if "speed" in f)
        alt = sum(1 for e in a["stars"].values() for v in e["variants"].values()
                  for f in v["fixes"] if {"alt", "alt_min", "alt_max"} & set(f))
        print(f"  {icao}: {len(a['stars'])} STARs / {nstar} runway variants, "
              f"{ntr} transitions, {napp} approach variants, "
              f"{len(a['fixes'])} fixes | {spd} speed + {alt} altitude constraints")
    if missing:
        print("  missing source files:", ", ".join(missing))

    if args.dry_run:
        print("\n(dry run — nothing written)")
        return 0

    with open(os.path.join(repo, "data", "procedures-v2.json"), "w", encoding="utf-8") as fh:
        json.dump(rich, fh, separators=(",", ":"))
    with open(os.path.join(repo, "data", "procedures.json"), "w", encoding="utf-8") as fh:
        json.dump(legacy, fh, separators=(",", ":"))
    print(f"\nwrote data/procedures-v2.json ({len(rich)} airports) "
          f"and data/procedures.json ({len(legacy)} legacy entries)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
