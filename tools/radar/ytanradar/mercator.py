"""Web Mercator (EPSG:3857) pixel/tile math, Google Maps tile scheme."""

from __future__ import annotations

import math
from dataclasses import dataclass

import numpy as np

TILE = 256


def lon_to_px(lon: float, z: int) -> float:
    return (lon + 180.0) / 360.0 * TILE * 2 ** z


def lat_to_px(lat: float, z: int) -> float:
    s = math.sin(math.radians(lat))
    return (0.5 - math.log((1 + s) / (1 - s)) / (4 * math.pi)) * TILE * 2 ** z


def px_to_lon(px, z: int):
    return np.asarray(px, dtype=np.float64) / (TILE * 2 ** z) * 360.0 - 180.0


def px_to_lat(py, z: int):
    n = math.pi * (1 - 2 * np.asarray(py, dtype=np.float64) / (TILE * 2 ** z))
    return np.degrees(np.arctan(np.sinh(n)))


@dataclass(frozen=True)
class TileGrid:
    """The mosaic the job renders: snapped to whole tiles at zmin, rendered
    at zmax resolution, so every lower zoom is an exact 2x2 reduction.

    Only the bbox window inside it carries data, the rest stays transparent.
    """

    south: float
    west: float
    north: float
    east: float
    zmin: int
    zmax: int

    @property
    def factor(self) -> int:
        return 2 ** (self.zmax - self.zmin)

    @property
    def tile_range(self) -> tuple[int, int, int, int]:
        """(tx0, tx1, ty0, ty1) inclusive, at zmin."""
        z = self.zmin
        return (
            int(lon_to_px(self.west, z) // TILE),
            int(lon_to_px(self.east, z) // TILE),
            int(lat_to_px(self.north, z) // TILE),
            int(lat_to_px(self.south, z) // TILE),
        )

    @property
    def origin_px(self) -> tuple[int, int]:
        """Mosaic's top-left corner in global zmax pixels."""
        tx0, _, ty0, _ = self.tile_range
        return tx0 * TILE * self.factor, ty0 * TILE * self.factor

    @property
    def shape(self) -> tuple[int, int]:
        """(height, width) of the mosaic at zmax."""
        tx0, tx1, ty0, ty1 = self.tile_range
        return (ty1 - ty0 + 1) * TILE * self.factor, (tx1 - tx0 + 1) * TILE * self.factor

    @property
    def window(self) -> tuple[int, int, int, int]:
        """(row0, row1, col0, col1) of the bbox inside the mosaic, end-exclusive."""
        ox, oy = self.origin_px
        z = self.zmax
        return (
            int(math.floor(lat_to_px(self.north, z))) - oy,
            int(math.ceil(lat_to_px(self.south, z))) - oy,
            int(math.floor(lon_to_px(self.west, z))) - ox,
            int(math.ceil(lon_to_px(self.east, z))) - ox,
        )
