#!/usr/bin/env python3
"""Erzeugt echte Höhenlinien (Contour-SVG) für das Verbandsgebiet Wildenstein
aus offenen Terrain-Kacheln (AWS terrarium DEM). Nur Python-Standardbibliothek.
"""
import urllib.request, zlib, struct, math, sys

Z = 12
# Basis-BBox = eigentliches Verbandsgebiet (Grünhainichen + Börnichen)
_B_LON_MIN, _B_LON_MAX = 13.0864, 13.2408
_B_LAT_MIN, _B_LAT_MAX = 50.6931, 50.7924
# EXPAND > 1 = "weiter rauszoomen": größerer Kartenausschnitt → kleinere/feinere
# Höhenlinien (mehr davon in gleicher Fläche). 1.0 = exakt das Verbandsgebiet.
EXPAND = 2.5
_clon = (_B_LON_MIN + _B_LON_MAX) / 2
_clat = (_B_LAT_MIN + _B_LAT_MAX) / 2
_dlon = (_B_LON_MAX - _B_LON_MIN) / 2 * EXPAND
_dlat = (_B_LAT_MAX - _B_LAT_MIN) / 2 * EXPAND
LON_MIN, LON_MAX = _clon - _dlon, _clon + _dlon
LAT_MIN, LAT_MAX = _clat - _dlat, _clat + _dlat
INTERVAL = 25.0            # Höhenlinien-Abstand in Metern
DOWNSAMPLE = 4            # Raster verkleinern (Glättung + kleinere Datei)
SMOOTH_PASSES = 2
OUT = sys.argv[1] if len(sys.argv) > 1 else "verband-topo.svg"
STROKE = "#13267b"
UA = "vv-wildenstein-topo/1.0 (stefan@gumu-agentur.de)"
TILE_URL = "https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png"

def deg_to_globalpx(lon, lat, z):
    n = 2 ** z
    x = (lon + 180.0) / 360.0 * n * 256.0
    latr = math.radians(lat)
    y = (1.0 - math.asinh(math.tan(latr)) / math.pi) / 2.0 * n * 256.0
    return x, y

def tilexy(lon, lat, z):
    n = 2 ** z
    xt = int((lon + 180.0) / 360.0 * n)
    latr = math.radians(lat)
    yt = int((1.0 - math.asinh(math.tan(latr)) / math.pi) / 2.0 * n)
    return xt, yt

def png_decode(data):
    assert data[:8] == b"\x89PNG\r\n\x1a\n", "kein PNG"
    pos = 8; w = h = ct = None; idat = bytearray()
    while pos < len(data):
        ln = struct.unpack(">I", data[pos:pos+4])[0]
        typ = data[pos+4:pos+8]; chunk = data[pos+8:pos+8+ln]; pos += 12 + ln
        if typ == b"IHDR":
            w, h, bd, ct = struct.unpack(">IIBB", chunk[:10])
        elif typ == b"IDAT":
            idat += chunk
        elif typ == b"IEND":
            break
    raw = zlib.decompress(bytes(idat))
    ch = 4 if ct == 6 else 3
    stride = w * ch
    out = bytearray(h * stride)
    prev = bytearray(stride)
    p = 0
    for r in range(h):
        f = raw[p]; p += 1
        line = bytearray(raw[p:p+stride]); p += stride
        if f == 1:
            for i in range(ch, stride): line[i] = (line[i] + line[i-ch]) & 255
        elif f == 2:
            for i in range(stride): line[i] = (line[i] + prev[i]) & 255
        elif f == 3:
            for i in range(stride):
                a = line[i-ch] if i >= ch else 0
                line[i] = (line[i] + ((a + prev[i]) >> 1)) & 255
        elif f == 4:
            for i in range(stride):
                a = line[i-ch] if i >= ch else 0
                b = prev[i]; c = prev[i-ch] if i >= ch else 0
                pp = a + b - c; pa = abs(pp-a); pb = abs(pp-b); pc = abs(pp-c)
                pr = a if (pa <= pb and pa <= pc) else (b if pb <= pc else c)
                line[i] = (line[i] + pr) & 255
        out[r*stride:(r+1)*stride] = line
        prev = line
    return w, h, ch, out

