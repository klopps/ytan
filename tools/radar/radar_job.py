#!/usr/bin/env python3
"""Radar tile job: fetch OPERA composites, compute the nowcast, write tiles.

Run from cron every 5 minutes (see README.md):
    tools/radar/.venv/bin/python tools/radar/radar_job.py
"""

from __future__ import annotations

import argparse
import logging
import os
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

from ytanradar.job import Config, Job, default_grid  # noqa: E402


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
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--storage", default=os.path.normpath(os.path.join(HERE, "..", "..", "storage", "radar")),
                        help="output directory (default: storage/radar of this checkout)")
    parser.add_argument("-v", "--verbose", action="store_true")
    args = parser.parse_args()

    logging.basicConfig(level=logging.DEBUG if args.verbose else logging.INFO,
                        format="%(asctime)s %(levelname)s %(message)s")
    log = logging.getLogger("ytanradar")
    os.makedirs(args.storage, exist_ok=True)

    with Lock(os.path.join(args.storage, ".lock")) as locked:
        if not locked:
            log.warning("previous run still active, skipped")
            return 0
        started = time.monotonic()
        try:
            manifest = Job(Config(storage=args.storage, grid=default_grid())).run()
        except Exception:
            log.exception("run failed")
            return 1
        log.info("done in %.1f s, latest observation %s, %d frames",
                 time.monotonic() - started, manifest["latest_observation"], len(manifest["frames"]))
    return 0


if __name__ == "__main__":
    sys.exit(main())
