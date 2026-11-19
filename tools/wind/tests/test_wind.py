"""Unit tests: python -m unittest discover -s tools/wind/tests"""

from __future__ import annotations

import bz2
import gzip
import io
import json
import os
import re
import struct
import urllib.error
import urllib.parse
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from unittest import mock

import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from ytanwind import arome, blocks, dwd, grib2, icon_global, meps  # noqa: E402
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


def make_grib_unstructured(values: np.ndarray) -> bytes:
    """A GRIB2 message on ICON's icosahedral grid (template 3.101), CCSDS packed, no bitmap."""
    import imagecodecs
    flat = values.reshape(-1).astype(np.float64)
    ref, e = float(flat.min()), -6
    x = np.rint((flat - ref) / 2.0 ** e).astype(np.uint16)
    data = imagecodecs.aec_encode(x.astype(">u2"), bitspersample=16, flags=14, blocksize=32, rsi=128)
    sign = lambda n: (0x8000 | -n) if n < 0 else n  # noqa: E731
    s5 = struct.pack(">IBIHfHHBB", 25, 5, flat.size, 42, ref, sign(e), 0, 16, 0) + struct.pack(">BBH", 14, 32, 128)
    s6 = struct.pack(">IBB", 6, 6, 255)
    s7 = struct.pack(">IB", 5 + len(data), 7) + data
    s3 = struct.pack(">IBBIBBH", 31, 3, 0, flat.size, 0, 0, 101) + b"\x01" + b"\x00" * 16
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

    def test_simple_packing_with_other_bit_widths(self):
        # Météo-France's AROME packs with 11 bits: take a 16 bit message's sections and swap in values of N bits
        sec = grib2._sections(make_grib(ramp(30, 40), 43.18, -3.94, 0.02, "simple"))

        def message(s5: bytes, s7: bytes) -> bytes:
            body = sec[1] + sec[3] + s5 + sec[6] + s7 + b"7777"
            return b"GRIB" + b"\x00\x00\x00\x02" + struct.pack(">Q", 16 + len(body)) + body

        rng = np.random.default_rng(1)
        for nbits in (1, 8, 11, 13, 24, 32):
            x = rng.integers(0, 1 << nbits, 30 * 40, dtype=np.uint64)
            packed = np.packbits(((x[:, None] >> np.arange(nbits - 1, -1, -1, dtype=np.uint64)) & 1)
                                 .astype(np.uint8)).tobytes()
            s5 = bytearray(sec[5])
            s5[11:15] = struct.pack(">f", -3.0)        # reference value
            s5[15:19] = struct.pack(">HH", 0x8000 | 3, 0)  # binary scale -3 (steps of 0.125), decimal scale 0
            s5[19] = nbits
            out = grib2.decode(message(bytes(s5), struct.pack(">IB", 5 + len(packed), 7) + packed))
            want = (-3.0 + x.astype(np.float64) * 0.125).reshape(30, 40)
            # float32 holds 24 bits: wider values are only exact up to its rounding
            np.testing.assert_allclose(out.values, want, rtol=1e-6, atol=1e-6)
        s5 = bytearray(sec[5])
        s5[19] = 40
        with self.assertRaises(grib2.Grib2Error):
            grib2.decode(message(bytes(s5), sec[7]))
        s5[19] = 11   # data section far too short for 1200 values of 11 bits
        with self.assertRaises(grib2.Grib2Error):
            grib2.decode(message(bytes(s5), struct.pack(">IB", 7, 7) + b"\x00\x00"))

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

    def test_unstructured_grid_gives_one_value_per_cell(self):
        v = np.linspace(-12.0, 9.0, 5000).astype(np.float32)
        out = grib2.decode_unstructured(make_grib_unstructured(v))
        self.assertEqual(out.shape, v.shape)
        self.assertLess(float(np.abs(out - v).max()), 0.02)
        with self.assertRaises(grib2.Grib2Error):
            grib2.decode_unstructured(make_grib(ramp(4, 4), 50.0, 5.0, 0.25))  # a lat/lon grid is refused

    def test_regular_decoder_refuses_the_unstructured_grid(self):
        with self.assertRaises(grib2.Grib2Error):
            grib2.decode(make_grib_unstructured(np.zeros(100, np.float32)))

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
        meta, data = blocks.overview(self.REGION, [[u], [u]], (29.5, -23.5, 70.5, 62.5))
        self.assertEqual(meta["res"], 0.25)
        self.assertEqual(len(data), meta["rows"] * meta["cols"] * 2)
        self.assertLessEqual(meta["south"], 54.0)
        self.assertGreaterEqual(meta["south"] + (meta["rows"] - 1) * 0.25, 56.0)
        a = np.frombuffer(data, np.uint8).reshape(meta["rows"], meta["cols"], 2)
        self.assertTrue((a[..., 0] != blocks.NODATA).any())

    def test_overview_takes_the_second_source_where_the_first_has_none(self):
        eu = grib2.Field(np.full((40, 40), 4.0, np.float32), 55.0, 10.0, 0.0625, 0.0625)
        world = grib2.Field(np.full((721, 1441), -8.0, np.float32), -90.0, -180.0, 0.25, 0.25)
        region = [{"name": "t", "south": 54.0, "west": 9.0, "north": 58.0, "east": 14.0}]
        meta, data = blocks.overview(region, [[eu, world], [eu, world]], (-90.0, -180.0, 90.0, 180.0))
        a = np.frombuffer(data, np.uint8).reshape(meta["rows"], meta["cols"], 2)
        north = a[-1, -1, 0]   # outside the small EU field: the world value
        inside = a[int((56.0 - meta["south"]) / 0.25), int((11.0 - meta["west"]) / 0.25), 0]
        self.assertNotEqual(north, blocks.NODATA)
        self.assertGreater(inside, north)  # 4 m/s > -8 m/s

    def test_pack_is_gzip(self):
        self.assertEqual(gzip.decompress(blocks.pack(b"abc")), b"abc")


