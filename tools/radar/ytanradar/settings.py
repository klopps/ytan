"""Admin settings for the job: storage/radar/config.json.

Written by PHP (RadarConfig::writeJobConfig(), /admin/radar) - the job has
no database access. A missing or unreadable file, or a missing/invalid
value, means the defaults below, which mirror RadarConfig::defaults().
"""

from __future__ import annotations

import hashlib
import json
import logging
import os
from dataclasses import dataclass, field
from datetime import datetime, timezone

from .mercator import TileGrid

log = logging.getLogger("ytanradar")

ZMIN, ZMAX = 5, 8

# Same boxes as RadarConfig::PRESETS de/dk/se.
DEFAULT_REGIONS = [
    {"name": "de", "south": 47.2, "west": 5.8, "north": 55.1, "east": 15.1},
    {"name": "dk", "south": 54.5, "west": 7.9, "north": 57.8, "east": 15.3},
    {"name": "se", "south": 55.2, "west": 10.8, "north": 69.1, "east": 24.2},
]


@dataclass
class JobSettings:
    regions: list[dict] = field(default_factory=lambda: [dict(r) for r in DEFAULT_REGIONS])
    history_minutes: int = 60      # 5-60, steps of 5
    forecast_minutes: int = 60     # 0-120, steps of 5
    fetch_interval_minutes: int = 5  # 5-15, steps of 5; cron still runs every 5 minutes
    workers: int = 4               # 1-12
    paused_until: datetime | None = None  # /admin/radar: no fetching until then (UTC)
    paused_forever: bool = False          # /admin/radar: no fetching until resumed

    def paused(self, now: datetime) -> bool:
        return self.paused_forever or (self.paused_until is not None and now < self.paused_until)


def _valid_region(r) -> bool:
    try:
        return (isinstance(r, dict) and -90 <= float(r["south"]) < float(r["north"]) <= 90
                and -180 <= float(r["west"]) < float(r["east"]) <= 180)
    except (KeyError, TypeError, ValueError):
        return False


def _stepped(data: dict, key: str, default: int, low: int, high: int, step: int) -> int:
    """The value clamped into low..high and down to whole steps; default when unusable."""
    try:
        v = int(data.get(key, default))
    except (TypeError, ValueError):
        return default
    v = max(low, min(high, v))
    return v - (v - low) % step


def load_settings(storage: str) -> JobSettings:
    """JobSettings from config.json, defaults when it's missing or broken."""
    path = os.path.join(storage, "config.json")
    try:
        with open(path, encoding="utf-8") as f:
            data = json.load(f)
    except FileNotFoundError:
        return JobSettings()
    except (OSError, ValueError) as e:
        log.warning("config.json unreadable (%s), using the defaults", e)
        return JobSettings()
    if not isinstance(data, dict):
        log.warning("config.json is not an object, using the defaults")
        return JobSettings()
    regions = [
        {"name": str(r.get("name", "")), "south": float(r["south"]), "west": float(r["west"]),
         "north": float(r["north"]), "east": float(r["east"])}
        for r in data.get("regions", []) if _valid_region(r)
    ]
    if not regions:
        log.warning("config.json has no valid region, using the defaults")
        regions = [dict(r) for r in DEFAULT_REGIONS]
    d = JobSettings()
    paused_until = None
    if data.get("paused_until"):
        try:
            paused_until = datetime.strptime(str(data["paused_until"]), "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
        except ValueError:
            log.warning("config.json: unreadable paused_until %r, ignored", data["paused_until"])
    return JobSettings(
        paused_until=paused_until,
        paused_forever=data.get("paused_forever") is True,
        regions=regions,
        history_minutes=_stepped(data, "history_minutes", d.history_minutes, 5, 60, 5),
        forecast_minutes=_stepped(data, "forecast_minutes", d.forecast_minutes, 0, 120, 5),
        fetch_interval_minutes=_stepped(data, "fetch_interval_minutes", d.fetch_interval_minutes, 5, 15, 5),
        workers=_stepped(data, "workers", d.workers, 1, 12, 1),
    )


def grid_for(regions: list[dict]) -> TileGrid:
    """The rectangle enclosing all regions; outside the regions the job masks."""
    return TileGrid(
        south=min(r["south"] for r in regions), west=min(r["west"] for r in regions),
        north=max(r["north"] for r in regions), east=max(r["east"] for r in regions),
        zmin=ZMIN, zmax=ZMAX,
    )


def regions_hash(regions: list[dict]) -> str:
    """Changes whenever the rendered area changes (names don't matter)."""
    key = [[round(r[k], 4) for k in ("south", "west", "north", "east")] for r in regions]
    return hashlib.sha1(json.dumps(sorted(key)).encode()).hexdigest()[:12]
