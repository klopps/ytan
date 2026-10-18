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
from ytanradar.job import Config, Job, floor_step  # noqa: E402
from ytanradar.settings import DEFAULT_REGIONS, JobSettings, grid_for, load_settings, regions_hash  # noqa: E402
from ytanradar import job as jobmod  # noqa: E402
import h5py  # noqa: E402
import json  # noqa: E402
from datetime import timedelta  # noqa: E402
from ytanradar.mercator import TILE, TileGrid, lat_to_px, lon_to_px, px_to_lat, px_to_lon  # noqa: E402
from ytanradar.reproject import Reprojector, smooth_for_display  # noqa: E402
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


class SmoothingTest(unittest.TestCase):
    def test_single_noisy_pixel_is_damped_and_nodata_stays_nodata(self):
        f = np.full((40, 40), opera.UNDETECT_DBZ, np.float32)
        f[:, :5] = np.nan
        f[20, 20] = 40.0  # one isolated 1 km pixel
        f[5:35, 25:35] = 30.0  # a real rain area
        out = smooth_for_display(f, OPERA)
        self.assertTrue(np.isnan(out[:, :5]).all())
        self.assertFalse(np.isnan(out[:, 5:]).any())
        self.assertLess(out[20, 20], palette.rate_to_dbz(0.1))  # speckle gone
        self.assertGreater(out[20, 30], 25.0)  # area kept


class SettingsTest(unittest.TestCase):
    def write(self, d, data):
        with open(os.path.join(d, "config.json"), "w", encoding="utf-8") as f:
            f.write(data if isinstance(data, str) else __import__("json").dumps(data))

    def test_missing_or_broken_file_gives_the_defaults(self):
        defaults = JobSettings()
        self.assertEqual((defaults.history_minutes, defaults.forecast_minutes, defaults.fetch_interval_minutes,
                          defaults.workers), (60, 60, 5, 4))  # same as RadarConfig::defaults()
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(load_settings(d), defaults)
            self.write(d, "{not json")
            self.assertEqual(load_settings(d), defaults)
            self.write(d, {"forecast_minutes": 30, "regions": [{"name": "bad", "south": 5, "north": 1, "west": 0, "east": 1}]})
            got = load_settings(d)
            self.assertEqual((got.regions, got.forecast_minutes), (DEFAULT_REGIONS, 30))

    def test_settings_from_php_are_clamped_to_their_steps(self):
        with tempfile.TemporaryDirectory() as d:
            self.write(d, {"history_minutes": 42, "forecast_minutes": 47, "fetch_interval_minutes": 99,
                           "workers": 0, "regions": [
                               {"name": "dk", "south": 54.5, "west": 7.9, "north": 57.8, "east": 15.3},
                               {"name": "Bornholm", "south": 54.9, "west": 14.6, "north": 55.4, "east": 15.3}]})
            got = load_settings(d)
        self.assertEqual((got.history_minutes, got.forecast_minutes, got.fetch_interval_minutes, got.workers),
                         (40, 45, 15, 1))
        regions = got.regions
        self.assertEqual([r["name"] for r in regions], ["dk", "Bornholm"])
        grid = grid_for(regions)
        self.assertEqual((grid.south, grid.west, grid.north, grid.east), (54.5, 7.9, 57.8, 15.3))

    def test_hash_follows_the_area_not_names_or_order(self):
        a = [{"name": "x", "south": 1, "west": 2, "north": 3, "east": 4}, {"name": "y", "south": 5, "west": 6, "north": 7, "east": 8}]
        b = [dict(a[1], name="other"), a[0]]
        self.assertEqual(regions_hash(a), regions_hash(b))
        self.assertNotEqual(regions_hash(a), regions_hash([a[0]]))


