"""Rain-rate classes (mm/h) and their colours.

dBZ -> mm/h with Marshall-Palmer, Z = 200 R^1.6 (the usual stratiform
default; convective showers are underestimated a little, which is fine for
"is it raining and how hard"). Index 0 = transparent; an index is the
class, so a higher index always means heavier rain (tiles.py relies on
that when it reduces 2x2 pixels to their maximum).
"""

from __future__ import annotations

import math

import numpy as np

# (lower bound in mm/h, colour) - from light blue drizzle to magenta cloudbursts.
CLASSES: list[tuple[float, str]] = [
    (0.1, "#9fd4ff"),
    (0.5, "#5aaaf2"),
    (1.0, "#2a6fdb"),
    (2.0, "#1c9e57"),
    (5.0, "#8fcb2e"),
    (10.0, "#f2d21b"),
    (20.0, "#f58b0f"),
    (50.0, "#e0261d"),
    (100.0, "#b1168a"),
]


def rate_to_dbz(rate_mm_h: float) -> float:
    return 10 * math.log10(200 * rate_mm_h ** 1.6)


def dbz_to_rate(dbz):
    return (10 ** (np.asarray(dbz, dtype=np.float64) / 10) / 200) ** (1 / 1.6)


THRESHOLDS_DBZ = np.array([rate_to_dbz(r) for r, _ in CLASSES], dtype=np.float32)


def classify(dbz: np.ndarray) -> np.ndarray:
    """dBZ (NaN = no coverage) -> uint8 class index, 0 = transparent.

    Counts the thresholds each value reaches (= searchsorted side="right"),
    straight into uint8: searchsorted returned int64 plus a float copy for
    nan_to_num, ~12 bytes of temporaries per mosaic pixel and frame. NaN
    compares False everywhere, so it stays 0 without nan_to_num."""
    out = np.zeros(np.shape(dbz), np.uint8)
    with np.errstate(invalid="ignore"):
        for threshold in THRESHOLDS_DBZ:
            out += dbz >= threshold
    return out


def png_palette() -> list[int]:
    """Flat RGB list for PIL's putpalette(), index 0 = transparent black."""
    flat = [0, 0, 0]
    for _, colour in CLASSES:
        flat += [int(colour[i:i + 2], 16) for i in (1, 3, 5)]
    return flat


def legend() -> list[dict]:
    return [{"mm_h": r, "color": c} for r, c in CLASSES]
