"""One wind run: newest complete ICON runs -> blocks -> manifest.

storage/wind/
  manifest.json            what the client loads first (atomically replaced)
  status.json              last run, last processing, size (shown on /admin/radar)
  .lock
  runs/{runId}/            runId = publication time, YYYYmmddHHMM
    L0/{hhh}.bin.gz        hhh = index into manifest "hours"
    L1/{hhh}/{bx}_{by}.bin.gz
    L2/{hhh}/{bx}_{by}.bin.gz
A finished run is published by renaming runs/{runId}.tmp; an older run stays
for RETAIN_MINUTES after being replaced, so a client holding the previous
manifest can still load its blocks.
"""

from __future__ import annotations

import json
import logging
import os
import shutil
import time
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

import numpy as np

from . import blocks, dwd, grib2
from .settings import WindSettings

log = logging.getLogger("ytanwind")

RETAIN_MINUTES = 30
VAR_UV = ("u_10m", "v_10m")
VAR_GUST = "vmax_10m"
# coverage of the models, (south, west, north, east); ICON-D2's is its valid area less the edge
EU_AREA = (29.5, -23.5, 70.5, 62.5)
D2_AREA = (43.18 + 0.24, -3.94 + 0.24, 58.08 - 0.24, 20.34 - 0.24)


def iso(t: datetime) -> str:
    return t.strftime("%Y-%m-%dT%H:%MZ")


def iso_sec(t: datetime | None = None) -> str:
    return (t or datetime.now(timezone.utc)).strftime("%Y-%m-%dT%H:%M:%SZ")


def directory_size(path: str) -> int:
    total = 0
    for root, _dirs, files in os.walk(path):
        for name in files:
            try:
                total += os.path.getsize(os.path.join(root, name))
            except OSError:
                pass
    return total


@dataclass
class Plan:
    eu_run: datetime
    d2_run: datetime | None
    hours: list[tuple[datetime, int, int | None]]  # valid time, EU step, D2 step (None = EU only)


