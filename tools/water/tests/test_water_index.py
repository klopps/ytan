import os
import sys
import tempfile
import unittest

import numpy as np

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import build_index  # noqa: E402
import water_index  # noqa: E402


class WaterIndexTest(unittest.TestCase):
    def setUp(self):
        handle, self.path = tempfile.mkstemp(suffix=".bin")
        os.close(handle)

    def tearDown(self):
        os.remove(self.path)

    def test_header_is_64_bytes(self):
        self.assertEqual(64, water_index.HEADER.size)

    def test_a_segment_is_in_every_cell_its_box_touches(self):
        # 0.12 degrees long east-west at 56.01: cells (1120, 179..181) with 0.05-degree cells.
        stats = water_index.write(self.path, {"sea": np.array([[56.01, 8.96, 56.01, 9.08]])})
        self.assertEqual(1, stats["segments"]["sea"])
        self.assertEqual(3, stats["stored_segments"])
        for col in (179, 180, 181):
            cell = water_index.read_cell(self.path, "sea", 1120, col)
            np.testing.assert_allclose(cell, [[56.01, 8.96, 56.01, 9.08]])
        self.assertEqual(0, len(water_index.read_cell(self.path, "river", 1120, 180)), "other kinds stay empty")

    def test_kinds_and_cells_are_kept_apart(self):
        water_index.write(self.path, {
            "sea": np.array([[56.01, 9.01, 56.02, 9.02]]),
            "river": np.array([[56.11, 9.01, 56.12, 9.02], [56.13, 9.01, 56.14, 9.02]]),
            "lake": np.array([[60.01, 20.01, 60.02, 20.02]]),
        })
        self.assertEqual(1, len(water_index.read_cell(self.path, "sea", 1120, 180)))
        self.assertEqual(2, len(water_index.read_cell(self.path, "river", 1122, 180)))
        self.assertEqual(0, len(water_index.read_cell(self.path, "sea", 1122, 180)))
        np.testing.assert_allclose(water_index.read_cell(self.path, "lake", 1200, 400), [[60.01, 20.01, 60.02, 20.02]])
        self.assertEqual(0, len(water_index.read_cell(self.path, "lake", 5000, 400)), "outside the grid")

    def test_negative_coordinates(self):
        water_index.write(self.path, {"sea": np.array([[-33.91, -18.41, -33.92, -18.42]])})
        self.assertEqual(1, len(water_index.read_cell(self.path, "sea", -679, -369)))


class BuildIndexTest(unittest.TestCase):
    def test_lakes_leave_out_ponds_and_river_surfaces(self):
        self.assertTrue(build_index.is_lake({"natural": "water"}))
        self.assertTrue(build_index.is_lake({"natural": "water", "water": "lake"}))
        self.assertTrue(build_index.is_lake({"natural": "water", "water": "reservoir"}))
        for kind in ("pond", "basin", "river", "canal", "wastewater"):
            self.assertFalse(build_index.is_lake({"natural": "water", "water": kind}), kind)
        self.assertFalse(build_index.is_lake({"natural": "wood"}))

    def test_simplifying_keeps_the_shape_and_drops_collinear_points(self):
        line = np.array([[9.0, 56.0], [9.0005, 56.0], [9.001, 56.0], [9.001, 56.001]])  # lng, lat
        segments = build_index.to_segments([line])
        self.assertEqual(2, len(segments), "the middle point of the straight part goes")
        np.testing.assert_allclose(segments[0][:2], [56.0, 9.0], atol=1e-6)
        np.testing.assert_allclose(segments[-1][2:], [56.001, 9.001], atol=1e-6)


if __name__ == "__main__":
    unittest.main()
