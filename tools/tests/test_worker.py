"""Unit tests: python -m unittest discover -s tools/tests"""

from __future__ import annotations

import fnmatch
import json
import os
import shutil
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from types import SimpleNamespace

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import ytan_jobs  # noqa: E402
import ytan_worker  # noqa: E402

NOW = datetime(2026, 11, 12, 16, 0, 5, tzinfo=timezone.utc)
PY_JOBS = [s for s in ytan_jobs.JOBS if not s.php]  # radar, wind
VECTORS = os.path.join(os.path.dirname(__file__), "..", "..", "tests", "testdata", "worker-election.json")


def iso(t: datetime) -> str:
    return t.strftime("%Y-%m-%dT%H:%M:%SZ")


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
            rules = [(a == "--include", args[i + 1].lstrip("/")) for i, a in enumerate(args) if a in ("--include", "--exclude")]
            if any(include and p == "*/" for include, p in rules):  # a filtered TREE (fetch_tree): files by name, all directories
                self._tree(src, dst, rules, "--delete" in args)
            elif any(include for include, _ in rules):  # filtered sync of top-level files: the first matching rule wins
                for name in os.listdir(src):
                    if self._wanted(name, rules) and os.path.isfile(os.path.join(src, name)):  # a missing one is simply not transferred
                        shutil.copy2(os.path.join(src, name), os.path.join(dst, name))
            else:
                shutil.copytree(src, dst, dirs_exist_ok=True)
        else:
            os.makedirs(os.path.dirname(dst) or ".", exist_ok=True)
            shutil.copy2(src, dst)
        return SimpleNamespace(returncode=0, stderr="")

    @staticmethod
    def _wanted(name, rules):
        return next((include for include, p in rules if p != "*/" and fnmatch.fnmatchcase(name, p)), True)

    def _tree(self, src, dst, rules, delete):
        os.makedirs(dst, exist_ok=True)
        for name in sorted(os.listdir(src)):
            path = os.path.join(src, name)
            if os.path.isdir(path):
                self._tree(path, os.path.join(dst, name), rules, delete)
            elif self._wanted(name, rules):
                shutil.copy2(path, os.path.join(dst, name))
        if delete:  # what the source dropped goes; files that the rules exclude are left alone
            for name in os.listdir(dst):
                path = os.path.join(dst, name)
                if os.path.isdir(path) and not os.path.isdir(os.path.join(src, name)):
                    shutil.rmtree(path)
                elif os.path.isfile(path) and self._wanted(name, rules) and not os.path.isfile(os.path.join(src, name)):
                    os.remove(path)

    @staticmethod
    def _is_pattern(args, a):
        i = args.index(a)
        return i > 0 and args[i - 1] == "--exclude"

    def _local(self, p: str) -> str:
        if ":" in p and not os.path.isabs(p.split(":", 1)[0]) and "@" in p.split(":", 1)[0]:
            return os.path.join(self.root, p.split(":", 1)[1])
        return p


def entries(*ids: str, off: tuple[str, ...] = ()) -> list[dict]:
    """A priority list: the ids in order, enabled unless named in `off`."""
    return [{"id": i, "enabled": i not in off} for i in ids]


class WorkerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.server = os.path.join(self.tmp, "server")
        self.local = os.path.join(self.tmp, "local")
        os.makedirs(os.path.join(self.server, "radar"))
        os.makedirs(os.path.join(self.server, "workers"))  # JobPlacement::ensureWorkersDir() makes it on the real server
        os.makedirs(os.path.join(self.server, "logs", "worker"))
        os.makedirs(self.local)
        self.fake = FakeServer(self.server)
        self.remote = ytan_worker.Remote("u@h", run=self.fake)
        self.ran: list[tuple[str, bool]] = []

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_busy_rrsync_is_retried(self):
        busy = SimpleNamespace(returncode=12, stderr="/usr/bin/rrsync error: " + ytan_worker.RRSYNC_BUSY + ".")
        answers = [busy, busy, SimpleNamespace(returncode=0, stderr="")]
        waits = []
        remote = ytan_worker.Remote("u@h", run=lambda *a, **k: answers.pop(0), sleep=waits.append)
        self.assertEqual(0, remote.rsync(["x"]))
        self.assertEqual([ytan_worker.RRSYNC_BUSY_WAIT_S] * 2, waits)

    def test_other_rsync_errors_are_not_retried(self):
        calls = []
        def run(*a, **k):
            calls.append(1)
            return SimpleNamespace(returncode=23, stderr="missing")
        remote = ytan_worker.Remote("u@h", run=run, sleep=lambda s: self.fail("must not wait"))
        self.assertEqual(23, remote.rsync(["x"]))
        self.assertEqual(1, len(calls))

    def server_config(self, wind=("pi",), radar=("server",), **kw):
        """The server's config.json with the priority lists (default: wind on the worker "pi", radar on the server)."""
        lists = {"wind": entries(*wind), "radar": entries(*radar)}
        lists.update(kw.pop("lists", {}))
        with open(os.path.join(self.server, "radar", "config.json"), "w") as f:
            json.dump({"workers": 4, "wind": {"workers": 4}, "job_workers": lists}, f)

    def heartbeat_of(self, wid, age=60, **extra):
        d = os.path.join(self.server, "workers", wid)
        os.makedirs(d, exist_ok=True)
        body = {"tick": iso(NOW - timedelta(seconds=age)), "id": wid, "host": wid + "-host", "instance": "inst-" + wid,
                "capabilities": ["radar", "wind"]}
        body.update(extra)
        with open(os.path.join(d, "worker-status.json"), "w") as f:
            json.dump(body, f)

    def server_heartbeat(self, age=60):
        with open(os.path.join(self.server, "jobs-heartbeat.json"), "w") as f:
            json.dump({"tick": iso(NOW - timedelta(seconds=age))}, f)

    def server_status(self, job, start):
        os.makedirs(os.path.join(self.server, job), exist_ok=True)
        with open(os.path.join(self.server, job, "status.json"), "w") as f:
            json.dump({"last_run": {"outcome": "processed", "start": iso(start)}}, f)

    def tick(self, runner=None, **kw):
        kw.setdefault("worker_id", "pi")
        kw.setdefault("instance", "inst-pi")
        return ytan_worker.tick(self.local, self.remote, NOW, runner=runner or self.runner, **kw)

    def own_heartbeat(self, wid="pi"):
        with open(os.path.join(self.server, "workers", wid, "worker-status.json")) as f:
            return json.load(f)

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

    def test_only_the_jobs_the_worker_is_given_run_and_are_uploaded_manifest_last(self):
        self.server_config()
        report = self.tick(workers=2, host="pi")

        self.assertEqual(self.ran, [("wind", False)])
        self.assertTrue(report["jobs"]["wind"]["published"])
        self.assertNotIn("radar", report["jobs"])
        self.assertEqual(report["active_jobs"], ["wind"])
        self.assertTrue(os.path.exists(os.path.join(self.server, "wind", "runs", "r1", "block")))
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

    def test_the_heartbeat_tells_who_it_is_and_what_it_can_do(self):
        self.server_config()
        self.tick(host="pi-host", address="192.168.178.11")
        beat = self.own_heartbeat()
        self.assertEqual((beat["id"], beat["instance"], beat["host"], beat["address"]), ("pi", "inst-pi", "pi-host", "192.168.178.11"))
        self.assertEqual((beat["capabilities"], beat["protocol"]), (["radar", "wind"], ytan_worker.WORKER_PROTOCOL))
        self.assertEqual(beat["jobs"]["wind"]["last_run"]["outcome"], "processed")
        # nothing at the old place of the single worker-status.json
        self.assertFalse(os.path.exists(os.path.join(self.server, "worker-status.json")))

    def test_the_current_logs_go_to_the_workers_own_directory_on_the_server(self):
        self.server_config(wind=("server",))
        logs = os.path.join(self.local, "logs")
        os.makedirs(logs)
        for name in ("worker.log", "wind.log", "wind.3.log", "wind.1-10.zip"):
            with open(os.path.join(logs, name), "w") as f:
                f.write(name)
        self.tick()
        self.assertEqual(sorted(os.listdir(os.path.join(self.server, "logs", "worker", "pi"))), ["wind.log", "worker.log"])

    def test_the_heartbeat_says_running_before_a_job_and_carries_the_end_time(self):
        self.server_config()
        seen = []

        def runner(spec, force=False):
            seen.append(self.own_heartbeat())  # what the server sees while the job runs
            return self.runner(spec, force)

        clock = [1000.0]
        old = ytan_worker.time.monotonic
        ytan_worker.time.monotonic = lambda: clock.__setitem__(0, clock[0] + 300) or clock[0]  # every call: +5 min
        try:
            self.tick(runner=runner)
        finally:
            ytan_worker.time.monotonic = old
        self.assertEqual(seen[0]["running"]["job"], "wind")
        final = self.own_heartbeat()
        self.assertNotIn("running", final)
        self.assertGreater(final["tick"], seen[0]["tick"])  # written at the end, not the round's start
        self.assertGreater(seen[0]["tick"], iso(NOW))

    def test_a_worker_in_no_list_only_reports(self):
        self.server_config(wind=("server",), radar=("server",))
        report = self.tick()
        self.assertEqual((self.ran, report["jobs"], report["active_jobs"]), ([], {}, []))
        self.assertEqual(self.own_heartbeat()["capabilities"], ["radar", "wind"])  # the admin sees it as a stranger and can list it

    def test_a_disabled_entry_does_not_work(self):
        with open(os.path.join(self.server, "radar", "config.json"), "w") as f:
            json.dump({"job_workers": {"wind": entries("pi", "server", off=("pi",)), "radar": entries("server")}}, f)
        report = self.tick()
        self.assertEqual((self.ran, report["active_jobs"]), ([], []))

    def test_a_run_request_forces_the_job_and_is_removed_on_the_server(self):
        self.server_config()
        os.makedirs(os.path.join(self.local, "wind"))
        with open(os.path.join(self.local, "wind", "status.json"), "w") as f:
            json.dump({"last_run": {"start": iso(NOW)}}, f)  # not due by its slot
        os.makedirs(os.path.join(self.server, "wind"))
        open(os.path.join(self.server, "wind", "run-request"), "w").close()

        report = self.tick()
        self.assertEqual(self.ran, [("wind", True)])
        self.assertTrue(report["jobs"]["wind"]["requested"])
        self.assertFalse(os.path.exists(os.path.join(self.local, "wind", "run-request")))
        delete_pass = [c for c in self.fake.commands if "--delete" in c and c[-1].startswith("u@h:wind")][-1]
        self.assertNotIn("run-request", delete_pass)  # not protected: the server's copy is deleted

    def test_not_due_and_not_requested_does_not_run(self):
        self.server_config()
        os.makedirs(os.path.join(self.local, "wind"))
        with open(os.path.join(self.local, "wind", "status.json"), "w") as f:
            json.dump({"last_run": {"start": iso(NOW)}}, f)
        report = self.tick()
        self.assertEqual(self.ran, [])
        self.assertFalse(report["jobs"]["wind"]["ran"])

    def test_without_the_servers_config_nothing_runs(self):
        report = self.tick()
        self.assertIn("config.json", report["error"])
        self.assertEqual(self.ran, [])

    def test_a_config_from_before_the_lists_is_refused_not_guessed(self):
        with open(os.path.join(self.server, "radar", "config.json"), "w") as f:
            json.dump({"external_jobs": ["wind"]}, f)
        report = self.tick()
        self.assertIn("job_workers", report["error"])
        self.assertEqual(self.ran, [])

    def test_a_stand_in_takes_over_only_when_the_first_one_is_silent(self):
        self.server_config(wind=("nuc", "pi"))
        self.heartbeat_of("nuc", age=60)
        self.assertEqual(self.tick()["active_jobs"], [])
        self.assertEqual(self.ran, [])

        self.heartbeat_of("nuc", age=3600)  # silent for an hour
        report = self.tick()
        self.assertEqual((report["active_jobs"], self.ran), (["wind"], [("wind", False)]))

    def test_the_one_that_is_running_the_job_keeps_it_when_a_higher_one_returns(self):
        self.server_config(wind=("pi", "nuc"))
        self.heartbeat_of("nuc", age=100, running={"job": "wind", "since": iso(NOW - timedelta(seconds=300))})
        report = self.tick()
        self.assertEqual((report["active_jobs"], self.ran), ([], []))

    def test_a_worker_does_not_take_a_job_it_cannot_do(self):
        self.server_config(wind=("pi",), radar=("pi", "server"))
        report = self.tick(jobs=["radar"])
        self.assertEqual((report["active_jobs"], self.ran), (["radar"], [("radar", False)]))
        self.assertEqual(self.own_heartbeat()["capabilities"], ["radar"])

    def test_the_server_is_the_stand_in_only_while_its_cron_ticks(self):
        self.server_config(wind=("nuc", "server", "pi"))
        self.heartbeat_of("nuc", age=3600)  # silent
        # the server's cron does not tick (no jobs-heartbeat.json): the server is no candidate, so pi, behind it, works
        self.assertEqual(self.tick()["active_jobs"], ["wind"])
        self.ran.clear()
        shutil.rmtree(os.path.join(self.local, "wind"))  # a fresh start of the same worker

        self.server_heartbeat(age=60)  # the cron ticks: the server comes before pi
        self.assertEqual(self.tick()["active_jobs"], [])
        self.assertEqual(self.ran, [])

    def test_a_stand_in_does_not_repeat_what_the_one_before_just_did(self):
        self.server_config(wind=("nuc", "pi"))
        self.heartbeat_of("nuc", age=3600)
        self.server_status("wind", NOW - timedelta(seconds=2))  # nuc ran in this very slot (15 min), then went silent
        report = self.tick()
        self.assertEqual((report["active_jobs"], self.ran), (["wind"], []))
        self.assertFalse(report["jobs"]["wind"]["ran"])

    def test_a_second_system_with_the_same_name_does_not_work_and_marks_it(self):
        self.server_config()
        self.heartbeat_of("pi", age=60, instance="the-real-pi", host="the-real-host")
        report = self.tick(instance="a-twin")
        self.assertIn("pi is used by another system (the-real-host)", report["error"])
        self.assertEqual(self.ran, [])
        with open(os.path.join(self.server, "workers", "pi", "conflict.json")) as f:
            marker = json.load(f)
        self.assertEqual(marker["instance"], "a-twin")
        # the real one's heartbeat was not touched
        self.assertEqual(self.own_heartbeat()["instance"], "the-real-pi")

    def test_the_same_system_after_a_restart_is_no_conflict(self):
        self.server_config()
        self.heartbeat_of("pi", age=60, instance="inst-pi")
        report = self.tick(instance="inst-pi")
        self.assertNotIn("error", report)
        self.assertEqual(self.ran, [("wind", False)])

    def test_a_replaced_system_is_a_conflict_until_the_old_report_is_stale(self):
        self.server_config()
        self.heartbeat_of("pi", age=3600, instance="old-sd-card")  # more than 15 min: the old one is gone
        report = self.tick(instance="new-sd-card")
        self.assertNotIn("error", report)
        self.assertEqual(self.own_heartbeat()["instance"], "new-sd-card")

    def test_without_workers_on_the_server_nothing_runs_and_it_says_why(self):
        shutil.rmtree(os.path.join(self.server, "workers"))
        self.server_config()
        report = self.tick()
        self.assertIn("workers/", report["error"])
        self.assertEqual(self.ran, [])

    def test_the_instance_id_is_made_once_and_kept(self):
        first = ytan_worker.load_instance(self.local)
        self.assertEqual(ytan_worker.load_instance(self.local), first)
        self.assertRegex(first, r"^[0-9a-f]{32}$")

    def test_the_worker_name_is_checked_on_the_command_line(self):
        for bad in ("server", "Pi", "-x", "a b", "x" * 33):
            with self.assertRaises(SystemExit):
                ytan_worker.main(["--remote", "u@h", "--worker-id", bad])
        with self.assertRaises(SystemExit):
            ytan_worker.main(["--remote", "u@h", "--worker-id", "pi", "--jobs", "radar,cleanup"])

    def test_radar_keeps_its_working_files_and_the_servers_config_out_of_the_upload(self):
        radar = next(s for s in ytan_jobs.JOBS if s.name == "radar")
        args = ytan_worker.excludes(radar)
        for pattern in ("raw/", "download/", "config.json", "reproject-*", "verify-masks/", "manifest.json", "run-request"):
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

        report = {"tick": "2026-11-12T16:00:05Z", "id": "pi", "external_jobs": ["wind"], "jobs": {
            "wind": {"ran": True, "code": 0, "published": True, "seconds": 812.0, "requested": False,
                     "last_run": {"outcome": "processed", "start": "a", "end": "b"}}}}
        ytan_worker.post_sensors(report, token="t", base="http://x/api", opener=opener)
        self.assertEqual(sent[0][0], "http://x/api/states/sensor.ytan_worker")
        self.assertEqual(sent[0][1]["attributes"]["worker_id"], "pi")
        self.assertEqual(sent[1][0], "http://x/api/states/sensor.ytan_worker_wind")
        self.assertEqual((sent[1][1]["state"], sent[1][1]["attributes"]["duration_s"]), ("processed", 812.0))


