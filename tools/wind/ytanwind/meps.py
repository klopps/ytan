"""MET Norway MEPS (2.5 km, Scandinavia, the Baltic, Northern Germany) 10 m wind.

MEPS comes as NetCDF behind THREDDS, not as GRIB2. Instead of a NetCDF
library (and a whole file), the job asks OPeNDAP for exactly the cut-out it
needs, in DAP2's binary form (".dods": the DDS text, "Data:", then XDR
arrays), which `parse_dods()` reads in a few lines.

Found while building it (2026-11-19):
- The arrays come in the order of the DDS, NOT in the order they were asked
  for, so they are read by name.
- The grid is Lambert conformal conic on a sphere (projection_lambert:
  standard parallel 63.3 twice, central meridian 15, R = 6,371,000 m); the
  x/y axes are the projected metres of that formula exactly (checked to
  0.1 m), so the job projects its own 0.02 degree points instead of loading
  the 2D latitude/longitude arrays (16 MB).
- x_wind_10m/y_wind_10m are along the grid axes; rotated by the convergence
  angle n * (lon - lon0) they give east/north (checked against the file's own
  wind_direction: median 0.002 degrees off).
- A run's NcML aggregation appears in the catalog when the run is complete
  (about 2.5 h after the run time, 67 hourly steps); the job still checks the
  last step for fill values.
- The server ignores Accept-Encoding: a full field is 4 MB, so the job cuts
  the window to the regions (and leaves out what ICON-D2 covers).
"""

from __future__ import annotations

import logging
import re
import struct
import urllib.parse
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

import numpy as np
from scipy import ndimage

from . import dwd
from .grib2 import Field

log = logging.getLogger("ytanwind")

THREDDS = "https://thredds.met.no/thredds"
CATALOG = THREDDS + "/catalog/mepslatest/catalog.xml"
DODS = THREDDS + "/dodsC/mepslatest/"

# projection_lambert of the files
LAT1 = 63.3
LON0 = 15.0
EARTH_RADIUS = 6371000.0
# rough lat/lon box of the domain (south, west, north, east), only to decide whether MEPS is worth asking
AREA = (49.7, -18.2, 75.3, 54.3)
# the outer cells are the model's relaxation zone (boundary values from the host model): not used
EDGE_CELLS = 10
FILL_ABOVE = 1e30          # _FillValue is 9.96921E36
STEP_DEG = 0.02            # the L2 grid, see blocks.L2
VAR_U, VAR_V, VAR_GUST = "x_wind_10m", "y_wind_10m", "wind_speed_of_gust"

_N = np.sin(np.radians(LAT1))
_F = np.cos(np.radians(LAT1)) * np.tan(np.pi / 4 + np.radians(LAT1) / 2) ** _N / _N
_RHO0 = EARTH_RADIUS * _F / np.tan(np.pi / 4 + np.radians(LAT1) / 2) ** _N

_TYPES = {"Float32": (">f4", 4), "Float64": (">f8", 8), "Int32": (">i4", 4), "Int16": (">i4", 4)}


def run_name(run: datetime) -> str:
    return f"meps_det_2_5km_{run:%Y%m%dT%H}Z.ncml"


