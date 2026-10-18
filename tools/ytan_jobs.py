#!/usr/bin/env python3
"""YTAN's scheduled jobs from ONE cron entry, one after the other.

Cron, every 5 minutes (replaces the separate radar and wind entries):
    */5 * * * * tools/radar/.venv/bin/python tools/ytan_jobs.py >> storage/logs/radar.log 2>&1

Each tick takes storage/.jobs.lock (no second tick starts while this one is
still working - it just ends) and goes through JOBS in order; a job that is
due runs in a process of its own, so its memory is free again before the next
one starts, and a failing job does not stop the others. A job is due when
its time slot (`slot_minutes`) differs from the slot of its last run
(storage/{job}/status.json, last_run.start): the rain radar every tick, the
wind job at the first tick of every quarter hour (it only fetches when a new
model run is out, otherwise a run costs a second).

The single jobs stay runnable by hand and from /admin/radar ("update now",
--force); they take the same lock themselves, unless they are started from
here (YTAN_JOBS_LOCK_HELD=1 in their environment).
"""

from __future__ import annotations

import json
import logging
import os
import subprocess
import sys
import time
from dataclasses import dataclass
from datetime import datetime, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, ".."))
log = logging.getLogger("ytanjobs")


@dataclass(frozen=True)
class JobSpec:
    name: str         # also the storage/{name} directory with status.json
    script: str       # relative to tools/
    slot_minutes: int  # due once per slot of this many minutes


# in this order: the rain radar first (it is the one people watch minute by minute)
JOBS = [
    JobSpec("radar", os.path.join("radar", "radar_job.py"), 5),
    JobSpec("wind", os.path.join("wind", "wind_job.py"), 15),
]


class Lock:
    """Non-blocking exclusive lock (same mechanism as the single jobs')."""

    def __init__(self, path: str):
        self.path = path
        self.f = None

    def __enter__(self) -> bool:
        self.f = open(self.path, "a+")
        try:
            if os.name == "nt":
                import msvcrt
                msvcrt.locking(self.f.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                fcntl.flock(self.f.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            return False
        return True

    def __exit__(self, *exc) -> None:
        self.f.close()


def slot_of(t: datetime, slot_minutes: int) -> int:
    return int(t.timestamp() // (slot_minutes * 60))


def last_start(storage_root: str, name: str) -> datetime | None:
    """Start of the job's last run (status.json), None when it never ran or the file is unreadable."""
    try:
        with open(os.path.join(storage_root, name, "status.json"), encoding="utf-8") as f:
            start = json.load(f)["last_run"]["start"]
        return datetime.strptime(start, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    except (OSError, ValueError, KeyError, TypeError):
        return None


def is_due(spec: JobSpec, last: datetime | None, now: datetime) -> bool:
    return last is None or slot_of(last, spec.slot_minutes) != slot_of(now, spec.slot_minutes)


def run_job(spec: JobSpec) -> int:
    """The job in a process of its own; its output goes on to ours (the cron log)."""
    env = dict(os.environ, YTAN_JOBS_LOCK_HELD="1")
    return subprocess.call([sys.executable, os.path.join(HERE, spec.script)], env=env, cwd=ROOT)


def run_due(jobs: list[JobSpec], storage_root: str, now: datetime, runner=run_job) -> dict[str, int | None]:
    """Run what is due, one after the other; {name: exit code, or None when it was not due}."""
    results: dict[str, int | None] = {}
    for spec in jobs:
        if not is_due(spec, last_start(storage_root, spec.name), now):
            results[spec.name] = None
            continue
        started = time.monotonic()
        try:
            code = runner(spec)
        except Exception:
            log.exception("%s could not be started", spec.name)
            code = -1
        results[spec.name] = code
        if code != 0:
            log.error("%s ended with exit code %s", spec.name, code)
        else:
            log.debug("%s done in %.1f s", spec.name, time.monotonic() - started)
    return results


def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    storage_root = os.path.join(ROOT, "storage")
    os.makedirs(storage_root, exist_ok=True)
    with Lock(os.path.join(storage_root, ".jobs.lock")) as locked:
        if not locked:
            log.warning("the previous tick is still working (or a job was started by hand), skipped")
            return 0
        results = run_due(JOBS, storage_root, datetime.now(timezone.utc))
    return 1 if any(code not in (None, 0) for code in results.values()) else 0


if __name__ == "__main__":
    sys.exit(main())