class DwdTest(unittest.TestCase):
    def test_file_names(self):
        run = datetime(2026, 10, 18, 6, tzinfo=timezone.utc)
        self.assertTrue(dwd.ICON_D2.url(run, 10, "u_10m").endswith(
            "icon-d2/grib/06/u_10m/icon-d2_germany_regular-lat-lon_single-level_2026101806_010_2d_u_10m.grib2.bz2"))
        self.assertTrue(dwd.ICON_EU.url(run, 10, "u_10m").endswith(
            "icon-eu/grib/06/u_10m/icon-eu_europe_regular-lat-lon_single-level_2026101806_010_U_10M.grib2.bz2"))

    def test_global_file_names_and_steps(self):
        run = datetime(2026, 10, 18, 18, tzinfo=timezone.utc)
        self.assertEqual(dwd.ICON_GLOBAL.url(run, 24, "u_10m"),
                         "https://opendata.dwd.de/weather/nwp/icon/grib/18/u_10m/"
                         "icon_global_icosahedral_single-level_2026101818_024_U_10M.grib2.bz2")
        steps = dwd.wanted_steps(dwd.ICON_GLOBAL, run, run, run.replace(day=22))
        self.assertIn(78, steps)
        self.assertNotIn(79, steps)
        self.assertIn(81, steps)

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

    def test_own_wind_regions_replace_the_radars_and_may_lie_anywhere(self):
        with tempfile.TemporaryDirectory() as d:
            with open(os.path.join(d, "config.json"), "w") as f:
                json.dump({"regions": [{"name": "de", "south": 47, "west": 6, "north": 55, "east": 15}],
                           "wind": {"regions": [{"name": "au", "south": -35, "west": 150, "north": -33, "east": 152},
                                                {"name": "bad", "south": 5, "west": 0, "north": 1, "east": 2}]}}, f)
            s = load_settings(d)
            self.assertEqual([r["name"] for r in s.regions], ["au"])  # the invalid one is dropped
            (box,) = s.padded_regions()
            self.assertEqual((box["south"], box["north"]), (-36.0, -32.0))
            with open(os.path.join(d, "config.json"), "w") as f:
                json.dump({"regions": [{"name": "de", "south": 47, "west": 6, "north": 55, "east": 15}],
                           "wind": {"regions": None}}, f)
            self.assertEqual([r["name"] for r in load_settings(d).regions], ["de"])  # null = the radar's

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

    def setUp(self):
        # no test may reach MET Norway or Météo-France (the real AROME key is in .env): MEPS and AROME
        # have their own tests (MepsJobTest, AromeJobTest)
        for module in (meps, arome):
            patch = mock.patch.object(module, "latest_complete_run", lambda *a, **k: None)
            patch.start()
            self.addCleanup(patch.stop)

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
            self.assertEqual(m["sources"], {"eu": "2026101821", "d2": "2026101821", "gl": None, "meps": None, "arome": None})
            self.assertTrue(all("icon_global" not in u for u in self.requested))  # inside Europe: no global files
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

            # an hour later the window has moved, but the model runs are the same: still nothing
            later = datetime(2026, 10, 18, 22, 40, tzinfo=timezone.utc)
            self.assertIsNone(Job(d, settings, now=later).run())
            self.assertEqual(len(self.requested), before)

            # --force does it again
            self.assertIsNotNone(Job(d, settings, force=True, now=self.NOW).run())

    def world_http(self, url: str, timeout: int = 60, tries: int = 3) -> bytes:
        self.requested.append(url)
        if "icon_global" in url:
            val = 5.0 if "U_10M" in url else -2.0
            return bz2.compress(make_grib_unstructured(np.full(1000, val, np.float32)
                                                       + np.arange(1000, dtype=np.float32) * 0.001))
        return self.fake_http(url, timeout, tries)

    def run_world(self, region: dict, directory: str) -> Job:
        steps = set(range(0, 121))
        settings = WindSettings(regions=[region], horizon_hours=3, workers=2)
        index = (np.arange(icon_global.ROWS * icon_global.COLS) % 1000).reshape(
            icon_global.ROWS, icon_global.COLS).astype(np.int32)
        with mock.patch.object(dwd, "http_get", self.world_http), \
                mock.patch.object(dwd, "listed_steps", lambda m, r, v: steps), \
                mock.patch.object(icon_global, "CELLS", 1000), \
                mock.patch.object(icon_global, "load_index", lambda storage: index):
            job = Job(directory, settings, now=self.NOW)
            job.run()
        return job

    def test_outside_europe_ICON_global_is_the_only_source(self):
        self.requested = []
        sydney = {"name": "au", "south": -35.0, "west": 150.0, "north": -33.0, "east": 152.0}
        with tempfile.TemporaryDirectory() as d:
            job = self.run_world(sydney, d)
            self.assertEqual(job.read_status()["last_run"]["outcome"], "processed")
            m = job.read_manifest()
            self.assertEqual(m["sources"], {"eu": None, "d2": None, "gl": "2026101818", "meps": None, "arome": None})
            self.assertEqual(len(m["hours"]), 5)  # the 18 UTC run: 20, 21, 22, 23, 00
            self.assertTrue(all("icon-eu" not in u and "icon-d2" not in u for u in self.requested))
            self.assertGreaterEqual(len(m["levels"]["L1"]["blocks"]), 1)
            self.assertEqual(m["levels"]["L2"]["blocks"], [])
            run_dir = os.path.join(d, "runs", m["run"])
            block = m["levels"]["L1"]["blocks"][0]
            raw = np.frombuffer(gzip.decompress(
                open(os.path.join(run_dir, "L1", "000", block + ".bin.gz"), "rb").read()), np.uint8)
            u = raw.reshape(blocks.SIZE, blocks.SIZE, 2)[..., 0]
            self.assertTrue((u != blocks.NODATA).any())
            self.assertTrue(os.path.isfile(os.path.join(run_dir, "L0", "000.bin.gz")))

    def test_ICON_global_fills_what_ICON_EU_does_not_cover(self):
        self.requested = []
        canaries = {"name": "x", "south": 25.0, "west": -10.0, "north": 31.0, "east": -5.0}  # EU starts at 29.5 N
        with tempfile.TemporaryDirectory() as d:
            job = self.run_world(canaries, d)
            m = job.read_manifest()
            self.assertEqual((m["sources"]["eu"], m["sources"]["gl"]), ("2026101821", "2026101818"))
            run_dir = os.path.join(d, "runs", m["run"])
            raw = np.frombuffer(gzip.decompress(
                open(os.path.join(run_dir, "L0", "000.bin.gz"), "rb").read()), np.uint8)
            meta = m["levels"]["L0"]
            a = raw.reshape(meta["rows"], meta["cols"], 2)
            south_row = int((26.0 - meta["south"]) / meta["res"])  # south of EU: only the global model has it
            north_row = int((30.0 - meta["south"]) / meta["res"])
            mid = int((-7.0 - meta["west"]) / meta["res"])
            self.assertNotEqual(a[south_row, mid, 0], blocks.NODATA)
            self.assertNotEqual(a[north_row, mid, 0], blocks.NODATA)

    def test_world_switch_off_keeps_the_old_behaviour(self):
        steps = set(range(0, 121))
        self.requested = []
        settings = WindSettings(regions=[{"name": "au", "south": -35.0, "west": 150.0, "north": -33.0, "east": 152.0}],
                                horizon_hours=3, world=False)
        with tempfile.TemporaryDirectory() as d, mock.patch.object(dwd, "http_get", self.world_http), \
                mock.patch.object(dwd, "listed_steps", lambda m, r, v: steps):
            self.assertIsNone(Job(d, settings, now=self.NOW).run())
            self.assertEqual(Job(d, settings).read_status()["last_run"]["outcome"], "no_data")

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


