"""Builds the water distance index (storage/water/water-index.bin) from
OpenStreetMap country extracts (Geofabrik .osm.pbf).

    tools/water/.venv/Scripts/python tools/water/build_index.py            DK NO SE FI DE
    tools/water/.venv/Scripts/python tools/water/build_index.py DK         only Denmark
    tools/water/.venv/Scripts/python tools/water/build_index.py --refresh  download the extracts again
    tools/water/.venv/Scripts/python tools/water/build_index.py --coverage-only
                                           only (re)write the covered area into water-index.json

Water, by kind:
    sea    ways natural=coastline (fjords and bays included - the coastline follows them)
    river  ways waterway=river|canal (centre lines)
    lake   areas natural=water of at least LAKE_MIN_KM2, without ponds, basins,
           river/canal surfaces etc. (NOT_LAKES); every ring is shore, so a
           position on an island measures to the island's shore

Lines are simplified by SIMPLIFY_METERS in EPSG:3035 (metric, Europe) and
written as segments sorted into grid cells (water_index.py). Runs on dev only;
bin/publish-water.bat uploads the result to the servers, where PHP reads it
(Ytan\\Service\\Water\\WaterDistanceService). See tools/water/README.md.

The index only knows the water of its countries, so water-index.json also
carries their borders (Geofabrik's .poly files of the same extracts) as
"coverage": a position outside them gets no distances at all - otherwise a
harbour in Germany measured 21.5 km to the nearest DANISH coast.
"""

from __future__ import annotations

import argparse
import gc
import json
import os
import sys
import time
import urllib.request
from datetime import datetime, timezone

import numpy as np
import osmium
import shapely
from pyproj import Transformer

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import water_index  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
STORAGE = os.path.join(ROOT, "storage", "water")
PBF_DIR = os.path.join(STORAGE, "pbf")

COUNTRIES = {
    "DK": "europe/denmark",
    "NO": "europe/norway",
    "SE": "europe/sweden",
    "FI": "europe/finland",
    "DE": "europe/germany",
}
GEOFABRIK = "https://download.geofabrik.de/{}-latest.osm.pbf"
GEOFABRIK_POLY = "https://download.geofabrik.de/{}.poly"
USER_AGENT = "YTAN/1.0 (+https://ytan.pesr.org; no-reply@pesr.org)"

LAKE_MIN_KM2 = 10.0
# Above this size the node locations go to a file instead of memory - the
# German extract (~4.5 GB) did not fit next to everything else in 14 GB RAM.
FILE_INDEX_BYTES = 2_000_000_000
SIMPLIFY_METERS = 5.0
NOT_LAKES = {"pond", "basin", "wastewater", "river", "canal", "stream", "ditch", "lock", "moat",
             "reflecting_pool", "fishpond", "fish_pass", "drain"}

TO_METRIC = Transformer.from_crs("EPSG:4326", "EPSG:3035", always_xy=True)
TO_DEGREES = Transformer.from_crs("EPSG:3035", "EPSG:4326", always_xy=True)


def log(message: str) -> None:
    print(time.strftime("%H:%M:%S"), message, flush=True)


