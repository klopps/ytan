# Water distance index

`storage/water/water-index.bin` holds the water features of the European
countries (`COUNTRIES` in `tools/water/build_index.py`, those of the campsite
import) as line segments sorted into a grid, so the distance from a
position to the nearest sea, river and large lake can be looked up in
milliseconds, on the servers too, without any external request.

- **Built** on dev by `tools/water/build_index.py` (Python, `tools/water/.venv`,
  see `tools/water/README.md`) from Geofabrik's OpenStreetMap extracts.
- **Published** to a server by `bin\publish-water.bat [test]` (into
  `storage/water/`, which no deploy script touches).
- **Read** by `Ytan\Service\Water\WaterIndex` / `WaterDistanceService` (PHP).
- **Age** (admin dashboard tile "Gewässerindex", `GET /admin/dashboard-stats` -> `water_index`,
  `Ytan\Service\Water\WaterIndexAge`): the index's `version` is the UTC time its build started
  (`YYYYMMDDHHMM`; a run that only adds the covered area sets a new one too). Coastlines, rivers and big
  lakes hardly change, so a rebuild once a year is enough: the tile is green up to 12 months, yellow up to
  13, red beyond; grey "kein Index" without one. The dashboard's "Probleme" card says so too
  (`AdminHealthService::checkWaterIndex()`: yellow = warning `waterindex.aging`, red = error
  `waterindex.outdated`, no index = nothing). A rebuild earlier: a new country, a changed format, a
  mistake in the source data.

## Water

| kind  | OpenStreetMap                                   | measured to |
|-------|-------------------------------------------------|-------------|
| sea   | ways `natural=coastline` (fjords and bays included) | the coastline |
| river | ways `waterway=river`                         | the centre line (wide rivers: up to half their width too far); segments keep the way's point order = flow direction (OpenStreetMap draws rivers downstream) |
| canal | ways `waterway=canal` (own kind since 2026-11-14, before: stored as river) | the centre line; no current, its direction means nothing |
| lake  | areas `natural=water` of at least 10 km², not `water=pond/basin/wastewater/river/canal/stream/...` | the shore, every ring (an island in a lake measures to its own shore) |

Lines are simplified by 5 m (EPSG:3035) before they are written. Distances
farther than 100 km (`WaterDistanceService::MAX_METERS`) are `null`.
`distances()`'s `river` is the nearer of river and canal (as before the
split). `flowAt(lat, lng, maxMeters)` (Routencheck: current) gives the river
or canal a position lies on - the nearest centre line within `maxMeters`,
unless coast or lake shore is nearer - with the direction of that segment
(downstream for a river); with an index without the canal kind it always
answers null, since canals would be taken for rivers there.

**Side of the shore**: the autorouting (`Autorouter`, `ShoreField`,
`WaterDistanceService::segmentsInBox()`) needs to know land from water, and the
index has no areas. Every `sea` and `lake` segment keeps its direction of
travel with the **water on the right** (OSM's coastline: land on the left;
since 2026-11-23 `build_index.py` orients lake rings the same way and writes
`"oriented": true` into `water-index.json`). Segments of an index without that
flag only serve the sea; lakes are then not used. Rivers/canals count as water
within 40 m / 15 m of their centre line.

**Covered area**: `water-index.json` carries the countries' borders (Geofabrik
`.poly`, outer rings as `[[lat, lng], ...]`) as `coverage`. Outside them
`WaterDistanceService::covers()` is false and there are no distances at all,
and `PoiWaterDistanceService` stores none (an existing row is deleted) - the
ring search would otherwise measure to the nearest water that happens to be in
the index (found 2026-10-23: a harbour in Germany had 21.5 km to the nearest
Danish coast, before DE was added). A meta file without `coverage` counts as
covering everything.

## File format (version 1)

Little-endian throughout.

