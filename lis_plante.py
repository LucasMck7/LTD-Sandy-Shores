"""
Plante de lis (lys « Stargazer ») en ASCII animée — tkinter, aucune dépendance externe.

Lancer :  python lis_plante.py
  - Clic ou barre d'espace : rejouer l'animation
  - Échap : quitter
"""

import math
import random
import time
import tkinter as tk
import tkinter.font as tkfont

# ----------------------------------------------------------------------------
# Réglages
# ----------------------------------------------------------------------------
W, H = 760, 880       # taille de la fenêtre
FONT_SIZE = 7         # plus petit = plus de détails
RAMP = ".:-=+*o#%@"   # du plus sombre au plus clair
BG = "#000000"
SEED = 7              # change la valeur pour d'autres mouchetures / trajectoires

D2R = math.pi / 180
LIGHT = (-0.45, -0.70, 0.75)   # lumière venant d'en haut à gauche


# ----------------------------------------------------------------------------
# Géométrie : chaque élément (tige, feuille, pétale, étamine…) est une « bande »
# définie par une ligne centrale et une demi-largeur le long de cette ligne.
# Repère : x de -1 à 1, y vers le bas (bouton en haut vers y=-1, pied en y=1).
# ----------------------------------------------------------------------------
def bezier(p0, p1, p2, p3, n=30):
    pts = []
    for i in range(n + 1):
        t = i / n
        u = 1 - t
        pts.append((u * u * u * p0[0] + 3 * u * u * t * p1[0] + 3 * u * t * t * p2[0] + t * t * t * p3[0],
                    u * u * u * p0[1] + 3 * u * u * t * p1[1] + 3 * u * t * t * p2[1] + t * t * t * p3[1]))
    return pts


def heading_path(x, y, ang, length, bend=0.0, recurve=0.0, n=28):
    """Ligne qui part de (x, y) dans la direction `ang` (degrés) et se courbe."""
    pts = [(x, y)]
    for i in range(1, n + 1):
        s = i / n
        a = (ang + bend * s + recurve * max(0.0, (s - 0.55) / 0.45) ** 2) * D2R
        x += math.cos(a) * length / n
        y += math.sin(a) * length / n
        pts.append((x, y))
    return pts


def petal_widths(n, w, seed):
    out = []
    for i in range(n + 1):
        s = i / n
        base = 0.14 * (1 - s) + math.sin(math.pi * s ** 0.8) ** 0.85
        ruffle = 1 + 0.06 * math.sin(s * 26 + seed)            # bord légèrement ondulé
        out.append(w * base * ruffle * (1 - s ** 8))
    return out


def leaf_widths(n, w):
    return [w * (0.08 * (1 - i / n) + math.sin(math.pi * (i / n) ** 0.7) ** 0.9) * (1 - (i / n) ** 6)
            for i in range(n + 1)]


def linear_widths(n, w0, w1):
    return [w0 + (w1 - w0) * i / n for i in range(n + 1)]


class Strip:
    def __init__(self, pts, widths, kind, birth, grow, dark=1.0, seed=0):
        self.pts, self.w = pts, widths
        self.n = len(pts) - 1
        self.kind, self.birth, self.grow = kind, birth, grow
        self.dark, self.seed = dark, seed
        pad = max(widths)
        xs = [p[0] for p in pts]
        ys = [p[1] for p in pts]
        self.box = (min(xs) - pad, min(ys) - pad, max(xs) + pad, max(ys) + pad)

    def locate(self, x, y):
        """Renvoie (s, v, px, py) si le point est dans la bande : s = 0→1 le long,
        v = -1→1 en travers, (px, py) = direction perpendiculaire."""
        x0, y0, x1, y1 = self.box
        if not (x0 <= x <= x1 and y0 <= y <= y1):
            return None
        best = None
        pts = self.pts
        for i in range(self.n):
            ax, ay = pts[i]
            bx, by = pts[i + 1]
            dx, dy = bx - ax, by - ay
            L = dx * dx + dy * dy
            if L == 0:
                continue
            t = max(0.0, min(1.0, ((x - ax) * dx + (y - ay) * dy) / L))
            qx, qy = ax + t * dx - x, ay + t * dy - y
            d2 = qx * qx + qy * qy
            if best is None or d2 < best[0]:
                best = (d2, i, t, dx, dy, L, ax, ay)
        if best is None:
            return None
        d2, i, t, dx, dy, L, ax, ay = best
        hw = self.w[i] + (self.w[i + 1] - self.w[i]) * t
        d = math.sqrt(d2)
        if hw <= 0 or d > hw:
            return None
        ln = math.sqrt(L)
        px, py = -dy / ln, dx / ln
        side = (x - ax) * px + (y - ay) * py
        v = math.copysign(d / hw, side) if d > 0 else 0.0
        return (i + t) / self.n, v, px, py