class RegionMaskTest(unittest.TestCase):
    def test_pixels_outside_every_region_stay_empty(self):
        regions = [{"name": "west", "south": 54.0, "west": 10.0, "north": 55.0, "east": 11.0},
                   {"name": "east", "south": 55.0, "west": 12.0, "north": 56.0, "east": 13.0}]
        grid = TileGrid(south=54.0, west=10.0, north=56.0, east=13.0, zmin=7, zmax=8)
        rp = Reprojector(grid, OPERA, regions=regions)
        cr0, cr1, cc0, cc1 = rp.crop
        out = rp.render(np.full((cr1 - cr0, cc1 - cc0), 30.0, np.float32))
        ox, oy = grid.origin_px
        r0, _, c0, _ = grid.window

        def value(lat, lng):
            r = int(lat_to_px(lat, 8) - oy - r0)
            c = int(lon_to_px(lng, 8) - ox - c0)
            return out[r, c]

        self.assertEqual(value(54.5, 10.5), 30.0)   # inside "west"
        self.assertEqual(value(55.5, 12.5), 30.0)   # inside "east"
        self.assertTrue(np.isnan(value(55.5, 10.5)))  # between them
        self.assertTrue(np.isnan(value(54.5, 12.5)))
        # every pixel outside both regions, not just two samples (remap's
        # 16-bit fixed point once wrapped a too large "outside" value around)
        lat = px_to_lat(np.arange(out.shape[0]) + oy + r0 + 0.5, 8)[:, None]
        lon = px_to_lon(np.arange(out.shape[1]) + ox + c0 + 0.5, 8)[None, :]
        inside = np.zeros(out.shape, bool)
        for r in regions:
            inside |= (lat >= r["south"]) & (lat <= r["north"]) & (lon >= r["west"]) & (lon <= r["east"])
        self.assertTrue(np.isnan(out[~inside]).all())
        self.assertGreater(np.count_nonzero(out[inside] == 30.0), 0.95 * inside.sum())


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


def fake_composite(path: str, dbz: float = 35.0, nodes: list | None = None, gap_cols: int = 0) -> None:
    """A small ODIM file like OPERA's: 500 x 500 km of the real LAEA grid
    (around 52-58 N / 7-15 E), rain (dbz) everywhere. nodes: the radar list
    (how/nodes); gap_cols: that many columns on the left without data, as if
    the radar there were missing."""
    tr = pyproj.Transformer.from_crs(pyproj.CRS(OPERA.projdef), "EPSG:4326", always_xy=True)
    x0, y0 = 1600000.0, -1800000.0
    ul_lon, ul_lat = tr.transform(x0, y0)
    data = np.full((500, 500), dbz)
    if gap_cols:
        data[:, :gap_cols] = -9999000.0  # nodata
        data[:, gap_cols:gap_cols + 60] = -8888000.0  # the neighbours see nothing there
    with h5py.File(path, "w") as f:
        w = f.create_group("where")
        for k, v in {"projdef": OPERA.projdef.encode(), "xsize": 500, "ysize": 500, "xscale": 1000.0,
                     "yscale": 1000.0, "UL_lon": ul_lon, "UL_lat": ul_lat}.items():
            w.attrs[k] = v
        d = f.create_group("dataset1/data1")
        d.create_dataset("data", data=data)
        if nodes is not None:
            f.create_group("how").attrs["nodes"] = ", ".join(f"'{n}'" for n in nodes).encode()
        what = d.create_group("what")
        for k, v in {"quantity": b"DBZH", "gain": 1.0, "offset": 0.0, "nodata": -9999000.0,
                     "undetect": -8888000.0}.items():
            what.attrs[k] = v


class FillMissingRadarsTest(unittest.TestCase):
    def test_the_missing_area_and_its_margin_take_the_previous_values(self):
        previous = np.full((200, 200), 40.0, np.float32)
        field = previous.copy()
        field[:, :50] = np.nan  # radar gone: no data
        field[:, 50:80] = opera.UNDETECT_DBZ  # its neighbours see nothing there
        field[:, 150:] = 10.0  # far away: really less rain, must stay
        out, pixels = nowcast.fill_missing_radars(field, previous, None, margin_px=40)
        self.assertGreater(pixels, 0)
        self.assertTrue((out[:, :50] == 40.0).all())
        self.assertTrue((out[:, 50:80] == 40.0).all())
        self.assertTrue((out[:, 150:] == 10.0).all())

    def test_nothing_missing_changes_nothing(self):
        previous = np.full((100, 100), 40.0, np.float32)
        field = np.full((100, 100), 20.0, np.float32)
        out, pixels = nowcast.fill_missing_radars(field, previous, None)
        self.assertEqual(pixels, 0)
        self.assertIs(out, field)


