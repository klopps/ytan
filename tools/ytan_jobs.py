#!/usr/bin/env python3
"""YTAN's scheduled jobs from ONE cron entry, one after the other.

Cron, every 5 minutes (replaces the separate radar and wind entries):
    */5 * * * * tools/radar/.venv/bin/python tools/ytan_jobs.py >> storage/logs/console.log 2>&1

The jobs write their own logs (storage/logs/jobs.log, radar.log, wind.log - tools/ytanlog.py);
console.log only catches what goes wrong before that (e.g. a missing package).

Each tick takes storage/.jobs.lock (no second tick starts while this one is
still working - it just ends) and goes through JOBS in order; a job that is
due runs in a process of its own, so its memory is free again before the next
one starts, and a failing job does not stop the others. A job is due when
its time slot (`slot_minutes`) differs from the slot of its last run
(storage/{job}/status.json, last_run.start): the rain radar every tick, the
wind job at the first tick of every quarter hour (it only fetches when a new
model run is out, otherwise a run costs a second), the PHP cleanups
(bin/cleanup-*.php) once per UTC day - their last runs are kept here in
storage/jobs-status.json, they write no status of their own.

The single jobs stay runnable by hand and from /admin/radar ("update now",
--force); they take the same lock themselves, unless they are started from
here (YTAN_JOBS_LOCK_HELD=1 in their environment).
"""

from __future__ import annotations

import json
import logging
import os
import shutil
import subprocess
import sys
import time
from dataclasses import dataclass
from datetime import datetime, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, ".."))
sys.path.insert(0, HERE)
import ytanlog  # noqa: E402

log = logging.getLogger("ytanjobs")


@dataclass(frozen=True)
class JobSpec:
    name: str         # also the storage/{name} directory with status.json (Python jobs)
    script: str       # relative to tools/ (Python job) or to the repository root (php=True)
    slot_minutes: int  # due once per slot of this many minutes
    # worker mode: what of storage/{name} NOT to upload (local caches/working files; also protected
    # from --delete on the server) besides manifest.json/status.json/.lock/run-request/*.tmp
    publish_excludes: tuple[str, ...] = ()
    # a PHP CLI script (bin/*.php): run with php_binary(); it writes no status.json of its own,
    # so this scheduler keeps its last run in storage/jobs-status.json (never on the YTAN worker)
    php: bool = False


# in this order: the rain radar first (it is the one people watch minute by minute)
JOBS = [
    JobSpec("radar", os.path.join("radar", "radar_job.py"), 5,
            ("raw/", "download/", "verify.csv", "verify.last", "reproject-*.npz", "config.json")),
    JobSpec("wind", os.path.join("wind", "wind_job.py"), 15, ("icon-global-index.npy",)),
    # daily cleanups (until 2026-11-12 a separate crontab line, bin/cron.sh, that never ran on prod:
    # a user crontab has PATH=/usr/bin:/bin and PHP is /usr/local/bin/php there); due once per UTC day
    JobSpec("cleanup-weather", os.path.join("bin", "cleanup-weather-data.php"), 1440, php=True),
    JobSpec("cleanup-caches", os.path.join("bin", "cleanup-file-caches.php"), 1440, php=True),
]

JOBS_STATUS = "jobs-status.json"  # last runs of the jobs without a status.json of their own (php=True)


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


def job_status(storage_root: str, spec: JobSpec) -> dict:
    """The job's status: storage/{name}/status.json, or its entry in storage/jobs-status.json (php=True)."""
    if spec.php:
        entry = (_read_json(os.path.join(storage_root, JOBS_STATUS)) or {}).get(spec.name)
        return entry if isinstance(entry, dict) else {}
    return _read_json(os.path.join(storage_root, spec.name, "status.json")) or {}


