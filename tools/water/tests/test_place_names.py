import os
import sys
import unittest

import numpy as np
import shapely

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import place_names  # noqa: E402


def metric_box(lng0, lat0, lng1, lat1):
    x0, y0 = place_names.TO_METRIC.transform(lng0, lat0)
    x1, y1 = place_names.TO_METRIC.transform(lng1, lat1)
    return shapely.box(x0, y0, x1, y1)


class PlaceNamesTest(unittest.TestCase):
    def setUp(self):
        self.area_names = ["Big Park", "Small Reserve"]
        self.area_shapes = [metric_box(9.0, 56.0, 10.0, 57.0), metric_box(9.4, 56.4, 9.6, 56.6)]
        self.place_names = ["Nearby Village", "Far Town"]
        self.place_xy = np.array([place_names.TO_METRIC.transform(12.01, 55.0), place_names.TO_METRIC.transform(14.0, 55.0)])

    def run_lookup(self, points):
        return place_names.lookup(points, self.area_names, self.area_shapes, self.place_names, self.place_xy)

    def test_smallest_protected_area_wins(self):
        result = self.run_lookup([{"id": 1, "lat": 56.5, "lng": 9.5}, {"id": 2, "lat": 56.1, "lng": 9.1}])
        self.assertEqual({"1": "Small Reserve", "2": "Big Park"}, result)

    def test_nearest_place_outside_areas_and_a_limit(self):
        result = self.run_lookup([{"id": 3, "lat": 55.0, "lng": 12.0}, {"id": 4, "lat": 60.0, "lng": 20.0}])
        self.assertEqual({"3": "Nearby Village"}, result, "id 4 is farther than MAX_PLACE_METERS from any place")

    def test_protected_area_filter(self):
        self.assertTrue(place_names.is_protected_area({"boundary": "protected_area", "name": "X"}))
        self.assertTrue(place_names.is_protected_area({"leisure": "nature_reserve", "name": "X"}))
        self.assertFalse(place_names.is_protected_area({"boundary": "protected_area"}), "a name is needed")
        self.assertFalse(place_names.is_protected_area({"boundary": "administrative", "name": "X"}))


if __name__ == "__main__":
    unittest.main()