# ----------------------------------------------------------------------------
# La plante
# ----------------------------------------------------------------------------
def flower(strips, cx, cy, petals, birth, stamen_dirs, stamen_len, seed):
    """petals : liste de (angle, longueur, demi-largeur, courbure, retroussement)."""
    # pétales extérieurs (derrière, plus sombres) puis intérieurs
    order = [k for k in range(len(petals)) if k % 2 == 0] + [k for k in range(len(petals)) if k % 2 == 1]
    for rank, k in enumerate(order):
        ang, L, w, bend, rec = petals[k]
        pts = heading_path(cx, cy, ang, L, bend, rec)
        strips.append(Strip(pts, petal_widths(len(pts) - 1, w, seed + k), "petal",
                            birth + rank * 0.07, 1.0, dark=0.82 if k % 2 == 0 else 1.0, seed=seed + k))
    # pistil
    a0 = sum(stamen_dirs) / len(stamen_dirs)
    pts = heading_path(cx, cy, a0, stamen_len * 1.08, 8, 0, n=12)
    strips.append(Strip(pts, linear_widths(12, 0.014, 0.012), "pistil", birth + 0.9, 0.4))
    tip = pts[-1]
    strips.append(Strip(heading_path(tip[0], tip[1], a0, 0.03, 0, 0, n=4), [0.022] * 5, "stigma", birth + 1.3, 0.1))
    # étamines : filaments + anthères orange
    for j, a in enumerate(stamen_dirs):
        L = stamen_len * (0.88 + 0.12 * math.sin(j * 2.3 + seed))
        pts = heading_path(cx, cy, a, L, 12 if j % 2 else -10, 0, n=12)
        strips.append(Strip(pts, linear_widths(12, 0.013, 0.010), "filament", birth + 0.8 + j * 0.04, 0.5))
        tx, ty = pts[-1]
        anth = heading_path(tx - math.cos((a + 90) * D2R) * 0.022, ty - math.sin((a + 90) * D2R) * 0.022,
                            a + 90, 0.045, 0, 0, n=6)
        strips.append(Strip(anth, [0.012, 0.018, 0.021, 0.021, 0.018, 0.013, 0.008], "anther",
                            birth + 1.3 + j * 0.04, 0.15))


