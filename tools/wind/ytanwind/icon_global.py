"""ICON global (13 km icosahedral grid) -> a regular 0.25 degree field.

opendata.dwd.de has no regular lat/lon version of the global model's 10 m
wind, only the icosahedral one: one value per triangle cell (2,949,120 of
them), and the cell centres are in two separate time-invariant files (CLAT,
CLON, degrees). The cells are ~13 km, the target grid 28 km, so nearest
neighbour is enough. The index is built once (KD tree on the unit sphere)
and cached next to the other storage files.
"""

from __future__ import annotations

import bz2
import logging
import os
import re

import numpy as np
from scipy.spatial import cKDTree

from . import dwd, grib2
from .grib2 import Field

log = logging.getLogger("ytanwind")

RES = 0.25
ROWS = 721      # -90 .. 90
COLS = 1441     # -180 .. 180: the last column repeats the first, so a block at the date line needs no wrap
CELLS = 2_949_120
INDEX_FILE = "icon-global-index.npy"


def _unit_vectors(lat: np.ndarray, lon: np.ndarray) -> np.ndarray:
    la, lo = np.radians(lat.astype(np.float64)), np.radians(lon.astype(np.float64))
    return np.stack([np.cos(la) * np.cos(lo), np.cos(la) * np.sin(lo), np.sin(la)], axis=-1)


def _fetch_coordinate(name: str) -> np.ndarray:
    folder = f"{dwd.BASE}/icon/grib/00/{name}/"
    html = dwd.http_get(folder, timeout=30).decode("utf-8", "replace")
    files = re.findall(r'href="([^"]+\.grib2\.bz2)"', html)
    if not files:
        raise grib2.Grib2Error(f"no {name} file for ICON global on the server")
    return grib2.decode_unstructured(bz2.decompress(dwd.http_get(folder + files[-1], timeout=120)))


def build_index(lat: np.ndarray, lon: np.ndarray) -> np.ndarray:
    """(ROWS, COLS) int32: for every point of the 0.25 degree grid the nearest cell."""
    if len(lat) != CELLS or len(lon) != CELLS:
        raise grib2.Grib2Error("ICON global grid has %d cells, expected %d" % (len(lat), CELLS))
    tree = cKDTree(_unit_vectors(lat, lon))
    glat = -90.0 + np.arange(ROWS) * RES
    glon = -180.0 + np.arange(COLS) * RES
    pts = _unit_vectors(np.repeat(glat, COLS), np.tile(glon, ROWS))
    _dist, idx = tree.query(pts, workers=-1)
    return idx.reshape(ROWS, COLS).astype(np.int32)


def load_index(storage: str) -> np.ndarray:
    """The cached index, built from the DWD's CLAT/CLON files on first use."""
    path = os.path.join(storage, INDEX_FILE)
    try:
        idx = np.load(path)
        if idx.shape == (ROWS, COLS):
            return idx
    except (OSError, ValueError):
        pass
    log.info("building the ICON global regridding index (one time)")
    idx = build_index(_fetch_coordinate("clat"), _fetch_coordinate("clon"))
    tmp = path + ".tmp.npy"
    np.save(tmp, idx)
    os.replace(tmp, path)
    return idx


def to_field(values: np.ndarray, index: np.ndarray) -> Field:
    """Cell values -> Field on the 0.25 degree grid (row 0 = south pole)."""
    if len(values) != CELLS:
        raise grib2.Grib2Error("ICON global field has %d cells, expected %d" % (len(values), CELLS))
    grid = values[index].astype(np.float32)
    grid[:, -1] = grid[:, 0]  # same point on the sphere: a tie in the nearest-cell search must not leave a seam
    return Field(grid, -90.0, -180.0, RES, RES)
