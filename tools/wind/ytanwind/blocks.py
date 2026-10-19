"""Wind fields -> small fixed-grid blocks the browser loads on demand.

Three levels share one global grid (origin -90 / -180), so the block a
coordinate belongs to is plain arithmetic on the client:

  L0  0.25   degrees, the whole area in ONE file (rows x cols x channels)
  L1  0.0625 degrees (ICON-EU),             blocks of 128 cells = 8 degrees
  L2  0.02   degrees (ICON-D2, EU as fill), blocks of 128 cells = 2.56 degrees

A block has 129 x 129 points: the extra row/column is the neighbour's first
one, so bilinear interpolation never needs two blocks. Bytes: for every
point (rows south -> north, columns west -> east) u, v [, gust], each
0..254 on the scale below, 255 = no data (outside the regions or the model).
"""

from __future__ import annotations

import gzip
from dataclasses import dataclass

import numpy as np
from scipy import ndimage

from .grib2 import Field

CELLS = 128
SIZE = CELLS + 1
UV_RANGE = (-40.0, 40.0)   # m/s, one byte step = 0.31 m/s
GUST_RANGE = (0.0, 60.0)
NODATA = 255
UNITS = 10_000             # resolutions as integers in 1e-4 degrees: no float drift in indices
D2_EDGE_CELLS = 12         # the outer 0.24 degrees of ICON-D2's valid area are not used


@dataclass(frozen=True)
class Level:
    name: str
    res: int  # in 1e-4 degrees

    @property
    def deg(self) -> float:
        return self.res / UNITS

    @property
    def block_deg(self) -> float:
        return CELLS * self.res / UNITS


L0 = Level("L0", 2500)
L1 = Level("L1", 625)
L2 = Level("L2", 200)


def lonlat_axes(level: Level, bx: int, by: int) -> tuple[np.ndarray, np.ndarray]:
    """(lat[129], lon[129]) of a block's points."""
    k = np.arange(SIZE)
    lon = -180.0 + (bx * CELLS + k) * level.res / UNITS
    lat = -90.0 + (by * CELLS + k) * level.res / UNITS
    return lat, lon


def encode(arr: np.ndarray, lo: float, hi: float) -> np.ndarray:
    """float -> uint8 0..254 (NaN -> 255)."""
    out = np.rint((arr - lo) / (hi - lo) * 254.0)
    out = np.clip(out, 0, 254)
    out[np.isnan(arr)] = NODATA
    return out.astype(np.uint8)


def inside_regions(lat: np.ndarray, lon: np.ndarray, regions: list[dict]) -> np.ndarray:
    """Boolean (len(lat), len(lon)): the point lies in at least one region rectangle."""
    la = lat[:, None]
    lo = lon[None, :]
    mask = np.zeros((len(lat), len(lon)), bool)
    eps = 1e-9
    for r in regions:
        mask |= (la >= r["south"] - eps) & (la <= r["north"] + eps) & (lo >= r["west"] - eps) & (lo <= r["east"] + eps)
    return mask


def sample(field: Field, lat: np.ndarray, lon: np.ndarray) -> np.ndarray:
    """Bilinear values of a field at lat[:,None] x lon[None,:]; NaN outside it or near NaN."""
    fj = (lat - field.lat0) / field.dlat
    fi = (lon - field.lon0) / field.dlon
    nj, ni = field.values.shape
    ok_j = (fj >= -1e-6) & (fj <= nj - 1 + 1e-6)
    ok_i = (fi >= -1e-6) & (fi <= ni - 1 + 1e-6)
    jj, ii = np.meshgrid(np.clip(fj, 0, nj - 1), np.clip(fi, 0, ni - 1), indexing="ij")
    out = ndimage.map_coordinates(field.values, [jj, ii], order=1, mode="nearest").astype(np.float32)
    out[~(ok_j[:, None] & ok_i[None, :])] = np.nan
    return out


def trim_d2(field: Field) -> Field:
    """ICON-D2 with its edge removed (valid area eroded by D2_EDGE_CELLS), the rest NaN."""
    valid = (~np.isnan(field.values)).astype(np.uint8)
    size = 2 * D2_EDGE_CELLS + 1
    valid = ndimage.minimum_filter(valid, size=size, mode="constant", cval=0)
    v = field.values.copy()
    v[valid == 0] = np.nan
    return Field(v, field.lat0, field.lon0, field.dlat, field.dlon)


