"""Writes tests/testdata/water-index-sample.bin, a tiny water index written by
the real Python writer, so the PHP tests (WaterDistanceServiceTest) check that
both sides agree on the format. Re-run after changing water_index.py:

    tools/water/.venv/Scripts/python tools/water/make_test_fixture.py

Around 56.0 N / 9.0 E:
    sea    an east-west line 100 m north of it (lat 56.000898, lng 8.99-9.01)
    river  a north-south line 300 m east of it (lng 9.004820, lat 55.99-56.01)
    lake   a square shore around 56.3 N / 9.0 E (+-0.01 degrees), ~32 km north
"""

import os
import sys

import numpy as np

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import water_index  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def square(lat: float, lng: float, d: float) -> np.ndarray:
    corners = [(lat - d, lng - d), (lat - d, lng + d), (lat + d, lng + d), (lat + d, lng - d), (lat - d, lng - d)]
    return np.array([[*corners[i], *corners[i + 1]] for i in range(4)])


def fixture() -> dict:
    return {
        "sea": np.array([[56.000898, 8.99, 56.000898, 9.01]]),
        "river": np.array([[55.99, 9.004820, 56.01, 9.004820]]),
        "lake": square(56.3, 9.0, 0.01),
    }


if __name__ == "__main__":
    target = os.path.join(ROOT, "tests", "testdata", "water-index-sample.bin")
    print(water_index.write(target, fixture()))
    print("written", target)