def job_last_start(storage_root: str, spec: JobSpec) -> datetime | None:
    last = job_status(storage_root, spec).get("last_run")
    try:
        return datetime.strptime(last["start"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    except (ValueError, KeyError, TypeError):
        return None


def write_php_status(storage_root: str, spec: JobSpec, started: datetime, ended: datetime, code: int) -> None:
    """Records a PHP job's run in storage/jobs-status.json (read by is_due() and the admin dashboard)."""
    path = os.path.join(storage_root, JOBS_STATUS)
    data = _read_json(path) or {}
    data[spec.name] = {"last_run": {"start": started.strftime("%Y-%m-%dT%H:%M:%SZ"), "end": ended.strftime("%Y-%m-%dT%H:%M:%SZ"),
                                    "outcome": "processed" if code == 0 else "failed", "exit_code": code, "script": spec.script.replace(os.sep, "/")}}
    try:
        with open(path + ".tmp", "w", encoding="utf-8") as f:
            json.dump(data, f, indent=1)
        os.replace(path + ".tmp", path)
    except OSError as e:
        log.warning("could not write %s: %s", JOBS_STATUS, e)


def php_binary() -> str:
    """PHP_CLI from the environment or .env, else php from the PATH - extended by /usr/local/bin,
    since cron starts us with PATH=/usr/bin:/bin (prod's PHP is /usr/local/bin/php)."""
    configured = os.environ.get("PHP_CLI", "").strip()
    if not configured:
        try:
            with open(os.path.join(ROOT, ".env"), encoding="utf-8") as f:
                for line in f:
                    if line.startswith("PHP_CLI="):
                        configured = line.split("=", 1)[1].strip().strip('"').strip("'")
        except OSError:
            pass
    if configured:
        return configured
    path = os.environ.get("PATH", "") + os.pathsep + os.pathsep.join(("/usr/local/bin", "/opt/php/bin"))
    return shutil.which("php", path=path) or "php"


def is_due(spec: JobSpec, last: datetime | None, now: datetime) -> bool:
    return last is None or slot_of(last, spec.slot_minutes) != slot_of(now, spec.slot_minutes)


def run_job(spec: JobSpec, force: bool = False) -> int:
    """The job in a process of its own; its output goes on to ours (the cron log)."""
    env = dict(os.environ, YTAN_JOBS_LOCK_HELD="1")
    if spec.php:
        return subprocess.call([php_binary(), os.path.join(ROOT, spec.script)], env=env, cwd=ROOT)
    command = [sys.executable, os.path.join(HERE, spec.script)] + (["--force"] if force else [])
    return subprocess.call(command, env=env, cwd=ROOT)


def external_jobs(storage_root: str) -> list[str]:
    """The jobs the admin moved to the YTAN worker (storage/radar/config.json "external_jobs", JobPlacement)."""
    try:
        with open(os.path.join(storage_root, "radar", "config.json"), encoding="utf-8") as f:
            jobs = json.load(f).get("external_jobs") or []
        return [str(j) for j in jobs] if isinstance(jobs, list) else []
    except (OSError, ValueError, AttributeError):
        return []


def run_due(jobs: list[JobSpec], storage_root: str, now: datetime, runner=run_job,
            skip: list[str] | tuple[str, ...] = ()) -> dict[str, int | None]:
    """Run what is due, one after the other; {name: exit code, or None when it was not due}.
    Jobs in skip (they run on the YTAN worker) are left out here."""
    results: dict[str, int | None] = {}
    summary: list[str] = []  # one line per tick in jobs.log: what ran, how it ended, what was left out
    for spec in jobs:
        if spec.name in skip:
            results[spec.name] = None
            summary.append(f"{spec.name} on the YTAN worker")
            continue
        if not is_due(spec, job_last_start(storage_root, spec), now):
            results[spec.name] = None
            summary.append(f"{spec.name} not due")
            continue
        started = time.monotonic()
        started_at = datetime.now(timezone.utc)
        try:
            code = runner(spec)
        except Exception:
            log.exception("%s could not be started", spec.name)
            code = -1
        results[spec.name] = code
        seconds = time.monotonic() - started
        if spec.php:
            write_php_status(storage_root, spec, started_at, datetime.now(timezone.utc), code)
        if code != 0:
            log.error("%s ended with exit code %s after %.1f s", spec.name, code, seconds)
        outcome = last_outcome(storage_root, spec)
        summary.append(f"{spec.name} exit {code} in {seconds:.1f} s" + (f" ({outcome})" if outcome else ""))
    log.info("tick: %s", "; ".join(summary) if summary else "no jobs")
    return results


def last_outcome(storage_root: str, spec: JobSpec) -> str | None:
    """last_run.outcome of the job's status (processed, unchanged, no_composite, failed, ...)."""
    last = job_status(storage_root, spec).get("last_run")
    return str(last.get("outcome")) if isinstance(last, dict) and last.get("outcome") else None


# --- Resuming a background OpenStreetMap import ------------------------------
# An OSM import started from an admin page (OsmImportJob) runs in the cgroup of
# the account's php-fpm systemd service and is killed with it when the service
# is stopped (prod, found 2026-11-12: France ended silently after 11 of 20
# tiles). Its status file says how to run it again; this tick - started by
# cron, i.e. outside that cgroup - does that with --resume when the status says
# running but nobody holds the import's lock. The script continues at the tile
# that was in progress (src/Service/Overpass/OsmImportCliRun.php).

OSM_STATUS = "osm-import-status.json"
OSM_LOCK = "osm-import.lock"
OSM_MAX_RESUMES = 3  # = OsmImportJob::MAX_RESUMES


def _read_json(path: str) -> dict | None:
    try:
        with open(path, encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else None
    except (OSError, ValueError):
        return None


def _write_json(path: str, data: dict) -> None:
    tmp = path + ".%d.tmp" % os.getpid()
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, separators=(",", ":"))
    os.replace(tmp, path)


def _spawn_detached(command: list[str], console_log: str) -> None:
    """Starts the command without waiting for it; it outlives this tick (and keeps none of our locks)."""
    with open(console_log, "a", encoding="utf-8") as out:
        kwargs = {"cwd": ROOT, "stdin": subprocess.DEVNULL, "stdout": out, "stderr": subprocess.STDOUT, "close_fds": True}
        if os.name == "nt":
            kwargs["creationflags"] = subprocess.DETACHED_PROCESS | subprocess.CREATE_NEW_PROCESS_GROUP
        else:
            kwargs["start_new_session"] = True
        env = dict(os.environ)
        env.pop("YTAN_JOBS_LOCK_HELD", None)
        subprocess.Popen(command, env=env, **kwargs)


def resume_osm_import(storage_root: str, spawn=_spawn_detached) -> str | None:
    """Resumes a killed background OSM import; returns what was done ('resumed', 'gave up') or None."""
    status_path = os.path.join(storage_root, OSM_STATUS)
    status = _read_json(status_path)
    if not status or status.get("state") not in ("running", "retrying"):
        return None
    resume = status.get("resume")
    if not isinstance(resume, dict) or not resume.get("php") or not resume.get("script"):
        return None
    with Lock(os.path.join(storage_root, OSM_LOCK)) as free:
        if not free:
            return None  # still running
    tool_log = ytanlog.LogFile(ytanlog.log_path(str(resume.get("log") or "jobs"), os.path.join(storage_root, "logs")))
    where = "%s tile %s/%s" % (status.get("country", "?"), status.get("tile", "?"), status.get("tiles", "?"))
    resumes = int(status.get("resumes") or 0)
    if resumes >= OSM_MAX_RESUMES:
        status.update(state="failed", finished=datetime.now().astimezone().isoformat(timespec="seconds"),
                      message="GAVE UP: the background process ended unexpectedly %d times" % (resumes + 1))
        _write_json(status_path, status)
        tool_log.write("ERROR cron: background import %s ended unexpectedly again - GAVE UP after %d resumes" % (where, resumes))
        log.error("OSM import %s: gave up after %d resumes", where, resumes)
        return "gave up"
    status.update(state="starting", resumes=resumes + 1, started_resume=datetime.now().astimezone().isoformat(timespec="seconds"))
    _write_json(status_path, status)
    command = [str(resume["php"]), str(resume["script"])] + [str(a) for a in resume.get("args") or []] + ["--resume"]
    if status.get("user") and status.get("user") != "cli":
        command.append("--user=" + str(status["user"]))
    tool_log.write("cron: background import %s had ended unexpectedly - resuming (%d/%d)" % (where, resumes + 1, OSM_MAX_RESUMES))
    log.info("OSM import %s resumed (%d/%d)", where, resumes + 1, OSM_MAX_RESUMES)
    spawn(command, os.path.join(storage_root, "logs", "console.log"))
    return "resumed"


HEARTBEAT = "jobs-heartbeat.json"


def write_heartbeat(storage_root: str, now: datetime) -> None:
    """storage/jobs-heartbeat.json on every cron tick (the admin dashboard's "cron is running" check,
    AdminHealthService) - jobs.log only gets a line when something is worth logging."""
    path = os.path.join(storage_root, HEARTBEAT)
    try:
        with open(path + ".tmp", "w", encoding="utf-8") as f:
            json.dump({"tick": now.strftime("%Y-%m-%dT%H:%M:%SZ"), "pid": os.getpid()}, f)
        os.replace(path + ".tmp", path)
    except OSError as e:
        log.warning("could not write %s: %s", HEARTBEAT, e)


def main() -> int:
    # storage/logs/jobs.log (the jobs themselves write radar.log / wind.log)
    ytanlog.setup("jobs")
    storage_root = os.path.join(ROOT, "storage")
    os.makedirs(storage_root, exist_ok=True)
    write_heartbeat(storage_root, datetime.now(timezone.utc))
    with Lock(os.path.join(storage_root, ".jobs.lock")) as locked:
        if not locked:
            log.warning("the previous tick is still working (or a job was started by hand), skipped")
            return 0
        try:
            resume_osm_import(storage_root)
        except Exception:
            log.exception("resuming the OpenStreetMap import failed")
        results = run_due(JOBS, storage_root, datetime.now(timezone.utc), skip=external_jobs(storage_root))
    return 1 if any(code not in (None, 0) for code in results.values()) else 0


if __name__ == "__main__":
    sys.exit(main())
