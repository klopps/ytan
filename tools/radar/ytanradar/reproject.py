"""Composite grid (LAEA) -> mosaic grid (Web Mercator) via cv2.remap.

The lookup (which source pixel feeds which mosaic pixel) depends only on
the two grids, so it is built once per run (~4 s for ~30 M pixels) and then
applied to every frame in ~0.2 s.
"""

from __future__ import annotations

import cv2
import numpy as np
import pyproj

from .mercator import TileGrid, px_to_lat, px_to_lon
from .opera import UNDETECT_DBZ, Geometry

MARGIN = 2  # source pixels kept around the crop, for bilinear sampling
DISPLAY_SMOOTH_M = 1500.0  # Gaussian sigma before colouring, see smooth_for_display()


class Reprojector:
    def __init__(self, grid: TileGrid, geom: Geometry, strip_rows: int = 512):
        self.grid = grid
        self.geom = geom
        r0, r1, c0, c1 = grid.window
        ox, oy = grid.origin_px
        tr = pyproj.Transformer.from_crs("EPSG:4326", pyproj.CRS(geom.projdef), always_xy=True)

        # Mercator: lon depends only on the column, lat only on the row.
        lon = px_to_lon(np.arange(c0, c1) + ox + 0.5, grid.zmax)
        lat_all = px_to_lat(np.arange(r0, r1) + oy + 0.5, grid.zmax)
        h, w = r1 - r0, c1 - c0
        col = np.empty((h, w), np.float32)
        row = np.empty((h, w), np.float32)
        for s in range(0, h, strip_rows):
            lat = lat_all[s:s + strip_rows]
            lo, la = np.meshgrid(lon, lat)
            x, y = tr.transform(lo, la)
            # pixel index, centre-based (cv2.remap samples at integer = centre)
            col[s:s + len(lat)] = (x - geom.x0) / geom.xscale - 0.5
            row[s:s + len(lat)] = (geom.y0 - y) / geom.yscale - 0.5

        # Crop of the composite that is actually needed.
        cr0 = max(int(np.floor(row.min())) - MARGIN, 0)
        cr1 = min(int(np.ceil(row.max())) + MARGIN + 1, geom.ysize)
        cc0 = max(int(np.floor(col.min())) - MARGIN, 0)
        cc1 = min(int(np.ceil(col.max())) + MARGIN + 1, geom.xsize)
        col -= cc0
        row -= cr0
        self.crop = (cr0, cr1, cc0, cc1)
        self.map_x = col
        self.map_y = row

    def render(self, field: np.ndarray) -> np.ndarray:
        """Cropped composite field (dBZ, NaN = no coverage) -> bbox window of the mosaic.

        Bilinear: smooth edges when zoomed in, the 1 km pixels would otherwise
        show up as rotated squares (LAEA vs. Mercator). Outside the composite = NaN.
        """
        return cv2.remap(field, self.map_x, self.map_y, cv2.INTER_LINEAR,
                         borderMode=cv2.BORDER_CONSTANT, borderValue=float("nan"))


def smooth_for_display(field: np.ndarray, geom: Geometry, sigma_m: float = DISPLAY_SMOOTH_M) -> np.ndarray:
    """Light Gaussian smoothing (dBZ) before the field is coloured.

    The 1 km composite is noisy near the class thresholds: in light,
    widespread rain about a quarter of the pixels changed colour between
    two 5-minute frames even where the rain only moved, which made the
    animation flicker. 1.5 km takes the pixel noise out (forecast frames:
    29% -> 22% changed pixels per step) without blurring showers away.
    No coverage (NaN) stays NaN; it counts as "no echo" for its neighbours.
    """
    if sigma_m <= 0:
        return field
    nodata = np.isnan(field)
    out = cv2.GaussianBlur(np.nan_to_num(field, nan=UNDETECT_DBZ), (0, 0), sigma_m / geom.xscale)
    out[nodata] = np.nan
    return out
