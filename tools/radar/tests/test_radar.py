"""Unit tests: python -m unittest discover -s tools/radar/tests"""

from __future__ import annotations

import os
import sys
import tempfile
import unittest
from datetime import datetime, timezone

import numpy as np
import pyproj
from PIL import Image

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from ytanradar import nowcast, opera, palette  # noqa: E402
from ytanradar.job import floor_step  # noqa: E402
from ytanradar.mercator import TILE, TileGrid, lat_to_px, lon_to_px, px_to_lat, px_to_lon  # noqa: E402
from ytanradar.reproject import Reprojector  # noqa: E402
from ytanradar.tiles import write_pyramid  # noqa: E402

# The real OPERA grid as measured 2026-10-17.
OPERA = opera.Geometry(
    projdef="+proj=laea +lat_0=55.0 +lon_0=10.0 +x_0=1950000.0 +y_0=-2100000.0 +units=m +ellps=WGS84",
    xsize=3800, ysize=4400, xscale=1000.0, yscale=1000.0, x0=0.0, y0=0.0,
)


class MercatorTest(unittest.TestCase):
    def test_round_trip(self):
        for lon, lat in [(10.15, 54.33), (12.6, 55.7), (-170.0, -60.0)]:
            self.assertAlmostEqual(float(px_to_lon(lon_to_px(lon, 8), 8)), lon, places=9)
            self.assertAlmostEqual(float(px_to_lat(lat_to_px(lat, 8), 8)), lat, places=9)

    def test_known_tile(self):
        # Kiel lies in Google tile z8 x=135 y=81
        self.assertEqual(int(lon_to_px(10.15, 8) // TILE), 135)
        self.assertEqual(int(lat_to_px(54.33, 8) // TILE), 81)

    def test_grid_is_whole_tiles_at_zmin(self):
        g = TileGrid(south=47, west=5, north=70, east=25, zmin=5, zmax=8)
        self.assertEqual(g.tile_range, (16, 18, 7, 11))
        self.assertEqual(g.shape, (5 * 2048, 3 * 2048))
        r0, r1, c0, c1 = g.window
        self.assertTrue(0 <= r0 < r1 <= g.shape[0] and 0 <= c0 < c1 <= g.shape[1])


class PaletteTest(unittest.TestCase):
    def test_marshall_palmer(self):
        self.assertAlmostEqual(palette.rate_to_dbz(1.0), 23.01, places=2)
        self.assertAlmostEqual(float(palette.dbz_to_rate(palette.rate_to_dbz(5.0))), 5.0, places=6)

    def test_classify(self):
        t = palette.THRESHOLDS_DBZ
        dbz = np.array([np.nan, -32, t[0] - 0.01, t[0], t[3] + 0.1, 99], dtype=np.float32)
        self.assertEqual(palette.classify(dbz).tolist(), [0, 0, 0, 1, 4, len(palette.CLASSES)])


class EncodingTest(unittest.TestCase):
    def test_round_trip_keeps_nodata_and_undetect_apart(self):
        dbz = np.array([np.nan, opera.UNDETECT_DBZ, -10.0, 23.5, 60.0], dtype=np.float32)
        back = opera.decode(opera.encode(dbz))
        self.assertTrue(np.isnan(back[0]))
        np.testing.assert_allclose(back[1:], dbz[1:], atol=0.25)


class ReprojectTest(unittest.TestCase):
    def test_hot_pixel_lands_where_it_belongs(self):
        """A single echo at a known place must show up there in the mosaic."""
        grid = TileGrid(south=54.0, west=10.0, north=56.0, east=13.0, zmin=7, zmax=8)
        rp = Reprojector(grid, OPERA)
        tr = pyproj.Transformer.from_crs("EPSG:4326", pyproj.CRS(OPERA.projdef), always_xy=True)
        lon, lat = 12.0, 55.0
        x, y = tr.transform(lon, lat)
        row, col = int(-y // 1000), int(x // 1000)
        cr0, cr1, cc0, cc1 = rp.crop
        field = np.full((cr1 - cr0, cc1 - cc0), opera.UNDETECT_DBZ, np.float32)
        field[row - cr0, col - cc0] = 50.0
        out = rp.render(field)
        r, c = np.unravel_index(np.nanargmax(out), out.shape)
        ox, oy = grid.origin_px
        w_r0, _, w_c0, _ = grid.window
        got_lon = float(px_to_lon(c + w_c0 + ox + 0.5, grid.zmax))
        got_lat = float(px_to_lat(r + w_r0 + oy + 0.5, grid.zmax))
        # pixel centre of the 1 km composite cell vs. 1 z8 pixel (~350 m)
        self.assertLess(abs(got_lon - lon) * 111 * np.cos(np.radians(lat)), 1.0)
        self.assertLess(abs(got_lat - lat) * 111, 1.0)

    def test_outside_the_composite_is_nodata(self):
        grid = TileGrid(south=54.0, west=10.0, north=56.0, east=13.0, zmin=7, zmax=8)
        rp = Reprojector(grid, OPERA)
        cr0, cr1, cc0, cc1 = rp.crop
        field = np.full((cr1 - cr0, cc1 - cc0), np.nan, np.float32)
        self.assertTrue(np.isnan(rp.render(field)).all())


class TilesTest(unittest.TestCase):
    def test_pyramid_writes_only_non_empty_tiles_and_keeps_maximum(self):
        grid = TileGrid(south=54.0, west=10.0, north=55.0, east=11.0, zmin=7, zmax=8)
        r0, r1, c0, c1 = grid.window
        idx = np.zeros((r1 - r0, c1 - c0), np.uint8)
        idx[0, 0] = 9  # one pixel of the heaviest class
        idx[1, 1] = 2
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(write_pyramid(grid, idx, d), 2)  # one tile per zoom
            tx0, _, ty0, _ = grid.tile_range
            z8 = os.path.join(d, "8", str((tx0 * 2 * TILE + c0) // TILE), f"{(ty0 * 2 * TILE + r0) // TILE}.png")
            self.assertTrue(os.path.exists(z8))
            with Image.open(z8) as img:
                self.assertEqual(img.mode, "P")
                self.assertEqual(img.info.get("transparency"), 0)
            z7 = os.path.join(d, "7", str((tx0 * 2 * TILE + c0) // TILE // 2), f"{(ty0 * 2 * TILE + r0) // TILE // 2}.png")
            with Image.open(z7) as img:
                self.assertEqual(int(np.asarray(img).max()), 9)


class NowcastTest(unittest.TestCase):
    def blob(self, cx, cy, shape=(300, 300)):
        yy, xx = np.mgrid[0:shape[0], 0:shape[1]]
        f = 45 - 0.02 * ((xx - cx) ** 2 + (yy - cy) ** 2)
        return np.maximum(f, opera.UNDETECT_DBZ).astype(np.float32)

    def test_moving_cell_is_extrapolated(self):
        frames = [self.blob(100 + 3 * i, 120 + 2 * i) for i in range(3)]  # 3 px right, 2 px down per 5 min
        motion = nowcast.motion_field(frames)
        self.assertIsNotNone(motion)
        vx, vy = motion
        self.assertAlmostEqual(float(vx[124, 106]), 3.0, delta=0.5)
        self.assertAlmostEqual(float(vy[124, 106]), 2.0, delta=0.5)
        fc = nowcast.extrapolate(frames[-1], motion, 4)
        r, c = np.unravel_index(np.argmax(fc[-1]), fc[-1].shape)
        self.assertAlmostEqual(c, 106 + 12, delta=2)
        self.assertAlmostEqual(r, 124 + 8, delta=2)

    def test_no_rain_means_persistence_and_nodata_stays_nodata(self):
        f = np.full((50, 50), opera.UNDETECT_DBZ, np.float32)
        f[:, :10] = np.nan
        self.assertIsNone(nowcast.motion_field([f, f]))
        out = nowcast.extrapolate(f, None, 3)
        self.assertEqual(len(out), 3)
        self.assertTrue(np.isnan(out[2][:, :10]).all())

    def test_csi(self):
        a = np.array([30, 30, 0, 0, np.nan], np.float32)
        b = np.array([30, 0, 30, 0, 30], np.float32)
        self.assertEqual(nowcast.csi(a, b, 23.0), (1 / 3, 2))


class JobTest(unittest.TestCase):
    def test_floor_step(self):
        t = datetime(2026, 10, 17, 4, 19, 59, tzinfo=timezone.utc)
        self.assertEqual(floor_step(t), datetime(2026, 10, 17, 4, 15, tzinfo=timezone.utc))


if __name__ == "__main__":
    unittest.main()