def build_plant():
    strips = []

    # tiges (base → haut)
    main = bezier((0.07, 1.00), (-0.06, 0.70), (-0.10, 0.10), (-0.16, -0.60))
    strips.append(Strip(main, linear_widths(len(main) - 1, 0.024, 0.014), "stem", 0.0, 1.9))
    br_r = bezier((-0.08, 0.20), (0.02, 0.00), (0.18, -0.20), (0.33, -0.38))
    strips.append(Strip(br_r, linear_widths(len(br_r) - 1, 0.016, 0.012), "stem", 1.2, 0.8))
    br_l = bezier((-0.07, 0.40), (-0.12, 0.28), (-0.28, 0.16), (-0.44, 0.05))
    strips.append(Strip(br_l, linear_widths(len(br_l) - 1, 0.015, 0.012), "stem", 0.9, 0.7))

    # feuilles : (base, angle, longueur, demi-largeur, courbure, naissance)
    leaves = [
        ((-0.06, 0.78), -72, 0.32, 0.050, 14, 0.25),
        ((-0.05, 0.56), 166, 0.56, 0.100, -24, 0.45),
        ((-0.02, 0.27), -8, 0.52, 0.082, 18, 0.85),
        ((-0.10, 0.12), -112, 0.24, 0.048, 22, 1.05),
        ((-0.12, -0.30), 198, 0.46, 0.112, 26, 1.40),
    ]
    for (bx, by), ang, L, w, bend, birth in leaves:
        pts = heading_path(bx, by, ang, L, bend, 0)
        strips.append(Strip(pts, leaf_widths(len(pts) - 1, w), "leaf", birth, 0.9))

    # bouton floral au sommet
    bud = heading_path(-0.16, -0.60, -128, 0.48, -6, 0)
    strips.append(Strip(bud, [0.115 * (0.45 + 0.75 * math.sin(math.pi * (i / 28) ** 0.65)) * (1 - (i / 28) ** 5)
                              for i in range(29)], "bud", 1.85, 0.9))
    # sépales verts à la base du bouton
    for a in (-150, -106):
        pts = heading_path(-0.16, -0.60, a, 0.09, 0, 0, n=8)
        strips.append(Strip(pts, leaf_widths(8, 0.03), "leaf", 1.8, 0.2))

    # grande fleur de droite (vue de face)
    flower(strips, 0.33, -0.38, [
        (-133, 0.48, 0.128, 14, 30),
        (-76, 0.42, 0.120, 18, 34),
        (4, 0.48, 0.130, -16, -30),
        (38, 0.56, 0.138, 12, 36),
        (96, 0.48, 0.126, -14, -28),
        (180, 0.44, 0.124, 16, 30),
    ], 2.3, [-82, -66, -52, -38, -24, -10], 0.30, seed=SEED)

    # petite fleur de gauche (de trois quarts)
    flower(strips, -0.44, 0.04, [
        (-100, 0.36, 0.105, -16, -28),
        (-38, 0.33, 0.100, 14, 32),
        (24, 0.30, 0.095, -10, 26),
        (100, 0.34, 0.104, 14, 30),
        (152, 0.42, 0.112, -16, -32),
        (-148, 0.38, 0.106, 12, 26),
    ], 2.7, [-200, -186, -172, -158, -144], 0.24, seed=SEED + 20)

    return strips


# ----------------------------------------------------------------------------
# Couleurs de chaque partie
# ----------------------------------------------------------------------------
def smooth(a, b, x):
    t = max(0.0, min(1.0, (x - a) / (b - a)))
    return t * t * (3 - 2 * t)


def mix(c1, c2, f):
    return tuple(c1[i] + (c2[i] - c1[i]) * f for i in range(3))


def cell_hash(r, c, k=0):
    h = (r * 73856093) ^ (c * 19349663) ^ (k * 83492791)
    h = (h ^ (h >> 13)) * 1274126177
    return ((h ^ (h >> 16)) & 0xFFFF) / 65535


def base_color(kind, s, v, r, c, seed):
    av = abs(v)
    if kind == "petal":
        white = (250, 240, 238)
        pink = (214, 42, 112)
        throat = (205, 200, 110)
        p = math.exp(-(v / 0.42) ** 2) * smooth(0.04, 0.26, s) * (1 - 0.85 * smooth(0.55, 0.95, s))
        col = mix(white, pink, min(1.0, p * 1.1))
        if av > 0.86:                                                # liseré rose foncé
            col = mix(col, (150, 40, 90), 0.55)
        col = mix(col, throat, 1 - smooth(0.02, 0.16, s))          # gorge jaune-vert
        if p > 0.35 and cell_hash(r, c, seed) < 0.10:                # mouchetures
            col = (120, 14, 52)
        return col
    if kind == "leaf":
        dark, light = (38, 92, 30), (122, 168, 68)
        col = mix(dark, light, 0.35 + 0.45 * (1 - av) * (0.6 + 0.4 * math.sin(s * math.pi)))
        if av < 0.10 and s < 0.92:                                   # nervure centrale
            col = mix(col, (190, 205, 120), 0.6)
        return col
    if kind == "stem":
        return mix((92, 70, 40), (88, 118, 48), smooth(0.0, 0.5, s) if s < 1 else 1)
    if kind == "bud":
        col = mix((176, 196, 104), (240, 214, 196), smooth(0.05, 0.45, s))
        col = mix(col, (216, 96, 132), smooth(0.55, 0.95, s) * (0.5 + 0.5 * math.exp(-(v / 0.5) ** 2)))
        if 0.3 < s < 0.95 and abs(math.sin(v * 7.5)) < 0.18:      # nervures du bouton
            col = mix(col, (200, 70, 120), 0.5)
        return col
    if kind == "filament":
        return (178, 196, 92)
    if kind == "pistil":
        return (196, 210, 120)
    if kind == "stigma":
        return (140, 60, 90)
    if kind == "anther":
        return (232, 108, 34)
    return (200, 200, 200)


