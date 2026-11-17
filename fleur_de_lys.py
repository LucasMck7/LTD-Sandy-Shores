"""
Fleur de lys en ASCII animée (tkinter, aucune dépendance externe).

Lancer :  python fleur_de_lys.py
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
THEME = "or"          # "or" (or sur fond noir) ou "quebec" (blanc sur fond bleu)
W, H = 700, 860       # taille de la fenêtre
FONT_SIZE = 8         # plus petit = plus de détails
X_STRETCH = 0.84      # < 1 = fleur plus élancée
RAMP = ".:-=+*o#%@"   # du plus sombre au plus clair
PROFILE = False       # True = affiche le temps de calcul par image
SEED = None           # mettre un entier pour une animation toujours identique

THEMES = {
    "or": {
        "bg": "#000000",
        "stops": [(0.00, "#2e1a03"), (0.30, "#7a4c08"), (0.55, "#c98d14"),
                  (0.80, "#f2c94c"), (1.00, "#fff7d6")],
        "spark": "#ffffff",
        "dust": "#5a3c0a",
    },
    "quebec": {
        "bg": "#001845",
        "stops": [(0.00, "#0d2a66"), (0.30, "#2f5aa8"), (0.60, "#8eaee6"),
                  (0.85, "#dfe8fb"), (1.00, "#ffffff")],
        "spark": "#ffffff",
        "dust": "#1d3f86",
    },
}

# ----------------------------------------------------------------------------
# Géométrie : la fleur est construite avec des courbes de Bézier.
# Repère : x de -1 à 1, y vers le bas, pointe du pétale central en y = -1.
# ----------------------------------------------------------------------------
def bezier(p0, p1, p2, p3, n=24):
    pts = []
    for i in range(1, n + 1):
        t = i / n
        u = 1 - t
        pts.append((u * u * u * p0[0] + 3 * u * u * t * p1[0] + 3 * u * t * t * p2[0] + t * t * t * p3[0],
                    u * u * u * p0[1] + 3 * u * u * t * p1[1] + 3 * u * t * t * p2[1] + t * t * t * p3[1]))
    return pts


def path(start, segments):
    """Chaque segment est soit (point,) pour une ligne, soit (c1, c2, fin) pour une Bézier."""
    pts = [start]
    cur = start
    for seg in segments:
        if len(seg) == 1:
            pts.append(seg[0])
            cur = seg[0]
        else:
            pts += bezier(cur, *seg)
            cur = seg[2]
    return pts


def mirror(pts):
    return [(-x, y) for x, y in pts]


def build_pieces():
    # Pétale central (moitié droite puis miroir)
    right = path((0.0, -1.0), [
        ((0.10, -0.86), (0.31, -0.62), (0.29, -0.36)),
        ((0.27, -0.14), (0.11, -0.04), (0.10, 0.16)),
    ])
    center = right + list(reversed(mirror(right)))

    # Pétale latéral droit : part du bandeau, monte, s'enroule et retombe en pointe
    side_r = path((0.14, 0.16), [
        ((0.22, -0.10), (0.34, -0.40), (0.56, -0.46)),
        ((0.72, -0.50), (0.82, -0.38), (0.76, -0.22)),
        ((0.73, -0.14), (0.68, -0.08), (0.66, 0.02)),
        ((0.82, -0.02), (0.98, -0.18), (0.98, -0.40)),
        ((0.98, -0.66), (0.78, -0.80), (0.56, -0.76)),
        ((0.32, -0.72), (0.22, -0.46), (0.23, -0.20)),
        ((0.24, 0.00), (0.32, 0.12), (0.46, 0.16)),
    ])

    # Bandeau horizontal
    band = path((-0.50, 0.14), [((0.50, 0.14),), ((0.54, 0.205),), ((0.50, 0.27),),
                                ((-0.50, 0.27),), ((-0.54, 0.205),)])

    # Pied central et petits pétales du bas
    foot = path((-0.10, 0.27), [
        ((-0.06, 0.40), (-0.03, 0.52), (0.0, 0.68)),
        ((0.03, 0.52), (0.06, 0.40), (0.10, 0.27)),
    ])
    low_r = path((0.10, 0.27), [
        ((0.16, 0.44), (0.36, 0.50), (0.56, 0.66)),
        ((0.52, 0.48), (0.48, 0.34), (0.40, 0.27)),
    ])

    # L'ordre compte : les derniers sont dessinés « par-dessus »
    return [mirror(side_r), side_r, center, mirror(low_r), low_r, foot, band]


def point_in_poly(x, y, poly):
    inside = False
    n = len(poly)
    j = n - 1
    for i in range(n):
        xi, yi = poly[i]
        xj, yj = poly[j]
        if (yi > y) != (yj > y) and x < (xj - xi) * (y - yi) / (yj - yi) + xi:
            inside = not inside
        j = i
    return inside


def edge_distance(x, y, poly):
    best = 1e9
    n = len(poly)
    for i in range(n):
        ax, ay = poly[i]
        bx, by = poly[(i + 1) % n]
        dx, dy = bx - ax, by - ay
        L = dx * dx + dy * dy
        t = 0.0 if L == 0 else max(0.0, min(1.0, ((x - ax) * dx + (y - ay) * dy) / L))
        px, py = ax + t * dx - x, ay + t * dy - y
        d = px * px + py * py
        if d < best:
            best = d
    return math.sqrt(best)


def bbox(poly):
    xs = [p[0] for p in poly]
    ys = [p[1] for p in poly]
    return min(xs), min(ys), max(xs), max(ys)


# ----------------------------------------------------------------------------
# Couleurs
# ----------------------------------------------------------------------------
def hex_to_rgb(h):
    return int(h[1:3], 16), int(h[3:5], 16), int(h[5:7], 16)


def make_palette(stops, steps=64):
    rgb_stops = [(t, hex_to_rgb(c)) for t, c in stops]
    pal = []
    for i in range(steps):
        v = i / (steps - 1)
        for k in range(len(rgb_stops) - 1):
            t0, c0 = rgb_stops[k]
            t1, c1 = rgb_stops[k + 1]
            if t0 <= v <= t1:
                f = (v - t0) / (t1 - t0)
                c = tuple(int(c0[j] + (c1[j] - c0[j]) * f) for j in range(3))
                pal.append("#%02x%02x%02x" % c)
                break
    return pal


def blend(c1, c2, f):
    a, b = hex_to_rgb(c1), hex_to_rgb(c2)
    return "#%02x%02x%02x" % tuple(int(a[i] + (b[i] - a[i]) * f) for i in range(3))


# ----------------------------------------------------------------------------
# Application
# ----------------------------------------------------------------------------
class FleurDeLys:
    def __init__(self, root):
        self.root = root
        self.theme = THEMES[THEME]
        self.palette = make_palette(self.theme["stops"])
        self.rng = random.Random(SEED)

        root.title("Fleur de lys")
        root.configure(bg=self.theme["bg"])
        self.canvas = tk.Canvas(root, width=W, height=H, bg=self.theme["bg"], highlightthickness=0)
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

    # -- calcul de la fleur sur la grille de caractères -----------------------
    def build_cells(self):
        pieces = build_pieces()
        boxes = [bbox(p) for p in pieces]
        cols, rows = W // self.cw, H // self.ch
        ox = (W - cols * self.cw) / 2
        oy = (H - rows * self.ch) / 2

        # la fleur occupe y ∈ [-1, 0.68] → centre à -0.16
        scale = min(W * 0.94 / (2 * X_STRETCH), H * 0.88 / 1.68)
        self.scale = scale

        piece_of = {}
        dist = {}
        maxd = [1e-6] * len(pieces)
        for r in range(rows):
            for c in range(cols):
                px = ox + c * self.cw + self.cw / 2
                py = oy + r * self.ch + self.ch / 2
                x = (px - W / 2) / (scale * X_STRETCH)
                y = (py - H / 2) / scale - 0.16
                for k in range(len(pieces) - 1, -1, -1):
                    x0, y0, x1, y1 = boxes[k]
                    if x0 <= x <= x1 and y0 <= y <= y1 and point_in_poly(x, y, pieces[k]):
                        d = edge_distance(x, y, pieces[k])
                        piece_of[(r, c)] = k
                        dist[(r, c)] = d
                        maxd[k] = max(maxd[k], d)
                        break

        # champ de hauteur en « bosse » (relief) puis éclairage façon gravure
        height = {}
        for key, d in dist.items():
            md = maxd[piece_of[key]]
            height[key] = math.sqrt(d * md) * scale  # en pixels

        lx, ly, lz = -0.55, -0.75, 0.9
        ln = math.sqrt(lx * lx + ly * ly + lz * lz)
        lx, ly, lz = lx / ln, ly / ln, lz / ln
        relief = 0.9

        self.cells = []
        for (r, c), h in height.items():
            hl = height.get((r, c - 1), 0.0)
            hr = height.get((r, c + 1), 0.0)
            hu = height.get((r - 1, c), 0.0)
            hd = height.get((r + 1, c), 0.0)
            gx = (hr - hl) / (2 * self.cw) * relief
            gy = (hd - hu) / (2 * self.ch) * relief
            nx, ny, nz = -gx, -gy, 1.0
            nn = math.sqrt(nx * nx + ny * ny + nz * nz)
            lam = max(0.0, (nx * lx + ny * ly + nz * lz) / nn)
            b = 0.08 + 0.80 * lam + 0.35 * lam ** 14
            b = max(0.0, min(1.0, b))
            px = ox + c * self.cw + self.cw / 2
            py = oy + r * self.ch + self.ch / 2
            self.cells.append({"x": px, "y": py, "b": b, "row": r})

        self.rows = rows
        self.cols = cols
        self.ox, self.oy = ox, oy
        self.occupied = set(height.keys())

    def char_for(self, b):
        return RAMP[min(len(RAMP) - 1, int(b * len(RAMP)))]

    def color_for(self, b):
        return self.palette[min(len(self.palette) - 1, max(0, int(b * (len(self.palette) - 1))))]

    # -- animation --------------------------------------------------------------
    def restart(self):
        if getattr(self, "job", None):
            self.root.after_cancel(self.job)
        self.canvas.delete("all")
        rng = self.rng
        bg = self.theme["bg"]

        # poussière de fond qui scintille doucement
        self.dust = []
        for _ in range(110):
            r = rng.randrange(self.rows)
            c = rng.randrange(self.cols)
            if (r, c) in self.occupied:
                continue
            x = self.ox + c * self.cw + self.cw / 2
            y = self.oy + r * self.ch + self.ch / 2
            it = self.canvas.create_text(x, y, text=rng.choice(".·:"), fill=bg, font=self.font)
            self.dust.append([it, rng.random() * 6.28, 0.4 + rng.random() * 1.2, -1])

        # chaque caractère part d'un point dispersé et vient se poser à sa place
        top = min(c["y"] for c in self.cells)
        bottom = max(c["y"] for c in self.cells)
        self.parts = []
        for cell in self.cells:
            yn = (cell["y"] - top) / max(1, bottom - top)          # 0 en haut, 1 en bas
            delay = (1 - yn) * 2.6 + rng.random() * 1.4            # on construit du bas vers le haut
            dur = 0.9 + rng.random() * 0.9
            ang = rng.random() * 2 * math.pi
            rad = 40 + rng.random() * 220
            sx = cell["x"] + math.cos(ang) * rad
            sy = cell["y"] + math.sin(ang) * rad * 0.7 - 60
            it = self.canvas.create_text(sx, sy, text=".", fill=bg, font=self.font)
            self.parts.append({**cell, "it": it, "sx": sx, "sy": sy, "delay": delay,
                               "dur": dur, "state": None, "landed": False})
        self.t0 = time.perf_counter()
        self.frames, self.cost = 0, 0.0
        self.build_end = max(p["delay"] + p["dur"] for p in self.parts)
        self.tick()

    def tick(self):
        start = time.perf_counter()
        t = start - self.t0
        cv = self.canvas
        bg = self.theme["bg"]

        # balayage lumineux une fois la fleur assemblée
        sweep = None
        if t > self.build_end + 0.4:
            period = 5.0
            phase = ((t - self.build_end - 0.4) % period) / period
            sweep = -200 + phase * (W + H + 400)

        for p in self.parts:
            local = t - p["delay"]
            if local < 0:
                continue
            if local < p["dur"]:
                f = local / p["dur"]
                e = 1 - (1 - f) ** 3                               # ease-out
                x = p["sx"] + (p["x"] - p["sx"]) * e
                y = p["sy"] + (p["y"] - p["sy"]) * e
                cv.coords(p["it"], x, y)
                b = p["b"] * min(1.0, 0.25 + f)
                ch = "." if f < 0.45 else (":" if f < 0.75 else self.char_for(b))
                col = blend(bg, self.color_for(b), min(1.0, 0.2 + f))
                state = (ch, col)
            else:
                if not p["landed"]:
                    cv.coords(p["it"], p["x"], p["y"])
                    p["landed"] = True
                b = p["b"]
                flash = max(0.0, 1 - (local - p["dur"]) / 0.35)    # petit éclat à l'arrivée
                if sweep is not None:
                    d = abs((p["x"] + p["y"]) - sweep)
                    if d < 90:
                        b += 0.45 * (1 - d / 90)
                b = min(1.0, b + 0.5 * flash)
                if self.rng.random() < 0.0008:                       # étincelles aléatoires
                    state = ("*", self.theme["spark"])
                else:
                    state = (self.char_for(b), self.color_for(b))
            if state != p["state"]:
                cv.itemconfigure(p["it"], text=state[0], fill=state[1])
                p["state"] = state

        for d in self.dust:
            it, ph, sp = d[0], d[1], d[2]
            v = round(8 * (0.5 + 0.5 * math.sin(t * sp + ph)) * min(1.0, t / 2)) / 8
            if v != d[3]:
                d[3] = v
                cv.itemconfigure(it, fill=blend(bg, self.theme["dust"], v))

        spent = time.perf_counter() - start
        self.frames += 1
        self.cost += spent
        if PROFILE and self.frames % 60 == 0:
            print("image %d  t=%.1fs  %.1f ms/image" % (self.frames, t, 1000 * self.cost / 60))
            self.cost = 0
        self.job = self.root.after(max(1, int(1000 / 30 - spent * 1000)), self.tick)


if __name__ == "__main__":
    root = tk.Tk()
    root.resizable(False, False)
    FleurDeLys(root)
    root.mainloop()