class ElectionTest(unittest.TestCase):
    """The shared vectors (tests/testdata/worker-election.json): ytan_jobs.active_worker() must agree with JobPlacement::activeWorker()."""

    def test_vectors(self):
        with open(VECTORS, encoding="utf-8") as f:
            data = json.load(f)
        now = datetime.fromtimestamp(data["now"], tz=timezone.utc)
        self.assertGreaterEqual(len(data["cases"]), 15)
        for case in data["cases"]:
            heartbeats = {
                wid: {"tick": iso(now - timedelta(seconds=w["age"])), "capabilities": w.get("capabilities", []),
                      "running": {"job": w["running"]} if w.get("running") else None}
                for wid, w in case["workers"].items()
            }
            server_tick = None if case["server_age"] is None else iso(now - timedelta(seconds=case["server_age"]))
            with self.subTest(case["name"]):
                self.assertEqual(ytan_jobs.active_worker(case["job"], case["list"], heartbeats, server_tick, now), case["expected"])

    def test_names(self):
        for ok in ("homeassistant", "ytanworker", "a", "pi-4", "0x"):
            self.assertTrue(ytan_jobs.valid_worker_id(ok), ok)
        for bad in ("", "server", "Home", "-a", "a b", "a_b", "a" * 33, 5, None):
            self.assertFalse(ytan_jobs.valid_worker_id(bad), repr(bad))

    def test_times_in_both_formats(self):
        self.assertEqual(ytan_jobs.parse_time("2026-11-12T16:00:05Z"), NOW)
        self.assertEqual(ytan_jobs.parse_time("2026-11-12T16:00:05+00:00"), NOW)
        self.assertIsNone(ytan_jobs.parse_time("soon"))
        self.assertIsNone(ytan_jobs.parse_time(None))