def shade(kind, v, px, py, dark):
    k = 0.35 if kind in ("filament", "pistil", "anther", "stigma") else 1.1
    nz = math.sqrt(max(0.04, 1 - v * v))
    nx, ny = v * px * k, v * py * k
    nn = math.sqrt(nx * nx + ny * ny + nz * nz)
    lx, ly, lz = LIGHT
    ln = math.sqrt(lx * lx + ly * ly + lz * lz)
    lam = max(0.0, (nx * lx + ny * ly + nz * lz) / (nn * ln))
    sh = (0.45 + 0.70 * lam) * dark
    if kind in ("leaf", "bud") and abs(v) > 0.80:                    # contour
        sh *= 0.5
    if kind == "petal" and abs(v) > 0.92:                            # sépare les pétales
        sh *= 0.4
    if kind == "leaf" and v < 0:                                      # feuille pliée
        sh *= 0.86
    return sh


# ----------------------------------------------------------------------------
# Application
# ----------------------------------------------------------------------------
def to_hex(rgb):
    return "#%02x%02x%02x" % tuple(max(0, min(255, int(x))) for x in rgb)


def lum(rgb):
    # intensité perçue pour choisir le caractère : surtout la composante la plus forte
    return (0.7 * max(rgb) + 0.3 * (0.30 * rgb[0] + 0.59 * rgb[1] + 0.11 * rgb[2])) / 255


def blend_hex(bg, rgb, f):
    b = (int(bg[1:3], 16), int(bg[3:5], 16), int(bg[5:7], 16))
    return to_hex(mix(b, rgb, f))


