# Water distance index

`storage/water/water-index.bin` holds the water features of Denmark, Norway,
Sweden, Finland and Germany as line segments sorted into a grid, so the distance from a
position to the nearest sea, river and large lake can be looked up in
milliseconds, on the servers too, without any external request.

- **Built** on dev by `tools/water/build_index.py` (Python, `tools/water/.venv`,
  see `tools/water/README.md`) from Geofabrik's OpenStreetMap extracts.
- **Published** to a server by `bin\publish-water.bat [test]` (into
  `storage/water/`, which no deploy script touches).
- **Read** by `Ytan\Service\Water\WaterIndex` / `WaterDistanceService` (PHP).

## Water

| kind  | OpenStreetMap                                   | measured to |
|-------|-------------------------------------------------|-------------|
| sea   | ways `natural=coastline` (fjords and bays included) | the coastline |
| river | ways `waterway=river` and `waterway=canal`      | the centre line (wide rivers: up to half their width too far) |
| lake  | areas `natural=water` of at least 10 km², not `water=pond/basin/wastewater/river/canal/stream/...` | the shore, every ring (an island in a lake measures to its own shore) |

Lines are simplified by 5 m (EPSG:3035) before they are written. Distances
farther than 100 km (`WaterDistanceService::MAX_METERS`) are `null`.

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
| 32 | 4 | uint32 kinds (3: sea, river, lake - in this order) |
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

## Where the distances go

- `poi_water_distance` (`sea_m`, `river_m`, `lake_m`, `index_version`): POIs of
  the types enabled on `/admin/settings` (`app_settings.water_distance_poitypes`,
  since migration 058 every type except 2, 6, 9, 14 and 16), computed when such a POI is created or moved
  (`PoiWaterDistanceService::afterSave()`); existing POIs and those from an
  older index: `php bin/poi-water-distance.php [TYPE ...] [--all]`.
- `shelter_import` (`sea_m`, `river_m`, `lake_m`, `water_checked_at`): computed
  while a list is staged; `php bin/import-shelters.php distances [--all]`.
