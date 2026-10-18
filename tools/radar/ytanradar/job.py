"""One run of the radar job (cron, every 5 minutes).

storage/radar/
  manifest.json            what the app shows (RadarController serves it)
  frames/{id}/{z}/{x}/{y}.png
                           o202610170415 = observation, f202610170415-030 =
                           forecast from that base time, +30 minutes. A frame
                           directory never changes once published (renamed
                           into place complete, with a .complete marker), so
                           tiles may be cached long.
  raw/{YYYYMMDDHHMM}.npz   cropped dBZ fields, for the nowcast and verification
  verify.csv               forecast skill against the observation that followed
  config.json              the admin settings (written by PHP, settings.py)
  status.json              what the last runs did, for /admin/radar (write_status())
"""

from __future__ import annotations

import json
import logging
import multiprocessing
import os
import shutil
import tempfile
import time
from concurrent.futures import ProcessPoolExecutor
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone

import numpy as np

from . import nowcast, opera, palette
from .mercator import TileGrid
from .reproject import Reprojector, smooth_for_display
from .tiles import write_pyramid

log = logging.getLogger("ytanradar")

STEP = timedelta(minutes=5)

# Written into a frame directory just before it is renamed into place. A
# directory without it is a leftover (e.g. only the PHP overzoom cache
# survived a failed delete) and must not count as published.
COMPLETE_MARKER = ".complete"

SOURCE = {
    "name": "EUMETNET OPERA",
    "url": "https://www.eumetnet.eu/observations/weather-radar-network/",
    "license": "CC BY 4.0",
    "license_url": "https://creativecommons.org/licenses/by/4.0/",
    # CC BY requires saying so: coloured, reprojected, forecast computed by YTAN
    "modified": True,
}


@dataclass
class Config:
    storage: str
    grid: TileGrid
    history_minutes: int = 60
    forecast_minutes: int = 60
    fetch_interval_minutes: int = 5  # cron runs every 5 minutes, runs in between are skipped
    look_back_minutes: int = 30   # how far back to look for the newest composite
    keep_raw_minutes: int = 80    # verification needs base - 10 min for a 60 min lead
    stale_frame_minutes: int = 10  # unreferenced frames stay that long (open clients)
    verify_leads: tuple[int, ...] = (15, 30, 60)
    verify_threshold_mm_h: float = 1.0
    workers: int = 4  # processes rendering frames in parallel; 1 = no pool
    regions: list[dict] = field(default_factory=list)  # empty = the whole grid (settings.py)
    regions_hash: str = ""  # a change rebuilds every frame


def default_grid() -> TileGrid:
    # The original fixed area: DE, DK, SE incl. the Baltic (wetterradar.md, Phase 0).
    # radar_job.py now takes the area from the admin settings (settings.py).
    return TileGrid(south=47.0, west=5.0, north=70.0, east=25.0, zmin=5, zmax=8)


def floor_step(t: datetime) -> datetime:
    t = t.astimezone(timezone.utc).replace(second=0, microsecond=0)
    return t - timedelta(minutes=t.minute % 5)


def iso(t: datetime) -> str:
    return t.strftime("%Y-%m-%dT%H:%M:%SZ")


def iso_now() -> str:
    return iso(datetime.now(timezone.utc))


def parse_iso(s: str) -> datetime:
    return datetime.strptime(s, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)


def obs_id(t: datetime) -> str:
    return f"o{t:%Y%m%d%H%M}"


def fc_id(base: datetime, lead: int) -> str:
    return f"f{base:%Y%m%d%H%M}-{lead:03d}"


