"""Extrapolation nowcast: estimate how the rain field moves, push it forward.

pysteps would do the same (its default motion estimate also uses OpenCV),
but has no binary wheels and the production host has no compiler - so this
is the minimal equivalent: DIS optical flow between the last frames, a
smooth motion field, semi-Lagrangian backward advection of the newest frame.
No growth/decay, no ensemble: cells keep their intensity, which is why the
forecast gets steadily worse with lead time (measured by verify()).
"""

from __future__ import annotations

import cv2
import numpy as np

from .opera import UNDETECT_DBZ

STEP_MINUTES = 5
LOW_DBZ = 7.0    # ~0.1 mm/h, below that is clutter/drizzle noise for the flow
HIGH_DBZ = 55.0
SMOOTH_SIGMA = 20.0  # px (= km): motion is a large-scale property


def _image(dbz: np.ndarray) -> np.ndarray:
    v = (np.nan_to_num(dbz, nan=UNDETECT_DBZ) - LOW_DBZ) / (HIGH_DBZ - LOW_DBZ) * 255
    img = np.clip(v, 0, 255).astype(np.uint8)
    return cv2.GaussianBlur(img, (0, 0), 1.5)


def _blur(a: np.ndarray) -> np.ndarray:
    return cv2.GaussianBlur(a, (0, 0), SMOOTH_SIGMA)


def motion_field(fields: list[np.ndarray], spacing_minutes: int = 5) -> tuple[np.ndarray, np.ndarray] | None:
    """Velocity (vx, vy) in pixels per 5 minutes, from frames spacing_minutes
    apart (oldest first). None if there is too little rain to tell."""
    if len(fields) < 2:
        return None
    dis = cv2.DISOpticalFlow_create(cv2.DISOPTICAL_FLOW_PRESET_MEDIUM)
    imgs = [_image(f) for f in fields]
    last = len(imgs) - 1
    sum_w = np.zeros(imgs[0].shape, np.float32)
    sum_vx = np.zeros_like(sum_w)
    sum_vy = np.zeros_like(sum_w)
    for i in range(last):
        gap = last - i
        # flow at positions of the older frame, pointing to where it went
        flow = dis.calc(imgs[i], imgs[last], None) / (gap * spacing_minutes / STEP_MINUTES)
        w = (imgs[i] > 0).astype(np.float32) * gap  # longer gaps = less noise
        sum_w += w
        sum_vx += w * flow[..., 0]
        sum_vy += w * flow[..., 1]
    total = float(sum_w.sum())
    if total < 500:  # a few hundred km^2 of echo
        return None
    mean_vx = float(sum_vx.sum()) / total
    mean_vy = float(sum_vy.sum()) / total
    # Weighted smoothing: rain-free areas take the motion of the rain nearby,
    # far from any rain the domain-wide mean.
    bw = _blur(sum_w)
    k = bw / (bw + 0.05)
    with np.errstate(invalid="ignore", divide="ignore"):
        vx = np.where(bw > 0, _blur(sum_vx) / bw, mean_vx)
        vy = np.where(bw > 0, _blur(sum_vy) / bw, mean_vy)
    vx = (k * vx + (1 - k) * mean_vx).astype(np.float32)
    vy = (k * vy + (1 - k) * mean_vy).astype(np.float32)
    return vx, vy


def extrapolate(latest: np.ndarray, motion: tuple[np.ndarray, np.ndarray] | None,
                steps: int) -> list[np.ndarray]:
    """Forecast fields for +5, +10, ... +5*steps minutes.

    Where the newest frame has no radar coverage the forecast is NaN too
    ("unknown", not "dry"); rain advected in from outside the coverage is
    treated as none.
    """
    coverage = ~np.isnan(latest)
    src = np.nan_to_num(latest, nan=UNDETECT_DBZ).astype(np.float32)
    if motion is None:
        out = np.where(coverage, src, np.nan).astype(np.float32)
        return [out] * steps
    vx, vy = motion
    h, w = src.shape
    px, py = np.meshgrid(np.arange(w, dtype=np.float32), np.arange(h, dtype=np.float32))
    result = []
    for _ in range(steps):
        # backward trajectory: where was the air that is at (px, py) now?
        ux = cv2.remap(vx, px, py, cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)
        uy = cv2.remap(vy, px, py, cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)
        px = px - ux
        py = py - uy
        f = cv2.remap(src, px, py, cv2.INTER_LINEAR,
                      borderMode=cv2.BORDER_CONSTANT, borderValue=UNDETECT_DBZ)
        f[~coverage] = np.nan
        result.append(f)
    return result


def fill_missing_radars(field: np.ndarray, previous: np.ndarray,
                        motion: tuple[np.ndarray, np.ndarray] | None,
                        margin_px: int = 100) -> tuple[np.ndarray, int]:
    """Fill the area of radars that are missing from `field` but were in
    `previous` (5 minutes earlier) - (filled field, filled pixels).

    Some countries deliver only every 10 or 15 minutes (measured 2026-10-18:
    8 of 10 Spanish radars are missing from every second 5-minute OPERA
    composite), so without this the rain there halves and comes back every
    5 minutes. Their area: where `field` has no data but `previous` had,
    plus margin_px around it - there the neighbouring radars still deliver,
    but see less or nothing (the composite is a maximum over all radars, so
    a missing radar can only make values smaller). In that area the larger
    of the current value and the previous field, moved on by one step,
    counts.
    """
    gone = np.isnan(field) & ~np.isnan(previous)
    if int(gone.sum()) < 50:  # a few stray pixels are not a missing radar
        return field, 0
    size = 2 * margin_px + 1
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (size, size))
    area = cv2.dilate(gone.astype(np.uint8), kernel).astype(bool) & ~np.isnan(previous)
    moved = extrapolate(previous, motion, 1)[0]
    out = field.copy()
    out[area] = np.fmax(field[area], moved[area])  # fmax: NaN in one of them = the other
    return out, int(area.sum())


def csi(forecast: np.ndarray, observed: np.ndarray, threshold_dbz: float) -> tuple[float | None, int]:
    """Critical success index hits / (hits + misses + false alarms) over
    pixels covered in both; (None, 0) if neither has rain there."""
    valid = ~np.isnan(forecast) & ~np.isnan(observed)
    f = (forecast >= threshold_dbz) & valid
    o = (observed >= threshold_dbz) & valid
    hits = int((f & o).sum())
    denom = int((f | o).sum())
    return (hits / denom if denom else None), int(o.sum())