class Job:
    def __init__(self, storage: str, settings: WindSettings, force: bool = False, now: datetime | None = None):
        self.storage = storage
        self.s = settings
        self.force = force
        self.now = now or datetime.now(timezone.utc)
        self.runs_dir = os.path.join(storage, "runs")
        os.makedirs(self.runs_dir, exist_ok=True)
        self.stats = {"bytes_downloaded": 0, "files": 0}

    # -- planning ---------------------------------------------------------

    def window(self) -> tuple[datetime, datetime]:
        first = self.now.replace(minute=0, second=0, microsecond=0) - timedelta(hours=1)
        return first, first + timedelta(hours=1 + self.s.horizon_hours)

    def plan(self) -> Plan | None:
        first, last = self.window()
        variables = VAR_UV + ((VAR_GUST,) if self.s.gusts else ())
        eu = dwd.latest_complete_run(dwd.ICON_EU, self.now, first, last, variables, self.s.run_interval_hours)
        if eu is None:
            return None
        eu_run, eu_steps = eu
        d2_run, d2_steps = None, []
        if self.s.fine:
            d2 = dwd.latest_complete_run(dwd.ICON_D2, self.now, first, last, variables, self.s.run_interval_hours)
            if d2 is not None:
                d2_run, d2_steps = d2
        d2_by_valid = {d2_run + timedelta(hours=s): s for s in d2_steps} if d2_run else {}
        hours = []
        for step in eu_steps:
            valid = eu_run + timedelta(hours=step)
            hours.append((valid, step, d2_by_valid.get(valid)))
        return Plan(eu_run, d2_run, hours)

    # -- one hour ---------------------------------------------------------

    def fetch_field(self, model: dwd.Model, run: datetime, step: int, var: str) -> grib2.Field:
        data = dwd.http_get(model.url(run, step, var))
        self.stats["bytes_downloaded"] += len(data)
        self.stats["files"] += 1
        return grib2.decode_bz2(data)

    def fetch_channels(self, model: dwd.Model, run: datetime, step: int) -> list[grib2.Field]:
        fields = [self.fetch_field(model, run, step, v) for v in VAR_UV]
        if self.s.gusts:
            if step == 0:
                # vmax_10m is a maximum over the past hour: step 0 carries no usable value
                f0 = fields[0]
                fields.append(grib2.Field(np.hypot(f0.values, fields[1].values).astype(np.float32),
                                          f0.lat0, f0.lon0, f0.dlat, f0.dlon))
            else:
                fields.append(self.fetch_field(model, run, step, VAR_GUST))
        return fields

    def process_hour(self, tmp: str, index: int, eu_step: int, d2_step: int | None,
                     plan: Plan, l1_blocks: list, l2_blocks: list) -> dict:
        eu = self.fetch_channels(dwd.ICON_EU, plan.eu_run, eu_step)
        d2 = None
        if d2_step is not None and plan.d2_run is not None:
            try:
                d2 = [blocks.trim_d2(f) for f in self.fetch_channels(dwd.ICON_D2, plan.d2_run, d2_step)]
            except Exception as e:  # a missing fine field only costs resolution for this hour
                log.warning("ICON-D2 step %d unusable (%s), using ICON-EU for this hour", d2_step, e)
        regions = self.s.padded_regions()
        written = 0
        meta = None
        overview = blocks.overview(regions, eu, EU_AREA)
        if overview is not None:
            meta, data = overview
            written += self.write(os.path.join(tmp, "L0"), f"{index:03d}.bin.gz", blocks.pack(data))
        levels = ((blocks.L1, l1_blocks, [[eu[k]] for k in range(len(eu))]),
                  (blocks.L2, l2_blocks, [([d2[k]] if d2 else []) + [eu[k]] for k in range(len(eu))]))
        for level, wanted, sources in levels:
            folder = os.path.join(tmp, level.name, f"{index:03d}")
            for bx, by in wanted:
                data = blocks.build_block(level, bx, by, regions, sources)
                if data is not None:
                    written += self.write(folder, f"{bx}_{by}.bin.gz", blocks.pack(data))
        return {"index": index, "bytes": written, "l0": meta}

    @staticmethod
    def write(folder: str, name: str, data: bytes) -> int:
        os.makedirs(folder, exist_ok=True)
        with open(os.path.join(folder, name), "wb") as f:
            f.write(data)
        return len(data)

    # -- run --------------------------------------------------------------

    def run(self) -> dict | None:
        started = iso_sec()
        status = self.read_status()
        trigger = (status.get("running") or {}).get("trigger") or ("manual" if self.force else "cron")
        try:
            outcome, info = self._run()
        except Exception as e:
            self.write_status(status, {"start": started, "end": iso_sec(), "outcome": "failed",
                                       "trigger": trigger, "error": str(e)[:300]}, None)
            raise
        ended = iso_sec()
        self.write_status(status, {"start": started, "end": ended, "outcome": outcome, "trigger": trigger},
                          dict(info, start=started, end=ended) if info else None)
        return info

    def _run(self) -> tuple[str, dict | None]:
        if not self.s.enabled:
            log.info("wind job switched off in /admin/radar")
            try:
                os.remove(self.manifest_path())  # no manifest = the layer says "no wind data"
            except OSError:
                pass
            return "disabled", None
        plan = self.plan()
        if plan is None:
            log.warning("no complete ICON-EU run found")
            self.cleanup(self.read_manifest())
            return "no_data", None
        manifest = self.read_manifest()
        key = {"eu": f"{plan.eu_run:%Y%m%d%H}", "d2": f"{plan.d2_run:%Y%m%d%H}" if plan.d2_run else None,
               "signature": self.s.signature(), "hours": [iso(h[0]) for h in plan.hours]}
        if not self.force and manifest and manifest.get("key") == key:
            log.info("runs unchanged (EU %s, D2 %s)", key["eu"], key["d2"])
            self.cleanup(manifest)
            return "unchanged", None

        t0 = time.monotonic()
        run_id = self.now.strftime("%Y%m%d%H%M")
        tmp = os.path.join(self.runs_dir, run_id + ".tmp")
        final = os.path.join(self.runs_dir, run_id)
        for p in (tmp, final):
            shutil.rmtree(p, ignore_errors=True)
        os.makedirs(tmp)

        l1 = blocks.block_points(blocks.L1, EU_AREA, self.s.padded_regions())
        l2 = blocks.block_points(blocks.L2, D2_AREA, self.s.padded_regions()) if plan.d2_run else []
        log.info("EU %s, D2 %s, %d hours, %d L1 and %d L2 candidate blocks",
                 key["eu"], key["d2"], len(plan.hours), len(l1), len(l2))

        def task(item):
            i, (_valid, eu_step, d2_step) = item
            return self.process_hour(tmp, i, eu_step, d2_step, plan, l1, l2)

        try:
            with ThreadPoolExecutor(max_workers=self.s.workers) as ex:
                results = list(ex.map(task, enumerate(plan.hours)))
        except Exception:
            shutil.rmtree(tmp, ignore_errors=True)
            raise
        l0 = next((r["l0"] for r in results if r["l0"]), None)
        levels = {"L0": dict(l0 or {}),
                  "L1": {"res": blocks.L1.deg, "size": blocks.SIZE, "blocks": self.present(tmp, "L1", l1)},
                  "L2": {"res": blocks.L2.deg, "size": blocks.SIZE, "blocks": self.present(tmp, "L2", l2)}}
        new_manifest = {
            "run": run_id, "generated": iso(self.now), "key": key,
            "sources": {"eu": key["eu"], "d2": key["d2"]},
            "hours": key["hours"],
            "scale": {"uv": list(blocks.UV_RANGE), "gust": list(blocks.GUST_RANGE), "nodata": blocks.NODATA},
            "channels": self.s.channels, "levels": levels,
        }
        os.rename(tmp, final)
        if manifest and manifest.get("run") != run_id:
            old = os.path.join(self.runs_dir, str(manifest.get("run")))
            if os.path.isdir(old):
                os.utime(old, None)  # its retention time starts now
        self.write_manifest(new_manifest)
        self.cleanup(new_manifest)
        elapsed = time.monotonic() - t0
        info = {"eu_run": key["eu"], "d2_run": key["d2"], "hours": len(plan.hours),
                "seconds": round(elapsed, 1), "bytes_downloaded": self.stats["bytes_downloaded"],
                "files_downloaded": self.stats["files"], "bytes_written": sum(r["bytes"] for r in results),
                "regions": [r.get("name", "") for r in self.s.regions]}
        log.info("published %s in %.1f s (%.0f MB downloaded, %.1f MB written)", run_id, elapsed,
                 info["bytes_downloaded"] / 1e6, info["bytes_written"] / 1e6)
        return "processed", info

    @staticmethod
    def present(run_dir: str, level: str, wanted: list[tuple[int, int]]) -> list[str]:
        """Blocks of the level that exist in the run (some wanted ones carry no data)."""
        first = os.path.join(run_dir, level, "000")
        if not os.path.isdir(first):
            return []
        have = {n[:-len(".bin.gz")] for n in os.listdir(first)}
        return sorted(f"{bx}_{by}" for bx, by in wanted if f"{bx}_{by}" in have)

    # -- status, manifest, cleanup ----------------------------------------

    def status_path(self) -> str:
        return os.path.join(self.storage, "status.json")

    def read_status(self) -> dict:
        try:
            with open(self.status_path(), encoding="utf-8") as f:
                data = json.load(f)
            return data if isinstance(data, dict) else {}
        except (OSError, ValueError):
            return {}

    def mark_running(self, trigger: str) -> None:
        status = self.read_status()
        status["running"] = {"start": iso_sec(), "pid": os.getpid(), "trigger": trigger}
        self._write_json(self.status_path(), status)

    def write_status(self, status: dict, last_run: dict, processed: dict | None) -> None:
        status = dict(status)
        status.pop("running", None)
        status["last_run"] = last_run
        if processed is not None:
            status["last_processing"] = processed
        status["storage_bytes"] = directory_size(self.storage)
        status["storage_measured_at"] = iso_sec()
        self._write_json(self.status_path(), status)

    @staticmethod
    def _write_json(path: str, data: dict) -> None:
        tmp = path + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(data, f, separators=(",", ":"))
        os.replace(tmp, path)

    def manifest_path(self) -> str:
        return os.path.join(self.storage, "manifest.json")

    def read_manifest(self) -> dict | None:
        try:
            with open(self.manifest_path(), encoding="utf-8") as f:
                return json.load(f)
        except (OSError, ValueError):
            return None

    def write_manifest(self, manifest: dict) -> None:
        self._write_json(self.manifest_path(), manifest)

    def cleanup(self, manifest: dict | None) -> None:
        current = str(manifest.get("run")) if manifest else None
        limit = time.time() - RETAIN_MINUTES * 60
        for name in os.listdir(self.runs_dir):
            if name == current:
                continue
            path = os.path.join(self.runs_dir, name)
            # .tmp dirs are leftovers of a crashed run (an hour old at the earliest)
            age_limit = time.time() - 3600 if name.endswith(".tmp") else limit
            if os.path.getmtime(path) < age_limit:
                shutil.rmtree(path, ignore_errors=True)