# --- Kacheln bestimmen + laden ---
xa, ya = tilexy(LON_MIN, LAT_MAX, Z)
xb, yb = tilexy(LON_MAX, LAT_MIN, Z)
xa, xb = min(xa, xb), max(xa, xb)
ya, yb = min(ya, yb), max(ya, yb)
ntx, nty = xb - xa + 1, yb - ya + 1
W, H = ntx * 256, nty * 256
print(f"Kacheln {ntx}x{nty} (z{Z}), Mosaik {W}x{H}px", file=sys.stderr)

elev = [[0.0] * W for _ in range(H)]
for ty in range(ya, yb + 1):
    for tx in range(xa, xb + 1):
        url = TILE_URL.format(z=Z, x=tx, y=ty)
        req = urllib.request.Request(url, headers={"User-Agent": UA})
        data = urllib.request.urlopen(req, timeout=30).read()
        w, h, ch, px = png_decode(data)
        ox, oy = (tx - xa) * 256, (ty - ya) * 256
        for r in range(h):
            base = r * w * ch
            row = elev[oy + r]
            for cx in range(w):
                i = base + cx * ch
                R, G, B = px[i], px[i+1], px[i+2]
                row[ox + cx] = (R * 256 + G + B / 256.0) - 32768.0
        print(f"  geladen z{Z}/{tx}/{ty}", file=sys.stderr)

# --- Downsample (Mittelwert) ---
def downsample(grid, f):
    if f <= 1: return grid, len(grid[0]), len(grid)
    hh, ww = len(grid), len(grid[0])
    nh, nw = hh // f, ww // f
    out = [[0.0] * nw for _ in range(nh)]
    inv = 1.0 / (f * f)
    for r in range(nh):
        for c in range(nw):
            s = 0.0
            for dr in range(f):
                gr = grid[r*f+dr]
                for dc in range(f):
                    s += gr[c*f+dc]
            out[r][c] = s * inv
    return out, nw, nh

grid, GW, GH = downsample(elev, DOWNSAMPLE)

# --- Glätten (3x3) ---
def smooth(g):
    hh, ww = len(g), len(g[0])
    out = [row[:] for row in g]
    for r in range(1, hh-1):
        for c in range(1, ww-1):
            out[r][c] = (g[r-1][c-1]+g[r-1][c]+g[r-1][c+1]
                        + g[r][c-1]+g[r][c]+g[r][c+1]
                        + g[r+1][c-1]+g[r+1][c]+g[r+1][c+1]) / 9.0
    return out
for _ in range(SMOOTH_PASSES):
    grid = smooth(grid)

# --- Crop auf exakte BBox (in Downsample-Pixeln) ---
gx0, gy0 = xa * 256, ya * 256
pxl, pyt = deg_to_globalpx(LON_MIN, LAT_MAX, Z)
pxr, pyb = deg_to_globalpx(LON_MAX, LAT_MIN, Z)
cxa = max(0, int((pxl - gx0) / DOWNSAMPLE))
cya = max(0, int((pyt - gy0) / DOWNSAMPLE))
cxb = min(GW - 1, int((pxr - gx0) / DOWNSAMPLE))
cyb = min(GH - 1, int((pyb - gy0) / DOWNSAMPLE))
CW, CH = cxb - cxa, cyb - cya
print(f"Crop {CW}x{CH} @ ({cxa},{cya})", file=sys.stderr)

emin = min(min(r[cxa:cxb+1]) for r in grid[cya:cyb+1])
emax = max(max(r[cxa:cxb+1]) for r in grid[cya:cyb+1])
print(f"Höhe {emin:.0f}–{emax:.0f} m", file=sys.stderr)

# --- Marching Squares ---
def interp(l, ax, ay, av, bx, by, bv):
    t = 0.5 if bv == av else (l - av) / (bv - av)
    return (ax + t * (bx - ax), ay + t * (by - ay))