class StatusTest(unittest.TestCase):
    """A whole run offline: downloads replaced by a synthetic composite."""

    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.now = datetime(2026, 10, 18, 10, 2, tzinfo=timezone.utc)
        self.regions = [{"name": "de", "south": 54.5, "west": 10.0, "north": 55.5, "east": 11.5}]
        self.downloads = 0
        self._orig = jobmod.opera.download

        def fake_download(url, dest, timeout=60):
            self.downloads += 1
            fake_composite(dest, nodes=["a", "b", "c"])
            return True

        jobmod.opera.download = fake_download

    def tearDown(self):
        jobmod.opera.download = self._orig
        import shutil
        shutil.rmtree(self.dir, ignore_errors=True)

    def job(self, **kw):
        grid = grid_for(self.regions)
        grid = TileGrid(south=grid.south, west=grid.west, north=grid.north, east=grid.east, zmin=7, zmax=8)
        cfg = Config(storage=self.dir, grid=grid, workers=1, regions=self.regions,
                     regions_hash=regions_hash(self.regions), history_minutes=10, forecast_minutes=10, **kw)
        return Job(cfg, now=self.now)

    def status(self):
        with open(os.path.join(self.dir, "status.json"), encoding="utf-8") as f:
            return json.load(f)

    def test_processed_run_reports_both_phases(self):
        manifest = self.job().run()
        st = self.status()
        self.assertEqual(st["last_run"]["outcome"], "processed")
        p = st["last_processing"]
        self.assertEqual(p["regions"], ["de"])
        self.assertEqual(p["download"]["files"], 4)  # the newest + 10 minutes of history + 1 to fill the oldest
        self.assertGreater(p["download"]["bytes"], 0)
        self.assertLessEqual(p["download"]["start"], p["download"]["end"])
        self.assertLessEqual(p["download"]["end"], p["processing"]["start"])
        self.assertEqual(p["processing"]["frames"], 3 + 2)  # 3 observations + 10 min forecast
        self.assertEqual(p["processing"]["workers"], 1)
        self.assertGreater(p["processing"]["bytes"], 0)
        self.assertGreaterEqual(st["storage_bytes"], p["processing"]["bytes"])
        self.assertEqual(len(manifest["frames"]), 5)
        self.assertEqual(manifest["history_minutes"], 10)

    def test_fetch_interval_skips_runs_in_between(self):
        self.job().run()
        first = self.status()["last_processing"]
        downloads = self.downloads
        # 5 minutes later with a 10 minute interval: nothing is fetched
        self.now += timedelta(minutes=5)
        self.job(fetch_interval_minutes=10).run()
        st = self.status()
        self.assertEqual(st["last_run"]["outcome"], "skipped_interval")
        self.assertEqual(st["last_processing"], first)
        self.assertEqual(self.downloads, downloads)

    def test_frames_missing_radars_are_filled(self):
        """Every second composite lacks a radar (as Spain's in OPERA): its frame
        must still show the rain there."""
        def alternating(url, dest, timeout=60):
            self.downloads += 1
            minute = int(url.split("@")[1][-2:])
            if minute % 10 == 5:
                fake_composite(dest, nodes=["a", "b"], gap_cols=400)  # reaches into the test region
            else:
                fake_composite(dest, nodes=["a", "b", "c"])
            return True
        jobmod.opera.download = alternating
        job = self.job()
        job.run()
        # 10:00 complete, 09:55 and 10:05-style frames incomplete: compare raw and filled
        t = floor_step(self.now) - timedelta(minutes=5)  # 09:55, lacks "c"
        raw, filled = job.load(t), job.field(t)
        self.assertGreater(np.isnan(raw).sum(), 0)
        self.assertLess(np.isnan(filled).sum(), np.isnan(raw).sum())
        rain = palette.rate_to_dbz(0.1)
        self.assertGreater((filled >= rain).sum(), (raw >= rain).sum())

    def test_raw_files_without_the_radar_list_are_fetched_again(self):
        job = self.job()
        job.run()
        t = floor_step(self.now)
        path = job.raw_path(t)
        with np.load(path) as z:  # rewrite it the way it was stored before 2026-10-18
            old = {k: z[k] for k in z.files if k != "nodes"}
        np.savez_compressed(path, **old)
        downloads = self.downloads
        self.assertIsNone(job.load_nodes(t))
        self.assertTrue(job.fetch(t))
        self.assertEqual(self.downloads, downloads + 1)
        self.assertIsNotNone(job.load(t))

    def test_frames_drawn_the_old_way_are_drawn_again(self):
        """Even when the manifest already carries the current render version."""
        job = self.job()
        job.run()
        oid = jobmod.obs_id(floor_step(self.now) - timedelta(minutes=5))
        marker = os.path.join(job.frames_dir, oid, jobmod.COMPLETE_MARKER)
        self.assertTrue(jobmod.is_published(job.frames_dir, oid, job.signature))
        open(marker, "w").close()  # as written before signatures existed
        self.assertFalse(jobmod.is_published(job.frames_dir, oid, job.signature))
        self.now += timedelta(minutes=5)  # a new composite, so the run processes
        job2 = self.job()
        job2.run()
        self.assertTrue(jobmod.is_published(job2.frames_dir, oid, job2.signature))

    def test_a_failed_run_is_recorded(self):
        def broken(url, dest, timeout=60):
            raise OSError("network down")
        jobmod.opera.download = broken
        with self.assertRaises(OSError):
            self.job().run()
        st = self.status()
        self.assertEqual(st["last_run"]["outcome"], "failed")
        self.assertIn("network down", st["last_run"]["error"])
        self.assertNotIn("last_processing", st)


