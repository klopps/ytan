#!/usr/bin/env python3
"""YTAN worker: runs the scheduled jobs the admin moved off the server, and uploads their output.

Runs in the Home Assistant add-on ha-addon/ytan-worker/ (a loop calls one tick
every few minutes), but works on any machine with Python, ssh and rsync.

The admin chooses per job where it runs (/admin/radar, /admin/wind ->
app_settings.external_jobs -> "external_jobs" in storage/radar/config.json,
src/Service/JobPlacement.php). One tick:

1. fetch radar/config.json from the server (regions, job settings, external_jobs);
2. for every job of ytan_jobs.JOBS in external_jobs: fetch its run-request
   ("update now" on the admin page), run it when it is due (same slots as on
   the server, ytan_jobs.is_due()) or requested (--force), then upload its
   output - files first, status.json, manifest.json last, then a --delete pass
   that removes what the job itself has cleaned up (publish());
3. upload worker-status.json (the admin pages show the last contact) and,
   in the add-on, set Home Assistant sensors (sensor.ytan_worker_*).

Only the worker connects - to the server, as the one SSH user there, with a
key that authorized_keys restricts to rsync below the YTAN storage directory
(command="rrsync .../storage",restrict): remote paths are relative to
storage/, there is no shell (so no "mv" - rsync --delay-updates makes the
manifest replacement atomic instead).

The local storage is ROOT/storage like on the server (the add-on links it to
/share/ytan-worker/storage), so the jobs run unchanged with their defaults.
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import socket
import subprocess
import sys
import time
import urllib.request
from datetime import datetime, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import ytan_jobs  # noqa: E402
import ytanlog  # noqa: E402

log = logging.getLogger("ytanworker")

# never uploaded, never deleted on the server by the --delete pass
COMMON_EXCLUDES = ("manifest.json", "status.json", ".lock", "run-request", "*.tmp", "*.tmp/")


class Remote:
    """rsync to/from the server's storage/ (the rrsync root of the worker's key)."""

    def __init__(self, target: str, ssh_key: str | None = None, known_hosts: str | None = None, run=subprocess.run):
        self.target = target  # user@host
        ssh = ["ssh", "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=accept-new", "-o", "ConnectTimeout=30"]
        if ssh_key:
            ssh += ["-i", ssh_key]
        if known_hosts:
            ssh += ["-o", "UserKnownHostsFile=" + known_hosts]
        self.ssh = " ".join(ssh)
        self.run = run

    def rsync(self, args: list[str]) -> int:
        command = ["rsync", "-e", self.ssh] + args
        result = self.run(command, capture_output=True, text=True)
        if result.returncode != 0:
            log.error("rsync failed (exit %s): %s", result.returncode, (result.stderr or "").strip()[-500:])
        return result.returncode

    def path(self, relative: str) -> str:
        return f"{self.target}:{relative}"

    def fetch(self, relative: str, local: str, optional: bool = False) -> bool:
        """One file from the server; optional = a missing file is no error (and removes the local copy)."""
        os.makedirs(os.path.dirname(local), exist_ok=True)
        if optional:
            if os.path.exists(local):
                os.remove(local)
            # the server's rrsync refuses a source path that does not exist ("post-realpath open
            # failed"), --ignore-missing-args notwithstanding - so ask for the directory, filtered
            # to that one name: a missing file is then simply not transferred
            name = os.path.basename(relative)
            remote_dir = os.path.dirname(relative)
            return self.rsync(["-rt", "--include", "/" + name, "--exclude", "*",
                               self.path((remote_dir + "/") if remote_dir else "./"), os.path.dirname(local) + "/"]) == 0
        return self.rsync(["-t", self.path(relative), local]) == 0 and os.path.exists(local)

    def put(self, local: str, relative: str) -> bool:
        """One file to the server, replaced atomically (temp file + rename).

        Sent as a filtered DIRECTORY sync, never "file -> remote file": the
        server's rrsync pins the destination argument as /proc/self/fd/N, and
        rsync then cannot replace an existing destination FILE ("delete_file:
        unlink(4) failed: Operation not permitted" - found 2026-11-12, the
        first upload of worker-status.json worked, every later one failed).
        The local and the remote file name must be the same.
        """
        name = os.path.basename(local)
        if name != os.path.basename(relative):
            raise ValueError("put() needs the same file name on both sides")
        remote_dir = os.path.dirname(relative)
        # -r: without it rsync skips a directory source silently ("skipping directory .") and still exits 0
        return self.rsync(["-rt", "--delay-updates", "--include", "/" + name, "--exclude", "*",
                           os.path.dirname(local) + "/", self.path((remote_dir + "/") if remote_dir else "./")]) == 0


def excludes(spec: ytan_jobs.JobSpec, keep_request: bool = True) -> list[str]:
    patterns = [p for p in COMMON_EXCLUDES if keep_request or p != "run-request"] + list(spec.publish_excludes)
    return [arg for p in patterns for arg in ("--exclude", p)]


def publish(spec: ytan_jobs.JobSpec, storage_root: str, remote: Remote, drop_request: bool = False) -> bool:
    """storage/{job}/ to the server: files, then status.json, manifest.json last, then the --delete pass.

    The jobs write every run/frame into a directory of its own and only then
    replace manifest.json, so the server never serves a manifest whose files
    are not there yet; the delete pass comes after the new manifest. With
    drop_request the server's run-request goes too (the request was handled).
    """
    local = os.path.join(storage_root, spec.name)
    if not os.path.isdir(local):
        return True
    target = remote.path(spec.name + "/")
    ok = remote.rsync(["-a"] + excludes(spec) + [local + "/", target]) == 0
    for name in ("status.json", "manifest.json"):
        if ok and os.path.exists(os.path.join(local, name)):
            ok = remote.put(os.path.join(local, name), f"{spec.name}/{name}")
    if ok:
        ok = remote.rsync(["-a", "--delete"] + excludes(spec, keep_request=not drop_request) + [local + "/", target]) == 0
    return ok


def publish_logs(storage_root: str, remote: Remote) -> bool:
    """The worker's current logs (worker.log and the jobs' own) to the server's storage/logs/worker/.

    A directory of their own, so they never mix with the server's logs of the
    same name (wind.log when the job ran there before); /admin/logs lists them
    as "YTAN worker". Only the current files, not the archives (NAME.N.log,
    ZIPs) - and no --delete. Filtered directory sync like put() (rrsync).
    """
    local = os.path.join(storage_root, "logs")
    if not os.path.isdir(local):
        return True
    return remote.rsync(["-rt", "--exclude", "*.[0-9]*.log", "--include", "/*.log", "--exclude", "*",
                         local + "/", remote.path("logs/worker/")]) == 0


def set_job_workers(storage_root: str, workers: int) -> None:
    """The add-on's thread/process count instead of the server's (radar "workers", wind "workers")."""
    path = os.path.join(storage_root, "radar", "config.json")
    try:
        with open(path, encoding="utf-8") as f:
            config = json.load(f)
        config["workers"] = workers
        if isinstance(config.get("wind"), dict):
            config["wind"]["workers"] = workers
        with open(path, "w", encoding="utf-8") as f:
            json.dump(config, f, ensure_ascii=False, indent=2)
    except (OSError, ValueError) as e:
        log.warning("could not set the job workers in config.json: %s", e)


def job_outcome(storage_root: str, name: str) -> dict:
    """last_run of the job's own status.json (outcome, start, end) - {} before its first run."""
    try:
        with open(os.path.join(storage_root, name, "status.json"), encoding="utf-8") as f:
            last = json.load(f).get("last_run") or {}
        return last if isinstance(last, dict) else {}
    except (OSError, ValueError, AttributeError):
        return {}


