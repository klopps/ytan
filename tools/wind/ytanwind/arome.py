"""Météo-France AROME (0.025 degrees, France and its surroundings) 10 m wind.

Source: Météo-France's public API portal, the AROME 0.025 WCS service
(https://public-api.meteofrance.fr/public/arome/1.0/wcs/MF-NWP-HIGHRES-AROME-0025-FRANCE-WCS).
Needs an API key (header `apikey`, `.env`'s `AROME_API_KEY` or the environment - on the
YTAN worker the add-on option of the same name); a key has a lifetime chosen when it was
created, and an expired one answers 401: the job then goes on without AROME.

Found while building it (2026-11-19):
- A coverage is one parameter of ONE run: `{PARAMETER}___{run as 2026-11-19T09.00.00Z}`,
  `U_COMPONENT_OF_WIND__SPECIFIC_HEIGHT_LEVEL_ABOVE_GROUND`, `V_...`, and the gust
  `WIND_SPEED_GUST__SPECIFIC_HEIGHT_LEVEL_ABOVE_GROUND` (starts one hour after the run,
  like ICON's vmax_10m, so step 0 uses the mean wind). Runs every 3 hours, 51 hourly steps;
  the portal keeps ~37 runs.
- GetCoverage cuts a lat/long box and answers a GRIB2 message (regular lat/lon, simple
  packing with 11 bits - grib2.py reads any width since this source). Only 2D slices:
  one time, one height (`height(10)`) per request, a time range is refused. So one request
  per hour and field (~14 KB for a Brittany-sized box, a whole-domain field is 1.1 MB).
- `time(...)` takes the value WITHOUT quotes; a wrong value lists the valid times.
- GetCapabilities is 2.9 MB (8,900 coverages): never used by the job. A run's existence is
  checked with DescribeCoverage (6 KB, 404 `NoSuchCoverage` when the run is not there yet),
  its last step with a 185-byte one-point GetCoverage.
- The grid is plain lat/lon, so the wind is already east/north - no rotation, no
  projection, and the field is used at its own 0.025 degrees (blocks.sample interpolates).
- The portal limits the request rate - 100 per minute for this account (told by the operator
  2026-11-19): the job spaces requests `MIN_GAP_S` apart across all threads and waits and
  retries on HTTP 429.
"""

from __future__ import annotations

import logging
import os
import re
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

import numpy as np

from . import grib2
from .grib2 import Field

log = logging.getLogger("ytanwind")

BASE = "https://public-api.meteofrance.fr/public/arome/1.0/wcs/MF-NWP-HIGHRES-AROME-0025-FRANCE-WCS"
USER_AGENT = "YTAN wind job (kayak trip planner; https://ytan.pesr.org)"
# the domain's lat/long box (south, west, north, east) and its grid: nodes at -12 + k * 0.025, 37.5 + j * 0.025
AREA = (37.5, -12.0, 55.4, 16.0)
STEP = 0.025
ORIGIN_LAT, ORIGIN_LON = 37.5, -12.0
EDGE_DEG = 0.2            # the domain's outer edge (boundary values of the host model) is not used
HEIGHT_M = 10
P_U = "U_COMPONENT_OF_WIND__SPECIFIC_HEIGHT_LEVEL_ABOVE_GROUND"
P_V = "V_COMPONENT_OF_WIND__SPECIFIC_HEIGHT_LEVEL_ABOVE_GROUND"
P_GUST = "WIND_SPEED_GUST__SPECIFIC_HEIGHT_LEVEL_ABOVE_GROUND"
MIN_GAP_S = 0.75          # between two requests, all threads together (~80 per minute; the plan allows 100)
RETRY_429_S = 20
REQUEST_TIMEOUT_S = 60

_throttle_lock = threading.Lock()
_last_request = 0.0


class AromeError(RuntimeError):
    """The portal refused or failed a request (the message never contains the key)."""


class NoSuchRun(AromeError):
    """The portal has no coverage for this run (yet)."""


def api_key() -> str:
    """AROME_API_KEY from the environment, else from the repository's .env ('' = none)."""
    key = os.environ.get("AROME_API_KEY", "").strip()
    if key:
        return key
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "..", ".env")
    try:
        with open(path, encoding="utf-8") as f:
            for line in f:
                m = re.match(r"\s*AROME_API_KEY\s*=\s*(.*?)\s*$", line)
                if m:
                    return m.group(1).strip().strip("\"'")
    except OSError:
        pass
    return ""


def coverage_id(parameter: str, run: datetime) -> str:
    return f"{parameter}___{run:%Y-%m-%dT%H}.00.00Z"