class JobTest(unittest.TestCase):
    def test_floor_step(self):
        t = datetime(2026, 10, 17, 4, 19, 59, tzinfo=timezone.utc)
        self.assertEqual(floor_step(t), datetime(2026, 10, 17, 4, 15, tzinfo=timezone.utc))

    def publish(self, storage: str, workers: int, regions: list | None = None) -> tuple[set, dict]:
        grid = TileGrid(south=54.0, west=10.0, north=56.0, east=13.0, zmin=7, zmax=8)
        job = Job(Config(storage=storage, grid=grid, workers=workers, regions=regions or []))
        job._geom = OPERA
        job._reprojector = Reprojector(grid, OPERA, regions=regions)
        cr0, cr1, cc0, cc1 = job._reprojector.crop
        items = []
        for i in range(3):
            field = np.full((cr1 - cr0, cc1 - cc0), 20.0 + 10 * i, np.float32)  # rain everywhere
            items.append((f"f202610170440-{5 * (i + 1):03d}", field))
        done = job.publish_frames(items)
        files = {}
        for root, _, names in os.walk(job.frames_dir):
            for n in names:
                path = os.path.join(root, n)
                with open(path, "rb") as f:
                    files[os.path.relpath(path, job.frames_dir)] = f.read()
        return done, files

    def test_parallel_publishing_writes_the_same_tiles_as_sequential(self):
        with tempfile.TemporaryDirectory() as a, tempfile.TemporaryDirectory() as b:
            done_seq, files_seq = self.publish(a, 1)
            done_par, files_par = self.publish(b, 2)
        self.assertEqual(len(done_seq), 3)
        self.assertEqual(done_seq, done_par)
        self.assertTrue(files_seq)
        self.assertEqual(files_seq, files_par)

    def test_parallel_workers_keep_the_region_mask(self):
        """Spawned workers (Windows) build their own reprojector - with the regions."""
        regions = [{"name": "a", "south": 54.0, "west": 10.0, "north": 54.5, "east": 10.5}]
        with tempfile.TemporaryDirectory() as a, tempfile.TemporaryDirectory() as b,                 tempfile.TemporaryDirectory() as c:
            _, files_seq = self.publish(a, 1, regions)
            _, files_par = self.publish(b, 2, regions)
            _, files_all = self.publish(c, 1)
        self.assertEqual(files_seq, files_par)
        # rain everywhere, but only tiles touching the region exist at zoom 8
        def zoom8(files):  # paths are {frame}/{z}/{x}/{y}.png
            return [k.split(os.sep) for k in files if k.split(os.sep)[1] == "8"]

        z8 = zoom8(files_par)
        self.assertGreater(len(z8), 0)
        self.assertLess(len(z8), len(zoom8(files_all)))
        r = regions[0]
        for _, _, x, y in z8:
            x, y = int(x), int(y[:-4])
            west, east = float(px_to_lon(x * TILE, 8)), float(px_to_lon((x + 1) * TILE, 8))
            north, south = float(px_to_lat(y * TILE, 8)), float(px_to_lat((y + 1) * TILE, 8))
            self.assertTrue(west <= r["east"] and east >= r["west"] and south <= r["north"] and north >= r["south"],
                            f"tile 8/{x}/{y} lies outside the region")


if __name__ == "__main__":
    unittest.main()