def download(country: str, refresh: bool) -> str:
    os.makedirs(PBF_DIR, exist_ok=True)
    path = os.path.join(PBF_DIR, COUNTRIES[country].split("/")[-1] + "-latest.osm.pbf")
    if os.path.exists(path) and not refresh:
        return path
    log(f"{country}: downloading {GEOFABRIK.format(COUNTRIES[country])}")
    request = urllib.request.Request(GEOFABRIK.format(COUNTRIES[country]), headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(request, timeout=120) as answer, open(path + ".tmp", "wb") as out:
        while chunk := answer.read(1 << 20):
            out.write(chunk)
    os.replace(path + ".tmp", path)
    return path


def parse_poly(text: str) -> list[list[list[float]]]:
    """Outer rings of an Osmosis .poly file as [[lat, lng], ...] lists; holes
    (sections starting with "!") are left out - a hole only makes the covered
    area a little too large, never too small."""
    rings = []
    lines = [line.strip() for line in text.splitlines()]
    i = 1  # line 0 is the file's name
    while i < len(lines):
        name = lines[i]
        i += 1
        if name == "END" or name == "":
            continue
        ring = []
        while i < len(lines) and lines[i] != "END":
            lng, lat = (float(v) for v in lines[i].split())
            ring.append([round(lat, 5), round(lng, 5)])
            i += 1
        i += 1
        if not name.startswith("!") and len(ring) >= 3:
            rings.append(ring)
    return rings


def coverage(country: str, refresh: bool) -> list[list[list[float]]]:
    """The extract's border (Geofabrik .poly, kept next to the .pbf)."""
    os.makedirs(PBF_DIR, exist_ok=True)
    path = os.path.join(PBF_DIR, COUNTRIES[country].split("/")[-1] + ".poly")
    if refresh or not os.path.exists(path):
        log(f"{country}: downloading {GEOFABRIK_POLY.format(COUNTRIES[country])}")
        request = urllib.request.Request(GEOFABRIK_POLY.format(COUNTRIES[country]), headers={"User-Agent": USER_AGENT})
        with urllib.request.urlopen(request, timeout=60) as answer:
            data = answer.read()
        with open(path + ".tmp", "wb") as out:
            out.write(data)
        os.replace(path + ".tmp", path)
    with open(path, encoding="utf-8") as source:
        return parse_poly(source.read())


def is_lake(tags) -> bool:
    return tags.get("natural") == "water" and tags.get("water", "") not in NOT_LAKES


def extract(path: str) -> dict[str, list[np.ndarray]]:
    """Lines (each an M x 2 lng/lat array) per kind from one extract."""
    lines: dict[str, list[np.ndarray]] = {k: [] for k in water_index.KINDS}
    wkb = osmium.geom.WKBFactory()
    node_file = path + ".nodes"
    big = os.path.getsize(path) > FILE_INDEX_BYTES
    if big and os.path.exists(node_file):
        os.remove(node_file)  # left over from an aborted run
    processor = (osmium.FileProcessor(path)
                 .with_locations("sparse_file_array," + node_file if big else "flex_mem")
                 .with_areas(osmium.filter.TagFilter(("natural", "water")))
                 .with_filter(osmium.filter.EntityFilter(osmium.osm.WAY | osmium.osm.AREA))
                 .with_filter(osmium.filter.TagFilter(("natural", "coastline"), ("waterway", "river"),
                                                      ("waterway", "canal"), ("natural", "water"))))
    for obj in processor:
        tags = obj.tags
        if obj.is_way():
            if tags.get("natural") == "coastline":
                kind = "sea"
            elif tags.get("waterway") in ("river", "canal"):
                kind = "river"
            else:
                continue
            coords = [(n.lon, n.lat) for n in obj.nodes if n.location.valid()]
            if len(coords) >= 2:
                lines[kind].append(np.asarray(coords, dtype=np.float64))
        elif obj.is_area() and is_lake(tags):
            try:
                geometry = shapely.from_wkb(wkb.create_multipolygon(obj))
            except Exception:  # broken multipolygon, e.g. cut at the extract's border
                continue
            metric = shapely.transform(geometry, lambda xy: np.column_stack(TO_METRIC.transform(xy[:, 0], xy[:, 1])))
            if metric.area < LAKE_MIN_KM2 * 1e6:
                continue
            for polygon in getattr(geometry, "geoms", [geometry]):
                for ring in [polygon.exterior, *polygon.interiors]:
                    lines["lake"].append(np.asarray(ring.coords, dtype=np.float64))
    processor = obj = None
    gc.collect()  # the index file stays open (and undeletable on Windows) until then
    if big and os.path.exists(node_file):
        try:
            os.remove(node_file)
        except OSError as error:
            # Seen on Windows: libosmium keeps it open until the process ends.
            # The next run deletes it first; by hand it can go after this run.
            log(f"could not delete {node_file} ({error}) - delete it after this run")
    return lines


def to_segments(lines: list[np.ndarray]) -> np.ndarray:
    """Simplifies the lines in metres and returns their segments (N x 4: lat1, lng1, lat2, lng2)."""
    parts = []
    for line in lines:
        x, y = TO_METRIC.transform(line[:, 0], line[:, 1])
        simple = shapely.simplify(shapely.LineString(np.column_stack([x, y])), SIMPLIFY_METERS)
        xy = np.asarray(simple.coords)
        if len(xy) < 2:
            continue
        lng, lat = TO_DEGREES.transform(xy[:, 0], xy[:, 1])
        parts.append(np.column_stack([lat[:-1], lng[:-1], lat[1:], lng[1:]]))
    return np.concatenate(parts) if parts else np.zeros((0, 4))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("countries", nargs="*", default=list(COUNTRIES))
    parser.add_argument("--refresh", action="store_true", help="download the extracts again")
    parser.add_argument("--coverage-only", action="store_true",
                        help="only add the covered area to the existing water-index.json (new version)")
    args = parser.parse_args()
    countries = [c.upper() for c in args.countries]
    unknown = [c for c in countries if c not in COUNTRIES]
    if unknown:
        parser.error("unknown country: " + ", ".join(unknown))

    meta_path = os.path.join(STORAGE, "water-index.json")
    if args.coverage_only:
        with open(meta_path, encoding="utf-8") as source:
            meta = json.load(source)
        meta["coverage"] = [ring for country in meta["countries"] for ring in coverage(country, args.refresh)]
        # A new version, so bin/poi-water-distance.php recomputes every POI.
        meta["version"] = datetime.now(timezone.utc).strftime("%Y%m%d%H%M")
        with open(meta_path + ".tmp", "w", encoding="utf-8") as out:
            json.dump(meta, out, indent=2)
        os.replace(meta_path + ".tmp", meta_path)
        log(f"coverage of {', '.join(meta['countries'])} written ({len(meta['coverage'])} rings), version {meta['version']}")
        return 0

    started = time.time()
    lines: dict[str, list[np.ndarray]] = {k: [] for k in water_index.KINDS}
    sources = {}
    covered = []
    for country in countries:
        covered.extend(coverage(country, args.refresh))
        path = download(country, args.refresh)
        sources[country] = datetime.fromtimestamp(os.path.getmtime(path), timezone.utc).strftime("%Y-%m-%d")
        log(f"{country}: reading {os.path.basename(path)} ({os.path.getsize(path) / 1e6:.0f} MB)")
        found = extract(path)
        for kind in water_index.KINDS:
            lines[kind].extend(found[kind])
        log(f"{country}: " + ", ".join(f"{k} {len(found[k])} lines" for k in water_index.KINDS))

    segments = {}
    for kind in water_index.KINDS:
        segments[kind] = to_segments(lines[kind])
        log(f"{kind}: {len(segments[kind])} segments after simplifying")

    os.makedirs(STORAGE, exist_ok=True)
    target = os.path.join(STORAGE, "water-index.bin")
    stats = water_index.write(target + ".tmp", segments)
    os.replace(target + ".tmp", target)
    version = datetime.now(timezone.utc).strftime("%Y%m%d%H%M")
    meta = {
        "version": version,
        "countries": countries,
        "sources": sources,
        "lake_min_km2": LAKE_MIN_KM2,
        "simplify_meters": SIMPLIFY_METERS,
        "bytes": os.path.getsize(target),
        "seconds": round(time.time() - started),
        **stats,
        "coverage": covered,
    }
    with open(meta_path, "w", encoding="utf-8") as out:
        json.dump(meta, out, indent=2)
    log(f"written {target} ({meta['bytes'] / 1e6:.1f} MB) in {meta['seconds']} s, version {version}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