def project(lat: np.ndarray, lon: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Lambert conformal conic (sphere) of the MEPS files: degrees -> x, y in metres."""
    rho = EARTH_RADIUS * _F / np.tan(np.pi / 4 + np.radians(lat) / 2) ** _N
    theta = _N * (np.radians(lon) - np.radians(LON0))
    return rho * np.sin(theta), _RHO0 - rho * np.cos(theta)


def convergence(lon: np.ndarray) -> np.ndarray:
    """Angle (radians) between grid north and true north at a longitude."""
    return _N * (np.radians(lon) - np.radians(LON0))


def to_east_north(xw: np.ndarray, yw: np.ndarray, lon: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Grid-relative wind components -> east (u) and north (v)."""
    t = convergence(lon)
    c, s = np.cos(t), np.sin(t)
    return xw * c + yw * s, -xw * s + yw * c


def parse_dods(raw: bytes) -> dict[str, np.ndarray]:
    """DAP2 binary response -> {variable name: array shaped as the DDS says}."""
    cut = raw.find(b"\nData:\n")
    if cut < 0:
        raise ValueError("OPeNDAP: no 'Data:' marker - " + raw[:200].decode("utf-8", "replace"))
    dds = raw[:cut].decode("utf-8", "replace")
    pos = cut + len(b"\nData:\n")
    out: dict[str, np.ndarray] = {}
    for typ, name, dims in re.findall(r"(Float32|Float64|Int32|Int16)\s+(\w+)((?:\[\s*\w+\s*=\s*\d+\s*\])*)\s*;", dds):
        shape = tuple(int(n) for n in re.findall(r"=\s*(\d+)", dims))
        dtype, size = _TYPES[typ]
        if shape:
            n, n2 = struct.unpack(">II", raw[pos:pos + 8])
            if n != n2 or n != int(np.prod(shape)):
                raise ValueError(f"OPeNDAP: {name} has {n} values, the DDS says {shape}")
            pos += 8
        else:
            n = 1
        arr = np.frombuffer(raw, dtype, n, pos).reshape(shape)
        pos += n * size
        out.setdefault(name, arr)  # a Grid repeats its map vectors (time, x, y) after the array
    if pos != len(raw):
        raise ValueError(f"OPeNDAP: {len(raw) - pos} bytes left after the arrays")
    return out


def fetch(run: datetime, constraint: str, stats: dict | None = None) -> dict[str, np.ndarray]:
    url = DODS + run_name(run) + ".dods?" + urllib.parse.quote(constraint, safe=",._:")
    raw = dwd.http_get(url, timeout=120)
    if stats is not None:
        stats["bytes_downloaded"] = stats.get("bytes_downloaded", 0) + len(raw)
        stats["files"] = stats.get("files", 0) + 1
    return parse_dods(raw)


def listed_runs() -> list[datetime]:
    """Run times of the deterministic 2.5 km runs in the catalog, newest first."""
    xml = dwd.http_get(CATALOG, timeout=30).decode("utf-8", "replace")
    runs = {datetime.strptime(m, "%Y%m%dT%H").replace(tzinfo=timezone.utc)
            for m in re.findall(r'name="meps_det_2_5km_(\d{8}T\d{2})Z\.ncml"', xml)}
    return sorted(runs, reverse=True)


def times_of(axes: dict[str, np.ndarray]) -> list[datetime]:
    return [datetime(1970, 1, 1, tzinfo=timezone.utc) + timedelta(seconds=float(t)) for t in axes["time"]]


def latest_complete_run(now: datetime, first: datetime, last: datetime, every: int = 3,
                        candidates: int = 3) -> tuple[datetime, list[int]] | None:
    """Newest run (only every `every` hours) with data in [first, last], and its steps in that window."""
    try:
        runs = [r for r in listed_runs() if r <= now and r.hour % every == 0][:candidates]
    except Exception as e:  # MEPS is an extra: without it the job goes on with ICON
        log.warning("MEPS catalog unreadable (%s), going on without MEPS", e)
        return None
    for run in runs:
        try:
            axes = fetch(run, "x,y,time")
            times = times_of(axes)
            if any(t != run + timedelta(hours=k) for k, t in enumerate(times)):
                raise ValueError("time axis is not hourly from the run time")  # the job counts on step = hour
            steps = [k for k, t in enumerate(times) if first <= t <= last]
            if not steps:
                continue
            j, i = len(axes["y"]) // 2, len(axes["x"]) // 2
            probe = fetch(run, f"{VAR_U}.{VAR_U}[{len(times) - 1}][0][{j}][{i}]")[VAR_U]
            if not np.isfinite(probe).all() or (np.abs(probe) > FILL_ABOVE).any():
                log.info("MEPS %s: last step not written yet", run_name(run))
                continue
            return run, steps
        except Exception as e:
            log.warning("MEPS %s unusable (%s)", run_name(run), e)
    return None


@dataclass
class Window:
    """What to ask OPeNDAP for, and where its cells land on the 0.02 degree target grid."""
    j0: int
    j1: int
    i0: int
    i1: int
    lat0: float
    lon0: float
    fj: np.ndarray      # (rows, cols) fractional row in the window per target point
    fi: np.ndarray
    lon: np.ndarray     # (cols,) longitudes of the target grid
    valid: np.ndarray   # (rows, cols) target point lies inside the domain less its edge (and outside `exclude`)


def snap(v: float, up: bool) -> float:
    """Down/up to the L2 grid (multiples of STEP_DEG from -180)."""
    k = (v + 180.0) / STEP_DEG
    return round((np.ceil(k - 1e-9) if up else np.floor(k + 1e-9)) * STEP_DEG - 180.0, 6)


def plan_window(x: np.ndarray, y: np.ndarray, box: dict,
                exclude: tuple[float, float, float, float] | None = None) -> Window | None:
    """The target grid over `box` (the padded regions) and the source window it needs."""
    s, w = max(box["south"], AREA[0]), max(box["west"], AREA[1])
    n, e = min(box["north"], AREA[2]), min(box["east"], AREA[3])
    if not (s < n and w < e):
        return None
    # 90 and 180 are multiples of the step: the same snapping fits latitudes and longitudes
    s, n, w, e = snap(s, False), snap(n, True), snap(w, False), snap(e, True)
    lat = np.round(np.arange(round((n - s) / STEP_DEG) + 1) * STEP_DEG + s, 6)
    lon = np.round(np.arange(round((e - w) / STEP_DEG) + 1) * STEP_DEG + w, 6)
    px, py = project(lat[:, None], lon[None, :])
    fi = (px - float(x[0])) / float(x[1] - x[0])
    fj = (py - float(y[0])) / float(y[1] - y[0])
    valid = (fi >= EDGE_CELLS) & (fi <= len(x) - 1 - EDGE_CELLS) & (fj >= EDGE_CELLS) & (fj <= len(y) - 1 - EDGE_CELLS)
    if exclude is not None:
        es, ew, en, ee = exclude
        valid &= ~((lat[:, None] >= es) & (lat[:, None] <= en) & (lon[None, :] >= ew) & (lon[None, :] <= ee))
    if not valid.any():
        return None
    j0, j1 = int(np.floor(fj[valid].min())), int(np.ceil(fj[valid].max()))
    i0, i1 = int(np.floor(fi[valid].min())), int(np.ceil(fi[valid].max()))
    return Window(j0, j1, i0, i1, float(lat[0]), float(lon[0]), fj - j0, fi - i0, lon, valid)


class Source:
    """One MEPS run, cut to the regions: fields on the 0.02 degree grid per forecast step."""

    def __init__(self, run: datetime, box: dict, exclude: tuple[float, float, float, float] | None = None,
                 stats: dict | None = None):
        self.run = run
        self.stats = stats
        axes = fetch(run, "x,y", stats)
        self.window = plan_window(axes["x"], axes["y"], box, exclude)

    def coverage(self) -> Field | None:
        """1 where MEPS is used, NaN elsewhere (which L2 blocks get MEPS data)."""
        if self.window is None:
            return None
        v = np.where(self.window.valid, 1.0, np.nan).astype(np.float32)
        return Field(v, self.window.lat0, self.window.lon0, STEP_DEG, STEP_DEG)

    def fields(self, step: int, gusts: bool) -> list[Field]:
        wnd = self.window
        cut = f"[{step}][0][{wnd.j0}:1:{wnd.j1}][{wnd.i0}:1:{wnd.i1}]"
        names = (VAR_U, VAR_V) + ((VAR_GUST,) if gusts else ())
        got = fetch(self.run, ",".join(f"{v}.{v}{cut}" for v in names), self.stats)
        planes = []
        for v in names:
            a = got[v].reshape(wnd.j1 - wnd.j0 + 1, wnd.i1 - wnd.i0 + 1).astype(np.float32)
            a[~np.isfinite(a) | (np.abs(a) > FILL_ABOVE)] = np.nan
            out = ndimage.map_coordinates(a, [wnd.fj, wnd.fi], order=1, mode="nearest", cval=np.nan).astype(np.float32)
            out[~wnd.valid] = np.nan
            planes.append(out)
        u, v = to_east_north(planes[0], planes[1], wnd.lon[None, :])
        result = [u.astype(np.float32), v.astype(np.float32)]
        if gusts:
            g = planes[2]
            missing = np.isnan(g) & ~np.isnan(u)  # e.g. a step without a gust value: the mean wind instead
            g[missing] = np.hypot(u, v)[missing]
            result.append(g)
        return [Field(p, wnd.lat0, wnd.lon0, STEP_DEG, STEP_DEG) for p in result]