| offset | size | content |
|--------|------|---------|
| 0  | 8 | magic `YTANWATR` |
| 8  | 4 | uint32 format version (1) |
| 12 | 4 | uint32 cell size in microdegrees (50000 = 0.05°, latitude and longitude alike) |
| 16 | 4 | int32 first cell row = floor(lat µ° / cell) |
| 20 | 4 | int32 first cell column = floor(lng µ° / cell) |
| 24 | 4 | uint32 rows |
| 28 | 4 | uint32 columns |
| 32 | 4 | uint32 kinds (4: sea, river, lake, canal - in this order; files built before 2026-11-14 have 3, canals stored as river) |
| 36 | 4 | uint32 segments stored |
| 40 | 24 | reserved (zero) |
| 64 | kinds × rows × columns × 8 | directory: per cell uint32 start, uint32 count (in segments); entry = (kind × rows + row) × columns + column |
| ... | 16 per segment | segments: int32 lat1, lng1, lat2, lng2 in microdegrees |

A segment is stored in every cell its bounding box touches. Segments are
sorted by directory entry, so the cells of one row lie next to each other in
the file - the PHP reader fetches a run of cells with one read.

The lookup searches the cells around a position ring by ring; ring N cannot
hold anything nearer than N-1 whole cells (narrowest cell width of the ring),
so it stops as soon as that bound exceeds the best distance found. The nearest
point of the best segment is then measured with the haversine formula.

`tests/testdata/water-index-sample.bin` is written by the Python writer
(`tools/water/make_test_fixture.py`) and read by PHP's
`WaterDistanceServiceTest`: change the format on both sides and regenerate it.

## Corrections (admin page /admin/water)

`/admin/water` draws the index's lines over a satellite map (sea/lake with
ticks on the water side, rivers/canals with an arrow in drawing direction) and
lets an admin correct it. Segments have no stable id - a rebuild simplifies
them anew - so a correction is a geometric operation that survives a rebuild:

- `delete`: polygon + kinds; segments of those kinds whose midpoint lies inside disappear
- `flip`: polygon + kinds; such segments are reversed (sea/lake: water side, river: flow direction)
- `add`: a line of one kind; sea/lake: water on the right of the drawing direction
- `replace`: a line of the index changed or deleted (info window of a clicked line:
  "Bearbeiten"/"Löschen"). The page follows the clicked segment's chain through the
  loaded segments of the same kind (`wxChain()`: drawing direction, stops where not
  exactly one segment goes on and one arrives - a junction - or at the edge of the
  loaded area) and sends it as `original`; `points` is the edited line, empty =
  deleted. Applied: every segment of that kind with BOTH ends within
  `WaterCorrections::REPLACE_BUFFER_M` (10 m) of the original is removed, then the
  new line added - both ends, so a line joining at a junction stays, and a rebuild
  that moves the segments a few metres still matches

"Bearbeiten"/"Löschen" on an OWN line (a segment from a correction - the overlay
gives each segment the id of the correction whose line it is) change that
correction instead (`PUT /admin/water/corrections/{id}`); deleting an own line
deletes its correction - an added line disappears, a changed line of the index
gets its original back (to leave the place empty, delete the original afterwards). Only a line of
the index gets a `replace` correction; the index file itself is never changed.

They apply in id order (a later delete also removes an earlier added line).
Stored in the table `water_correction` (migration 088); after every change
`WaterCorrectionRepository::writeFile()` writes them to
`storage/water/water-corrections.json` (no corrections = no file), which
`WaterDistanceService` applies to every read of the index (`segments()` ->
`WaterCorrections::apply()`), so distances, `flowAt()`, `nearestSea()`,
`openWaterAlong()` and the autorouting's `segmentsInBox()` all see them, in
CLIs too, without a database. The stored POI distances within 0.1 degrees of a
changed correction are recomputed at once (at most 2,000; more:
`php bin/poi-water-distance.php --all`). Added lines outside the index's
`coverage` have no effect (positions there get no distances at all). Moving
corrections between systems: "Exportieren" / "Datei einlesen" on the page.
The file lives in `storage/water`, which no deploy touches.

## Where the distances go

- `poi_water_distance` (`sea_m`, `river_m`, `lake_m`, `index_version`): POIs of
  the types enabled on `/admin/settings` (`app_settings.water_distance_poitypes`,
  since migration 058 every type except 2, 6, 9, 14 and 16), computed when such a POI is created or moved
  (`PoiWaterDistanceService::afterSave()`); existing POIs and those from an
  older index: `php bin/poi-water-distance.php [TYPE ...] [--all]`.
- `shelter_import` (`sea_m`, `river_m`, `lake_m`, `water_checked_at`): computed
  while a list is staged; `php bin/import-shelters.php distances [--all]`.