def make_dods(arrays: dict[str, np.ndarray]) -> bytes:
    """A DAP2 binary response: DDS text, "Data:", XDR arrays in the order given."""
    names = {np.dtype(np.float32): "Float32", np.dtype(np.float64): "Float64"}
    dds = "Dataset {\n"
    body = b""
    for name, a in arrays.items():
        dims = "".join(f"[d{k} = {n}]" for k, n in enumerate(a.shape))
        dds += f"    {names[a.dtype]} {name}{dims};\n"
        body += struct.pack(">II", a.size, a.size) + a.astype(a.dtype.newbyteorder(">")).tobytes()
    return (dds + "} x.ncml;\n\nData:\n").encode() + body


class MepsTest(unittest.TestCase):
    def test_parse_dods_reads_by_name_and_keeps_shapes(self):
        raw = make_dods({"time": np.arange(3, dtype=np.float64),
                         "y": np.arange(4, dtype=np.float32),
                         "wind": np.arange(6, dtype=np.float32).reshape(2, 3)})
        got = meps.parse_dods(raw)
        self.assertEqual(list(got), ["time", "y", "wind"])
        self.assertEqual(got["wind"].shape, (2, 3))
        self.assertEqual(float(got["wind"][1, 2]), 5.0)
        self.assertEqual(float(got["time"][2]), 2.0)

    def test_parse_dods_refuses_a_truncated_or_error_answer(self):
        raw = make_dods({"a": np.arange(4, dtype=np.float32)})
        with self.assertRaises(ValueError):
            meps.parse_dods(raw[:-4])
        with self.assertRaises(ValueError):
            meps.parse_dods(b"Error { code = 500; }")

    def test_projection_matches_the_files_axes(self):
        # the projection's origin (63.3 N, 15 E) is x = y = 0; the cell grid of the real file (checked 2026-11-19
        # against its 2D latitude/longitude arrays to 0.1 m) runs from x = -1,060,084 m, y = -1,332,517.9 m in 2.5 km steps
        px, py = meps.project(np.array(63.3), np.array(15.0))
        self.assertAlmostEqual(float(px), 0.0, delta=0.01)
        self.assertAlmostEqual(float(py), 0.0, delta=0.01)
        px, py = meps.project(np.array(63.3), np.array(25.0))   # 10 degrees east on the standard parallel
        self.assertGreater(float(px), 4.0e5)
        self.assertGreater(float(py), 0.0)                       # parallels are arcs around the pole: they rise to the east

    def test_rotation_to_east_north(self):
        lon = np.array([15.0, 25.0])
        u, v = meps.to_east_north(np.array([6.0, 6.0]), np.array([0.0, 0.0]), lon)
        self.assertAlmostEqual(float(u[0]), 6.0, places=5)   # on the central meridian grid north = north
        self.assertAlmostEqual(float(v[0]), 0.0, places=5)
        self.assertLess(float(v[1]), 0.0)                    # east of it grid north points to the west of north
        self.assertAlmostEqual(float(np.hypot(u[1], v[1])), 6.0, places=5)

    def test_window_covers_the_regions_and_leaves_out_the_edge_and_d2(self):
        x = np.arange(949, dtype=np.float32) * 2500 - 1060084.0
        y = np.arange(1069, dtype=np.float32) * 2500 - 1332517.9
        box = {"south": 55.0, "west": 5.0, "north": 60.0, "east": 12.0}
        w = meps.plan_window(x, y, box)
        self.assertTrue(w.valid.any())
        self.assertGreaterEqual(w.i1 - w.i0, 10)
        self.assertEqual(w.fi.shape, w.valid.shape)
        d2 = (55.0, 5.0, 57.0, 8.0)
        w2 = meps.plan_window(x, y, box, d2)
        self.assertLess(int(w2.valid.sum()), int(w.valid.sum()))
        self.assertIsNone(meps.plan_window(x, y, {"south": -35.0, "west": 150.0, "north": -33.0, "east": 152.0}))
        # a box on the domain's edge only keeps what is at least EDGE_CELLS inside
        edge = meps.plan_window(x, y, {"south": 49.0, "west": -19.0, "north": 50.5, "east": -17.0})
        self.assertTrue(edge is None or not edge.valid[0, 0])


