"""Unit tests: python -m unittest discover -s tools/tests"""

from __future__ import annotations

import json
import os
import shutil
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from types import SimpleNamespace

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import ytan_jobs  # noqa: E402
import ytan_worker  # noqa: E402

NOW = datetime(2026, 11, 12, 16, 0, 5, tzinfo=timezone.utc)


class FakeServer:
    """Stands in for rsync over SSH: the "server" is a directory, commands are recorded."""

    def __init__(self, root: str):
        self.root = root
        self.commands: list[list[str]] = []

    def __call__(self, command, capture_output=True, text=True):
        self.commands.append(command)
        args = command[3:]  # after "rsync -e <ssh>"
        paths = [a for a in args if not a.startswith("-") and not self._is_pattern(args, a)]
        src, dst = paths[-2], paths[-1]
        src = self._local(src)
        dst = self._local(dst)
        if not os.path.exists(src.rstrip("/")):
            return SimpleNamespace(returncode=0 if "--ignore-missing-args" in args else 23, stderr="missing")
        if os.path.isdir(src.rstrip("/")):
            os.makedirs(dst, exist_ok=True)
            only = [args[i + 1].lstrip("/") for i, a in enumerate(args) if a == "--include"]
            if only:  # put(): "--include /NAME --exclude *" = just that file into the directory
                for name in only:
                    if os.path.exists(os.path.join(src, name)):  # a missing one is simply not transferred
                        shutil.copy2(os.path.join(src, name), os.path.join(dst, name))
            else:
                shutil.copytree(src, dst, dirs_exist_ok=True)
        else:
            os.makedirs(os.path.dirname(dst) or ".", exist_ok=True)
            shutil.copy2(src, dst)
        return SimpleNamespace(returncode=0, stderr="")

    @staticmethod
    def _is_pattern(args, a):
        i = args.index(a)
        return i > 0 and args[i - 1] == "--exclude"

    def _local(self, p: str) -> str:
        if ":" in p and not os.path.isabs(p.split(":", 1)[0]) and "@" in p.split(":", 1)[0]:
            return os.path.join(self.root, p.split(":", 1)[1])
        return p


class WorkerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.server = os.path.join(self.tmp, "server")
        self.local = os.path.join(self.tmp, "local")
        os.makedirs(os.path.join(self.server, "radar"))
        os.makedirs(self.local)
        self.fake = FakeServer(self.server)
        self.remote = ytan_worker.Remote("u@h", run=self.fake)
        self.ran: list[tuple[str, bool]] = []

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def server_config(self, external):
        with open(os.path.join(self.server, "radar", "config.json"), "w") as f:
            json.dump({"workers": 4, "wind": {"workers": 4}, "external_jobs": external}, f)

    def runner(self, spec, force=False):
        self.ran.append((spec.name, force))
        out = os.path.join(self.local, spec.name)
        os.makedirs(os.path.join(out, "runs", "r1"), exist_ok=True)
        with open(os.path.join(out, "runs", "r1", "block"), "w") as f:
            f.write("data")
        with open(os.path.join(out, "manifest.json"), "w") as f:
            f.write('{"run": "r1"}')
        with open(os.path.join(out, "status.json"), "w") as f:
            json.dump({"last_run": {"outcome": "processed", "start": "2026-11-12T16:00:05Z"}}, f)
        return 0

    def test_only_external_jobs_run_and_are_uploaded_manifest_last(self):
        self.server_config(["wind"])
        report = ytan_worker.tick(self.local, self.remote, NOW, runner=self.runner, workers=2, host="pi")

        self.assertEqual(self.ran, [("wind", False)])
        self.assertTrue(report["jobs"]["wind"]["published"])
        self.assertNotIn("radar", report["jobs"])
        self.assertTrue(os.path.exists(os.path.join(self.server, "wind", "runs", "r1", "block")))
        self.assertTrue(os.path.exists(os.path.join(self.server, "worker-status.json")))
        # the local copy of config.json got the add-on's worker count
        with open(os.path.join(self.local, "radar", "config.json")) as f:
            self.assertEqual(json.load(f)["wind"]["workers"], 2)
        # order: files (manifest excluded), status.json, manifest.json, then the --delete pass
        uploads = [c for c in self.fake.commands if c[-1].startswith("u@h:wind")]
        self.assertIn("manifest.json", uploads[0])  # as an --exclude pattern
        self.assertIn("/status.json", uploads[1])
        self.assertIn("/manifest.json", uploads[2])
        self.assertIn("--delay-updates", uploads[2])
        # single files go as a filtered directory sync (rrsync cannot replace a file named as the target)
        self.assertEqual(uploads[2][-1], "u@h:wind/")
        self.assertIn("-rt", uploads[2])  # without -r rsync skips a directory source and still exits 0
        fetches = [c for c in self.fake.commands if "u@h:wind/" in c[:-1]]
        self.assertTrue(fetches and "/run-request" in fetches[0])  # run-request asked for as a filtered directory
        self.assertIn("--delete", uploads[3])

    def test_nothing_runs_when_no_job_is_external(self):
        self.server_config([])
        report = ytan_worker.tick(self.local, self.remote, NOW, runner=self.runner)
        self.assertEqual((self.ran, report["jobs"]), ([], {}))
        self.assertTrue(os.path.exists(os.path.join(self.server, "worker-status.json")))

    def test_a_run_request_forces_the_job_and_is_removed_on_the_server(self):
        self.server_config(["wind"])
        os.makedirs(os.path.join(self.local, "wind"))
        with open(os.path.join(self.local, "wind", "status.json"), "w") as f:
            json.dump({"last_run": {"start": NOW.strftime("%Y-%m-%dT%H:%M:%SZ")}}, f)  # not due by its slot
        os.makedirs(os.path.join(self.server, "wind"))
        open(os.path.join(self.server, "wind", "run-request"), "w").close()

        report = ytan_worker.tick(self.local, self.remote, NOW, runner=self.runner)
        self.assertEqual(self.ran, [("wind", True)])
        self.assertTrue(report["jobs"]["wind"]["requested"])
        self.assertFalse(os.path.exists(os.path.join(self.local, "wind", "run-request")))
        delete_pass = [c for c in self.fake.commands if "--delete" in c][-1]
        self.assertNotIn("run-request", delete_pass)  # not protected: the server's copy is deleted

    def test_not_due_and_not_requested_does_not_run(self):
        self.server_config(["wind"])
        os.makedirs(os.path.join(self.local, "wind"))
        with open(os.path.join(self.local, "wind", "status.json"), "w") as f:
            json.dump({"last_run": {"start": NOW.strftime("%Y-%m-%dT%H:%M:%SZ")}}, f)
        report = ytan_worker.tick(self.local, self.remote, NOW, runner=self.runner)
        self.assertEqual(self.ran, [])
        self.assertFalse(report["jobs"]["wind"]["ran"])

    def test_without_the_servers_config_nothing_runs(self):
        report = ytan_worker.tick(self.local, self.remote, NOW, runner=self.runner)
        self.assertIn("config.json", report["error"])
        self.assertEqual(self.ran, [])

    def test_radar_keeps_its_working_files_and_the_servers_config_out_of_the_upload(self):
        radar = next(s for s in ytan_jobs.JOBS if s.name == "radar")
        args = ytan_worker.excludes(radar)
        for pattern in ("raw/", "download/", "config.json", "reproject-*.npz", "manifest.json", "run-request"):
            self.assertIn(pattern, args)
        self.assertNotIn("run-request", ytan_worker.excludes(radar, keep_request=False))

    def test_sensors(self):
        sent = []

        class Answer:
            def close(self):
                pass

        def opener(req, timeout=10):
            sent.append((req.full_url, json.loads(req.data)))
            return Answer()

        report = {"tick": "2026-11-12T16:00:05Z", "external_jobs": ["wind"], "jobs": {
            "wind": {"ran": True, "code": 0, "published": True, "seconds": 812.0, "requested": False,
                     "last_run": {"outcome": "processed", "start": "a", "end": "b"}}}}
        ytan_worker.post_sensors(report, token="t", base="http://x/api", opener=opener)
        self.assertEqual(sent[0][0], "http://x/api/states/sensor.ytan_worker")
        self.assertEqual(sent[1][0], "http://x/api/states/sensor.ytan_worker_wind")
        self.assertEqual((sent[1][1]["state"], sent[1][1]["attributes"]["duration_s"]), ("processed", 812.0))


class ServerSkipTest(unittest.TestCase):
    def test_the_server_skips_external_jobs(self):
        with tempfile.TemporaryDirectory() as d:
            os.makedirs(os.path.join(d, "radar"))
            with open(os.path.join(d, "radar", "config.json"), "w") as f:
                json.dump({"external_jobs": ["wind"]}, f)
            ran = []
            results = ytan_jobs.run_due(ytan_jobs.JOBS, d, NOW, runner=lambda s: ran.append(s.name) or 0,
                                        skip=ytan_jobs.external_jobs(d))
            self.assertEqual(ran, ["radar"])
            self.assertIsNone(results["wind"])

    def test_no_config_means_nothing_external(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(ytan_jobs.external_jobs(d), [])


if __name__ == "__main__":
    unittest.main()