def _wait_turn() -> None:
    global _last_request
    with _throttle_lock:
        wait = _last_request + MIN_GAP_S - time.monotonic()
        if wait > 0:
            time.sleep(wait)
        _last_request = time.monotonic()


def _get(operation: str, params: list[tuple[str, str]], key: str, stats: dict | None = None,
         tries: int = 4) -> bytes:
    query = urllib.parse.urlencode([("service", "WCS"), ("version", "2.0.1")] + params)
    url = f"{BASE}/{operation}?{query}"
    last: Exception | None = None
    for attempt in range(tries):
        _wait_turn()
        try:
            req = urllib.request.Request(url, headers={"apikey": key, "User-Agent": USER_AGENT})
            with urllib.request.urlopen(req, timeout=REQUEST_TIMEOUT_S) as r:
                body = r.read()
            if stats is not None:
                stats["bytes_downloaded"] = stats.get("bytes_downloaded", 0) + len(body)
                stats["files"] = stats.get("files", 0) + 1
            return body
        except urllib.error.HTTPError as e:
            text = e.read().decode("utf-8", "replace")
            if e.code in (401, 403):
                raise AromeError("AROME API key rejected (HTTP %d) - expired or not subscribed to the AROME API" % e.code)
            if e.code == 404 and "NoSuchCoverage" in text:
                raise NoSuchRun("no such coverage")
            if e.code == 429:
                last = AromeError("rate limit (HTTP 429)")
                time.sleep(RETRY_429_S * (attempt + 1))
                continue
            if e.code < 500:
                raise AromeError("HTTP %d: %s" % (e.code, re.sub(r"<[^>]+>", " ", text)[:200].strip()))
            last = AromeError("HTTP %d" % e.code)
        except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
            last = AromeError("connection failed: %s" % e)
        time.sleep(2 * (attempt + 1))
    raise last  # type: ignore[misc]