def tick(storage_root: str, remote: Remote, now: datetime, runner=ytan_jobs.run_job,
         workers: int | None = None, host: str | None = None) -> dict:
    """One round: config down, due/requested external jobs run and uploaded, heartbeat up. Returns the report."""
    report: dict = {"tick": now.strftime("%Y-%m-%dT%H:%M:%SZ"), "host": host or socket.gethostname(), "jobs": {}}
    if not remote.fetch("radar/config.json", os.path.join(storage_root, "radar", "config.json")):
        report["error"] = "config.json could not be fetched from the server"
        log.error(report["error"])
        return report
    if workers:
        set_job_workers(storage_root, workers)
    external = ytan_jobs.external_jobs(storage_root)
    report["external_jobs"] = external
    for spec in ytan_jobs.JOBS:
        if spec.name not in external:
            continue
        request = os.path.join(storage_root, spec.name, "run-request")
        requested = remote.fetch(f"{spec.name}/run-request", request, optional=True) and os.path.exists(request)
        due = requested or ytan_jobs.is_due(spec, ytan_jobs.last_start(storage_root, spec.name), now)
        entry = {"ran": False, "requested": requested}
        if due:
            started = time.monotonic()
            try:
                entry["code"] = runner(spec, force=requested)
            except Exception as e:  # a job that cannot start must not stop the others
                log.exception("%s could not be started", spec.name)
                entry["code"] = -1
                entry["error"] = str(e)
            entry["ran"] = True
            entry["seconds"] = round(time.monotonic() - started, 1)
            if requested:
                os.remove(request)
            entry["published"] = publish(spec, storage_root, remote, drop_request=requested)
            log.info("%s: exit %s in %.1f s%s, upload %s", spec.name, entry["code"], entry["seconds"],
                     " (requested)" if requested else "", "ok" if entry["published"] else "FAILED")
        entry["last_run"] = job_outcome(storage_root, spec.name)
        report["jobs"][spec.name] = entry
    heartbeat = os.path.join(storage_root, "worker-status.json")
    with open(heartbeat, "w", encoding="utf-8") as f:
        json.dump(report, f, ensure_ascii=False, indent=2)
    if not remote.put(heartbeat, "worker-status.json"):
        report["error"] = "worker-status.json could not be uploaded"
    publish_logs(storage_root, remote)  # never fails the tick; rsync() logs a failure
    return report


