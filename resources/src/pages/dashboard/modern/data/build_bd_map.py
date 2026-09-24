#!/usr/bin/env python3
"""
Builds bd-districts-map.json for the Modern Dashboard "Sales map" (Bangladesh district heat map).

Source data (not committed, fetch with:  npm pack bd-geojson@1.0.5  and unpack):
  - src/data/bangladesh.geojson   boundaries  (c) geoBoundaries (geoboundaries.org), CC BY 4.0 (BBS / OCHA ROAP)
  - src/data/bd-districts.json    64 districts (English + Bangla name, division, centre)
  - src/data/bd-upazilas.json     upazila -> district
  - src/data/dhaka-city.json      Dhaka city areas -> district
Usage:  python3 build_bd_map.py /path/to/unpacked/package/src/data  bd-districts-map.json

What it does: joins the 544 upazila polygons into the 64 district shapes (an upazila the source did not label is
given to the district it was matched to by name, otherwise to the district it shares the longest border with),
simplifies them, projects to a flat SVG, and writes an ALIAS table: normalised place text -> district id. A text
maps only when it is unambiguous (a district, a unique upazila or a unique Dhaka area name, in English or Bangla).
Nothing is fuzzy-matched: an unknown text stays unmatched and the page says so.
"""
import json, math, re, sys, unicodedata
from shapely.geometry import shape, mapping
from shapely.ops import unary_union

src, out = sys.argv[1], sys.argv[2]
load = lambda n: json.load(open(f'{src}/{n}'))
geo, dist, upa, dhk = load('bangladesh.geojson'), load('bd-districts.json')['districts'], load('bd-upazilas.json')['upazilas'], load('dhaka-city.json')['dhaka']


def norm(s):
    s = unicodedata.normalize('NFC', str(s or '')).lower()
    return ''.join(ch for ch in s if unicodedata.category(ch)[0] in 'LM')


divs = {d['id']: d for d in load('bd-divisions.json')['divisions']} if 'divisions' in load('bd-divisions.json') else {}
name_to_ids = {}
for x in upa + dhk:
    name_to_ids.setdefault(norm(x['name']), set()).add(x['district_id'])

feats = []
for f in geo['features']:
    p = f['properties']
    did = p.get('district_id') or None
    if not did:
        ids = name_to_ids.get(norm(p['name']))
        did = next(iter(ids)) if ids and len(ids) == 1 else None
    feats.append([did, shape(f['geometry']).buffer(0), p])

by = {}
for did, geom, _ in feats:
    if did:
        by.setdefault(did, []).append(geom)
union = {d: unary_union(g) for d, g in by.items()}
for i, (did, geom, p) in enumerate(feats):
    if did:
        continue
    best, bl = None, 0
    for d, u in union.items():
        l = geom.buffer(0.0005).intersection(u).length
        if l > bl:
            best, bl = d, l
    if best is None:  # no shared border (a small city area): nearest district
        best = min(union, key=lambda d: geom.distance(union[d]))
    feats[i][0] = best
by = {}
for did, geom, _ in feats:
    if did:
        by.setdefault(did, []).append(geom)
union = {d: unary_union(g).buffer(0.0008).buffer(-0.0008) for d, g in by.items()}

lon0, lat1 = 88.0, 26.65
latmid = 23.6
kx = math.cos(math.radians(latmid)) * 100
P = lambda lon, lat: ((lon - lon0) * kx, (lat1 - lat) * 100)
W, H = P(92.7, 20.55)[0], P(88.0, 20.55)[1]


def path(geom, tol=0.006):
    g = geom.simplify(tol, preserve_topology=True)
    polys = [g] if g.geom_type == 'Polygon' else list(g.geoms)
    parts = []
    for poly in polys:
        if poly.area < 1e-5:
            continue
        for ring in [poly.exterior] + list(poly.interiors):
            pts = [P(x, y) for x, y in ring.coords]
            parts.append('M' + 'L'.join(f'{x:.1f} {y:.1f}' for x, y in pts[:-1]) + 'Z')
    return ''.join(parts)


districts = []
for d in dist:
    u = union.get(d['id'])
    if u is None:
        print('NO SHAPE', d['name']); continue
    c = P(float(d['long']), float(d['lat']))
    districts.append({'id': int(d['id']), 'name': d['name'], 'bn': d['bn_name'], 'division_id': int(d['division_id']), 'cx': round(c[0], 1), 'cy': round(c[1], 1), 'd': path(u)})

alias = {}
def put(text, did):
    k = norm(text)
    if not k:
        return
    if k in alias and alias[k] != did:
        alias[k] = -1  # ambiguous: never used
    else:
        alias[k] = did
for d in dist:
    put(d['name'], int(d['id'])); put(d['bn_name'], int(d['id']))
# common alternative spellings of district names (the official names changed spelling over the years)
for a, did in {'chittagong': 'Chattogram', 'comilla': 'Cumilla', 'barisal': 'Barishal', 'bogra': 'Bogura', 'jessore': 'Jashore', 'narayangonj': 'Narayanganj', 'coxsbazar': "Cox's Bazar",
               'coxbazar': "Cox's Bazar", 'jhalakathi': 'Jhalokati', 'jhalakati': 'Jhalokati', 'moulvibazar': 'Maulvibazar', 'chapainawabganj': 'Nawabganj', 'nawabgonj': 'Nawabganj', 'netrakona': 'Netrokona',
               'sirajganj': 'Sirajgonj', 'munshigonj': 'Munshiganj', 'kishorganj': 'Kishoreganj', 'kishoregonj': 'Kishoreganj', 'gaibanda': 'Gaibandha', 'joypurhat': 'Joypurhat', 'jaipurhat': 'Joypurhat',
               'khagrachhari': 'Khagrachari', 'khagrachhori': 'Khagrachari', 'lakshmipur': 'Lakshmipur', 'laxmipur': 'Lakshmipur', 'nilphamari': 'Nilphamari', 'thakurgoan': 'Thakurgaon', 'panchagor': 'Panchagarh',
               'brahmanbaria': 'Brahmanbaria', 'bbaria': 'Brahmanbaria', 'gopalgonj': 'Gopalganj', 'habigonj': 'Habiganj', 'narsingdhi': 'Narsingdi', 'narshingdi': 'Narsingdi', 'sunamgonj': 'Sunamganj',
               'patuakhali': 'Patuakhali', 'mymensingh': 'Mymensingh', 'dhakacity': 'Dhaka'}.items():
    hit = [x for x in dist if x['name'] == did]
    if hit:
        alias.setdefault(a, int(hit[0]['id']))
for x in upa + dhk:
    put(x['name'], int(x['district_id'])); put(x['bn_name'], int(x['district_id']))
# a district's own name always wins over an upazila that shares the name (e.g. "Khulna" the district)
for d in dist:
    alias[norm(d['name'])] = int(d['id']); alias[norm(d['bn_name'])] = int(d['id'])
alias = {k: v for k, v in alias.items() if v != -1}

res = {'viewBox': [round(W, 1), round(H, 1)], 'districts': districts, 'aliases': alias,
       'attribution': 'Boundaries (c) geoBoundaries (geoboundaries.org), CC BY 4.0; BBS / OCHA ROAP. Place names: bd-geojson (MIT).'}
json.dump(res, open(out, 'w'), ensure_ascii=False, separators=(',', ':'))
import os
print('districts', len(districts), 'aliases', len(alias), 'bytes', os.path.getsize(out), 'viewBox', res['viewBox'])