# Kanten pro Case: 0=oben,1=rechts,2=unten,3=links
TBL = {1:[(3,0)],2:[(0,1)],3:[(3,1)],4:[(1,2)],5:[(3,0),(1,2)],6:[(0,2)],
       7:[(3,2)],8:[(2,3)],9:[(2,0)],10:[(0,1),(2,3)],11:[(2,1)],12:[(1,3)],
       13:[(1,0)],14:[(0,3)]}

levels = []
lv = math.ceil(emin / INTERVAL) * INTERVAL
while lv < emax:
    levels.append(lv); lv += INTERVAL

def rnd(v): return round(v, 1)

paths_by_level = []
for lv in levels:
    segs = {}   # start-point -> list of end-points  (für Stitching)
    adj = {}
    def key(p): return (round(p[0], 1), round(p[1], 1))
    seglist = []
    for r in range(cya, cyb):
        g0 = grid[r]; g1 = grid[r+1]
        for c in range(cxa, cxb):
            vTL = g0[c]; vTR = g0[c+1]; vBR = g1[c+1]; vBL = g1[c]
            idx = (1 if vTL >= lv else 0) | (2 if vTR >= lv else 0) | (4 if vBR >= lv else 0) | (8 if vBL >= lv else 0)
            if idx == 0 or idx == 15: continue
            # Ecken (in Crop-Koordinaten)
            x = c - cxa; y = r - cya
            def edge(e):
                if e == 0: return interp(lv, x, y, vTL, x+1, y, vTR)
                if e == 1: return interp(lv, x+1, y, vTR, x+1, y+1, vBR)
                if e == 2: return interp(lv, x+1, y+1, vBR, x, y+1, vBL)
                return interp(lv, x, y+1, vBL, x, y, vTL)
            for a, b in TBL[idx]:
                pa = edge(a); pb = edge(b)
                seglist.append((pa, pb))
    # Stitching zu Polylinien
    from_pt = {}
    for pa, pb in seglist:
        from_pt.setdefault(key(pa), []).append((pa, pb))
    used = [False] * len(seglist)
    # baue Nachbarschaft über Endpunkt-Keys
    startmap = {}
    for i, (pa, pb) in enumerate(seglist):
        startmap.setdefault(key(pa), []).append(i)
    polylines = []
    consumed = set()
    endmap = {}
    for i, (pa, pb) in enumerate(seglist):
        endmap.setdefault(key(pb), []).append(i)
    for i in range(len(seglist)):
        if i in consumed: continue
        pa, pb = seglist[i]
        consumed.add(i)
        poly = [pa, pb]
        # vorwärts verlängern
        cur = key(pb)
        while True:
            nxt = None
            for j in startmap.get(cur, []):
                if j not in consumed:
                    nxt = j; break
            if nxt is None: break
            consumed.add(nxt)
            poly.append(seglist[nxt][1])
            cur = key(seglist[nxt][1])
            if cur == key(poly[0]): break
        polylines.append(poly)
    paths_by_level.append((lv, polylines))

# --- SVG schreiben ---
def fmt(p): return f"{p[0]:.1f} {p[1]:.1f}"
parts = []
parts.append(f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {CW} {CH}" preserveAspectRatio="xMidYMid slice">')
parts.append(f'<g fill="none" stroke="{STROKE}" stroke-width="0.6" stroke-opacity="0.13" stroke-linejoin="round" stroke-linecap="round">')
total_pts = 0
for lv, polylines in paths_by_level:
    d = []
    for poly in polylines:
        if len(poly) < 2: continue
        total_pts += len(poly)
        d.append("M" + fmt(poly[0]) + "".join("L" + fmt(p) for p in poly[1:]))
    if d:
        parts.append(f'<path d="{"".join(d)}"/>')
parts.append('</g></svg>')
svg = "".join(parts)
open(OUT, "w").write(svg)
print(f"Levels {len(levels)}, Punkte {total_pts}, SVG {len(svg)//1024} KB -> {OUT}", file=sys.stderr)
