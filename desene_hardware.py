"""Desene simple (vectoriale, cu Pillow) pentru componente hardware.

Folosite de build_seed.py pentru imaginile produselor, ale categoriilor si ale
paginii principale. Fiecare desen lucreaza in coordonate 0..1 intr-un patrat,
asa ca poate fi pus la orice dimensiune.
"""
import math

DARK = (45, 50, 58)
DARK2 = (78, 85, 96)
METAL = (196, 202, 210)
METAL2 = (150, 157, 166)
GOLD = (214, 172, 62)
PCB = (32, 92, 64)
CHIP = (24, 26, 30)
WHITE = (255, 255, 255)


def mix(c1, c2, t):
    """culoare intre c1 si c2 (t=0 -> c1, t=1 -> c2)"""
    return tuple(int(c1[i] + (c2[i] - c1[i]) * t) for i in range(3))


class Pen:
    """deseneaza intr-un patrat din caseta data, cu coordonate 0..1"""

    def __init__(self, d, box):
        self.d = d
        x0, y0, x1, y1 = box
        self.s = min(x1 - x0, y1 - y0)
        self.x0 = x0 + (x1 - x0 - self.s) / 2
        self.y0 = y0 + (y1 - y0 - self.s) / 2

    def p(self, x, y):
        return (self.x0 + x * self.s, self.y0 + y * self.s)

    def w(self, v):
        return max(1, round(v * self.s))

    def rect(self, x0, y0, x1, y1, fill=None, outline=None, lw=0.008, r=0):
        self.d.rounded_rectangle([self.p(x0, y0), self.p(x1, y1)], radius=self.w(r) if r else 0,
                                 fill=fill, outline=outline, width=self.w(lw) if outline else 0)

    def circle(self, cx, cy, r, fill=None, outline=None, lw=0.008):
        self.d.ellipse([self.p(cx - r, cy - r), self.p(cx + r, cy + r)],
                       fill=fill, outline=outline, width=self.w(lw) if outline else 0)

    def line(self, pts, fill, lw=0.008):
        self.d.line([self.p(*q) for q in pts], fill=fill, width=self.w(lw), joint="curve")

    def poly(self, pts, fill):
        self.d.polygon([self.p(*q) for q in pts], fill=fill)

    def arc(self, x0, y0, x1, y1, start, end, fill, lw):
        self.d.arc([self.p(x0, y0), self.p(x1, y1)], start, end, fill=fill, width=self.w(lw))


def fan(p, cx, cy, r, accent=None):
    p.circle(cx, cy, r, fill=CHIP, outline=accent or DARK2, lw=0.012)
    for k in range(7):
        a = 2 * math.pi * k / 7
        pts = [(cx + rr * r * math.cos(a + da), cy + rr * r * math.sin(a + da))
               for rr, da in [(0.25, 0), (0.9, 0.35), (0.9, 0.75), (0.3, 0.55)]]
        p.poly(pts, fill=(98, 105, 116))
    p.circle(cx, cy, r * 0.28, fill=DARK, outline=DARK2, lw=0.006)


def screen(p, x0, y0, x1, y1, a):
    """ecran cu o reflexie diagonala"""
    p.rect(x0, y0, x1, y1, fill=mix(a, CHIP, 0.35))
    w = x1 - x0
    p.poly([(x0 + w * 0.55, y0), (x0 + w * 0.8, y0), (x0 + w * 0.45, y1), (x0 + w * 0.2, y1)],
           fill=mix(a, WHITE, 0.2))


def cpu(p, a, bg):
    for i in range(9):
        t = 0.24 + i * 0.065
        p.rect(t - 0.012, 0.10, t + 0.012, 0.17, fill=GOLD)
        p.rect(t - 0.012, 0.83, t + 0.012, 0.90, fill=GOLD)
        p.rect(0.10, t - 0.012, 0.17, t + 0.012, fill=GOLD)
        p.rect(0.83, t - 0.012, 0.90, t + 0.012, fill=GOLD)
    p.rect(0.16, 0.16, 0.84, 0.84, fill=PCB, r=0.03)
    p.rect(0.26, 0.26, 0.74, 0.74, fill=METAL, outline=METAL2, lw=0.012, r=0.04)
    p.rect(0.31, 0.31, 0.69, 0.69, outline=WHITE, lw=0.006, r=0.03)
    p.poly([(0.19, 0.81), (0.19, 0.74), (0.26, 0.81)], fill=GOLD)
    p.rect(0.36, 0.45, 0.64, 0.55, fill=a, r=0.02)


def gpu(p, a, bg):
    p.rect(0.03, 0.22, 0.07, 0.80, fill=METAL, outline=METAL2, lw=0.006)
    p.rect(0.22, 0.72, 0.62, 0.79, fill=GOLD)
    for i in range(16):
        x = 0.23 + i * 0.025
        p.line([(x, 0.745), (x, 0.79)], fill=mix(GOLD, CHIP, 0.4), lw=0.004)
    p.rect(0.07, 0.27, 0.97, 0.74, fill=DARK, r=0.04)
    p.rect(0.07, 0.27, 0.97, 0.31, fill=a, r=0.02)
    fan(p, 0.31, 0.51, 0.17)
    fan(p, 0.73, 0.51, 0.17)