def publish_frame(frames_dir: str, grid: TileGrid, rp: Reprojector, frame_id: str,
                  field: np.ndarray) -> tuple[str, bool, int, int, str | None]:
    """Write a frame's tiles and move them into place.

    Returns (frame_id, published, tile count, bytes written, error); count
    -1 = it was already there. Not published = the frame must stay out of
    the manifest. Module-level, so pool workers can
    run it; they report back instead of logging (spawned workers have no
    logging setup).
    """
    final = os.path.join(frames_dir, frame_id)
    if is_published(frames_dir, frame_id):
        return frame_id, True, -1, 0, None
    if os.path.isdir(final):  # incomplete leftover
        shutil.rmtree(final, ignore_errors=True)
    tmp = tempfile.mkdtemp(prefix=f".tmp-{frame_id}-", dir=frames_dir)
    idx = palette.classify(rp.render(smooth_for_display(field, rp.geom)))
    count = write_pyramid(grid, idx, tmp)
    open(os.path.join(tmp, COMPLETE_MARKER), "w").close()
    size = directory_size(tmp)
    # On Windows a virus scanner/indexer briefly holding a fresh file makes
    # the rename fail (seen in local runs) - retry before giving up.
    for attempt in range(10):
        try:
            os.rename(tmp, final)
            return frame_id, True, count, size, None
        except OSError as e:
            if is_published(frames_dir, frame_id):  # a concurrent run published it
                shutil.rmtree(tmp, ignore_errors=True)
                return frame_id, True, -1, 0, None
            if attempt == 9:
                shutil.rmtree(tmp, ignore_errors=True)
                return frame_id, False, 0, 0, str(e)
            time.sleep(0.3)
    return frame_id, False, 0, 0, "rename failed"


def directory_size(path: str) -> int:
    """Bytes of all files below path."""
    total = 0
    for root, _, names in os.walk(path):
        for name in names:
            try:
                total += os.path.getsize(os.path.join(root, name))
            except OSError:
                pass  # deleted meanwhile
    return total


def is_published(frames_dir: str, frame_id: str) -> bool:
    return os.path.exists(os.path.join(frames_dir, frame_id, COMPLETE_MARKER))


_WORKER: dict = {}


def _init_worker(frames_dir: str, grid: TileGrid, geom: opera.Geometry, rp: Reprojector | None,
                 regions: list[dict] | None = None) -> None:
    # fork: rp is the parent's object, shared copy-on-write (~250 MB lookup).
    # spawn (Windows): rp is None - rebuilding (~5 s) beats pickling it; it
    # needs the regions too, or everything outside them gets drawn (seen
    # 2026-10-18: rain over France with the regions DE/DK).
    _WORKER.update(frames_dir=frames_dir, grid=grid, rp=rp or Reprojector(grid, geom, regions=regions))


def _publish_in_worker(frame_id: str, field: np.ndarray) -> tuple[str, bool, int, int, str | None]:
    return publish_frame(_WORKER["frames_dir"], _WORKER["grid"], _WORKER["rp"], frame_id, field)


