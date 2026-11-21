#!/usr/bin/env python3
"""YTAN worker: runs the scheduled jobs the admin moved off the server, and uploads their output.

Runs in the Home Assistant add-on ha-addon/ytan-worker/ (a loop calls one tick
every few minutes), but works on any machine with Python, ssh and rsync.

Every worker has ONE unique name (--worker-id, registered in /admin/workers). The
admin keeps a priority list of workers per job there (app_settings.job_workers ->
"job_workers" in storage/radar/config.json, src/Service/JobPlacement.php); who works
on a job NOW is decided by ytan_jobs.active_worker() - the same function runs in the
server's cron and in every worker, from the lists and the heartbeats, because the
workers only talk to the server through rsync (no coordinator). One tick:

1. fetch radar/config.json from the server (regions, job settings, the lists) and
   the others' state: workers/*/worker-status.json, jobs-heartbeat.json (the
   server's cron) and the jobs' status.json, into storage/cluster/;
2. another live system under the same name (a different "instance", the UUID this
   worker keeps in storage/.worker-instance) = name conflict: this one marks it
   (workers/{id}/conflict.json), reports an error and does NOT work;
3. for every job that active_worker() gives to this worker: fetch its run-request
   ("update now" on the admin page), run it when it is due (same slots as on
   the server, ytan_jobs.is_due(); the last start of the server's copy of the
   job's status.json counts too, so a stand-in does not repeat what the one
   before just did) or requested (--force), then upload its output - files
   first, status.json, manifest.json last, then a --delete pass that removes
   what the job itself has cleaned up (publish());
4. upload workers/{id}/worker-status.json (who it is, what it can do, what it
   did; the admin pages show the last contact) and, in the add-on, set Home
   Assistant sensors (sensor.ytan_worker_*).

A worker that is in no list (or not enabled) only reports. Only the worker
connects - to the server, as the one SSH user there, with a key that
authorized_keys restricts to rsync below the YTAN storage directory
(command="rrsync .../storage",restrict): remote paths are relative to
storage/, there is no shell (so no "mv" - rsync --delay-updates makes the
manifest replacement atomic instead). rrsync does not tell the keys apart, so the
name is the worker's own claim - see docs/API.md "Workers" for what that means.

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
import uuid
from datetime import datetime, timedelta, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import ytan_jobs  # noqa: E402
import ytanlog  # noqa: E402

log = logging.getLogger("ytanworker")

# never uploaded, never deleted on the server by the --delete pass
COMMON_EXCLUDES = ("manifest.json", "status.json", ".lock", "run-request", "*.tmp", "*.tmp/")

WORKER_PROTOCOL = 2          # what the heartbeat's layout is (1 = before the registry: one worker-status.json)
INSTANCE_FILE = ".worker-instance"  # in the local storage/: this system's UUID, so a second system with the same name is noticed
CLUSTER_DIR = "cluster"      # local copy of what the others reported: workers/{id}/..., jobs-heartbeat.json, {job}-status.json
OUTBOX_DIR = "outbox"        # what this worker uploads about itself: worker-status.json, conflict.json


def iso(t: datetime) -> str:
    return t.strftime("%Y-%m-%dT%H:%M:%SZ")


def load_instance(storage_root: str) -> str:
    """This system's UUID (storage/.worker-instance; made on the first start, kept in the data volume)."""
    path = os.path.join(storage_root, INSTANCE_FILE)
    try:
        with open(path, encoding="utf-8") as f:
            value = f.read().strip()
        if value:
            return value
    except OSError:
        pass
    value = uuid.uuid4().hex
    try:
        with open(path, "w", encoding="utf-8") as f:
            f.write(value)
    except OSError as e:
        log.warning("could not keep the instance id (%s): a restart will look like a new system", e)
    return value


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
        # --timeout: give up after 120 s without data instead of hanging the tick (and the lock) for ever
        command = ["rsync", "-e", self.ssh, "--timeout=120"] + args
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

    def fetch_tree(self, relative_dir: str, local_dir: str, names: tuple[str, ...]) -> bool:
        """A directory tree from the server, only the files called `names` (workers/{id}/worker-status.json ...).

        --delete: what the server dropped (a reset worker, a removed file) goes here too; the excluded files
        of the local directory are never deleted. Fails when the server has no such directory (rrsync refuses
        a source that does not exist) - the caller treats that as "nothing known"."""
        os.makedirs(local_dir, exist_ok=True)
        args = ["-rt", "--delete", "--include", "*/"] + [a for n in names for a in ("--include", n)] + ["--exclude", "*"]
        return self.rsync(args + [self.path(relative_dir.rstrip("/") + "/"), local_dir.rstrip("/") + "/"]) == 0

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