class MepsJobTest(unittest.TestCase):
    """A whole run with MEPS as an extra source, against a fake THREDDS."""

    NOW = datetime(2026, 11, 19, 13, 30, tzinfo=timezone.utc)
    RUN = datetime(2026, 11, 19, 9, tzinfo=timezone.utc)
    REGION = [{"name": "t", "south": 60.0, "west": 8.0, "north": 62.0, "east": 12.0}]  # south Norway: north of ICON-D2

    def setUp(self):
        self.fetched = []
        self.x = np.arange(949, dtype=np.float32) * 2500 - 1060084.0
        self.y = np.arange(1069, dtype=np.float32) * 2500 - 1332517.9
        self.times = np.array([(self.RUN + timedelta(hours=k) - datetime(1970, 1, 1, tzinfo=timezone.utc)).total_seconds()
                               for k in range(67)])

    def fake_fetch(self, run, constraint, stats=None):
        self.fetched.append(constraint)
        if constraint == "x,y,time":
            return {"x": self.x, "y": self.y, "time": self.times}
        if constraint == "x,y":
            return {"x": self.x, "y": self.y}
        if constraint.startswith("x_wind_10m.x_wind_10m[66]"):
            return {"x_wind_10m": np.zeros((1, 1, 1, 1), np.float32)}
        out = {}
        for part in constraint.split(","):
            name = part.split(".")[0]
            m = re.search(r"\[(\d+):1:(\d+)\]\[(\d+):1:(\d+)\]$", part)
            nj, ni = int(m.group(2)) - int(m.group(1)) + 1, int(m.group(4)) - int(m.group(3)) + 1
            out[name] = np.full((nj, ni), {"x_wind_10m": 6.0, "y_wind_10m": 0.0}.get(name, 9.0), np.float32)
        return out

    def run_job(self, directory: str, **kw) -> Job:
        steps = set(range(0, 49))
        settings = WindSettings(regions=self.REGION, horizon_hours=3, workers=1, **kw)
        with mock.patch.object(dwd, "http_get", JobTest.fake_http.__get__(self)), \
                mock.patch.object(dwd, "listed_steps", lambda m, r, v: steps), \
                mock.patch.object(meps, "listed_runs", lambda: [self.RUN]), \
                mock.patch.object(meps, "fetch", self.fake_fetch):
            job = Job(directory, settings, now=self.NOW)
            job.run()
        return job

    requested: list

    def test_meps_fills_l2_where_icon_d2_has_no_data(self):
        self.requested = []
        with tempfile.TemporaryDirectory() as d:
            job = self.run_job(d)
            m = job.read_manifest()
            self.assertEqual(m["sources"]["meps"], "2026111909")
            self.assertIn("meps", m["key"])
            self.assertGreaterEqual(len(m["levels"]["L2"]["blocks"]), 1)  # without MEPS there would be none here
            run_dir = os.path.join(d, "runs", m["run"])
            block = m["levels"]["L2"]["blocks"][0]
            raw = np.frombuffer(gzip.decompress(
                open(os.path.join(run_dir, "L2", "000", block + ".bin.gz"), "rb").read()), np.uint8)
            u = raw.reshape(blocks.SIZE, blocks.SIZE, 2)[..., 0]
            self.assertTrue((u != blocks.NODATA).any())
            self.assertEqual(job.read_status()["last_processing"]["meps_run"], "2026111909")

    def test_meps_switch_off_does_not_ask_met_norway(self):
        self.requested = []
        with tempfile.TemporaryDirectory() as d:
            job = self.run_job(d, meps=False)
            self.assertIsNone(job.read_manifest()["sources"]["meps"])
            self.assertEqual(job.read_manifest()["levels"]["L2"]["blocks"], [])
        self.assertEqual(self.fetched, [])

    def test_a_meps_outage_only_costs_resolution(self):
        self.requested = []
        steps = set(range(0, 49))
        settings = WindSettings(regions=self.REGION, horizon_hours=3, workers=1)

        def down(*a, **k):
            raise OSError("500 datasetTracker_2")
        with tempfile.TemporaryDirectory() as d, \
                mock.patch.object(dwd, "http_get", JobTest.fake_http.__get__(self)), \
                mock.patch.object(dwd, "listed_steps", lambda m, r, v: steps), \
                mock.patch.object(meps, "listed_runs", down):
            job = Job(d, settings, now=self.NOW)
            job.run()
            self.assertEqual(job.read_status()["last_run"]["outcome"], "processed")
            self.assertIsNone(job.read_manifest()["sources"]["meps"])

    def test_a_run_whose_last_step_is_not_written_is_skipped(self):
        def unfinished(run, constraint, stats=None):
            if constraint.startswith("x_wind_10m.x_wind_10m[66]"):
                return {"x_wind_10m": np.full((1, 1, 1, 1), 9.96921e36, np.float32)}
            return self.fake_fetch(run, constraint, stats)
        with mock.patch.object(meps, "listed_runs", lambda: [self.RUN]), mock.patch.object(meps, "fetch", unfinished):
            first = self.NOW - timedelta(hours=1)
            self.assertIsNone(meps.latest_complete_run(self.NOW, first, first + timedelta(hours=4)))


