"""
Stream-extract from CTP simulator-data JSON into small per-array JSONL files.
"""
import json
import os

SRC = r'D:\GitHub\atfm-tools\data\26E\ctp-sim.json.tmp'
DST_DIR = r'D:\GitHub\atfm-tools\data\26E'
BS = '\\'


def find_array_start(f, key_name):
    target = f'"{key_name}":['
    CHUNK = 1 << 20
    pos = 0
    f.seek(0)
    prev_tail = ''
    while True:
        chunk = f.read(CHUNK)
        if not chunk:
            return None
        combined = prev_tail + chunk
        idx = combined.find(target)
        if idx >= 0:
            return pos - len(prev_tail) + idx + len(target) - 1
        prev_tail = combined[-len(target):]
        pos += len(chunk)


def iter_array_objects(f, start_pos):
    """Yield each top-level {...} object in a JSON array starting at `start_pos` (on '[')."""
    f.seek(start_pos)
    depth = 0
    in_str = False
    esc = False
    buf = []
    saw_open = False
    CHUNK = 1 << 20
    while True:
        chunk = f.read(CHUNK)
        if not chunk:
            return
        for c in chunk:
            if not saw_open:
                if c == '[':
                    saw_open = True
                continue
            if esc:
                esc = False
                buf.append(c)
                continue
            if c == BS:
                esc = True
                buf.append(c)
                continue
            if c == '"':
                in_str = not in_str
                buf.append(c)
                continue
            if not in_str:
                if c == '{':
                    depth += 1
                    buf.append(c)
                    continue
                if c == '}':
                    depth -= 1
                    buf.append(c)
                    if depth == 0:
                        yield ''.join(buf).strip()
                        buf = []
                    continue
                if depth == 0:
                    if c == ']':
                        return
                    continue
            buf.append(c)


def extract_array(array_key, out_filename, limit=None, sample_parse=False):
    with open(SRC) as f:
        pos = find_array_start(f, array_key)
        if pos is None:
            print(f'  [{array_key}] NOT FOUND')
            return 0, None
        print(f'  [{array_key}] start pos = {pos:,}')
        out_path = os.path.join(DST_DIR, out_filename)
        n = 0
        first_keys = None
        with open(out_path, 'w') as out:
            for obj_str in iter_array_objects(f, pos):
                out.write(obj_str + '\n')
                n += 1
                if first_keys is None and sample_parse:
                    try:
                        obj = json.loads(obj_str)
                        first_keys = list(obj.keys())
                    except Exception:
                        first_keys = []
                if limit and n >= limit:
                    break
        print(f'  [{array_key}] wrote {n} rows -> {out_path}')
        return n, first_keys


def main():
    # Explore: find where various candidate arrays live
    for name in ('slots', 'throughputPoints', 'throughputPointVisits',
                 'routeSegments', 'airports', 'waypoints', 'sectors'):
        n, keys = extract_array(name, f'ctp-{name}.jsonl', sample_parse=True)
        if keys:
            print(f'     keys: {keys}')
        print()


if __name__ == '__main__':
    main()