def publish_logs(storage_root: str, remote: Remote, worker_id: str) -> bool:
    """The worker's current logs (worker.log and the jobs' own) to the server's storage/logs/worker/{id}/.

    A directory of their own per worker, so they never mix with the server's logs of the
    same name (wind.log when the job ran there before) or another worker's; /admin/logs
    lists them as "YTAN worker". Only the current files, not the archives (NAME.N.log,
    ZIPs) - and no --delete. Filtered directory sync like put() (rrsync). storage/logs/worker/
    must exist on the server (rsync makes only the last path component; JobPlacement::ensureWorkersDir()).
    """
    local = os.path.join(storage_root, "logs")
    if not os.path.isdir(local):
        return True
    return remote.rsync(["-rt", "--exclude", "*.[0-9]*.log", "--include", "/*.log", "--exclude", "*",
                         local + "/", remote.path(f"logs/worker/{worker_id}/")]) == 0


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


def start_of(path: str) -> datetime | None:
    """last_run.start of a job's status.json (the server's copy fetched into storage/cluster/), None if there is none."""
    data = ytan_jobs._read_json(path)
    last = data.get("last_run") if data else None
    return ytan_jobs.parse_time(last.get("start")) if isinstance(last, dict) else None


def tick(storage_root: str, remote: Remote, now: datetime, runner=ytan_jobs.run_job,
         workers: int | None = None, host: str | None = None, worker_id: str = "worker",
         jobs: list[str] | None = None, instance: str | None = None, address: str | None = None) -> dict:
    """One round: config and cluster state down, the jobs this worker is given run and are uploaded, heartbeat up. Returns the report.

    `jobs` = what this system can do (None = every placeable job); the server's lists and the heartbeats decide
    which of them it works on now (ytan_jobs.active_worker()).

    The heartbeat's "tick" is the time it is written, not the start of the round: a wind run takes
    ~15 minutes, and a start time made the admin pages call the worker "silent" right after every
    long run (found 2026-11-12). Before each job it is also sent once with "running" = {job, since},
    so the server knows the worker is busy, not gone (JobPlacement::heartbeatAlive())."""
    started_round = time.monotonic()
    log.info("tick: start")
    instance = instance or load_instance(storage_root)
    capabilities = [s.name for s in ytan_jobs.JOBS if s.name in ytan_jobs.PLACEABLE_JOBS and (jobs is None or s.name in jobs)]
    report: dict = {"tick": iso(now), "id": worker_id, "instance": instance, "protocol": WORKER_PROTOCOL,
                    "host": host or socket.gethostname(), "capabilities": capabilities, "jobs": {}}
    if address:
        report["address"] = address
    cluster = os.path.join(storage_root, CLUSTER_DIR)
    outbox = os.path.join(storage_root, OUTBOX_DIR)
    os.makedirs(outbox, exist_ok=True)
    heartbeat = os.path.join(outbox, "worker-status.json")
    own_dir = f"workers/{worker_id}"

    def clock() -> str:
        return iso(now + timedelta(seconds=time.monotonic() - started_round))

    def send_heartbeat(running: dict | None = None) -> bool:
        body = dict(report, tick=clock(), **({"running": running} if running else {}))
        with open(heartbeat, "w", encoding="utf-8") as f:
            json.dump(body, f, ensure_ascii=False, indent=2)
        return remote.put(heartbeat, f"{own_dir}/worker-status.json")

    if not remote.fetch("radar/config.json", os.path.join(storage_root, "radar", "config.json")):
        report["error"] = "config.json could not be fetched from the server"
        log.error(report["error"])
        return report
    if workers:
        set_job_workers(storage_root, workers)
    lists = ytan_jobs.job_lists(storage_root)
    if lists is None:
        report["error"] = "the server's config.json has no job_workers: the server is older than this worker"
        log.error(report["error"])
        return report

    # the others: who has reported (workers/*), whether the server's cron ticks
    tree_ok = remote.fetch_tree("workers", os.path.join(cluster, "workers"), ("worker-status.json", "conflict.json"))
    remote.fetch("jobs-heartbeat.json", os.path.join(cluster, "jobs-heartbeat.json"), optional=True)
    if not tree_ok:
        report["error"] = "workers/ could not be fetched from the server (open Admin > Workers once: it creates the directory)"
        log.error(report["error"])
        send_heartbeat()
        return report
    known = ytan_jobs.read_workers(os.path.join(cluster, "workers"))

    # one name, one system: a live report of another instance under this name = a second system claims it
    other = known.get(worker_id)
    if other and other.get("instance") not in (None, instance) and ytan_jobs.heartbeat_alive(other, now):
        report["error"] = f"the name {worker_id} is used by another system ({other.get('host') or '?'}); this one does not work"
        log.error(report["error"])
        marker = os.path.join(outbox, "conflict.json")
        with open(marker, "w", encoding="utf-8") as f:
            json.dump({"tick": clock(), "host": report["host"], "instance": instance}, f)
        remote.put(marker, f"{own_dir}/conflict.json")
        return report

    mine = dict(known, **{worker_id: {"tick": iso(now), "capabilities": capabilities, "running": None}})
    server = ytan_jobs._read_json(os.path.join(cluster, "jobs-heartbeat.json"))
    server_tick = server.get("tick") if server else None
    active = [s.name for s in ytan_jobs.JOBS
              if s.name in capabilities and ytan_jobs.active_worker(s.name, lists.get(s.name) or [], mine, server_tick, now) == worker_id]
    report["active_jobs"] = active
    report["external_jobs"] = active  # what the Home Assistant sensors call it
    for spec in ytan_jobs.JOBS:
        if spec.name not in active:
            continue
        request = os.path.join(storage_root, spec.name, "run-request")
        requested = remote.fetch(f"{spec.name}/run-request", request, optional=True) and os.path.exists(request)
        # the server's copy of the job's status counts too: a stand-in must not repeat what the one before did this slot
        server_status = os.path.join(cluster, spec.name, "status.json")  # same file name as on the server (fetch() cannot rename)
        remote.fetch(f"{spec.name}/status.json", server_status, optional=True)
        last = max((t for t in (ytan_jobs.last_start(storage_root, spec.name), start_of(server_status)) if t is not None), default=None)
        due = requested or ytan_jobs.is_due(spec, last, now)
        entry = {"ran": False, "requested": requested}
        if due:
            log.info("%s: start%s", spec.name, " (requested)" if requested else "")
            send_heartbeat({"job": spec.name, "since": clock()})
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
    if not send_heartbeat():
        report["error"] = "worker-status.json could not be uploaded"
    # one line per tick, written before the logs are uploaded so the server sees it at once
    overview = ", ".join(f"{name} {'ran' if e['ran'] else 'not due'}" for name, e in report["jobs"].items())
    log.info("tick: done in %.1f s - %s", time.monotonic() - started_round,
             overview or "no job assigned to this worker")
    publish_logs(storage_root, remote, worker_id)  # never fails the tick; rsync() logs a failure
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
        "worker_id": report.get("id"),
        # the jobs this worker works on now (the name of the old key, so that automations keep working)
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
    parser.add_argument("--worker-id", required=True,
                        help="this worker's unique name, registered in Admin > Workers (a-z, 0-9, '-', at most 32 characters)")
    parser.add_argument("--jobs", default=None,
                        help="the jobs this system can do, comma separated (default: all of %s)" % ",".join(ytan_jobs.PLACEABLE_JOBS))
    parser.add_argument("--host-name", default=None, help="host name shown on the admin pages (default: this host's name)")
    parser.add_argument("--address", default=None, help="address shown on the admin pages, e.g. the LAN IP (display only)")
    parser.add_argument("--ha-sensors", action="store_true", help="set Home Assistant sensors (needs SUPERVISOR_TOKEN)")
    args = parser.parse_args(argv)

    if not ytan_jobs.valid_worker_id(args.worker_id):
        parser.error("--worker-id must be 1-32 characters: a-z, 0-9 and '-', not starting with '-', and not 'server'")
    jobs = [j.strip() for j in args.jobs.split(",") if j.strip()] if args.jobs else None
    unknown = [j for j in jobs or [] if j not in ytan_jobs.PLACEABLE_JOBS]
    if unknown:
        parser.error("--jobs: unknown job(s) %s (known: %s)" % (", ".join(unknown), ", ".join(ytan_jobs.PLACEABLE_JOBS)))

    ytanlog.setup("worker")  # storage/logs/worker.log
    storage_root = os.path.join(ytan_jobs.ROOT, "storage")
    os.makedirs(storage_root, exist_ok=True)
    with ytan_jobs.Lock(os.path.join(storage_root, ".jobs.lock")) as locked:
        if not locked:
            log.warning("the previous tick is still working, skipped")
            return 0
        report = tick(storage_root, Remote(args.remote, args.ssh_key, args.known_hosts),
                      datetime.now(timezone.utc), workers=args.workers, host=args.host_name,
                      worker_id=args.worker_id, jobs=jobs, address=args.address)
    if args.ha_sensors:
        post_sensors(report)
    failed = report.get("error") or any(e.get("ran") and (e.get("code") != 0 or not e.get("published")) for e in report["jobs"].values())
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