class FakeAromePortal:
    """Stands in for arome._get: DescribeCoverage and GetCoverage of a run published at RUN."""

    RUN = datetime(2026, 11, 19, 9, tzinfo=timezone.utc)
    HOURS = 51

    def __init__(self, published=None, fail_with=None):
        self.published = {self.RUN} if published is None else set(published)
        self.fail_with = fail_with
        self.calls = []

    def __call__(self, operation, params, key, stats=None, tries=4):
        self.calls.append((operation, dict(params)))
        if self.fail_with:
            raise self.fail_with
        p = {}
        for k, v in params:
            p.setdefault(k, []).append(v)
        run = datetime.strptime(p["coverageid"][0].split("___")[1], "%Y-%m-%dT%H.00.00Z").replace(tzinfo=timezone.utc)
        if run not in self.published:
            raise arome.NoSuchRun("no such coverage")
        if stats is not None:
            stats["files"] = stats.get("files", 0) + 1
        if operation == "DescribeCoverage":
            end = run + timedelta(hours=self.HOURS)
            return f'<x><gml:endPosition frame="#ISO-8601">{end:%Y-%m-%dT%H:%M:%SZ}</gml:endPosition></x>'.encode()
        subsets = {m.group(1): (m.group(2), m.group(3)) for s in p["subset"]
                   for m in [re.match(r"(lat|long)\(([-\d.]+),([-\d.]+)\)", s)] if m}
        south, north = map(float, subsets["lat"])
        west, east = map(float, subsets["long"])
        rows, cols = round((north - south) / 0.025) + 1, round((east - west) / 0.025) + 1
        name = p["coverageid"][0]
        base = 6.0 if name.startswith("U_") else -3.0 if name.startswith("V_") else 9.0
        return make_grib(np.full((rows, cols), base, np.float32) + np.arange(cols, dtype=np.float32) * 0.001,
                         south, west, 0.025)