def coarse(field: Field, factor: int) -> Field:
    """Box-averaged copy of a field (the source of the L0 overview)."""
    v = ndimage.uniform_filter(field.values, size=factor, mode="nearest")
    return Field(v.astype(np.float32), field.lat0, field.lon0, field.dlat, field.dlon)


def block_points(level: Level, rect: tuple[float, float, float, float], regions: list[dict]) -> list[tuple[int, int]]:
    """Blocks of a level that touch both `rect` (south, west, north, east: the
    model's coverage) and at least one region."""
    s, w, n, e = rect
    out = []
    bd = level.block_deg
    bx0, bx1 = int(np.floor((w + 180) / bd)), int(np.floor((e + 180) / bd))
    by0, by1 = int(np.floor((s + 90) / bd)), int(np.floor((n + 90) / bd))
    for by in range(by0, by1 + 1):
        for bx in range(bx0, bx1 + 1):
            south, west = -90 + by * bd, -180 + bx * bd
            north, east = south + bd, west + bd
            if any(r["south"] < north and r["north"] > south and r["west"] < east and r["east"] > west
                   for r in regions):
                out.append((bx, by))
    return out


def build_block(level: Level, bx: int, by: int, regions: list[dict], channels: list[list[Field]]) -> bytes | None:
    """Bytes of one block, or None when no point carries data.

    `channels`: per channel (u, v[, gust]) the fields to sample in order of
    preference - the first that has a value at a point wins (ICON-D2, then ICON-EU)."""
    lat, lon = lonlat_axes(level, bx, by)
    mask = inside_regions(lat, lon, regions)
    if not mask.any():
        return None
    planes = []
    for k, sources in enumerate(channels):
        arr = np.full((SIZE, SIZE), np.nan, np.float32)
        for f in sources:
            got = sample(f, lat, lon)
            fill = np.isnan(arr) & ~np.isnan(got)
            arr[fill] = got[fill]
        arr[~mask] = np.nan
        lo, hi = GUST_RANGE if k == 2 else UV_RANGE
        planes.append(encode(arr, lo, hi))
    stacked = np.stack(planes, axis=-1)
    if (stacked[..., 0] == NODATA).all():
        return None
    return stacked.tobytes()


def overview(regions: list[dict], channels: list[list[Field]], area: tuple[float, float, float, float]) -> tuple[dict, bytes] | None:
    """The L0 file: the area of the regions (inside `area`, the models' coverage) at 0.25 degrees.

    `channels`: as in build_block, per channel the fields in order of preference."""
    s = max(area[0], min(r["south"] for r in regions))
    w = max(area[1], min(r["west"] for r in regions))
    n = min(area[2], max(r["north"] for r in regions))
    e = min(area[3], max(r["east"] for r in regions))
    if not (s < n and w < e):
        return None
    step = L0.res / UNITS
    j0, j1 = int(np.floor((s + 90) / step + 1e-9)), int(np.ceil((n + 90) / step - 1e-9))
    i0, i1 = int(np.floor((w + 180) / step + 1e-9)), int(np.ceil((e + 180) / step - 1e-9))
    lat = -90.0 + np.arange(j0, j1 + 1) * step
    lon = -180.0 + np.arange(i0, i1 + 1) * step
    mask = inside_regions(lat, lon, regions)
    planes = []
    for k, sources in enumerate(channels):
        arr = np.full((len(lat), len(lon)), np.nan, np.float32)
        for f in sources:
            factor = round(step / f.dlat)
            got = sample(coarse(f, factor) if factor > 1 else f, lat, lon)
            fill = np.isnan(arr) & ~np.isnan(got)
            arr[fill] = got[fill]
        arr[~mask] = np.nan
        lo, hi = GUST_RANGE if k == 2 else UV_RANGE
        planes.append(encode(arr, lo, hi))
    meta = {"res": step, "south": float(lat[0]), "west": float(lon[0]), "rows": len(lat), "cols": len(lon)}
    return meta, np.stack(planes, axis=-1).tobytes()


def pack(data: bytes) -> bytes:
    return gzip.compress(data, compresslevel=6, mtime=0)