def mb(p, a, bg):
    p.rect(0.08, 0.08, 0.92, 0.92, fill=(38, 44, 52), r=0.02)
    for x, y in [(0.12, 0.12), (0.88, 0.12), (0.12, 0.88), (0.88, 0.88), (0.5, 0.5)]:
        p.circle(x, y, 0.014, fill=METAL)
    p.rect(0.08, 0.10, 0.20, 0.48, fill=a, r=0.01)
    p.rect(0.30, 0.15, 0.56, 0.41, fill=METAL, outline=METAL2, lw=0.008)
    p.rect(0.34, 0.19, 0.52, 0.37, fill=(120, 126, 134))
    for i in range(4):
        x = 0.65 + i * 0.055
        p.rect(x, 0.12, x + 0.028, 0.50, fill=CHIP, outline=METAL2, lw=0.004)
    p.rect(0.14, 0.57, 0.72, 0.61, fill=CHIP, outline=METAL, lw=0.004)
    p.rect(0.22, 0.65, 0.58, 0.70, fill=METAL2, r=0.01)
    p.rect(0.14, 0.75, 0.50, 0.78, fill=CHIP, outline=METAL, lw=0.004)
    p.rect(0.70, 0.62, 0.86, 0.78, fill=a, r=0.015)


def ram(p, a, bg):
    p.rect(0.04, 0.34, 0.96, 0.64, fill=PCB, r=0.01)
    for i in range(40):
        x = 0.06 + i * 0.0222
        p.rect(x, 0.60, x + 0.012, 0.665, fill=GOLD)
    p.rect(0.485, 0.60, 0.515, 0.67, fill=bg)
    p.poly([(0.04, 0.30), (0.66, 0.30), (0.72, 0.24), (0.96, 0.24), (0.96, 0.58), (0.04, 0.58)], fill=a)
    for i in range(6):
        x = 0.70 + i * 0.04
        p.line([(x, 0.30), (x + 0.03, 0.27)], fill=mix(a, WHITE, 0.35), lw=0.006)
    p.rect(0.10, 0.38, 0.52, 0.50, fill=DARK, r=0.01)
    p.rect(0.12, 0.42, 0.40, 0.46, fill=mix(a, WHITE, 0.5))


def ssd_m2(p, a, bg):
    p.rect(0.04, 0.39, 0.96, 0.61, fill=PCB, r=0.01)
    for i in range(9):
        y = 0.405 + i * 0.022
        p.rect(0.04, y, 0.09, y + 0.012, fill=GOLD)
    p.rect(0.04, 0.475, 0.075, 0.495, fill=bg)
    p.circle(0.96, 0.50, 0.032, fill=bg)
    p.circle(0.96, 0.50, 0.018, fill=METAL)
    p.rect(0.14, 0.415, 0.86, 0.585, fill=DARK, r=0.01)
    p.rect(0.14, 0.415, 0.32, 0.585, fill=a, r=0.01)
    p.rect(0.40, 0.47, 0.78, 0.53, fill=DARK2, r=0.01)


def ssd_sata(p, a, bg):
    p.rect(0.25, 0.12, 0.75, 0.86, fill=DARK, r=0.03)
    p.rect(0.29, 0.17, 0.71, 0.60, fill=a, r=0.02)
    p.rect(0.33, 0.48, 0.67, 0.53, fill=mix(a, WHITE, 0.5), r=0.01)
    p.rect(0.32, 0.66, 0.68, 0.70, fill=METAL2, r=0.01)
    p.rect(0.32, 0.74, 0.55, 0.77, fill=METAL2, r=0.01)
    p.rect(0.30, 0.86, 0.50, 0.90, fill=CHIP)
    p.rect(0.53, 0.86, 0.66, 0.90, fill=CHIP)


def psu(p, a, bg):
    p.rect(0.08, 0.18, 0.92, 0.82, fill=DARK, r=0.02)
    p.rect(0.08, 0.18, 0.92, 0.24, fill=a, r=0.02)
    p.circle(0.50, 0.53, 0.25, fill=CHIP)
    fan(p, 0.50, 0.53, 0.23)
    for r in (0.23, 0.17, 0.11):
        p.circle(0.50, 0.53, r, outline=METAL2, lw=0.007)
    p.line([(0.27, 0.53), (0.73, 0.53)], fill=METAL2, lw=0.007)
    p.line([(0.50, 0.30), (0.50, 0.76)], fill=METAL2, lw=0.007)


def case(p, a, bg):
    p.rect(0.24, 0.06, 0.78, 0.92, fill=DARK, r=0.02)
    p.rect(0.28, 0.90, 0.34, 0.95, fill=DARK2)
    p.rect(0.68, 0.90, 0.74, 0.95, fill=DARK2)
    p.rect(0.30, 0.12, 0.72, 0.86, fill=(58, 70, 86), outline=METAL2, lw=0.006, r=0.01)
    for y in (0.25, 0.45, 0.65):
        fan(p, 0.635, y, 0.07, accent=a)
    p.rect(0.36, 0.22, 0.50, 0.36, fill=METAL2, r=0.01)
    p.rect(0.34, 0.50, 0.56, 0.56, fill=DARK2, r=0.005)
    p.rect(0.34, 0.74, 0.56, 0.82, fill=DARK, r=0.005)