def describe_end(run: datetime, key: str, stats: dict | None = None) -> datetime:
    """Last valid time of a run's wind coverage (raises NoSuchRun when the run is not published)."""
    xml = _get("DescribeCoverage", [("coverageid", coverage_id(P_U, run))], key, stats).decode("utf-8", "replace")
    m = re.search(r"<gml:endPosition[^>]*>([^<]+)</gml:endPosition>", xml)
    if not m:
        raise AromeError("DescribeCoverage without a time range")
    return datetime.strptime(m.group(1).strip(), "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)


def snap_up(v: float, origin: float) -> float:
    return round(origin + np.ceil((v - origin) / STEP - 1e-9) * STEP, 6)


def snap_down(v: float, origin: float) -> float:
    return round(origin + np.floor((v - origin) / STEP + 1e-9) * STEP, 6)


def fetch_field(parameter: str, run: datetime, valid: datetime, window: "Window", key: str,
                stats: dict | None = None) -> np.ndarray:
    """One parameter at one valid time over the window: (rows south -> north, cols west -> east)."""
    raw = _get("GetCoverage", [
        ("coverageid", coverage_id(parameter, run)), ("format", "application/wmo-grib"),
        ("subset", f"time({valid:%Y-%m-%dT%H:%M:%SZ})"),
        ("subset", f"lat({window.south:g},{window.north:g})"),
        ("subset", f"long({window.west:g},{window.east:g})"),
        ("subset", f"height({HEIGHT_M})"),
    ], key, stats)
    if raw[:4] != b"GRIB":
        raise AromeError("not a GRIB answer: " + re.sub(r"<[^>]+>", " ", raw[:300].decode("utf-8", "replace")).strip())
    field = grib2.decode(raw)
    shape = (window.rows, window.cols)
    if field.values.shape != shape or abs(field.lat0 - window.south) > 1e-4 or abs(field.lon0 - window.west) > 1e-4:
        raise AromeError("unexpected grid %s at %.3f/%.3f, wanted %s at %.3f/%.3f" % (
            field.values.shape, field.lat0, field.lon0, shape, window.south, window.west))
    return field.values


@dataclass
class Window:
    """The box asked for, on the domain's grid nodes."""
    south: float
    west: float
    north: float
    east: float

    @property
    def rows(self) -> int:
        return int(round((self.north - self.south) / STEP)) + 1

    @property
    def cols(self) -> int:
        return int(round((self.east - self.west) / STEP)) + 1


def plan_window(box: dict) -> Window | None:
    """The part of `box` (the padded regions) the domain covers, without its outer edge, snapped to grid nodes."""
    s, w = max(box["south"], AREA[0] + EDGE_DEG), max(box["west"], AREA[1] + EDGE_DEG)
    n, e = min(box["north"], AREA[2] - EDGE_DEG), min(box["east"], AREA[3] - EDGE_DEG)
    if not (s < n and w < e):
        return None
    win = Window(snap_down(s, ORIGIN_LAT), snap_down(w, ORIGIN_LON), snap_up(n, ORIGIN_LAT), snap_up(e, ORIGIN_LON))
    return win if win.rows > 1 and win.cols > 1 else None


def latest_complete_run(now: datetime, first: datetime, last: datetime, every: int = 3,
                        candidates: int = 4, key: str | None = None,
                        stats: dict | None = None) -> tuple[datetime, list[int]] | None:
    """Newest run (only every `every` hours) with data in [first, last] and its last step written; its steps in that window."""
    key = api_key() if key is None else key
    if not key:
        log.info("AROME_API_KEY not set, going on without AROME")
        return None
    t = now.replace(minute=0, second=0, microsecond=0)
    t -= timedelta(hours=t.hour % every)
    try:
        for _ in range(candidates):
            run = t
            t -= timedelta(hours=every)
            try:
                end = describe_end(run, key, stats)
            except NoSuchRun:
                continue
            steps = [k for k in range(0, int((end - run).total_seconds() // 3600) + 1)
                     if first <= run + timedelta(hours=k) <= last]
            if not steps:
                continue
            # a run is listed before it is complete: ask for a single point of its last step
            tiny = Window(AREA[0] + 5.0, AREA[1] + 5.0, AREA[0] + 5.0 + STEP, AREA[1] + 5.0 + STEP)
            try:
                _get("GetCoverage", [
                    ("coverageid", coverage_id(P_V, run)), ("format", "application/wmo-grib"),
                    ("subset", f"time({end:%Y-%m-%dT%H:%M:%SZ})"),
                    ("subset", f"lat({tiny.south:g},{tiny.north:g})"), ("subset", f"long({tiny.west:g},{tiny.east:g})"),
                    ("subset", f"height({HEIGHT_M})"),
                ], key, stats)
            except NoSuchRun:
                continue
            except AromeError as e:
                if "HTTP 4" in str(e):
                    log.info("AROME %s: last step not available yet (%s)", run, e)
                    continue
                raise
            return run, steps
    except Exception as e:  # AROME is an extra: without it the job goes on with ICON/MEPS
        log.warning("AROME unusable (%s), going on without AROME", e)
    return None


class Source:
    """One AROME run, cut to the regions: fields per forecast hour on the domain's own 0.025 degree grid."""

    def __init__(self, run: datetime, box: dict, exclude: tuple[float, float, float, float] | None = None,
                 stats: dict | None = None, key: str | None = None):
        self.run = run
        self.stats = stats
        self.key = api_key() if key is None else key
        self.window = plan_window(box)
        self.exclude = exclude
        if self.window is not None:
            w = self.window
            lat = w.south + np.arange(w.rows) * STEP
            lon = w.west + np.arange(w.cols) * STEP
            valid = np.ones((w.rows, w.cols), bool)
            if exclude is not None:
                es, ew, en, ee = exclude
                valid &= ~((lat[:, None] >= es) & (lat[:, None] <= en) & (lon[None, :] >= ew) & (lon[None, :] <= ee))
            self.valid = valid

    def coverage(self) -> Field | None:
        """1 where AROME is used, NaN elsewhere (which L2 blocks get AROME data)."""
        if self.window is None or not self.valid.any():
            return None
        v = np.where(self.valid, 1.0, np.nan).astype(np.float32)
        return Field(v, self.window.south, self.window.west, STEP, STEP)

    def fields(self, step: int, gusts: bool) -> list[Field]:
        w = self.window
        valid_time = self.run + timedelta(hours=step)
        u = fetch_field(P_U, self.run, valid_time, w, self.key, self.stats)
        v = fetch_field(P_V, self.run, valid_time, w, self.key, self.stats)
        planes = [u.astype(np.float32), v.astype(np.float32)]
        if gusts:
            if step == 0:
                g = np.hypot(planes[0], planes[1])  # the gust field starts one hour after the run
            else:
                g = fetch_field(P_GUST, self.run, valid_time, w, self.key, self.stats).astype(np.float32)
            planes.append(g)
        out = []
        for p in planes:
            p = np.where(self.valid & np.isfinite(p) & (np.abs(p) < 1e30), p, np.nan).astype(np.float32)
            out.append(Field(p, w.south, w.west, STEP, STEP))
        return out
