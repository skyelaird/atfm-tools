"""
Validate my geometric sector attribution against CTP's planned
facility progression for each slot.

For each slot:
  - CTP's planned facilities (from route segments) include tokens
    like "CZQM", "CZQX", "QMQX1", "QMQX2", "QX5", "QX6", "QX7".
  - My sim produces a sequence of QM1..QM4, QX1..QX7 polygon
    entries in time order.

Translate my fine-grained split into CTP's coarse union:
  {QM1, QX1} -> "QMQX1"
  {QM2, QX2} -> "QMQX2"
  {QM3, QX3} -> "QMQX3"
  {QM4, QX4} -> "QMQX4"
  {QX5,QX6,QX7} stay as themselves
and compare ordered sequences.
"""
import json
import os
from collections import Counter

LOAD = r'D:\GitHub\atfm-tools\data\26E\sector-load.json'
ROUTES = r'D:\GitHub\atfm-tools\data\26E\ctp-routes.jsonl'


MY_TO_CTP = {
    'QM1': 'QMQX1', 'QX1': 'QMQX1',
    'QM2': 'QMQX2', 'QX2': 'QMQX2',
    'QM3': 'QMQX3', 'QX3': 'QMQX3',
    'QM4': 'QMQX4', 'QX4': 'QMQX4',
    'QX5': 'QX5',
    'QX6': 'QX6',
    'QX7': 'QX7',
}
CTP_QX_TOKENS = {'QMQX1', 'QMQX2', 'QMQX3', 'QMQX4', 'QX5', 'QX6', 'QX7'}


def dedup_consecutive(seq):
    out = []
    for x in seq:
        if not out or out[-1] != x:
            out.append(x)
    return out


def main():
    # Load my sim's per-flight sector sequence
    with open(LOAD) as f:
        load = json.load(f)
    mine_by_slot = {}
    for fr in load['flights']:
        # Callsign field holds 'slot_<id>'
        sid = fr['callsign'].replace('slot_', '')
        # Build a chronological list of my sector visits
        visits = []
        for sec, intervals in fr['sectors'].items():
            for a, b in intervals:
                visits.append((a, sec))
        visits.sort()
        my_seq = dedup_consecutive([MY_TO_CTP.get(v[1], v[1]) for v in visits if v[1] in MY_TO_CTP])
        mine_by_slot[sid] = my_seq

    # Load CTP's facility progression per slot
    match = 0
    extra_mine = 0   # I found QM/QX visit not in CTP plan
    missing_mine = 0 # CTP plan has QM/QX that my sim missed
    match_rate_by_ctp_token = Counter()
    miss_rate_by_ctp_token = Counter()
    examples_extra = []
    examples_missing = []

    total = 0
    with open(ROUTES) as f:
        for line in f:
            row = json.loads(line)
            sid = str(row['slot_id'])
            ctp_fac = row.get('facilities', []) or []
            ctp_qx = [t for t in ctp_fac if t in CTP_QX_TOKENS]
            ctp_qx_seq = dedup_consecutive(ctp_qx)
            my_qx_seq = mine_by_slot.get(sid, [])

            if not ctp_qx_seq and not my_qx_seq:
                continue   # neither crosses QM/QX — uninteresting

            total += 1
            if ctp_qx_seq == my_qx_seq:
                match += 1
                for t in ctp_qx_seq:
                    match_rate_by_ctp_token[t] += 1
            else:
                mine_set = set(my_qx_seq)
                ctp_set = set(ctp_qx_seq)
                missing = ctp_set - mine_set
                extra = mine_set - ctp_set
                if extra:
                    extra_mine += 1
                    if len(examples_extra) < 5:
                        examples_extra.append((sid, row['dep'], row['arr'], ctp_qx_seq, my_qx_seq))
                if missing:
                    missing_mine += 1
                    if len(examples_missing) < 5:
                        examples_missing.append((sid, row['dep'], row['arr'], ctp_qx_seq, my_qx_seq))
                for t in ctp_set - mine_set:
                    miss_rate_by_ctp_token[t] += 1

    print(f'Slots with QM/QX on either side: {total}')
    print(f'  exact match: {match}  ({100*match/total:.1f}%)')
    print(f'  my sim had an extra sector CTP did not plan: {extra_mine}')
    print(f'  CTP planned a sector my sim missed:           {missing_mine}')
    print()
    print('Sector-level match rate (my sim agrees with CTP plan):')
    all_toks = set(match_rate_by_ctp_token) | set(miss_rate_by_ctp_token)
    for t in sorted(all_toks):
        hit = match_rate_by_ctp_token[t]
        miss = miss_rate_by_ctp_token[t]
        ctp_total = hit + miss
        pct = 100 * hit / ctp_total if ctp_total else 0
        print(f'  {t:8s} match {hit:4d}/{ctp_total:4d} = {pct:5.1f}%')
    print()
    print('Examples of CTP-planned-but-my-sim-missed:')
    for e in examples_missing:
        sid, d, a, c, m = e
        print(f'  slot {sid}  {d}->{a}  CTP plan: {c}  my sim: {m}')
    print()
    print('Examples of my-sim-extra-beyond-CTP-plan:')
    for e in examples_extra:
        sid, d, a, c, m = e
        print(f'  slot {sid}  {d}->{a}  CTP plan: {c}  my sim: {m}')


if __name__ == '__main__':
    main()