def monitor(p, a, bg):
    p.rect(0.06, 0.12, 0.94, 0.66, fill=CHIP, r=0.015)
    screen(p, 0.08, 0.14, 0.92, 0.635, a)
    p.rect(0.46, 0.66, 0.54, 0.82, fill=DARK2)
    p.rect(0.30, 0.81, 0.70, 0.86, fill=DARK2, r=0.012)


def laptop(p, a, bg):
    p.rect(0.18, 0.14, 0.82, 0.61, fill=CHIP, r=0.015)
    screen(p, 0.20, 0.16, 0.80, 0.585, a)
    p.poly([(0.16, 0.60), (0.84, 0.60), (0.96, 0.74), (0.04, 0.74)], fill=METAL)
    p.poly([(0.22, 0.615), (0.78, 0.615), (0.81, 0.67), (0.19, 0.67)], fill=METAL2)
    p.poly([(0.43, 0.685), (0.57, 0.685), (0.575, 0.725), (0.425, 0.725)], fill=METAL2)
    p.rect(0.04, 0.74, 0.96, 0.765, fill=METAL2, r=0.008)


def mouse(p, a, bg):
    p.line([(0.50, 0.17), (0.50, 0.09), (0.60, 0.05), (0.80, 0.06)], fill=DARK2, lw=0.014)
    p.rect(0.30, 0.15, 0.70, 0.86, fill=DARK, r=0.2)
    p.line([(0.50, 0.15), (0.50, 0.42)], fill=CHIP, lw=0.008)
    p.line([(0.31, 0.42), (0.69, 0.42)], fill=CHIP, lw=0.008)
    p.rect(0.47, 0.21, 0.53, 0.33, fill=a, r=0.03)
    p.circle(0.50, 0.66, 0.045, fill=a)


def keyboard(p, a, bg):
    p.rect(0.03, 0.30, 0.97, 0.70, fill=DARK, r=0.02)
    key = mix(DARK, WHITE, 0.25)
    for r in range(5):
        for c in range(14):
            if r == 4 and 3 <= c <= 9:
                continue
            x, y = 0.06 + c * 0.0636, 0.33 + r * 0.07
            fill = a if (r, c) in ((0, 0), (2, 13)) else key
            p.rect(x, y, x + 0.054, y + 0.058, fill=fill, r=0.008)
    p.rect(0.06 + 3 * 0.0636, 0.61, 0.06 + 9 * 0.0636 + 0.054, 0.668, fill=key, r=0.008)


def headset(p, a, bg):
    p.arc(0.18, 0.10, 0.82, 0.80, 180, 360, fill=DARK, lw=0.06)
    p.rect(0.12, 0.42, 0.30, 0.78, fill=DARK, r=0.06)
    p.rect(0.70, 0.42, 0.88, 0.78, fill=DARK, r=0.06)
    p.rect(0.28, 0.46, 0.34, 0.74, fill=DARK2, r=0.03)
    p.rect(0.66, 0.46, 0.72, 0.74, fill=DARK2, r=0.03)
    p.rect(0.15, 0.52, 0.27, 0.68, fill=a, r=0.04)
    p.rect(0.73, 0.52, 0.85, 0.68, fill=a, r=0.04)
    p.line([(0.20, 0.76), (0.27, 0.88), (0.42, 0.90)], fill=DARK2, lw=0.016)
    p.circle(0.43, 0.90, 0.026, fill=DARK)


def webcam(p, a, bg):
    p.rect(0.44, 0.55, 0.56, 0.65, fill=DARK2)
    p.poly([(0.30, 0.65), (0.70, 0.65), (0.66, 0.72), (0.34, 0.72)], fill=DARK2)
    p.rect(0.18, 0.30, 0.82, 0.56, fill=DARK, r=0.13)
    p.circle(0.50, 0.43, 0.10, fill=CHIP, outline=METAL2, lw=0.012)
    p.circle(0.50, 0.43, 0.055, fill=mix(a, CHIP, 0.3))
    p.circle(0.48, 0.41, 0.015, fill=WHITE)
    p.circle(0.29, 0.43, 0.012, fill=METAL2)
    p.circle(0.71, 0.43, 0.012, fill=METAL2)
    p.circle(0.66, 0.35, 0.011, fill=a)


DESENE = {
    "cpu": cpu, "gpu": gpu, "mb": mb, "ram": ram, "ssd_m2": ssd_m2, "ssd_sata": ssd_sata,
    "psu": psu, "case": case, "monitor": monitor, "laptop": laptop,
    "mouse": mouse, "keyboard": keyboard, "headset": headset, "webcam": webcam,
}


def deseneaza(d, kind, box, accent, bg):
    """deseneaza componenta `kind` in caseta box=(x0, y0, x1, y1), peste fundalul bg"""
    DESENE[kind](Pen(d, box), accent, bg)
