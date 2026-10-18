"""EUMETNET OPERA composites from the Open Radar Data (ORD) S3 cache.

Files: https://s3.waw3-1.cloudferro.com/openradar-24h/YYYY/MM/DD/OPERA/COMP/
OPERA@YYYYMMDDTHHMM@0@DBZH.h5 - ODIM HDF5, maximum reflectivity in dBZ,
every 5 minutes, ~7 minutes behind real time, rolling 24 h. The time in the
name is the END of the 5-minute window. CC BY 4.0.

Measured 2026-10-17: 3800 x 4400 float64 grid at 1 km in LAEA (lat_0=55,
lon_0=10), upper-left corner exactly at projected (0, 0). Older docs say
2 km - so the grid is never hard-coded, Geometry reads it from every file.
"""

from __future__ import annotations

import json
import os
import urllib.error
import urllib.request
from dataclasses import asdict, dataclass
from datetime import datetime

import h5py
import numpy as np
import pyproj

BASE_URL = "https://s3.waw3-1.cloudferro.com/openradar-24h"
USER_AGENT = "YTAN-radar/1.0 (https://ytan.pesr.org)"

# Stand-in dBZ for "radar sees no echo" (ODIM undetect). Missing coverage
# (ODIM nodata) is NaN instead, the two must never be mixed up: one means
# "no rain", the other "we don't know".
UNDETECT_DBZ = -32.0


def frame_url(t: datetime, product: str = "DBZH") -> str:
    return f"{BASE_URL}/{t:%Y/%m/%d}/OPERA/COMP/OPERA@{t:%Y%m%dT%H%M}@0@{product}.h5"


def download(url: str, dest: str, timeout: int = 60) -> bool:
    """Download to dest (atomically). False if the file doesn't exist (yet)."""
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            data = resp.read()
    except urllib.error.HTTPError as e:
        if e.code in (403, 404):  # S3 answers 403 for a missing key without list rights
            return False
        raise
    tmp = dest + ".part"
    with open(tmp, "wb") as f:
        f.write(data)
    os.replace(tmp, dest)
    return True


@dataclass(frozen=True)
class Geometry:
    """Where a composite's pixels are. Pixel (row, col) covers projected
    x in [x0 + col*xscale, x0 + (col+1)*xscale), y in (y0 - (row+1)*yscale, y0 - row*yscale]."""

    projdef: str
    xsize: int
    ysize: int
    xscale: float
    yscale: float
    x0: float
    y0: float

    @classmethod
    def from_where(cls, where) -> "Geometry":
        projdef = _str(where["projdef"])
        tr = pyproj.Transformer.from_crs("EPSG:4326", pyproj.CRS(projdef), always_xy=True)
        x0, y0 = tr.transform(float(where["UL_lon"]), float(where["UL_lat"]))
        xscale = float(where["xscale"])
        yscale = float(where["yscale"])
        # The corner coordinates are given in degrees with limited precision,
        # snap to the pixel lattice (sub-metre noise otherwise).
        return cls(
            projdef=projdef,
            xsize=int(where["xsize"]),
            ysize=int(where["ysize"]),
            xscale=xscale,
            yscale=yscale,
            x0=round(x0 / xscale * 1000) / 1000 * xscale,
            y0=round(y0 / yscale * 1000) / 1000 * yscale,
        )

    def to_json(self) -> str:
        return json.dumps(asdict(self), sort_keys=True)

    @classmethod
    def from_json(cls, s: str) -> "Geometry":
        return cls(**json.loads(s))


def _str(v) -> str:
    return v.decode() if isinstance(v, bytes) else str(v)


def read_geometry(path: str) -> Geometry:
    with h5py.File(path, "r") as f:
        return Geometry.from_where(f["where"].attrs)


def read_nodes(path: str) -> list[str]:
    """The radars in this composite (ODIM how/nodes, e.g. "'deboo', 'dedrs', ..."),
    sorted; empty if the file doesn't say."""
    with h5py.File(path, "r") as f:
        raw = f["how"].attrs.get("nodes", b"") if "how" in f else b""
    return sorted({n.strip(" '\"") for n in _str(raw).split(",") if n.strip(" '\"")})


def read_dbzh(path: str, window: tuple[int, int, int, int]) -> np.ndarray:
    """dBZ (float32) for rows r0:r1, cols c0:c1; nodata -> NaN, undetect -> UNDETECT_DBZ."""
    r0, r1, c0, c1 = window
    with h5py.File(path, "r") as f:
        what = f["dataset1/data1/what"].attrs
        quantity = _str(what["quantity"])
        if quantity != "DBZH":
            raise ValueError(f"unexpected quantity {quantity!r}")
        raw = f["dataset1/data1/data"][r0:r1, c0:c1]
        gain = float(what["gain"])
        offset = float(what["offset"])
        nodata = float(what["nodata"])
        undetect = float(what["undetect"])
    out = (raw * gain + offset).astype(np.float32)
    out[raw == undetect] = UNDETECT_DBZ
    out[raw == nodata] = np.nan
    np.maximum(out, UNDETECT_DBZ, out=out)  # keeps NaN
    return out


# Compact on-disk form of a cropped field (raw cache): uint8, 0.5 dBZ steps.
# 0 = nodata, 1 = undetect/<= -32 dBZ, 2..255 = -31.5 .. 94.5 dBZ.
def encode(dbz: np.ndarray) -> np.ndarray:
    v = np.clip(np.round((np.nan_to_num(dbz, nan=UNDETECT_DBZ) - UNDETECT_DBZ) * 2) + 1, 1, 255)
    out = v.astype(np.uint8)
    out[np.isnan(dbz)] = 0
    return out


def decode(enc: np.ndarray) -> np.ndarray:
    out = (enc.astype(np.float32) - 1) / 2 + UNDETECT_DBZ
    out[enc == 0] = np.nan
    return out
