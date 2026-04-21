"""Extract one complete slot object from the CTP simulator JSON for inspection."""
import re

PATH = r'D:\GitHub\atfm-tools\data\26E\ctp-sim.json.tmp'

with open(PATH) as f:
    f.seek(50_000_000)
    chunk = f.read(200_000)

start = chunk.find('"slot":{')
if start < 0:
    print('No slot found')
    raise SystemExit(1)
# rewind to the outer { enclosing this slot (should be {"slot":{...},"departureAirport":{...},...})
while start > 0 and chunk[start-1] != '{':
    start -= 1
start -= 1

depth = 0
i = start
in_str = False
esc = False
BS = chr(92)
while i < len(chunk):
    c = chunk[i]
    if esc:
        esc = False
    elif c == BS:
        esc = True
    elif c == '"':
        in_str = not in_str
    elif not in_str:
        if c == '{':
            depth += 1
        elif c == '}':
            depth -= 1
            if depth == 0:
                break
    i += 1

sample = chunk[start:i+1]
print(f'Sample slot object: {len(sample)} chars')
print(sample[:5000])
print('...' if len(sample) > 5000 else '(end)')
