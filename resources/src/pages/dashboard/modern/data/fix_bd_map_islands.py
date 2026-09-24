#!/usr/bin/env python3
"""
Post-processing for bd-districts-map.json (run after build_bd_map.py, or on the committed file: it is idempotent).

Why: the source data has some islands / river chars with no district label, and the join in build_bd_map.py attached a few of
them to a district on the far side of the country (e.g. islands in the Meghna estuary painted as Sylhet or Dhaka), so those
districts lit up in two places. Rule here: a piece of a district that lies more than FAR map units (about 1 map unit = 1 km)
from the district's main body is handed to the district whose main body is nearest to it. Nothing else is changed.
Usage: python3 fix_bd_map_islands.py bd-districts-map.json
"""
import json, re, sys, math

FAR = 30.0
path = sys.argv[1]
m = json.load(open(path))
D = {str(d['id']): d for d in m['districts']}


def parse(d):
    parts = []
    for raw in re.split(r'(?=M)', d):
        if not raw.strip():
            continue
        nums = [float(x) for x in re.findall(r'-?\d+\.?\d*', raw)]
        pts = list(zip(nums[0::2], nums[1::2]))
        if len(pts) >= 3:
            parts.append({'raw': raw, 'pts': pts})
    return parts


def area(pts):
    return abs(sum(pts[i][0] * pts[(i + 1) % len(pts)][1] - pts[(i + 1) % len(pts)][0] * pts[i][1] for i in range(len(pts)))) / 2


def dist(a, b):
    best = 1e9
    for x, y in a[::2]:
        for u, v in b[::2]:
            dd = (x - u) ** 2 + (y - v) ** 2
            if dd < best:
                best = dd
    return math.sqrt(best)


parts = {k: parse(v['d']) for k, v in D.items()}
main = {k: max(p, key=lambda s: area(s['pts'])) for k, p in parts.items() if p}
moves = []
for k, plist in parts.items():
    for s in list(plist):
        if s is main[k]:
            continue
        if dist(s['pts'], main[k]['pts']) <= FAR:
            continue
        # nearest other district main body
        cand = sorted(((dist(s['pts'], main[o]['pts']), o) for o in main if o != k))
        dd, o = cand[0]
        moves.append((D[k]['name'], D[o]['name'], round(dd, 1), round(sum(x for x, _ in s['pts']) / len(s['pts'])), round(sum(y for _, y in s['pts']) / len(s['pts']))))
        plist.remove(s)
        parts[o].append(s)
for k, plist in parts.items():
    D[k]['d'] = ''.join(s['raw'] for s in plist)
json.dump(m, open(path, 'w'), ensure_ascii=False, separators=(',', ':'))
for x in moves:
    print('moved a piece from %s to %s (nearest body %s away) at %s,%s' % x)
print(len(moves), 'pieces moved')