class Job:
    def __init__(self, cfg: Config, now: datetime | None = None):
        self.cfg = cfg
        self.now = now or datetime.now(timezone.utc)
        self.raw_dir = os.path.join(cfg.storage, "raw")
        self.frames_dir = os.path.join(cfg.storage, "frames")
        self.dl_dir = os.path.join(cfg.storage, "download")
        for d in (self.raw_dir, self.frames_dir, self.dl_dir):
            os.makedirs(d, exist_ok=True)
        self._reprojector: Reprojector | None = None
        self._geom: opera.Geometry | None = None
        # statistics of this run, for status.json
        self.download = {"start": None, "end": None, "bytes": 0, "files": 0}
        self.processing = {"start": None, "end": None, "workers": 0, "bytes": 0, "frames": 0}

    # -- raw fields -------------------------------------------------------

    def raw_path(self, t: datetime) -> str:
        return os.path.join(self.raw_dir, f"{t:%Y%m%d%H%M}.npz")

    def reprojector(self, geom: opera.Geometry) -> Reprojector:
        if self._reprojector is None or self._geom != geom:
            started = time.monotonic()
            self._reprojector = Reprojector(self.cfg.grid, geom, regions=self.cfg.regions or None)
            self._geom = geom
            log.info("reprojection lookup built in %.1f s, crop %s", time.monotonic() - started,
                     self._reprojector.crop)
        return self._reprojector

    def fetch(self, t: datetime) -> bool:
        """Make sure raw/{t}.npz exists for the current grid. False if the
        composite isn't available."""
        if os.path.exists(self.raw_path(t)):
            if self._raw_fits(self.raw_path(t)):
                return True
            os.remove(self.raw_path(t))  # cut for another area: fetch again
        h5 = os.path.join(self.dl_dir, f"{t:%Y%m%d%H%M}.h5")
        if self.download["start"] is None:
            self.download["start"] = iso_now()
        found = opera.download(opera.frame_url(t), h5)
        self.download["end"] = iso_now()
        if not found:
            return False
        self.download["bytes"] += os.path.getsize(h5)
        self.download["files"] += 1
        try:
            geom = opera.read_geometry(h5)
            rp = self.reprojector(geom)
            field = opera.read_dbzh(h5, rp.crop)
        finally:
            os.remove(h5)
        tmp = self.raw_path(t) + ".tmp.npz"
        np.savez_compressed(tmp, field=opera.encode(field), geometry=geom.to_json(),
                            crop=np.array(rp.crop))
        os.replace(tmp, self.raw_path(t))
        log.info("fetched %s", t.strftime("%Y-%m-%d %H:%M"))
        return True

    def _raw_fits(self, path: str) -> bool:
        with np.load(path) as z:
            geom = opera.Geometry.from_json(str(z["geometry"]))
            crop = tuple(int(v) for v in z["crop"])
        return crop == self.reprojector(geom).crop

    def load(self, t: datetime) -> np.ndarray | None:
        """Cropped dBZ field, None if missing or from a different grid."""
        path = self.raw_path(t)
        if not os.path.exists(path):
            return None
        with np.load(path) as z:
            geom = opera.Geometry.from_json(str(z["geometry"]))
            crop = tuple(int(v) for v in z["crop"])
            enc = z["field"]
        rp = self.reprojector(geom)
        if crop != rp.crop:
            log.warning("raw %s has a different crop, ignored", path)
            return None
        return opera.decode(enc)

    # -- frames -----------------------------------------------------------

    def publish_frames(self, items: list[tuple[str, np.ndarray]]) -> set[str]:
        """Publish (frame_id, field) pairs, in parallel with cfg.workers > 1.
        Returns the ids that are in place afterwards."""
        rp = self._reprojector
        if not items:
            return set()
        workers = max(1, min(self.cfg.workers, len(items)))
        self.processing["workers"] = workers
        if workers == 1:
            results = [publish_frame(self.frames_dir, self.cfg.grid, rp, fid, f) for fid, f in items]
        else:
            method = "fork" if "fork" in multiprocessing.get_all_start_methods() else "spawn"
            ctx = multiprocessing.get_context(method)
            with ProcessPoolExecutor(max_workers=workers, mp_context=ctx, initializer=_init_worker,
                                     initargs=(self.frames_dir, self.cfg.grid, self._geom,
                                               rp if method == "fork" else None,
                                               self.cfg.regions or None)) as pool:
                results = list(pool.map(_publish_in_worker, [i[0] for i in items], [i[1] for i in items]))
        done = set()
        for frame_id, ok, count, size, error in results:
            if ok:
                done.add(frame_id)
                if count >= 0:
                    self.processing["bytes"] += size
                    self.processing["frames"] += 1
                    log.info("frame %s: %d tiles", frame_id, count)
            else:
                log.warning("frame %s not published: %s", frame_id, error)
        return done

    # -- run --------------------------------------------------------------

    def run(self) -> dict:
        """One run; records what it did in status.json, also when it fails."""
        started = iso_now()
        status = self.read_status()
        try:
            outcome, manifest = self._run(status)
        except Exception as e:
            self.write_status(status, {"start": started, "end": iso_now(), "outcome": "failed",
                                       "error": str(e)[:300]}, None)
            raise
        processed = None
        if outcome == "processed":
            processed = {
                "start": started, "end": iso_now(),
                "regions": [r.get("name", "") for r in self.cfg.regions],
                "download": self.download, "processing": self.processing,
            }
        self.write_status(status, {"start": started, "end": iso_now(), "outcome": outcome}, processed)
        return manifest

    def _run(self, status: dict) -> tuple[str, dict | None]:
        """(outcome, manifest): processed, no_new_data, skipped_interval or no_composite."""
        cfg = self.cfg
        previous = self.read_manifest()

        # Fetch interval: cron runs every 5 minutes, data is processed only every N.
        last = (status.get("last_processing") or {}).get("start")
        if last and cfg.fetch_interval_minutes > 5 and previous is not None:
            age = (self.now - parse_iso(last)).total_seconds()
            if age < cfg.fetch_interval_minutes * 60 - 60:  # a minute of slack for cron jitter
                log.info("fetch interval %d min not reached (last processing %d s ago), skipped",
                         cfg.fetch_interval_minutes, age)
                return "skipped_interval", previous

        # -- download phase: the newest composite, then the history
        newest = None
        t = floor_step(self.now)
        oldest_look = t - timedelta(minutes=cfg.look_back_minutes)
        while t >= oldest_look:
            if self.fetch(t):
                newest = t
                break
            t -= STEP
        if newest is None:
            log.warning("no composite in the last %d minutes", cfg.look_back_minutes)
            if previous is None:
                raise RuntimeError("no composite available and no previous manifest")
            return "no_composite", previous

        if previous is not None and previous.get("regions_hash", "") != cfg.regions_hash:
            # other regions: every frame has to be drawn again
            log.info("regions changed, rebuilding all frames")
            self.drop_all_frames()
            previous = None
        if (previous is not None and previous.get("latest_observation") == iso(newest)
                and previous.get("forecast_minutes") == cfg.forecast_minutes
                and previous.get("history_minutes") == cfg.history_minutes):
            log.info("no new composite since %s", iso(newest))
            self.cleanup(previous)
            return "no_new_data", previous

        history = [newest - STEP * i for i in range(cfg.history_minutes // 5, -1, -1)]
        available = [t for t in history if t == newest or self.fetch(t)]

        # -- processing phase: render observations, nowcast, publish
        self.processing["start"] = iso_now()
        frames = []
        items = []  # frames still to render: (id, field)
        for t in available:
            if not is_published(self.frames_dir, obs_id(t)):
                field = self.load(t)
                if field is None:
                    continue
                items.append((obs_id(t), field))
            frames.append({"id": obs_id(t), "time": iso(t), "type": "observation"})

        latest = self.load(newest)
        steps = cfg.forecast_minutes // 5
        motion = None
        if steps > 0:
            past = [self.load(newest - STEP * i) for i in (2, 1)]
            motion = nowcast.motion_field([f for f in past if f is not None] + [latest])
        for k, field in enumerate(nowcast.extrapolate(latest, motion, steps), start=1):
            lead = k * 5
            items.append((fc_id(newest, lead), field))
            frames.append({"id": fc_id(newest, lead), "time": iso(newest + STEP * k),
                           "type": "forecast", "base_time": iso(newest), "lead_minutes": lead})

        published = self.publish_frames(items)
        pending = {fid for fid, _ in items}
        frames = [f for f in frames if f["id"] not in pending or f["id"] in published]
        self.processing["end"] = iso_now()

        manifest = {
            "version": 1,
            "generated": iso(datetime.now(timezone.utc)),
            "latest_observation": iso(newest),
            "step_minutes": 5,
            "source": SOURCE,
            "zoom": {"min": cfg.grid.zmin, "max": cfg.grid.zmax},
            "bounds": {"south": cfg.grid.south, "west": cfg.grid.west,
                       "north": cfg.grid.north, "east": cfg.grid.east},
            "regions": cfg.regions,
            "regions_hash": cfg.regions_hash,
            "history_minutes": cfg.history_minutes,
            "forecast_minutes": cfg.forecast_minutes,
            "legend": palette.legend(),
            "motion": None if motion is None else "optical-flow",
            "frames": frames,
        }
        self.write_manifest(manifest)
        self.verify(newest, latest)
        self.cleanup(manifest)
        return "processed", manifest

    # -- status for /admin/radar --------------------------------------------

    def status_path(self) -> str:
        return os.path.join(self.cfg.storage, "status.json")

    def read_status(self) -> dict:
        try:
            with open(self.status_path(), encoding="utf-8") as f:
                data = json.load(f)
            return data if isinstance(data, dict) else {}
        except (OSError, ValueError):
            return {}

    def write_status(self, status: dict, last_run: dict, processed: dict | None) -> None:
        """status.json: the last run (whatever it did), the last run that
        processed data, and the size of storage/radar right now."""
        status = dict(status)
        status["last_run"] = last_run
        if processed is not None:
            status["last_processing"] = processed
        status["storage_bytes"] = directory_size(self.cfg.storage)
        status["storage_measured_at"] = iso_now()
        tmp = self.status_path() + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(status, f, indent=1)
        os.replace(tmp, self.status_path())

    # -- manifest, cleanup, verification ----------------------------------

    def manifest_path(self) -> str:
        return os.path.join(self.cfg.storage, "manifest.json")

    def read_manifest(self) -> dict | None:
        try:
            with open(self.manifest_path(), encoding="utf-8") as f:
                return json.load(f)
        except (OSError, ValueError):
            return None

    def write_manifest(self, manifest: dict) -> None:
        tmp = self.manifest_path() + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(manifest, f, separators=(",", ":"))
        os.replace(tmp, self.manifest_path())

    def drop_all_frames(self) -> None:
        """Delete every frame and the manifest (regions changed). Clients get
        404s for a moment and reload the manifest on their next refresh."""
        try:
            os.remove(self.manifest_path())
        except OSError:
            pass
        for name in os.listdir(self.frames_dir):
            if not name.startswith(".tmp-"):
                shutil.rmtree(os.path.join(self.frames_dir, name), ignore_errors=True)

    def cleanup(self, manifest: dict) -> None:
        keep = {f["id"] for f in manifest["frames"]}
        limit = time.time() - self.cfg.stale_frame_minutes * 60
        for name in os.listdir(self.frames_dir):
            path = os.path.join(self.frames_dir, name)
            if name in keep:
                continue
            # temp dirs of a crashed run are an hour old at the earliest
            age_limit = time.time() - 3600 if name.startswith(".tmp-") else limit
            if os.path.getmtime(path) < age_limit:
                shutil.rmtree(path, ignore_errors=True)
        raw_limit = self.now - timedelta(minutes=self.cfg.keep_raw_minutes)
        for name in os.listdir(self.raw_dir):
            try:
                t = datetime.strptime(name[:12], "%Y%m%d%H%M").replace(tzinfo=timezone.utc)
            except ValueError:
                continue
            if t < raw_limit:
                os.remove(os.path.join(self.raw_dir, name))
        for name in os.listdir(self.dl_dir):  # leftovers of an interrupted download
            path = os.path.join(self.dl_dir, name)
            if os.path.getmtime(path) < time.time() - 3600:
                os.remove(path)

    def verify(self, newest: datetime, observed: np.ndarray) -> None:
        """Score the forecasts that were valid for `newest` against what came.

        One CSV line per lead time with the nowcast's and plain persistence's
        (\"it stays as it is\") CSI at verify_threshold_mm_h - the nowcast is
        only worth showing for lead times where it beats persistence.
        """
        path = os.path.join(self.cfg.storage, "verify.csv")
        marker = os.path.join(self.cfg.storage, "verify.last")
        try:
            with open(marker, encoding="utf-8") as f:
                if f.read().strip() == iso(newest):
                    return
        except OSError:
            pass
        thr = palette.rate_to_dbz(self.cfg.verify_threshold_mm_h)
        rows = []
        for lead in self.cfg.verify_leads:
            base = newest - timedelta(minutes=lead)
            fields = [self.load(base - STEP * i) for i in (2, 1, 0)]
            if any(f is None for f in fields):
                continue
            motion = nowcast.motion_field(fields)
            fc = nowcast.extrapolate(fields[-1], motion, lead // 5)[-1]
            score, rain_px = nowcast.csi(fc, observed, thr)
            persist, _ = nowcast.csi(fields[-1], observed, thr)
            rows.append(f"{iso(newest)},{lead},{rain_px},{_fmt(score)},{_fmt(persist)}\n")
        new_file = not os.path.exists(path)
        with open(path, "a", encoding="utf-8") as f:
            if new_file:
                f.write("valid_time,lead_minutes,observed_rain_px,csi_nowcast,csi_persistence\n")
            f.writelines(rows)
        with open(marker, "w", encoding="utf-8") as f:
            f.write(iso(newest))


def _fmt(v: float | None) -> str:
    return "" if v is None else f"{v:.3f}"