class LisPlante:
    def __init__(self, root):
        self.root = root
        self.rng = random.Random(SEED)
        root.title("Lis")
        root.configure(bg=BG)
        self.canvas = tk.Canvas(root, width=W, height=H, bg=BG, highlightthickness=0)
        self.canvas.pack()

        families = set(tkfont.families())
        family = next((f for f in ("Consolas", "Menlo", "DejaVu Sans Mono", "Courier New")
                       if f in families), "Courier")
        self.font = tkfont.Font(family=family, size=FONT_SIZE, weight="bold")
        self.cw = self.font.measure("@")
        self.ch = max(self.cw + 2, round(self.font.metrics("linespace") * 0.8))

        self.build_cells()
        root.bind("<Button-1>", lambda e: self.restart())
        root.bind("<space>", lambda e: self.restart())
        root.bind("<Escape>", lambda e: root.destroy())
        self.restart()

    def build_cells(self):
        strips = build_plant()
        cols, rows = W // self.cw, H // self.ch
        ox = (W - cols * self.cw) / 2
        oy = (H - rows * self.ch) / 2
        scale = min(W * 0.94 / 1.8, H * 0.94 / 2.04)
        self.cells = []
        self.occupied = set()
        for r in range(rows):
            for c in range(cols):
                px = ox + c * self.cw + self.cw / 2
                py = oy + r * self.ch + self.ch / 2
                x = (px - W / 2) / scale + 0.02
                y = (py - H / 2) / scale + 0.01
                for st in reversed(strips):                      # le dernier ajouté est devant
                    hit = st.locate(x, y)
                    if hit is None:
                        continue
                    s, v, nx, ny = hit
                    rgb = base_color(st.kind, s, v, r, c, st.seed)
                    sh = shade(st.kind, v, nx, ny, st.dark)
                    rgb = tuple(min(255.0, x_ * sh) for x_ in rgb)
                    self.cells.append({"x": px, "y": py, "rgb": rgb, "lum": lum(rgb),
                                       "t": st.birth + s * st.grow, "kind": st.kind})
                    self.occupied.add((r, c))
                    break
        self.rows, self.cols, self.ox, self.oy = rows, cols, ox, oy

    def char_for(self, b):
        b = max(0.0, min(0.999, b ** 0.85))
        return RAMP[int(b * len(RAMP))]

    def restart(self):
        if getattr(self, "job", None):
            self.root.after_cancel(self.job)
        self.canvas.delete("all")
        rng = self.rng

        self.dust = []
        for _ in range(120):
            r, c = rng.randrange(self.rows), rng.randrange(self.cols)
            if (r, c) in self.occupied:
                continue
            it = self.canvas.create_text(self.ox + c * self.cw + self.cw / 2, self.oy + r * self.ch + self.ch / 2,
                                         text=rng.choice(".·:"), fill=BG, font=self.font)
            self.dust.append([it, rng.random() * 6.28, 0.4 + rng.random() * 1.2, -1])

        # chaque caractère arrive d'un point dispersé ; la plante pousse depuis le pied
        self.parts = []
        for cell in self.cells:
            delay = cell["t"] + rng.random() * 0.45
            dur = 0.6 + rng.random() * 0.6
            ang = rng.random() * 2 * math.pi
            rad = 20 + rng.random() * 110
            sx = cell["x"] + math.cos(ang) * rad
            sy = cell["y"] + math.sin(ang) * rad * 0.8 - 30
            it = self.canvas.create_text(sx, sy, text=".", fill=BG, font=self.font)
            self.parts.append({**cell, "it": it, "sx": sx, "sy": sy, "delay": delay, "dur": dur,
                               "state": None, "landed": False})
        self.build_end = max(p["delay"] + p["dur"] for p in self.parts)
        self.t0 = time.perf_counter()
        self.tick()

    def tick(self):
        start = time.perf_counter()
        t = start - self.t0
        cv = self.canvas

        sweep = None
        if t > self.build_end + 0.5:
            phase = ((t - self.build_end - 0.5) % 6.0) / 6.0
            sweep = -250 + phase * (W + H + 500)

        for p in self.parts:
            local = t - p["delay"]
            if local < 0:
                continue
            if local < p["dur"]:
                f = local / p["dur"]
                e = 1 - (1 - f) ** 3
                cv.coords(p["it"], p["sx"] + (p["x"] - p["sx"]) * e, p["sy"] + (p["y"] - p["sy"]) * e)
                ch = "." if f < 0.45 else (":" if f < 0.75 else self.char_for(p["lum"]))
                state = (ch, blend_hex(BG, p["rgb"], min(1.0, 0.2 + f)))
            else:
                if not p["landed"]:
                    cv.coords(p["it"], p["x"], p["y"])
                    p["landed"] = True
                boost = 1.0 + 0.6 * max(0.0, 1 - (local - p["dur"]) / 0.3)   # éclat à l'arrivée
                if sweep is not None:
                    d = abs((p["x"] + p["y"]) - sweep)
                    if d < 80:
                        boost += 0.35 * (1 - d / 80)
                boost = round(boost * 10) / 10
                if p["kind"] == "petal" and self.rng.random() < 0.0004:      # reflets
                    state = ("*", "#ffffff")
                else:
                    state = (self.char_for(p["lum"] * boost),
                             to_hex(tuple(x * boost for x in p["rgb"])))
            if state != p["state"]:
                cv.itemconfigure(p["it"], text=state[0], fill=state[1])
                p["state"] = state

        for d in self.dust:
            v = round(8 * (0.5 + 0.5 * math.sin(t * d[2] + d[1])) * min(1.0, t / 2)) / 8
            if v != d[3]:
                d[3] = v
                cv.itemconfigure(d[0], fill=blend_hex(BG, (40, 52, 30), v))

        spent = time.perf_counter() - start
        self.job = self.root.after(max(1, int(1000 / 30 - spent * 1000)), self.tick)


if __name__ == "__main__":
    root = tk.Tk()
    root.resizable(False, False)
    LisPlante(root)
    root.mainloop()
