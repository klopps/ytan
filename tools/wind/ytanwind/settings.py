"""Settings for the wind job.

The regions are the rain radar's (storage/radar/config.json, written by PHP
from /admin/radar) - one region choice for both overlays. The job-specific
values sit in the "wind" object of the same file; defaults mirror
WindConfig::defaults() on the PHP side.
"""

from __future__ import annotations

import json
import logging
import os
import sys
from dataclasses import dataclass, field

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "..", "radar"))

from ytanradar.settings import DEFAULT_REGIONS, _valid_region, regions_hash  # noqa: E402,F401

log = logging.getLogger("ytanwind")

# The data covers the rectangle ENCLOSING all regions, grown by this much on every
# side. Cutting to the single region rectangles left big holes over the sea
# between and beside countries (North Sea west of Denmark, Skagerrak), which is
# exactly where kayakers look; the enclosing box has none inside it.
PAD_DEGREES = 1.0


@dataclass
class WindSettings:
    regions: list[dict] = field(default_factory=lambda: [dict(r) for r in DEFAULT_REGIONS])
    enabled: bool = True         # admin switch: off = the job does nothing
    horizon_hours: int = 48      # 24, 48, 72, 120
    run_interval_hours: int = 3  # 3 or 6: which model runs are processed
    gusts: bool = False          # third channel from vmax_10m
    fine: bool = True            # ICON-D2 (0.02 degrees) as the L2 level
    workers: int = 4             # parallel hours (download + decode + write)

    def padded_regions(self) -> list[dict]:
        """The area the data is cut to: ONE rectangle around all regions, grown by PAD_DEGREES."""
        return [{"name": "wind",
                 "south": max(-90.0, min(r["south"] for r in self.regions) - PAD_DEGREES),
                 "west": max(-180.0, min(r["west"] for r in self.regions) - PAD_DEGREES),
                 "north": min(90.0, max(r["north"] for r in self.regions) + PAD_DEGREES),
                 "east": min(180.0, max(r["east"] for r in self.regions) + PAD_DEGREES)}]

    @property
    def channels(self) -> int:
        return 3 if self.gusts else 2

    def signature(self) -> str:
        """Everything but the model runs that changes the output."""
        return f"{regions_hash(self.regions)}:box{PAD_DEGREES:g}:{self.horizon_hours}:{self.channels}:{int(self.fine)}"


def _choice(v, allowed: tuple, default):
    try:
        v = int(v)
    except (TypeError, ValueError):
        return default
    return v if v in allowed else default


def load_settings(radar_storage: str) -> WindSettings:
    path = os.path.join(radar_storage, "config.json")
    try:
        with open(path, encoding="utf-8") as f:
            data = json.load(f)
    except FileNotFoundError:
        return WindSettings()
    except (OSError, ValueError) as e:
        log.warning("config.json unreadable (%s), using the defaults", e)
        return WindSettings()
    if not isinstance(data, dict):
        return WindSettings()
    regions = [
        {"name": str(r.get("name", "")), "south": float(r["south"]), "west": float(r["west"]),
         "north": float(r["north"]), "east": float(r["east"])}
        for r in data.get("regions", []) if _valid_region(r)
    ] or [dict(r) for r in DEFAULT_REGIONS]
    w = data.get("wind") if isinstance(data.get("wind"), dict) else {}
    d = WindSettings()
    try:
        workers = max(1, min(8, int(w.get("workers", d.workers))))
    except (TypeError, ValueError):
        workers = d.workers
    return WindSettings(
        regions=regions,
        enabled=w.get("enabled") is not False,
        horizon_hours=_choice(w.get("horizon_hours"), (24, 48, 72, 120), d.horizon_hours),
        run_interval_hours=_choice(w.get("run_interval_hours"), (3, 6), d.run_interval_hours),
        gusts=w.get("gusts") is True,
        fine=w.get("fine") is not False,
        workers=workers,
    )
