"""Finds a place name for positions, from the OpenStreetMap extracts the water
index is built from (storage/water/pbf/): the smallest named protected area
the position lies in, else the nearest named place (village, hamlet,
locality, ...) within MAX_PLACE_METERS. Used to name shelters that have no
name of their own ("Gapahuk Femundsmarka", "Shelter Lønstrup").

    tools/water/.venv/Scripts/python tools/water/place_names.py IN.json OUT.json [--refresh]

IN.json  = [{"id": 1, "lat": 56.1, "lng": 9.2}, ...]
OUT.json = {"1": "Mols Bjerge", ...}  (positions without any place are left out)

The protected areas and places are extracted once per country (each in a
child process) into storage/water/places-COUNTRY.pkl (a few minutes) and
reused until --refresh or a newer extract. Called by
php bin/import-shelters.php places; dev only.
"""

from __future__ import annotations

import argparse
import json
import os
import pickle
import sys
import time

import numpy as np
import osmium
import shapely
from pyproj import Transformer

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import build_index  # noqa: E402

CACHE_VERSION = 1
MAX_PLACE_METERS = 20000
PLACE_TYPES = {"city", "town", "village", "hamlet", "suburb", "quarter", "neighbourhood", "locality",
               "isolated_dwelling", "farm"}
TO_METRIC = Transformer.from_crs("EPSG:4326", "EPSG:3035", always_xy=True)


def log(message: str) -> None:
    print(time.strftime("%H:%M:%S"), message, file=sys.stderr, flush=True)


def is_protected_area(tags) -> bool:
    return (tags.get("boundary") in ("protected_area", "national_park") or tags.get("leisure") == "nature_reserve") \
        and bool(tags.get("name"))


def extract(path: str) -> tuple[list[tuple[str, bytes]], list[tuple[str, float, float]]]:
    """(protected areas as (name, metric WKB), places as (name, x, y)) of one extract."""
    areas: list[tuple[str, bytes]] = []
    places: list[tuple[str, float, float]] = []
    wkb = osmium.geom.WKBFactory()
    processor = (osmium.FileProcessor(path)
                 .with_locations()
                 .with_areas(osmium.filter.TagFilter(("boundary", "protected_area"), ("boundary", "national_park"),
                                                      ("leisure", "nature_reserve")))
                 .with_filter(osmium.filter.EntityFilter(osmium.osm.NODE | osmium.osm.AREA))
                 .with_filter(osmium.filter.KeyFilter("place", "boundary", "leisure")))
    for obj in processor:
        tags = obj.tags
        if obj.is_node():
            if tags.get("place") in PLACE_TYPES and tags.get("name") and obj.location.valid():
                x, y = TO_METRIC.transform(obj.location.lon, obj.location.lat)
                places.append((tags.get("name"), x, y))
        elif obj.is_area() and is_protected_area(tags):
            try:
                geometry = shapely.from_wkb(wkb.create_multipolygon(obj))
            except Exception:  # broken multipolygon, e.g. cut at the extract's border
                continue
            metric = shapely.transform(geometry, lambda xy: np.column_stack(TO_METRIC.transform(xy[:, 0], xy[:, 1])))
            if metric.is_valid and not metric.is_empty:
                areas.append((tags.get("name"), shapely.to_wkb(metric)))
    return areas, places


def _extract_to_cache(path: str) -> str:
    """Child process: extracts one country into its own cache file (osmium keeps
    a lot of memory until the process ends - Norway after Denmark and Finland
    in one process ran out of memory)."""
    target = os.path.join(build_index.STORAGE, "places-" + os.path.basename(path).split("-latest")[0] + ".pkl")
    a, p = extract(path)
    with open(target + ".tmp", "wb") as f:
        pickle.dump({"version": CACHE_VERSION, "areas": a, "places": p}, f)
    os.replace(target + ".tmp", target)
    return target


def load(refresh: bool) -> tuple[list[str], list, list[str], np.ndarray]:
    import multiprocessing

    pbfs = [os.path.join(build_index.PBF_DIR, f) for f in sorted(os.listdir(build_index.PBF_DIR)) if f.endswith(".osm.pbf")]
    if not pbfs:
        raise SystemExit("No extracts in storage/water/pbf - run build_index.py first.")
    areas, places = [], []
    for path in pbfs:
        cache = os.path.join(build_index.STORAGE, "places-" + os.path.basename(path).split("-latest")[0] + ".pkl")
        if refresh or not os.path.exists(cache) or os.path.getmtime(cache) < os.path.getmtime(path):
            log(f"reading {os.path.basename(path)}")
            with multiprocessing.get_context("spawn").Pool(1) as pool:
                pool.apply(_extract_to_cache, (path,))
        with open(cache, "rb") as f:
            data = pickle.load(f)
        if data.get("version") != CACHE_VERSION:
            raise SystemExit(f"{cache} is from another version - run with --refresh.")
        areas.extend(data["areas"])
        places.extend(data["places"])
        log(f"{os.path.basename(path)}: {len(data['areas'])} protected areas, {len(data['places'])} places")
    return _prepare(areas, places)


def _prepare(areas, places):
    area_names = [name for name, _ in areas]
    area_shapes = [shapely.from_wkb(w) for _, w in areas]
    place_names = [name for name, _, _ in places]
    place_xy = np.array([(x, y) for _, x, y in places], dtype=np.float64).reshape(-1, 2)
    return area_names, area_shapes, place_names, place_xy


def lookup(points: list[dict], area_names, area_shapes, place_names, place_xy) -> dict[str, str]:
    if not points:
        return {}
    x, y = TO_METRIC.transform([p["lng"] for p in points], [p["lat"] for p in points])
    geoms = shapely.points(np.column_stack([x, y]))
    result: dict[str, str] = {}

    # Smallest protected area containing the point.
    if area_shapes:
        tree = shapely.STRtree(area_shapes)
        point_index, area_index = tree.query(geoms, predicate="within")
        areas_size = shapely.area(np.array(area_shapes, dtype=object))
        best: dict[int, int] = {}
        for p, a in zip(point_index, area_index):
            if p not in best or areas_size[a] < areas_size[best[p]]:
                best[p] = a
        for p, a in best.items():
            result[str(points[p]["id"])] = area_names[a]

    # Nearest named place for the rest.
    rest = [i for i in range(len(points)) if str(points[i]["id"]) not in result]
    if rest and len(place_xy):
        tree = shapely.STRtree(shapely.points(place_xy))
        point_index, place_index = tree.query_nearest(geoms[rest], max_distance=MAX_PLACE_METERS, all_matches=False)
        for p, q in zip(point_index, place_index):
            result.setdefault(str(points[rest[p]]["id"]), place_names[q])
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("input")
    parser.add_argument("output")
    parser.add_argument("--refresh", action="store_true", help="extract the protected areas and places again")
    args = parser.parse_args()
    with open(args.input, encoding="utf-8") as f:
        points = json.load(f)
    data = load(args.refresh)
    log(f"{len(data[0])} protected areas, {len(data[2])} places; looking up {len(points)} positions")
    result = lookup(points, *data)
    with open(args.output, "w", encoding="utf-8") as f:
        json.dump(result, f, ensure_ascii=False)
    log(f"{len(result)} of {len(points)} positions got a place")
    return 0


if __name__ == "__main__":
    sys.exit(main())
