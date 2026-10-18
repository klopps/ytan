"""Unit tests: python -m unittest discover -s tools/tests"""

from __future__ import annotations

import json
import os
import sys
import tempfile
import unittest
from datetime import datetime, timezone

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import ytan_jobs  # noqa: E402
from ytan_jobs import JobSpec  # noqa: E402


def at(h: int, m: int, s: int = 0) -> datetime:
    return datetime(2026, 10, 19, h, m, s, tzinfo=timezone.utc)


def iso(t: datetime) -> str:
    return t.strftime("%Y-%m-%dT%H:%M:%SZ")


RADAR = JobSpec("radar", "radar/radar_job.py", 5)
WIND = JobSpec("wind", "wind/wind_job.py", 15)


class DueTest(unittest.TestCase):
    def test_never_ran_is_due(self):
        self.assertTrue(ytan_jobs.is_due(WIND, None, at(0, 3)))

    def test_wind_runs_once_per_quarter_hour_slot(self):
        # last run in slot 00:00-00:15
        last = at(0, 2)
        self.assertFalse(ytan_jobs.is_due(WIND, last, at(0, 5)))
        self.assertFalse(ytan_jobs.is_due(WIND, last, at(0, 10)))
        self.assertTrue(ytan_jobs.is_due(WIND, last, at(0, 15, 1)))
        self.assertTrue(ytan_jobs.is_due(WIND, last, at(0, 20)))

    def test_a_run_late_in_its_slot_does_not_push_the_next_one_back(self):
        # started at 00:14:50 (slot 0); the tick at 00:15:01 is slot 1 -> due again (cheap check)
        self.assertTrue(ytan_jobs.is_due(WIND, at(0, 14, 50), at(0, 15, 1)))

    def test_radar_every_five_minute_tick(self):
        self.assertTrue(ytan_jobs.is_due(RADAR, at(0, 0, 40), at(0, 5, 1)))
        self.assertFalse(ytan_jobs.is_due(RADAR, at(0, 5, 2), at(0, 7)))  # a manual run just before


class LastStartTest(unittest.TestCase):
    def test_reads_last_run_start(self):
        with tempfile.TemporaryDirectory() as d:
            os.makedirs(os.path.join(d, "wind"))
            with open(os.path.join(d, "wind", "status.json"), "w") as f:
                json.dump({"last_run": {"start": iso(at(0, 2)), "outcome": "processed"}}, f)
            self.assertEqual(ytan_jobs.last_start(d, "wind"), at(0, 2))

    def test_missing_or_broken_file_means_never(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertIsNone(ytan_jobs.last_start(d, "wind"))
            os.makedirs(os.path.join(d, "wind"))
            with open(os.path.join(d, "wind", "status.json"), "w") as f:
                f.write("{not json")
            self.assertIsNone(ytan_jobs.last_start(d, "wind"))
            with open(os.path.join(d, "wind", "status.json"), "w") as f:
                json.dump({"running": {}}, f)
            self.assertIsNone(ytan_jobs.last_start(d, "wind"))


class RunDueTest(unittest.TestCase):
    def status(self, d: str, name: str, start: datetime) -> None:
        os.makedirs(os.path.join(d, name), exist_ok=True)
        with open(os.path.join(d, name, "status.json"), "w") as f:
            json.dump({"last_run": {"start": iso(start)}}, f)

    def test_runs_the_due_jobs_in_order_one_after_the_other(self):
        calls = []
        with tempfile.TemporaryDirectory() as d:
            result = ytan_jobs.run_due([RADAR, WIND], d, at(0, 15, 1), runner=lambda s: calls.append(s.name) or 0)
        self.assertEqual(calls, ["radar", "wind"])
        self.assertEqual(result, {"radar": 0, "wind": 0})

    def test_a_job_that_is_not_due_is_left_out(self):
        calls = []
        with tempfile.TemporaryDirectory() as d:
            self.status(d, "wind", at(0, 2))
            result = ytan_jobs.run_due([RADAR, WIND], d, at(0, 10, 1), runner=lambda s: calls.append(s.name) or 0)
        self.assertEqual(calls, ["radar"])
        self.assertIsNone(result["wind"])

    def test_a_failing_job_does_not_stop_the_next_one(self):
        def runner(spec):
            if spec.name == "radar":
                raise OSError("cannot start")
            return 3
        with tempfile.TemporaryDirectory() as d:
            with self.assertLogs("ytanjobs", level="ERROR"):
                result = ytan_jobs.run_due([RADAR, WIND], d, at(0, 15, 1), runner=runner)
        self.assertEqual(result, {"radar": -1, "wind": 3})


class ChildProcessTest(unittest.TestCase):
    def test_child_gets_the_lock_held_marker_and_runs_to_the_end(self):
        with tempfile.TemporaryDirectory() as d:
            out = os.path.join(d, "out.txt")
            script = os.path.join(d, "child.py")
            with open(script, "w") as f:
                f.write("import os, sys\nopen(sys.argv[0] + '.out', 'w').write(os.environ.get('YTAN_JOBS_LOCK_HELD', ''))\nsys.exit(0)\n")
            code = ytan_jobs.run_job(JobSpec("x", script, 5))  # an absolute path wins over tools/
            self.assertEqual(code, 0)
            with open(script + ".out") as f:
                self.assertEqual(f.read(), "1")
            del out

    def test_exit_code_comes_back(self):
        with tempfile.TemporaryDirectory() as d:
            script = os.path.join(d, "child.py")
            with open(script, "w") as f:
                f.write("import sys\nsys.exit(7)\n")
            self.assertEqual(ytan_jobs.run_job(JobSpec("x", script, 5)), 7)


class LockTest(unittest.TestCase):
    def test_second_tick_is_refused(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, ".jobs.lock")
            with ytan_jobs.Lock(path) as first:
                self.assertTrue(first)
                with ytan_jobs.Lock(path) as second:
                    self.assertFalse(second)


if __name__ == "__main__":
    unittest.main()