class AromeTest(unittest.TestCase):
    def setUp(self):
        patch = mock.patch.object(arome, "MIN_GAP_S", 0.0)
        patch.start()
        self.addCleanup(patch.stop)

    def test_window_is_snapped_to_the_grid_and_loses_the_domain_edge(self):
        w = arome.plan_window({"south": 47.0, "west": -5.5, "north": 49.0, "east": -3.0})
        self.assertAlmostEqual((w.south - 37.5) / 0.025, round((w.south - 37.5) / 0.025), places=6)
        self.assertAlmostEqual((w.west + 12.0) / 0.025, round((w.west + 12.0) / 0.025), places=6)
        self.assertLessEqual(w.south, 47.0)
        self.assertGreaterEqual(w.north, 49.0)
        self.assertEqual(w.rows, round((w.north - w.south) / 0.025) + 1)
        edge = arome.plan_window({"south": 30.0, "west": -20.0, "north": 45.0, "east": -5.0})
        self.assertGreaterEqual(edge.south, 37.5 + arome.EDGE_DEG - 0.025)  # nothing of the outer 0.2 degrees
        self.assertGreaterEqual(edge.west, -12.0 + arome.EDGE_DEG - 0.025)
        self.assertIsNone(arome.plan_window({"south": -35.0, "west": 150.0, "north": -33.0, "east": 152.0}))

    def test_coverage_id_and_key_lookup(self):
        self.assertEqual(arome.coverage_id(arome.P_U, datetime(2026, 11, 19, 9, tzinfo=timezone.utc)),
                         arome.P_U + "___2026-11-19T09.00.00Z")
        with mock.patch.dict(os.environ, {"AROME_API_KEY": " abc "}):
            self.assertEqual(arome.api_key(), "abc")

    def test_newest_published_run_with_a_written_last_step_is_taken(self):
        now = datetime(2026, 11, 19, 16, 30, tzinfo=timezone.utc)
        portal = FakeAromePortal()  # the 15 and 12 UTC runs are not there yet, 09 is
        first = now.replace(minute=0) - timedelta(hours=1)
        with mock.patch.object(arome, "_get", portal):
            run, steps = arome.latest_complete_run(now, first, first + timedelta(hours=5), key="k")
        self.assertEqual(run, portal.RUN)
        self.assertEqual(steps, list(range(6, 12)))  # valid 15:00 ... 20:00 = hours 6..11 after 09 UTC
        self.assertEqual(portal.calls[-1][0], "GetCoverage")  # the last step was probed with one tiny request
        self.assertEqual(portal.calls[-1][1]["coverageid"].split("___")[0], arome.P_V)

    def test_a_listed_run_with_an_unwritten_last_step_is_skipped(self):
        now = datetime(2026, 11, 19, 12, 30, tzinfo=timezone.utc)
        portal = FakeAromePortal(published={datetime(2026, 11, 19, 12, tzinfo=timezone.utc), FakeAromePortal.RUN})
        real = portal.__call__

        def half_written(operation, params, key, stats=None, tries=4):
            if operation == "GetCoverage" and "T12.00.00Z" in dict(params)["coverageid"]:
                raise arome.AromeError("HTTP 404: Synopsis backend error")
            return real(operation, params, key, stats, tries)
        with mock.patch.object(arome, "_get", half_written):
            run, _ = arome.latest_complete_run(now, now, now + timedelta(hours=2), key="k")
        self.assertEqual(run, FakeAromePortal.RUN)

    def test_without_a_key_or_with_a_rejected_one_there_is_no_run_and_no_exception(self):
        now = datetime(2026, 11, 19, 16, 30, tzinfo=timezone.utc)
        with mock.patch.object(arome, "api_key", lambda: ""), mock.patch.object(arome, "_get", self.fail):
            self.assertIsNone(arome.latest_complete_run(now, now, now + timedelta(hours=2)))
        rejected = FakeAromePortal(fail_with=arome.AromeError("AROME API key rejected (HTTP 401)"))
        with mock.patch.object(arome, "_get", rejected):
            self.assertIsNone(arome.latest_complete_run(now, now, now + timedelta(hours=2), key="k"))

    def test_http_errors_never_show_the_key_and_429_is_retried(self):
        def http_error(code, body=b"<mw:fault>x</mw:fault>"):
            return urllib.error.HTTPError("https://example.invalid/x?y", code, "err", {}, io.BytesIO(body))
        with mock.patch.object(urllib.request, "urlopen", side_effect=http_error(401)):
            with self.assertRaises(arome.AromeError) as ctx:
                arome._get("GetCoverage", [("coverageid", "x")], "SECRET-KEY-123")
        self.assertNotIn("SECRET-KEY-123", str(ctx.exception))
        self.assertIn("401", str(ctx.exception))

        class Ok:
            def __enter__(self):
                return self
            def __exit__(self, *a):
                return False
            def read(self):
                return b"GRIB"
        answers = [http_error(429), Ok()]

        def urlopen(req, timeout=0):
            a = answers.pop(0)
            if isinstance(a, Exception):
                raise a
            return a
        with mock.patch.object(urllib.request, "urlopen", urlopen), mock.patch.object(time, "sleep", lambda s: None):
            stats = {}
            self.assertEqual(arome._get("GetCoverage", [("coverageid", "x")], "k", stats), b"GRIB")
        self.assertEqual(stats["files"], 1)


