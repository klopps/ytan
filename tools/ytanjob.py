"""Small pieces every YTAN job needs - until 2026-11-15 each of radar_job.py,
wind_job.py, ytan_jobs.py and the two job packages had its own copy.

- Lock: the exclusive lock file that keeps runs from overlapping (radar and
  wind .lock, storage/.jobs.lock); PHP's flock() sees it on both systems
  (RadarJobRunner checks it before starting a job).
- write_json_atomic(): write to a temp file, then os.replace() - readers
  (PHP, the YTAN worker's rsync) never see a half-written file.
- directory_size(): bytes below a directory, for the admin pages.
"""

from __future__ import annotations

import json
import os
import time


class Lock:
    """Exclusive file lock: non-blocking by default (a slow run never overlaps
    the next one), or waiting up to wait_seconds, checking every poll_seconds.

    with Lock(path) as locked:  # locked is False when another process holds it
    """

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


def write_json_atomic(path: str, data, **dump_options) -> None:
    """json.dump() into path via a temp file of this process, then os.replace().
    dump_options go to json.dump (e.g. indent=1 or separators=(",", ":"))."""
    tmp = "%s.%d.tmp" % (path, os.getpid())
    try:
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(data, f, **dump_options)
        os.replace(tmp, path)
    except BaseException:
        try:
            os.remove(tmp)
        except OSError:
            pass
        raise


def directory_size(path: str) -> int:
    """Bytes of all files below path (files deleted meanwhile are skipped)."""
    total = 0
    for root, _dirs, names in os.walk(path):
        for name in names:
            try:
                total += os.path.getsize(os.path.join(root, name))
            except OSError:
                pass
    return total
