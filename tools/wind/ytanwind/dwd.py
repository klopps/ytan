"""DWD open data (opendata.dwd.de): ICON-D2 and ICON-EU 10 m wind.

Both models run every 3 hours and publish one bz2-compressed GRIB2 file per
variable and forecast hour. The directory listing tells which steps of a run
exist, which is how a run still being uploaded is told from a finished one.
"""

from __future__ import annotations

import logging
import re
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

log = logging.getLogger("ytanwind")

BASE = "https://opendata.dwd.de/weather/nwp"
USER_AGENT = "YTAN wind job (kayak trip planner; https://ytan.pesr.org)"


@dataclass(frozen=True)
class Model:
    key: str            # "eu" / "d2"
    lower: str          # directory name of the model
    last_step_main: int  # last forecast hour of the 00/06/12/18 runs
    last_step_other: int

    def last_step(self, run: datetime) -> int:
        return self.last_step_main if run.hour % 6 == 0 else self.last_step_other

    def folder(self, run: datetime, var: str) -> str:
        return f"{BASE}/{self.lower}/grib/{run:%H}/{var}/"

    def filename(self, run: datetime, step: int, var: str) -> str:
        if self.key == "d2":
            return f"icon-d2_germany_regular-lat-lon_single-level_{run:%Y%m%d%H}_{step:03d}_2d_{var}.grib2.bz2"
        return f"icon-eu_europe_regular-lat-lon_single-level_{run:%Y%m%d%H}_{step:03d}_{var.upper()}.grib2.bz2"

    def url(self, run: datetime, step: int, var: str) -> str:
        return self.folder(run, var) + self.filename(run, step, var)

    def step_in_name(self, name: str, run: datetime) -> int | None:
        m = re.search(rf"_{run:%Y%m%d%H}_(\d{{3}})_", name)
        return int(m.group(1)) if m else None


ICON_D2 = Model("d2", "icon-d2", 48, 48)
ICON_EU = Model("eu", "icon-eu", 120, 51)


def http_get(url: str, timeout: int = 60, tries: int = 3) -> bytes:
    """GET with a few retries on server errors and dropped connections."""
    last: Exception | None = None
    for attempt in range(tries):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
            with urllib.request.urlopen(req, timeout=timeout) as r:
                return r.read()
        except urllib.error.HTTPError as e:
            if e.code < 500:
                raise
            last = e
        except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
            last = e
        time.sleep(2 * (attempt + 1))
    raise last  # type: ignore[misc]


def listed_steps(model: Model, run: datetime, var: str) -> set[int]:
    """Forecast hours of this run that are on the server (empty when the run is not there)."""
    try:
        html = http_get(model.folder(run, var), timeout=30).decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        if e.code == 404:
            return set()
        raise
    steps = set()
    for name in re.findall(r'href="([^"]+\.grib2\.bz2)"', html):
        s = model.step_in_name(name, run)
        if s is not None:
            steps.add(s)
    return steps


def wanted_steps(model: Model, run: datetime, first: datetime, last: datetime) -> list[int]:
    """The forecast hours of a run that fall into [first, last] and that the model publishes.

    ICON-EU is hourly to +78 h and every 3 hours after that; nothing is
    interpolated, the hours in between are left out of the timeline."""
    out = []
    for step in range(0, model.last_step(run) + 1):
        if model.key == "eu" and step > 78 and step % 3:
            continue
        valid = run + timedelta(hours=step)
        if first <= valid <= last:
            out.append(step)
    return out


def latest_complete_run(model: Model, now: datetime, first: datetime, last: datetime,
                        variables: tuple[str, ...], every: int = 3,
                        candidates: int = 6) -> tuple[datetime, list[int]] | None:
    """Newest run (only every `every` hours: 3 or 6) whose every wanted step is already published."""
    t = now.replace(minute=0, second=0, microsecond=0)
    t -= timedelta(hours=t.hour % every)
    for _ in range(candidates):
        wanted = wanted_steps(model, t, first, last)
        if wanted:
            have = None
            for var in variables:
                s = listed_steps(model, t, var)
                have = s if have is None else have & s
                if not have:
                    break
            if have and set(wanted) <= have:
                return t, wanted
        t -= timedelta(hours=every)
    return None


def utc(s: str) -> datetime:
    return datetime.strptime(s, "%Y%m%d%H").replace(tzinfo=timezone.utc)