class AromeJobTest(unittest.TestCase):
    """A whole run with AROME as an extra source, against a fake portal."""

    NOW = datetime(2026, 11, 19, 16, 30, tzinfo=timezone.utc)
    REGION = [{"name": "t", "south": 47.5, "west": -5.5, "north": 48.5, "east": -4.5}]  # western Brittany: only AROME is finer than ICON-EU

    def setUp(self):
        patches = [mock.patch.object(arome, "MIN_GAP_S", 0.0),
                   mock.patch.object(meps, "latest_complete_run", lambda *a, **k: None),
                   mock.patch.object(dwd, "http_get", JobTest.fake_http.__get__(self)),
                   mock.patch.object(dwd, "listed_steps", lambda m, r, v: set(range(0, 49)))]
        for p in patches:
            p.start()
            self.addCleanup(p.stop)
        self.requested = []

    def run_job(self, directory: str, portal, **kw) -> Job:
        settings = WindSettings(regions=self.REGION, horizon_hours=3, workers=2, **kw)
        with mock.patch.object(arome, "_get", portal), mock.patch.object(arome, "api_key", lambda: "k"):
            job = Job(directory, settings, now=self.NOW)
            job.run()
        return job

    def test_arome_fills_l2_where_icon_d2_has_no_data(self):
        portal = FakeAromePortal()
        with tempfile.TemporaryDirectory() as d:
            job = self.run_job(d, portal)
            m = job.read_manifest()
            self.assertEqual(m["sources"]["arome"], "2026111909")
            self.assertGreaterEqual(len(m["levels"]["L2"]["blocks"]), 1)
            run_dir = os.path.join(d, "runs", m["run"])
            block = m["levels"]["L2"]["blocks"][0]
            with open(os.path.join(run_dir, "L2", "000", block + ".bin.gz"), "rb") as f:
                raw = np.frombuffer(gzip.decompress(f.read()), np.uint8)
            u = raw.reshape(blocks.SIZE, blocks.SIZE, 2)[..., 0]
            self.assertTrue((u != blocks.NODATA).any())
            last = job.read_status()["last_processing"]
            self.assertEqual(last["arome_run"], "2026111909")
            self.assertGreater(last["traffic"]["arome"]["files"], 4)  # planning probes + a u and a v slice per hour
            self.assertGreaterEqual(last["files_downloaded"], last["traffic"]["arome"]["files"])
        slices = [c for c in portal.calls if c[0] == "GetCoverage" and c[1]["coverageid"].startswith(arome.P_U)]
        self.assertGreaterEqual(len(slices), 4)  # one request per hour (a time range is refused by the portal)

    def test_the_gust_field_is_requested_only_with_gusts_on(self):
        portal = FakeAromePortal()
        with tempfile.TemporaryDirectory() as d:
            job = self.run_job(d, portal, gusts=True)
            self.assertEqual(job.read_manifest()["channels"], 3)
        self.assertTrue([c for c in portal.calls if c[1]["coverageid"].startswith(arome.P_GUST)])
        plain = FakeAromePortal()
        with tempfile.TemporaryDirectory() as d:
            self.run_job(d, plain)
        self.assertFalse([c for c in plain.calls if c[1]["coverageid"].startswith(arome.P_GUST)])

    def test_the_first_hour_of_a_run_has_no_gust_field_the_mean_wind_stands_in(self):
        portal = FakeAromePortal()
        window = arome.plan_window(self.REGION[0])
        with mock.patch.object(arome, "_get", portal):
            src = arome.Source(portal.RUN, self.REGION[0], key="k")
            fields = src.fields(0, True)
        self.assertEqual(len(fields), 3)
        self.assertFalse([c for c in portal.calls if c[1]["coverageid"].startswith(arome.P_GUST)])
        np.testing.assert_allclose(fields[2].values, np.hypot(fields[0].values, fields[1].values), rtol=1e-5)
        self.assertEqual(fields[0].values.shape, (window.rows, window.cols))

    def test_switch_off_and_a_missing_key_do_not_ask_the_portal(self):
        portal = FakeAromePortal()
        with tempfile.TemporaryDirectory() as d:
            job = self.run_job(d, portal, arome=False)
            self.assertIsNone(job.read_manifest()["sources"]["arome"])
        self.assertEqual(portal.calls, [])

    def test_an_expired_key_only_costs_resolution(self):
        rejected = FakeAromePortal(fail_with=arome.AromeError("AROME API key rejected (HTTP 401)"))
        with tempfile.TemporaryDirectory() as d:
            job = self.run_job(d, rejected)
            self.assertEqual(job.read_status()["last_run"]["outcome"], "processed")
            self.assertIsNone(job.read_manifest()["sources"]["arome"])

    def test_a_failing_hour_is_built_from_the_other_models(self):
        portal = FakeAromePortal()
        real = portal.__call__
        state = {"slices": 0}

        def flaky(operation, params, key, stats=None, tries=4):
            if operation == "GetCoverage" and dict(params)["coverageid"].startswith(arome.P_U) and "lat(" in str(params):
                state["slices"] += 1
                if state["slices"] == 2:
                    raise arome.AromeError("HTTP 500")
            return real(operation, params, key, stats, tries)
        with tempfile.TemporaryDirectory() as d:
            job = self.run_job(d, flaky)
            self.assertEqual(job.read_status()["last_run"]["outcome"], "processed")
            self.assertEqual(job.read_manifest()["sources"]["arome"], "2026111909")


if __name__ == "__main__":
    unittest.main()
