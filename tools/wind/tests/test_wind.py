"""Unit tests: python -m unittest discover -s tools/wind/tests"""

from __future__ import annotations

import bz2
import gzip
import json
import os
import struct
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from unittest import mock

import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from ytanwind import blocks, dwd, grib2  # noqa: E402
import threading  # noqa: E402
import time  # noqa: E402
import wind_job  # noqa: E402
from ytanwind.job import Job  # noqa: E402
from ytanwind.settings import WindSettings, load_settings  # noqa: E402


def make_grib(values: np.ndarray, lat0: float, lon0: float, d: float, packing: str = "simple",
              scan: int = 64, holes: np.ndarray | None = None) -> bytes:
    """A one-field GRIB2 message as the DWD writes it (values: rows south -> north when scan=64)."""
    nj, ni = values.shape
    grid = values if scan == 64 else values[::-1]
    flat = grid.reshape(-1).astype(np.float64)
    present = np.ones(flat.size, bool) if holes is None else ~(holes if scan == 64 else holes[::-1]).reshape(-1)
    packed_vals = flat[present]
    ref = float(packed_vals.min())
    e = -6
    x = np.rint((packed_vals - ref) / 2.0 ** e).astype(np.uint16)
    s5_tail = b""
    if packing == "simple":
        tpl, data = 0, x.astype(">u2").tobytes()
    else:
        import imagecodecs
        tpl = 42
        data = imagecodecs.aec_encode(x.astype(">u2"), bitspersample=16, flags=14, blocksize=32, rsi=128)
        s5_tail = struct.pack(">BBH", 14, 32, 128)
    sign = lambda n: (0x8000 | -n) if n < 0 else n  # noqa: E731
    s5 = struct.pack(">IBIHfHHBB", 21 + len(s5_tail), 5, int(present.sum()), tpl, ref, sign(e), 0, 16, 0) + s5_tail
    s6 = struct.pack(">IBB", 6, 6, 255) if holes is None else (
        struct.pack(">IBB", 6 + (flat.size + 7) // 8, 6, 0) + np.packbits(present).tobytes())
    s7 = struct.pack(">IB", 5 + len(data), 7) + data
    la1 = lat0 if scan == 64 else lat0 + (nj - 1) * d
    la2 = lat0 + (nj - 1) * d if scan == 64 else lat0
    lo1 = lon0 % 360
    lo2 = (lon0 + (ni - 1) * d) % 360
    s3 = (struct.pack(">IBBIBBH", 72, 3, 0, flat.size, 0, 0, 0) + b"\x06" + b"\x00" * 15
          + struct.pack(">II", ni, nj) + b"\x00" * 8
          + struct.pack(">iiBiiIIB", round(la1 * 1e6), round(lo1 * 1e6), 0x30, round(la2 * 1e6),
                        round(lo2 * 1e6), round(d * 1e6), round(d * 1e6), scan))
    assert len(s3) == 72, len(s3)
    s1 = struct.pack(">IB", 21, 1) + b"\x00" * 16
    body = s1 + s3 + s5 + s6 + s7 + b"7777"
    return b"GRIB" + b"\x00\x00\x00\x02" + struct.pack(">Q", 16 + len(body)) + body


def ramp(nj: int, ni: int) -> np.ndarray:
    return (np.arange(nj)[:, None] * 0.5 + np.arange(ni)[None, :] * 0.25 - 10.0).astype(np.float32)


class Grib2Test(unittest.TestCase):
    def check(self, packing: str, scan: int, holes=None):
        v = ramp(30, 40)
        f = grib2.decode(make_grib(v, 43.18, -3.94, 0.02, packing, scan, holes))
        self.assertEqual(f.values.shape, (30, 40))
        self.assertAlmostEqual(f.lat0, 43.18, places=5)
        self.assertAlmostEqual(f.lon0, -3.94, places=5)
        self.assertAlmostEqual(f.dlat, 0.02, places=6)
        want = v.copy()
        if holes is not None:
            want[holes] = np.nan
        np.testing.assert_allclose(f.values, want, atol=0.02)

    def test_simple_packing(self):
        self.check("simple", 64)

    def test_ccsds_packing(self):
        self.check("ccsds", 64)

    def test_north_to_south_is_flipped(self):
        self.check("simple", 0)

    def test_bitmap_holes_become_nan(self):
        holes = np.zeros((30, 40), bool)
        holes[:5, :7] = True
        self.check("simple", 64, holes)

    def test_longitude_over_180_is_negative(self):
        f = grib2.decode(make_grib(ramp(4, 5), 40.0, -23.5, 0.0625))
        self.assertAlmostEqual(f.lon0, -23.5, places=5)

    def test_rejects_other_things(self):
        with self.assertRaises(grib2.Grib2Error):
            grib2.decode(b"not grib at all, nothing to see here")
        msg = make_grib(ramp(4, 5), 40.0, 5.0, 0.02)
        with self.assertRaises(grib2.Grib2Error):
            grib2.decode(msg[:-30])  # truncated

    def test_bz2_wrapper(self):
        f = grib2.decode_bz2(bz2.compress(make_grib(ramp(4, 5), 40.0, 5.0, 0.02)))
        self.assertEqual(f.values.shape, (4, 5))


class BlocksTest(unittest.TestCase):
    REGION = [{"name": "t", "south": 54.0, "west": 9.0, "north": 56.0, "east": 12.0}]

    def eu_like(self, fn) -> grib2.Field:
        lat = 29.5 + np.arange(657) * 0.0625
        lon = -23.5 + np.arange(1377) * 0.0625
        vals = np.broadcast_to(fn(lat[:, None], lon[None, :]), (657, 1377))
        return grib2.Field(np.array(vals, np.float32), 29.5, -23.5, 0.0625, 0.0625)

    def decode(self, data: bytes, ch: int = 2) -> np.ndarray:
        return np.frombuffer(data, np.uint8).reshape(blocks.SIZE, blocks.SIZE, ch)

    def test_encode_scale_and_nodata(self):
        out = blocks.encode(np.array([-40.0, 0.0, 40.0, 100.0, np.nan]), -40, 40)
        self.assertEqual(list(out), [0, 127, 254, 254, 255])

    def test_block_values_and_mask(self):
        u = self.eu_like(lambda la, lo: 0.1 * (lo - 10.0) + 0 * la)
        v = self.eu_like(lambda la, lo: 0.1 * (la - 55.0) + 0 * lo)
        bd = blocks.L1.block_deg
        bx, by = int((10 + 180) // bd), int((55 + 90) // bd)
        data = blocks.build_block(blocks.L1, bx, by, self.REGION, [[u], [v]])
        self.assertIsNotNone(data)
        a = self.decode(data)
        lat, lon = blocks.lonlat_axes(blocks.L1, bx, by)
        j, i = int(np.argmin(abs(lat - 55.0))), int(np.argmin(abs(lon - 10.0)))
        got_u = a[j, i, 0] / 254 * 80 - 40
        got_v = a[j, i, 1] / 254 * 80 - 40
        self.assertAlmostEqual(got_u, 0.1 * (lon[i] - 10.0), delta=0.2)
        self.assertAlmostEqual(got_v, 0.1 * (lat[j] - 55.0), delta=0.2)
        # outside the region: no data
        outside = a[:, :, 0] == blocks.NODATA
        self.assertTrue(outside.any())
        self.assertTrue((a[outside, 1] == blocks.NODATA).all())
        self.assertNotEqual(a[j, i, 0], blocks.NODATA)

    def test_neighbouring_blocks_share_their_edge(self):
        u = self.eu_like(lambda la, lo: lo * 0.05 + la * 0.0)
        v = self.eu_like(lambda la, lo: la * 0.0 + 1.0)
        region = [{"name": "wide", "south": 40.0, "west": 0.0, "north": 60.0, "east": 30.0}]
        bd = blocks.L1.block_deg
        bx, by = int((8 + 180) // bd), int((50 + 90) // bd)
        a = self.decode(blocks.build_block(blocks.L1, bx, by, region, [[u], [v]]))
        b = self.decode(blocks.build_block(blocks.L1, bx + 1, by, region, [[u], [v]]))
        np.testing.assert_array_equal(a[:, -1, :], b[:, 0, :])

    def test_empty_block_is_none(self):
        u = self.eu_like(lambda la, lo: lo * 0.0)
        far = [{"name": "x", "south": 60.0, "west": 20.0, "north": 62.0, "east": 22.0}]
        bd = blocks.L1.block_deg
        bx, by = int((10 + 180) // bd), int((55 + 90) // bd)
        self.assertIsNone(blocks.build_block(blocks.L1, bx, by, far, [[u], [u]]))

    def test_block_points_only_where_regions_are(self):
        pts = blocks.block_points(blocks.L1, (29.5, -23.5, 70.5, 62.5), self.REGION)
        self.assertEqual(len(pts), 1)
        bd = blocks.L1.block_deg
        self.assertEqual(pts[0], (int((10 + 180) // bd), int((55 + 90) // bd)))

    def test_fine_field_wins_where_it_has_data_and_coarse_fills_the_rest(self):
        eu = self.eu_like(lambda la, lo: lo * 0.0 + 5.0)
        vals = np.full((200, 200), 20.0, np.float32)  # 4 degrees square near 55N 10E at 0.02
        fine = grib2.Field(vals, 53.0, 8.0, 0.02, 0.02)
        bd = blocks.L2.block_deg
        bx, by = int((10 + 180) // bd), int((55 + 90) // bd)
        a = self.decode(blocks.build_block(blocks.L2, bx, by, self.REGION, [[fine, eu], [fine, eu]]))
        lat, lon = blocks.lonlat_axes(blocks.L2, bx, by)
        j, i = int(np.argmin(abs(lat - 55.0))), int(np.argmin(abs(lon - 10.0)))
        self.assertAlmostEqual(a[j, i, 0] / 254 * 80 - 40, 20.0, delta=0.2)
        # fine field ends at 57N / 12E: beyond it the EU value 5 m/s
        jj, ii = int(np.argmin(abs(lat - 55.95))), int(np.argmin(abs(lon - 11.9)))
        self.assertEqual(blocks.L2.block_deg, 2.56)
        self.assertNotEqual(a[jj, ii, 0], blocks.NODATA)

    def test_trim_d2_removes_edge_and_holes(self):
        v = np.full((100, 100), 3.0, np.float32)
        v[40:45, 40:45] = np.nan
        out = blocks.trim_d2(grib2.Field(v, 50.0, 5.0, 0.02, 0.02)).values
        self.assertTrue(np.isnan(out[0, :]).all() and np.isnan(out[:, 0]).all())
        self.assertTrue(np.isnan(out[30:55, 30:55]).all())  # hole plus the eroded ring around it
        self.assertFalse(np.isnan(out[50, 70]))

    def test_overview_covers_the_regions(self):
        u = self.eu_like(lambda la, lo: lo * 0.0 + 4.0)
        meta, data = blocks.overview(self.REGION, [u, u], (29.5, -23.5, 70.5, 62.5))
        self.assertEqual(meta["res"], 0.25)
        self.assertEqual(len(data), meta["rows"] * meta["cols"] * 2)
        self.assertLessEqual(meta["south"], 54.0)
        self.assertGreaterEqual(meta["south"] + (meta["rows"] - 1) * 0.25, 56.0)
        a = np.frombuffer(data, np.uint8).reshape(meta["rows"], meta["cols"], 2)
        self.assertTrue((a[..., 0] != blocks.NODATA).any())

    def test_pack_is_gzip(self):
        self.assertEqual(gzip.decompress(blocks.pack(b"abc")), b"abc")


class DwdTest(unittest.TestCase):
    def test_file_names(self):
        run = datetime(2026, 10, 18, 6, tzinfo=timezone.utc)
        self.assertTrue(dwd.ICON_D2.url(run, 10, "u_10m").endswith(
            "icon-d2/grib/06/u_10m/icon-d2_germany_regular-lat-lon_single-level_2026101806_010_2d_u_10m.grib2.bz2"))
        self.assertTrue(dwd.ICON_EU.url(run, 10, "u_10m").endswith(
            "icon-eu/grib/06/u_10m/icon-eu_europe_regular-lat-lon_single-level_2026101806_010_U_10M.grib2.bz2"))

    def test_wanted_steps_eu_skips_odd_hours_after_78(self):
        run = datetime(2026, 10, 18, 6, tzinfo=timezone.utc)
        first = run
        last = run + __import__("datetime").timedelta(hours=120)
        steps = dwd.wanted_steps(dwd.ICON_EU, run, first, last)
        self.assertEqual(steps[:3], [0, 1, 2])
        self.assertIn(78, steps)
        self.assertNotIn(79, steps)
        self.assertIn(81, steps)
        self.assertEqual(steps[-1], 120)

    def test_latest_complete_run_skips_a_run_still_uploading(self):
        now = datetime(2026, 10, 18, 21, 30, tzinfo=timezone.utc)
        first = datetime(2026, 10, 18, 20, tzinfo=timezone.utc)
        last = datetime(2026, 10, 18, 23, tzinfo=timezone.utc)

        def listing(model, run, var):
            if run.hour == 21:
                return {0}  # barely started
            if run.hour == 18:
                return set(range(0, 49))
            return set()
        with mock.patch.object(dwd, "listed_steps", listing):
            got = dwd.latest_complete_run(dwd.ICON_D2, now, first, last, ("u_10m", "v_10m"))
        self.assertIsNotNone(got)
        self.assertEqual(got[0].hour, 18)
        self.assertEqual(got[1], [2, 3, 4, 5])

    def test_six_hour_interval_only_looks_at_00_06_12_18(self):
        now = datetime(2026, 10, 18, 22, tzinfo=timezone.utc)
        seen = []

        def listing(model, run, var):
            seen.append(run.hour)
            return set()
        with mock.patch.object(dwd, "listed_steps", listing):
            dwd.latest_complete_run(dwd.ICON_EU, now, now, now, ("u_10m",), every=6, candidates=3)
        self.assertTrue(all(h % 6 == 0 for h in seen))


class LockTest(unittest.TestCase):
    """The lock shared with the rain radar job (storage/.jobs.lock)."""

    def test_second_holder_is_refused_without_waiting(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, ".jobs.lock")
            with wind_job.Lock(path) as first:
                self.assertTrue(first)
                with wind_job.Lock(path) as second:
                    self.assertFalse(second)
            with wind_job.Lock(path) as again:  # free again once the first one let go
                self.assertTrue(again)

    def test_waiting_holder_gets_the_lock_when_it_is_released_in_time(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, ".jobs.lock")
            holder = wind_job.Lock(path)
            self.assertTrue(holder.__enter__())
            threading.Timer(0.6, lambda: holder.__exit__(None, None, None)).start()
            started = time.monotonic()
            with wind_job.Lock(path, wait_seconds=5, poll_seconds=0.1) as waiter:
                self.assertTrue(waiter)
            self.assertGreaterEqual(time.monotonic() - started, 0.5)
            self.assertLess(time.monotonic() - started, 3)

    def test_waiting_gives_up_after_the_time_is_up(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, ".jobs.lock")
            with wind_job.Lock(path):
                started = time.monotonic()
                with wind_job.Lock(path, wait_seconds=0.5, poll_seconds=0.1) as waiter:
                    self.assertFalse(waiter)
                self.assertGreaterEqual(time.monotonic() - started, 0.5)


class SettingsTest(unittest.TestCase):
    def test_defaults_without_file(self):
        with tempfile.TemporaryDirectory() as d:
            s = load_settings(d)
        self.assertEqual((s.horizon_hours, s.run_interval_hours, s.gusts, s.fine), (48, 3, False, True))
        self.assertEqual(s.channels, 2)
        self.assertTrue(s.enabled)

    def test_reads_regions_and_wind(self):
        with tempfile.TemporaryDirectory() as d:
            with open(os.path.join(d, "config.json"), "w") as f:
                json.dump({"regions": [{"name": "x", "south": 1, "west": 2, "north": 3, "east": 4}],
                           "wind": {"horizon_hours": 72, "run_interval_hours": 6, "gusts": True,
                                    "fine": False, "workers": 99}}, f)
            s = load_settings(d)
        self.assertEqual(len(s.regions), 1)
        self.assertEqual((s.horizon_hours, s.run_interval_hours, s.gusts, s.fine, s.workers),
                         (72, 6, True, False, 8))
        self.assertEqual(s.channels, 3)

    def test_invalid_values_fall_back(self):
        with tempfile.TemporaryDirectory() as d:
            with open(os.path.join(d, "config.json"), "w") as f:
                json.dump({"wind": {"horizon_hours": 13, "run_interval_hours": "x"}}, f)
            s = load_settings(d)
        self.assertEqual((s.horizon_hours, s.run_interval_hours), (48, 3))

    def test_the_area_is_one_rectangle_around_all_regions_plus_a_degree(self):
        s = WindSettings(regions=[{"name": "a", "south": 54.0, "west": 9.0, "north": 56.0, "east": 12.0},
                                  {"name": "b", "south": 47.0, "west": 5.0, "north": 50.0, "east": 8.0}])
        (box,) = s.padded_regions()
        self.assertEqual((box["south"], box["west"], box["north"], box["east"]), (46.0, 4.0, 57.0, 13.0))
        self.assertEqual(s.regions[0]["south"], 54.0)  # the originals stay as they are

    def test_the_area_stays_on_the_globe(self):
        s = WindSettings(regions=[{"name": "b", "south": -90.0, "west": -180.0, "north": -80.0, "east": -170.0}])
        (box,) = s.padded_regions()
        self.assertEqual((box["south"], box["west"]), (-90.0, -180.0))

    def test_signature_changes_with_the_output(self):
        a, b = WindSettings(), WindSettings(horizon_hours=72)
        self.assertNotEqual(a.signature(), b.signature())


class JobTest(unittest.TestCase):
    """A whole run against a fake DWD server."""

    NOW = datetime(2026, 10, 18, 21, 30, tzinfo=timezone.utc)

    def fake_http(self, url: str, timeout: int = 60, tries: int = 3) -> bytes:
        self.requested.append(url)
        if "icon-eu" in url:
            lat0, lon0, d, nj, ni = 29.5, -23.5, 0.0625, 657, 1377
        else:
            lat0, lon0, d, nj, ni = 43.18, -3.94, 0.02, 200, 300
        val = 6.0 if "u_10m" in url else -3.0
        return bz2.compress(make_grib(np.full((nj, ni), val, np.float32) + np.arange(ni, dtype=np.float32) * 0.001,
                                      lat0, lon0, d, "ccsds" if "icon-eu" in url else "simple"))

    def test_run_publishes_a_manifest_and_blocks(self):
        self.requested = []
        steps = set(range(0, 49))
        settings = WindSettings(regions=[{"name": "t", "south": 54.0, "west": 9.0, "north": 56.0, "east": 12.0}],
                                horizon_hours=3, workers=2)
        with tempfile.TemporaryDirectory() as d, \
                mock.patch.object(dwd, "http_get", self.fake_http), \
                mock.patch.object(dwd, "listed_steps", lambda m, r, v: steps):
            job = Job(d, settings, now=self.NOW)
            info = job.run()
            self.assertEqual(job.read_status()["last_run"]["outcome"], "processed")
            m = job.read_manifest()
            self.assertEqual(m["sources"], {"eu": "2026101821", "d2": "2026101821"})
            self.assertEqual(len(m["hours"]), 4)  # window 20:00-24:00, the run starts at 21: 21, 22, 23, 00
            self.assertEqual(m["channels"], 2)
            self.assertGreaterEqual(len(m["levels"]["L1"]["blocks"]), 1)
            run_dir = os.path.join(d, "runs", m["run"])
            self.assertTrue(os.path.isfile(os.path.join(run_dir, "L0", "000.bin.gz")))
            block = m["levels"]["L1"]["blocks"][0]
            raw = gzip.decompress(open(os.path.join(run_dir, "L1", "000", block + ".bin.gz"), "rb").read())
            self.assertEqual(len(raw), blocks.SIZE * blocks.SIZE * 2)
            self.assertIsNotNone(info)

            # second run with the same model runs does nothing
            before = len(self.requested)
            self.assertIsNone(Job(d, settings, now=self.NOW).run())
            self.assertEqual(len(self.requested), before)
            self.assertEqual(Job(d, settings).read_status()["last_run"]["outcome"], "unchanged")

            # --force does it again
            self.assertIsNotNone(Job(d, settings, force=True, now=self.NOW).run())

    def test_failed_download_keeps_the_old_manifest(self):
        steps = set(range(0, 49))
        settings = WindSettings(regions=[{"name": "t", "south": 54.0, "west": 9.0, "north": 56.0, "east": 12.0}],
                                horizon_hours=2, workers=1, fine=False)
        self.requested = []
        with tempfile.TemporaryDirectory() as d:
            with mock.patch.object(dwd, "http_get", self.fake_http), \
                    mock.patch.object(dwd, "listed_steps", lambda m, r, v: steps):
                Job(d, settings, now=self.NOW).run()
            old = Job(d, settings).read_manifest()

            def broken(url, timeout=60, tries=3):
                raise OSError("down")
            later = datetime(2026, 10, 19, 0, 30, tzinfo=timezone.utc)
            with mock.patch.object(dwd, "http_get", broken), \
                    mock.patch.object(dwd, "listed_steps", lambda m, r, v: steps):
                with self.assertRaises(OSError):
                    Job(d, settings, now=later).run()
            job = Job(d, settings)
            self.assertEqual(job.read_manifest()["run"], old["run"])
            self.assertEqual(job.read_status()["last_run"]["outcome"], "failed")
            self.assertFalse([n for n in os.listdir(os.path.join(d, "runs")) if n.endswith(".tmp")])

    def test_switched_off_does_nothing(self):
        with tempfile.TemporaryDirectory() as d, mock.patch.object(dwd, "listed_steps", self.fail):
            with open(os.path.join(d, "manifest.json"), "w") as f:
                f.write("{}")
            self.assertIsNone(Job(d, WindSettings(enabled=False), now=self.NOW).run())
            self.assertFalse(os.path.exists(os.path.join(d, "manifest.json")))
            self.assertEqual(Job(d, WindSettings()).read_status()["last_run"]["outcome"], "disabled")

    def test_no_run_available(self):
        settings = WindSettings(horizon_hours=2)
        with tempfile.TemporaryDirectory() as d, mock.patch.object(dwd, "listed_steps", lambda m, r, v: set()):
            self.assertIsNone(Job(d, settings, now=self.NOW).run())
            self.assertEqual(Job(d, settings).read_status()["last_run"]["outcome"], "no_data")


if __name__ == "__main__":
    unittest.main()