class ServerSkipTest(unittest.TestCase):
    def config(self, d, lists=None, **extra):
        os.makedirs(os.path.join(d, "radar"), exist_ok=True)
        body = dict(extra)
        if lists is not None:
            body["job_workers"] = lists
        with open(os.path.join(d, "radar", "config.json"), "w") as f:
            json.dump(body, f)

    def worker(self, d, wid, age=60, **extra):
        os.makedirs(os.path.join(d, "workers", wid), exist_ok=True)
        body = {"tick": iso(NOW - timedelta(seconds=age)), "capabilities": ["radar", "wind"]}
        body.update(extra)
        with open(os.path.join(d, "workers", wid, "worker-status.json"), "w") as f:
            json.dump(body, f)

    def test_the_server_skips_external_jobs(self):
        with tempfile.TemporaryDirectory() as d:
            self.config(d, external_jobs=["wind"])
            ran = []
            results = ytan_jobs.run_due(PY_JOBS, d, NOW, runner=lambda s: ran.append(s.name) or 0,
                                        skip=ytan_jobs.external_jobs(d))
            self.assertEqual(ran, ["radar"])
            self.assertIsNone(results["wind"])

    def test_a_config_without_lists_follows_the_old_external_jobs_key(self):
        with tempfile.TemporaryDirectory() as d:
            self.config(d, external_jobs=["wind"])
            self.assertEqual(ytan_jobs.server_skip(d, NOW), ["wind"])

    def test_no_config_at_all_means_the_server_runs_everything(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(ytan_jobs.server_skip(d, NOW), [])

    def test_a_job_on_a_living_worker_is_skipped_the_others_stay(self):
        with tempfile.TemporaryDirectory() as d:
            self.config(d, {"wind": entries("pi", "server"), "radar": entries("server")})
            self.worker(d, "pi")
            self.assertEqual(ytan_jobs.server_skip(d, NOW), ["wind"])

    def test_with_the_worker_gone_the_server_stands_in_when_it_is_listed(self):
        with tempfile.TemporaryDirectory() as d:
            self.config(d, {"wind": entries("pi", "server"), "radar": entries("pi", "server", off=("server",))})
            self.worker(d, "pi", age=3600)
            # wind: the server is listed and enabled = the stand-in; radar: its entry is off = nobody
            self.assertEqual(ytan_jobs.server_skip(d, NOW), ["radar"])

    def test_the_server_first_in_the_list_runs_even_with_a_living_worker_behind_it(self):
        with tempfile.TemporaryDirectory() as d:
            self.config(d, {"wind": entries("server", "pi")})
            self.worker(d, "pi")
            self.assertEqual(ytan_jobs.server_skip(d, NOW), [])

    def test_a_worker_that_is_running_the_job_keeps_it_over_the_server_above_it(self):
        with tempfile.TemporaryDirectory() as d:
            self.config(d, {"wind": entries("server", "pi")})
            self.worker(d, "pi", running={"job": "wind"})
            self.assertEqual(ytan_jobs.server_skip(d, NOW), ["wind"])

    def test_a_worker_that_cannot_do_the_job_does_not_take_it(self):
        with tempfile.TemporaryDirectory() as d:
            self.config(d, {"wind": entries("pi", "server")})
            self.worker(d, "pi", capabilities=["radar"])
            self.assertEqual(ytan_jobs.server_skip(d, NOW), [])

    def test_every_tick_logs_what_ran_and_what_not(self):
        with tempfile.TemporaryDirectory() as d:
            os.makedirs(os.path.join(d, "radar"))
            with open(os.path.join(d, "radar", "status.json"), "w") as f:
                json.dump({"last_run": {"outcome": "no_composite"}}, f)
            with self.assertLogs(ytan_jobs.log, level="INFO") as logs:
                ytan_jobs.run_due(PY_JOBS, d, NOW, runner=lambda s: 0, skip=["wind"])
            self.assertEqual(len(logs.records), 1)
            self.assertRegex(logs.output[0], r"tick: radar exit 0 in \d+\.\d s \(no_composite\); wind on the YTAN worker$")

    def test_the_php_cleanups_run_once_a_day_and_keep_their_status_here(self):
        cleanups = [s for s in ytan_jobs.JOBS if s.php]
        self.assertEqual([s.name for s in cleanups], ["cleanup-weather", "cleanup-caches"])
        with tempfile.TemporaryDirectory() as d:
            ran = []
            codes = {"cleanup-weather": 0, "cleanup-caches": 3}
            runner = lambda s: ran.append(s.name) or codes[s.name]  # noqa: E731
            ytan_jobs.run_due(cleanups, d, NOW, runner=runner)
            self.assertEqual(ran, ["cleanup-weather", "cleanup-caches"])
            with open(os.path.join(d, ytan_jobs.JOBS_STATUS)) as f:
                status = json.load(f)
            self.assertEqual(status["cleanup-weather"]["last_run"]["outcome"], "processed")
            self.assertEqual((status["cleanup-caches"]["last_run"]["outcome"], status["cleanup-caches"]["last_run"]["exit_code"]), ("failed", 3))
            self.assertEqual(status["cleanup-caches"]["last_run"]["script"], "bin/cleanup-file-caches.php")

            # same UTC day: not again (the start written above is "now", i.e. 2026-11-12); next day: due
            ran.clear()
            today = datetime.now(timezone.utc)
            ytan_jobs.run_due(cleanups, d, today, runner=runner)
            self.assertEqual(ran, [])
            ytan_jobs.run_due(cleanups, d, today + timedelta(days=1), runner=runner)
            self.assertEqual(ran, ["cleanup-weather", "cleanup-caches"])

    def test_the_cleanups_can_never_be_placed_on_a_worker(self):
        self.assertEqual(ytan_jobs.PLACEABLE_JOBS, ("radar", "wind"))

    def test_php_jobs_run_with_the_configured_php(self):
        spec = next(s for s in ytan_jobs.JOBS if s.name == "cleanup-weather")
        calls = []
        old = (os.environ.get("PHP_CLI"), ytan_jobs.subprocess.run)
        os.environ["PHP_CLI"] = "/opt/my/php"

        def fake_run(command, **kw):
            calls.append(command)
            self.assertEqual(kw["timeout"], spec.timeout_s)
            return ytan_jobs.subprocess.CompletedProcess(command, 0)
        ytan_jobs.subprocess.run = fake_run
        try:
            ytan_jobs.run_job(spec)
        finally:
            ytan_jobs.subprocess.run = old[1]
            if old[0] is None:
                del os.environ["PHP_CLI"]
            else:
                os.environ["PHP_CLI"] = old[0]
        self.assertEqual(calls[0][0], "/opt/my/php")
        self.assertTrue(calls[0][1].replace(os.sep, "/").endswith("bin/cleanup-weather-data.php"))

    def test_every_tick_leaves_a_heartbeat(self):
        with tempfile.TemporaryDirectory() as d:
            ytan_jobs.write_heartbeat(d, NOW)
            with open(os.path.join(d, ytan_jobs.HEARTBEAT)) as f:
                self.assertEqual(json.load(f)["tick"], "2026-11-12T16:00:05Z")

    def test_no_config_means_nothing_external(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(ytan_jobs.external_jobs(d), [])


if __name__ == "__main__":
    unittest.main()
