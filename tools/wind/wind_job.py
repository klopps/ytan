#!/usr/bin/env python3
"""Wind field job: newest DWD ICON-D2 / ICON-EU runs -> blocks in storage/wind.

Run from cron every 15 minutes (see README.md):
    tools/radar/.venv/bin/python tools/wind/wind_job.py
"""

from __future__ import annotations

import argparse
import logging
import os
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

from ytanwind.job import Job  # noqa: E402
from ytanwind.settings import load_settings  # noqa: E402


class Lock:
    """Non-blocking exclusive lock, so a slow run never overlaps the next one."""

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