def post_sensors(report: dict, token: str | None = None, base: str = "http://supervisor/core/api", opener=urllib.request.urlopen) -> None:
    """Home Assistant sensors (add-on with homeassistant_api): one per external job plus sensor.ytan_worker."""
    token = token or os.environ.get("SUPERVISOR_TOKEN")
    if not token:
        return

    def post(entity: str, state: str, attributes: dict) -> None:
        body = json.dumps({"state": state, "attributes": attributes}).encode()
        req = urllib.request.Request(f"{base}/states/{entity}", data=body, method="POST",
                                     headers={"Authorization": "Bearer " + token, "Content-Type": "application/json"})
        try:
            opener(req, timeout=10).close()
        except Exception as e:  # sensors are a convenience, never a reason to fail the tick
            log.warning("sensor %s not set: %s", entity, e)

    post("sensor.ytan_worker", "error" if report.get("error") else "ok", {
        "friendly_name": "YTAN Worker", "icon": "mdi:server-network", "last_tick": report["tick"],
        "external_jobs": report.get("external_jobs", []), "error": report.get("error"),
    })
    for name, entry in report.get("jobs", {}).items():
        last = entry.get("last_run") or {}
        state = "error" if entry.get("ran") and (entry.get("code") != 0 or not entry.get("published")) else (last.get("outcome") or "idle")
        attributes = {
            "friendly_name": f"YTAN Worker {name}", "icon": "mdi:weather-windy" if name == "wind" else "mdi:radar",
            "last_run_start": last.get("start"), "last_run_end": last.get("end"),
        }
        if entry.get("ran"):
            attributes.update(last_tick_ran=report["tick"], duration_s=entry.get("seconds"), exit_code=entry.get("code"),
                              published=entry.get("published"), requested=entry.get("requested"))
        post(f"sensor.ytan_worker_{name}", state, attributes)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--remote", required=True, help="user@host of the server (key restricted to rrsync on its storage/)")
    parser.add_argument("--ssh-key", help="private key file")
    parser.add_argument("--known-hosts", help="known_hosts file (the server's host key is remembered on first contact)")
    parser.add_argument("--workers", type=int, default=None, help="threads/processes per job instead of the server's setting")
    parser.add_argument("--host-name", default=None, help="name shown on the admin pages (default: this host's name)")
    parser.add_argument("--ha-sensors", action="store_true", help="set Home Assistant sensors (needs SUPERVISOR_TOKEN)")
    args = parser.parse_args(argv)

    ytanlog.setup("worker")  # storage/logs/worker.log
    storage_root = os.path.join(ytan_jobs.ROOT, "storage")
    os.makedirs(storage_root, exist_ok=True)
    with ytan_jobs.Lock(os.path.join(storage_root, ".jobs.lock")) as locked:
        if not locked:
            log.warning("the previous tick is still working, skipped")
            return 0
        report = tick(storage_root, Remote(args.remote, args.ssh_key, args.known_hosts),
                      datetime.now(timezone.utc), workers=args.workers, host=args.host_name)
    if args.ha_sensors:
        post_sensors(report)
    failed = report.get("error") or any(e.get("ran") and (e.get("code") != 0 or not e.get("published")) for e in report["jobs"].values())
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
