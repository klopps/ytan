#!/usr/bin/env python3
"""Wind field job: newest DWD ICON-D2 / ICON-EU runs -> blocks in storage/wind.

Run from cron every 15 minutes (see README.md):
    tools/radar/.venv/bin/python tools/wind/wind_job.py
"""

from __future__ import annotations

import argparse
from contextlib import nullcontext
import logging
import os
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

from ytanwind.job import Job  # noqa: E402
from ytanwind.settings import load_settings  # noqa: E402


class Lock:
    """Exclusive file lock: non-blocking by default, so a slow run never overlaps the next one."""

    def __init__(self, path: str, wait_seconds: float = 0, poll_seconds: float = 5):
        self.path = path
        self.f = None
        self.wait_seconds = wait_seconds
        self.poll_seconds = poll_seconds

    def _try(self) -> bool:
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

    def __enter__(self) -> bool:
        self.f = open(self.path, "a+")
        deadline = time.monotonic() + self.wait_seconds
        while not self._try():
            if time.monotonic() >= deadline:
                return False
            time.sleep(self.poll_seconds)
        return True

    def __exit__(self, *exc) -> None:
        self.f.close()


# The rain radar job (every 5 minutes, ~2 minutes of heavy work, up to ~1 GB) and this job must not run
# at the same time: both hold storage/.jobs.lock while they work (radar_job.py skips its run when this
# one holds it, this job waits for the radar to finish).
SHARED_LOCK_WAIT_SECONDS = 6 * 60


def main() -> int:
    root = os.path.normpath(os.path.join(HERE, "..", ".."))
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--storage", default=os.path.join(root, "storage", "wind"),
                        help="output directory (default: storage/wind of this checkout)")
    parser.add_argument("--radar-storage", default=os.path.join(root, "storage", "radar"),
                        help="where config.json (regions and wind settings) is read from")
    parser.add_argument("--force", action="store_true", help="process even when the model runs are unchanged")
    parser.add_argument("-v", "--verbose", action="store_true")
    args = parser.parse_args()

    logging.basicConfig(level=logging.DEBUG if args.verbose else logging.INFO,
                        format="%(asctime)s %(levelname)s %(message)s")
    log = logging.getLogger("ytanwind")
    os.makedirs(args.storage, exist_ok=True)

    with Lock(os.path.join(args.storage, ".lock")) as locked:
        if not locked:
            log.warning("previous run still active, skipped")
            return 0
        # (started by tools/ytan_jobs.py, which holds the lock itself: nothing to take)
        shared = (nullcontext(True) if os.environ.get("YTAN_JOBS_LOCK_HELD") == "1" else
                  Lock(os.path.join(os.path.dirname(os.path.abspath(args.storage)), ".jobs.lock"),
                       wait_seconds=SHARED_LOCK_WAIT_SECONDS))
        with shared as exclusive:
            if not exclusive:
                log.warning("the rain radar job kept running for %d minutes, skipped (next cron run tries again)",
                            SHARED_LOCK_WAIT_SECONDS // 60)
                return 0
            started = time.monotonic()
            try:
                job = Job(args.storage, load_settings(args.radar_storage), force=args.force)
                job.mark_running("manual" if args.force else "cron")
                info = job.run()
            except Exception:
                log.exception("run failed")
                return 1
            if info:
                log.info("done in %.1f s", time.monotonic() - started)
    return 0


if __name__ == "__main__":
    sys.exit(main())
