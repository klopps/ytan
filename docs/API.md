# YTAN REST API (`/api/v1`)

JSON in, JSON out. Successful responses are `{"data": ...}`; errors are
`{"error": {"message": "..."}}` with a non-2xx HTTP status.

## Auth

Stateless JWT bearer tokens (no server-side session). Include
`Authorization: Bearer <token>` on every request that needs a logged-in user.

| Method | Path | Auth | Body | Notes |
|---|---|---|---|---|
| POST | `/auth/login` | - | `{username, password}` | Returns `{token, expires_at, user}`. 429 (`auth.too_many_attempts`) after 10 failed attempts for the username or 50 from the IP within 15 minutes |
| POST | `/auth/forgot-password` | - | `{identifier}` | Always the same generic answer; 429 after 3 requests for the identifier or 10 from the IP per hour (counted whether or not the account exists) |
| PUT | `/auth/password` | required | `{current_password, new_password}` | Signs out every session and returns `{message, token, expires_at, user}` - the caller continues with the new token |
| POST | `/auth/logout-all` | required | - | "Sign out on all devices": every token issued so far becomes invalid; returns `{token, expires_at, user}` for the calling device |
| GET | `/auth/me` | required | - | Returns the user's current data from the database (same fields as the token claims, plus `pending_email*`), not the stale token payload |
| GET | `/auth/default-speed` | required | - | `{data: {default_speed_kmh: number\|null}}` |
| PUT | `/auth/default-speed` | required | `{default_speed_kmh}` | Usual paddling speed in km/h (0.5-30, one decimal) for the watch's initial ETA; empty/0/null clears it |

There is no `/auth/logout` endpoint: "logging out" on one device just means
the client discards the token it's holding. Every token carries the user's
`token_version` as claim `tv`; a password change/reset, an admin setting the
password and `/auth/logout-all` raise it, which makes every older token
answer 401 (tokens from before this claim existed count as `tv` 0).

## Pagination

`GET /pois`, `GET /routes` (scope-based, not `?tour_id=`), `GET /areas`,
`GET /tours` and `GET /users` all accept optional `limit`/`offset` query
params. Omit both to get the full unpaginated result set for the given scope
(the historical, still-default behavior every existing caller relies on).
When `limit` is given (clamped to 1-500), the response gains a `meta` object
alongside `data`: `{"total": <matching rows across all pages>, "limit": ...,
"offset": ...}` - `total` reflects the same scope/search/filters as `data`,
just without the limit applied, so a client can compute page count from it.

## POIs

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/pois?scope=public\|mine\|mine_public\|all` | scope-dependent | `mine`/`mine_public`/`all` require auth; `all` requires `is_admin`; see Pagination above. The rows carry `data_source` (code) but not the joined `source_name/url/license/license_url/attribution` of single-POI responses - those come once per source in a top-level `data_sources: {code: {name, url, license, license_url, attribution}}` (`{}` when none) |
| GET | `/pois/bounds` | - | `{max_lat, min_lat, max_lng, min_lng}` across all POIs |
| GET | `/pois/{id}` | - | |
| POST | `/pois` | required | body: `poitype_id, name, description, public, latitude, longitude, url`, plus `direction` (camps/campsites, 16 chars of `0`/`1`/`2`) or `characteristic`/`sector_characteristic`/`international_id` (lighthouses; `international_id` is stored without spaces and kept as is when a request leaves it out; a lighthouse in a response also carries `imported_at`, the time of the lighthouse list import that created or overwrote it - `null` for one drawn by hand - which an ordinary edit keeps) |
| PUT | `/pois/{id}` | required, owner or admin | same body as POST |
| DELETE | `/pois/{id}` | required, owner or admin | |

## Routes / Areas

Same CRUD shape as POIs, under `/routes` and `/areas`:
`GET ?scope=public|mine|mine_public`, `GET /{id}`, `POST`, `PUT /{id}`, `DELETE /{id}`.
`GET /routes?tour_id={id}` returns the routes belonging to a tour instead of
filtering by scope, and is never paginated (a tour's own route list is
inherently small).

Route body: `name, description, public, length, points (JSON-encoded array of {lat,lng}), color`.
Optionally, for a GPS-recorded route (set at creation only - `PUT` ignores
both, see `RouteRepository::update()`): `recorded_at` (datetime, UTC),
`recording_duration_seconds` (int, total elapsed time including pauses).
A response also carries the joined `recorded_by_username` alongside those
two fields when they're set. All three are only ever present in a response
for a caller who is `is_admin` or has the `route_view_recording` right -
every other caller (including the route's own owner and anonymous
requests) gets the route with these three keys removed entirely rather
than nulled, so the payload shape itself doesn't reveal whether a route
was recorded (see `RouteController::redactRecordingInfo()`).
`POST /routes/{id}/split` - owner or admin; `{points: [{lat, lng}, ...]
(the line as currently edited), index (an inner waypoint), name_suffixes:
[s1, s2], expected_updated_at?}`. The route keeps waypoints 0..index with
name + s1; a new route of the same owner gets index..end with name + s2 and
everything else copied (description, public, color, recorded_at/
recording_duration_seconds, copies of all photos) and follows the route in
every tour. Lengths are computed server-side. 201 with
`{routes: [{id, name, updated_at}, {id, name, updated_at}]}`
(`RouteSplitController`).

`GET /routes/{id}/gpx` - the route as a GPX 1.1 file (`application/gpx+xml`,
as an attachment): one `<trk>` with the route's name/description and one
`<trkpt lat lon>` per point (no times/elevations - a route has none).
Allowed if the route is visible to the caller (public, own, or admin) and
the site setting `gpx_export_public` is on (then for anyone, even
anonymous), or the caller is admin, or has `export_routes_public` and the
route is public, or has
`export_routes_own` and owns the route; 403 otherwise
(`GpxExportController`).
Area body: `name, description, public, points (JSON-encoded array of {lat,lng}), color, opacity, zindex`.

## GPX import

Both take `{"gpx": "<file content>"}` (max. 5 MB) and require a logged-in
user (`GpxImportController`); nothing is stored between the two calls.

`POST /gpx/preview` - `{name, desc, time, tracks: [{index, kind ("trk"|"rte"),
name, desc, time, point_count, route_point_count, length}]}` - `route_point_count`
is what the track keeps as a separate route (lower than `point_count` once
simplified) (times UTC ISO 8601, length in
meters), plus `waypoints: [{index, name}]` (the POI names they would get).
With a single track, its missing name/desc/time come from the file.

`POST /gpx/import` - additionally `tracks` (indices), `mode` (`"merge"`: one
route from the chosen tracks in file order, `"separate"`: one route each)
and `create_tour` (separate only, needs `tour_create`/admin) and
`import_waypoints` (every `<wpt>` with a name/desc becomes a private POI of
type 0, name "IMPORT: " + 20 characters of the description if it has none;
with it, `tracks` may be empty). Creates
everything private (separate routes in alternating colors from
`GpxImportService::ROUTE_COLORS`), points simplified to at most 500, `recorded_at`/
`recording_duration_seconds` from point times if present. 201 with
`{routes: [{id, name}], tour: {id, name}|null, pois: <count>}`.

## Tours

`GET /tours?scope=public|mine|mine_public`, `GET /tours/{id}`. `GET /tours`
also takes `search` (matches name/description/creator username) and
`min_length`/`max_length` (meters) filters, on top of the shared
`limit`/`offset` from Pagination above.

`GET /tours/{id}/gpx` - the tour as one GPX 1.1 file (`application/gpx+xml`,
as an attachment): tour name/description in `<metadata>`, then one `<trk>`
per route in tour order (`GET /routes/{id}/gpx`'s track shape), its
creator's private routes included. Same rule as the route export, applied
to the tour: visible (public tour, or owner/admin/tour_manage) and (site
setting `gpx_export_public`, or admin, or public tour + `export_routes_public`,
or own tour + `export_routes_own`); 403 otherwise (`GpxExportController`).

`GET /tours/{id}/document?poi_radius=200` - generates and returns a PDF
(`application/pdf`, as an attachment): a tour overview section (name, total
length, a table of each route's name/length, description, a map of every
route combined), then one section per route (name, length, description, a
map of just that route), each followed by a sub-entry for every POI within
`poi_radius` meters of that route and every Area that route crosses (or
starts/ends inside) - but only if the POI/Area has a description and/or at
least one image. Same visibility rule as `GET /tours/{id}/images/...`
(public tour, or owner/admin/tour_manage). `poi_radius` defaults to 200
(must be a positive number). Maps always render as Terrain (Hybrid/
Satellite are 403-rejected by the Static Maps API for EEA-region accounts).
Map images are omitted (not an error) if `MAPS_STATIC_API_KEY` isn't
configured or a Static Maps request fails.

## Users (admin only)

`GET /users` - optional exact-match filters `is_admin`/`tour_create`/
`tour_publish`/`tour_manage`/`tour_copy`/`route_view_recording`/
`export_routes_own`/`export_routes_public` (each `0`
or `1`), plus the shared `limit`/`offset` from Pagination above.

## Lighthouse import (admin only)

Staged entries of the official lighthouse lists (`lighthouse_import` table, filled by `bin/import-lighthouse-list.php`), used by the admin tool `/admin/lighthouse-import`.

| Method | Path | Auth | Body / query | Notes |
|---|---|---|---|---|
| GET | `/admin/lighthouse-import` | admin | filters `dk_no`, `international_id` (spaces ignored), `region`, `subregion`, `name`, `location` (substring), `status` (`open`/`imported`), `source`, `polygon` (`lat,lng;lat,lng;...`, 3 to 100 vertices, only entries inside it; 422 when malformed; no polygons across the 180th meridian); `limit`, `offset` | `meta`: `total` (all matches), `open` (matches not imported yet - what "select all" selects), `limit`, `offset`, `regions`, `subregions` (for the drop-downs) |
| GET | `/admin/lighthouse-import/ids` | admin | same filters | ids of ALL matches ("select all" across pages) |
| GET | `/admin/lighthouse-import/export` | admin | - | transfer file of every staged entry: `{format: "ytan-lighthouse-list", version: 1, exported_at, entries: [{source, source_key, ...}]}` (no `id`/`poi_id`/`imported_at`) |
| POST | `/admin/lighthouse-import/file` | admin | body = the transfer file | adds new entries, refreshes existing ones (same `source` + `source_key`), keeps their imported state; `data`: `inserted`, `updated`; 422 `lighthouse_import.invalid_file` for anything that is not such a file |
| POST | `/admin/lighthouse-import/nga` | admin | `volume` (110-116) | loads one volume of the NGA List of Lights from NGA's Maritime Safety Information web service (`/api/publications/ngalol/lights-buoys`, JSON, positions to the second) into the staging table as source `ngaapi` (`source_key` `<volume>|<list no>|<international no>`, `source_ref` `pub<volume>/<list no>`, region/sub-region from the list's headings); racon rows and rows without position are skipped. At most one request per 10 seconds (429 `lighthouse_import.nga_too_soon`), 502 `lighthouse_import.nga_unavailable` when the service doesn't deliver. `data`: `fetched` (rows), `lights`, `skipped`, `inserted`, `updated`; loading a volume again refreshes instead of duplicating |
| POST | `/admin/lighthouse-import/osm` | admin | `south`, `west`, `north`, `east` (decimal degrees), `label` (1-100 characters, stored as the entries' region) | loads the lights (`seamark:type` light_major/light_minor/light_vessel/light_float) of the area from OpenStreetMap through the Overpass API into the staging table as source `osm` (`source_key`/`source_ref` = `node/ID`, `international_id` from `seamark:light:reference`/`seamark:reference`/`ref`); area at most 10 x 10 degrees, at most one request per 30 seconds (429 `lighthouse_import.osm_too_soon`); 502 `lighthouse_import.osm_unavailable` when Overpass doesn't deliver. `data`: `fetched`, `skipped` (not navigation lights), `inserted`, `updated`. Entries are upserted by `node/ID`, so loading an area again refreshes instead of duplicating |
| POST | `/admin/lighthouse-import` | admin | `ids` (non-empty), `public` (bool), `decisions` (object staging id => `add`/`overwrite`/`skip`), `on_conflict` (fallback for entries without a decision: `ask` default / `add` / `overwrite` / `skip`), `default_ranges` (`{W, R, G}` nautical miles, default 3/2/2: range for sectors the list gives none for) | creates lighthouse POIs for the selected entries not imported yet. `lighthouse.international_id` is not unique, but importing a number that already exists (or occurs twice in the selection) must be decided per entry: while an entry like that has no decision and `on_conflict` is `ask`, nothing is imported and `data.conflicts` lists them (`id`, `international_id`, `name`, `existing_poi_id` (the oldest, null if only repeated within the selection), `existing_name`, `existing_count`); repeat with `decisions`: `add` creates another lighthouse, `overwrite` replaces the content of the oldest existing one (owner/visibility/photos kept), `skip` leaves the entry open. `data`: `imported`, `overwritten`, `skipped`, `conflicts`. Sectors without a range are staged as `G ?M 10-20` and get the range of their colour from `default_ranges` on import; the POI's description then ends with "Es sind keine Leuchtweiten bekannt. Es wurden 3, 2 und 2 Seemeilen für Weiß, Rot und Grün angenommen." (the values used) |

## Lighthouse duplicates (admin only)

Finds lighthouse POIs that lie close together and merges the ones the admin says describe the same lighthouse (admin tool `/admin/lighthouse-duplicates`).

| Method | Path | Auth | Body / query | Notes |
|---|---|---|---|---|
| GET | `/admin/lighthouse-duplicates` | admin | `distance` (metres, > 0 and <= 5000, default 50), `hide_different_ids` (1/true: leave out groups with an imported lighthouse in which every lighthouse has an international ID and not all the same - a group with at least one NULL/empty ID is always listed), `only_with_manual` (1/true: only groups in which at least one lighthouse was NOT imported; combines with the other filter), `limit`/`offset` (by group) | `data`: groups `{key, max_distance, pois: [...]}`, sorted by `max_distance` ascending (then by the first POI's id), applied before `limit`/`offset` - a chain of close lighthouses is one group; `pois` hand-drawn ones first (then by id), each with `distance_m` from the first, `manual` (false if a list-import entry points at it), `username`, `image_count`. `meta`: `total`, `limit`, `offset`, `distance`, `hide_different_ids`, `only_with_manual` |
| POST | `/admin/lighthouse-duplicates/merge` | admin | `merges`: list of `{keep_id, ids, fields}` (`ids` = all records of the merge, `keep_id` among them, at least two, all lighthouses; `fields`: field => id of the record to take it from, fields `name`, `description`, `position` (latitude+longitude), `characteristic`, `sector_characteristic`, `international_id`, `public`; a field left out stays with the kept record) | per merge: the kept POI is updated, the photos of the others are copied to it (up to 5 in all), `lighthouse_import` entries that pointed at them now point at it, then the others are deleted. `data`: `merged`, `deleted`, `images_moved`, `images_dropped`. 422 for an invalid merge (nothing of it is changed) |

## Garmin watch

For the sideloaded Connect IQ data field in `watch/`. All but the last
endpoint require a logged-in user (JWT).

- `GET /watch` → `{"data": {"has_token", "has_settings_file", "route": {"id", "name"} | null, "unit", "colors", "default_colors"}}`
- `POST /watch/token` → `201 {"data": {"token"}}` - a new 32-hex-char watch
  key, shown only in this response (stored as SHA-256 hash). Replaces and
  invalidates any previous key.
- `GET /watch/settings-file` - the Connect IQ settings file (`.SET`, binary,
  `Content-Disposition: attachment; filename="ytan-ReplaceByWatchName.SET"`)
  holding the user's CURRENT watch key, for the watch's `GARMIN\Apps\SETTINGS`
  folder (the user renames it to the name the watch gave its own file).
  Available until a new key is created or the watch is unpaired; 404 if there is
  no key or it predates the encrypted copy (`has_settings_file` false). The key is
  kept encrypted (`watch_link.token_enc`, AES-256-GCM, `WatchKeyVault`, key
  derived from `JWT_SECRET`) next to its hash.
- `DELETE /watch/token` - unpair; the device endpoint then answers 401.
- `PUT /watch/route` `{"route_id", "unit": "metric"|"nautical"}` - the route
  the watch shows. Must be the caller's own, public, or the caller is admin.
- `DELETE /watch/route`
- `PUT /watch/colors` `{"colors": {"bearing"|"distance"|"text"|"north": {"dark": "#rrggbb", "light": "#rrggbb"}}}` -
  the data field's text colors on a dark and on a light watch background
  (bearing = direction value and waypoint marker, text = all other text).
  Anything left out keeps its default; returns the status like `GET /watch`.
  Unlike changing the route this does not change `"v"`.
- `DELETE /watch/colors` - back to the default colors.
- `GET /watch/device/route` with header `X-Watch-Token: <key>` (no JWT) →
  compact payload **without** the `data` wrapper:
  `{"v": "<version>", "n": "<name>", "u": "m"|"n", "p": [lat0, lng0, lat1, lng1, ...], "c": [...]}`
  with coordinates as integers in 1e-5 degrees, simplified to at most 250
  points. `"v"` changes when the route or the selection changes; an empty
  `"p"` (and `"v": "none"`) means no route is selected or the selected route
  is no longer visible to the user. `"c"` is the color set as eight 0xRRGGBB
  integers: bearing, distance, text, north, each dark then light background.
  `"s"` (only if the user set one) is the default paddling speed in m/s for
  the initial ETA.
  Unknown key → 401.
- `GET /watch/payload` (JWT, no key) → the very same payload for the signed-in
  user, also without the `data` wrapper - same `"v"`, `"c"`, `"s"` as the
  device endpoint serves that user's key (a user who never sent or configured
  anything gets `"v": "none"` and the default colors). The YTAN Android app
  fetches it and hands it to the watch over the Connect IQ Mobile SDK
  (`GarminWatchPlugin.send()`), so a data field build needs no key for that
  path.

## WSI (wind shelter indicator)

`GET /wsi/{code}` where `code` is a 16-character string of `0`/`1`/`2` (one
per 22.5° compass sector). Returns `image/svg+xml`, rendered server-side and
cached to disk. No auth required (same as POIs).

## Weather and reverse geocoding

`GET /api/v1/weather?lat=..&lng=..` (forecast for the weather timeline) and
`GET /api/v1/geocode/reverse?lat=..&lng=..` (`{data: {place_name}}`), no
auth required. `lat` must be -90..90, `lng` -180..180 (else 422). Both call
external services (Open-Meteo/SMHI, Nominatim), so together they are limited
to 120 requests per IP in 10 minutes (429, `auth.too_many_attempts`).

## Rain radar

Produced by the Python job in `tools/radar/` (see its README.md), served
read-only from `storage/radar/`, no auth required.

`GET /api/v1/radar/manifest` → `{data: {...}}`, `Cache-Control: max-age=60`;
404 `radar.not_available` until the job has run once.

```json
{
  "version": 1,
  "generated": "2026-10-17T04:49:40Z",
  "latest_observation": "2026-10-17T04:40:00Z",
  "step_minutes": 5,
  "source": {"name": "EUMETNET OPERA", "url": "...", "license": "CC BY 4.0", "license_url": "...", "modified": true},
  "zoom": {"min": 5, "max": 8},
  "bounds": {"south": 47.2, "west": 5.8, "north": 69.1, "east": 24.2},
  "regions": [{"name": "de", "south": 47.2, "west": 5.8, "north": 55.1, "east": 15.1}, "..."],
  "regions_hash": "3f1c0a9b2d7e",
  "forecast_minutes": 120,
  "legend": [{"mm_h": 0.1, "color": "#9fd4ff"}, "..."],
  "motion": "optical-flow",
  "frames": [
    {"id": "o202610170440", "time": "2026-10-17T04:40:00Z", "type": "observation"},
    {"id": "f202610170440-030", "time": "2026-10-17T05:10:00Z", "type": "forecast",
     "base_time": "2026-10-17T04:40:00Z", "lead_minutes": 30}
  ]
}
```

`motion` is `null` when there was too little rain to estimate a movement (the
forecast frames then repeat the latest observation), or with
`forecast_minutes` 0. `regions` are the rectangles the job computes (admin
settings, below); outside them there is no data, `bounds` encloses them.

**Settings** (admin only, page /admin/radar): `GET /api/v1/settings/radar` and
`PUT /api/v1/settings/radar` with
`{history_minutes, forecast_minutes, fetch_interval_minutes, workers, max_mosaic_pixels, presets: ["de", ...], custom: [{name, south, west, north, east}]}`
→ the same plus `status` (the job's `storage/radar/status.json`, null before
its first run: `last_run {start, end, outcome: processed|no_new_data|skipped_interval|no_composite|failed, error?}`,
`last_processing {start, end, regions, download {start, end, bytes, files}, processing {start, end, workers, bytes, frames}}`,
`storage_bytes`, `storage_measured_at`), `preset_regions` (code → box),
`mosaic_pixels`, `max_mosaic_pixels_min` and `max_mosaic_pixels_limit`. All fields are required:
`history_minutes` 5-60 and `forecast_minutes` 0-120 in steps of 5,
`fetch_interval_minutes` 5, 10 or 15 (the cron job still runs every 5
minutes, the job skips runs in between), `workers` 1-12; presets are the
codes of `RadarConfig::PRESETS` - every European country by its lower-case
ISO 3166 code (de, dk, se, ..., Kosovo xk; Russia only up to 45 E); at
most 10 own rectangles inside the OPERA coverage (32-72 N, 30 W-45 E); the
rectangle enclosing all regions may have at most `max_mosaic_pixels` pixels at
zoom 8 (the job's memory, ~8 bytes per pixel; the setting itself 50-500 M,
default 120 M, 422 `radar.settings_invalid_max_mosaic` outside). `POST /api/v1/settings/radar/pause` (admin only) pauses the job: `{minutes: 15|30|60}`
(from now, ends on its own) or `{forever: true}`; `DELETE /api/v1/settings/radar/pause`
resumes it. Both answer like the GET (`paused`, `paused_until` UTC ISO or null,
`paused_forever`) and update `config.json`; while paused the job fetches
nothing (outcome `paused` in `status.json`). 422 `radar.pause_invalid` for
other durations.

`POST /api/v1/settings/radar/run` (admin only) starts the job right away in
the background (`radar_job.py --force`, the fetch interval doesn't apply):
202 `{started: true}`; 409 `radar.job_paused` while paused, `radar.job_running` while a run holds the job's
lock (`storage/radar/.lock`, the same check gives `running` in the GET
above); 503 `radar.job_not_installed` (no `tools/radar/.venv`) or
`radar.job_cannot_start` (`exec` disabled). The job's output goes to
`storage/logs/radar.log` like the cron runs'.

Errors (422): `radar.settings_invalid_history`, `radar.settings_invalid_forecast`,
`radar.settings_invalid_interval`, `radar.settings_invalid_workers`,
`radar.settings_unknown_region`, `radar.settings_too_many_regions`,
`radar.settings_invalid_region`, `radar.settings_no_region`,
`radar.settings_too_large`. Saving also writes `storage/radar/config.json`,
which the job reads on its next run; changed regions make it recompute every
frame.

`GET /api/v1/radar/tiles/{frame}/{z}/{x}/{y}.png` - Google/OSM tile scheme,
256 px, palette PNG, `Cache-Control: max-age=86400, immutable` (a frame never
changes). Below `zoom.min` and where there is no rain: a transparent tile
(200). Above `zoom.max` the matching part of the `zoom.max` tile is enlarged
(nearest neighbour) and cached inside the frame's directory. Errors: 422
`radar.invalid_frame` (id not `o` + 12 digits or `f` + 12 digits + `-NNN`),
422 `radar.invalid_tile` (z > 20 or x/y outside 0..2^z-1), 404
`radar.frame_not_found` (expired: the job deletes a frame ~10 minutes after it
left the manifest).

## Wind

Produced by the Python job in `tools/wind/` (see its README.md) from the DWD's
ICON-D2/ICON-EU 10 m wind, served read-only from `storage/wind/`, no auth
required. The regions are the rain radar's.

`GET /api/v1/wind/manifest` → `{data: {...}}`, `Cache-Control: max-age=60`;
`data` is `null` before the job's first run and while the layer is switched
off (the client then says "no wind data").

```json
{
  "run": "202610182145",
  "generated": "2026-10-18T21:45Z",
  "sources": {"eu": "2026101818", "d2": "2026101818"},
  "hours": ["2026-10-18T20:00Z", "2026-10-18T21:00Z", "..."],
  "scale": {"uv": [-40, 40], "gust": [0, 60], "nodata": 255},
  "channels": 2,
  "levels": {
    "L0": {"res": 0.25, "south": 35.25, "west": -9.5, "rows": 137, "cols": 136},
    "L1": {"res": 0.0625, "size": 129, "blocks": ["23_18", "..."]},
    "L2": {"res": 0.02, "size": 129, "blocks": ["73_56", "..."]}
  }
}
```

`sources` are the model runs (YYYYMMDDHH, UTC; `d2` null without the fine
grid), `hours` the valid times (the index is the `{hour}` below; ICON-EU
beyond +78 h is every 3 hours, the hours in between are left out), `channels`
2 (u, v) or 3 (+ gust). All levels share one global grid with origin
-90 / -180: block `bx_by` of a level covers longitude `-180 + bx*128*res`
(+128*res) and latitude `-90 + by*128*res`. `blocks` lists the blocks that
exist (the others carry no data).

`GET /api/v1/wind/{run}/L0/{hour}` - the overview of an hour: `rows` x `cols`
points from (`south`, `west`) at 0.25 degrees.
`GET /api/v1/wind/{run}/{L1|L2}/{hour}/{bx}_{by}` - one block, 129 x 129
points (the last row/column is the neighbour's first one, so bilinear
interpolation never needs two blocks). Both return the job's gzipped bytes
unchanged (`Content-Type: application/octet-stream`, `Content-Encoding: gzip`,
`Cache-Control: max-age=86400, immutable`); after decompression, per point
(rows south to north, columns west to east) `channels` bytes: u (east), v
(north) and optionally the gust, each 0..254 on the `scale` range (u/v
-40..40 m/s, gust 0..60), 255 = no data. Errors: 422 `wind.invalid_block`
(malformed run/level/hour/block), 404 `wind.block_not_found` (the run was
replaced and expired - the job keeps a replaced run ~30 minutes -, or the
block has no data).

**Settings** (admin only, card "Wind" on /admin/radar): `GET /api/v1/settings/wind`
and `PUT /api/v1/settings/wind` with
`{enabled, horizon_hours: 24|48|72|120, run_interval_hours: 3|6, gusts, fine}`
(all required, booleans for the flags) → the same plus `status` (the job's
`storage/wind/status.json`, null before its first run: `last_run {start, end,
outcome: processed|unchanged|no_data|disabled|failed, trigger, error?}`,
`last_processing {eu_run, d2_run, hours, seconds, bytes_downloaded,
files_downloaded, bytes_written, regions, start, end}`, `storage_bytes`),
`running`, `horizons`, `intervals`. Saving writes the `wind` object of
`storage/radar/config.json`; `enabled: false` also deletes the manifest at
once. Errors (422): `wind.settings_invalid_horizon`,
`wind.settings_invalid_interval`, `wind.settings_invalid_flag`.
`POST /api/v1/settings/wind/run` (admin only) starts the job in the background
(`wind_job.py --force`): 202 `{started: true}`; 409 `wind.job_disabled` while
switched off, `wind.job_running` while a run holds `storage/wind/.lock`; 503
`wind.job_not_installed` / `wind.job_cannot_start`. Output goes to
`storage/logs/wind.log`.

## CSP reports

`POST /api/v1/csp-report` - target of the HTML pages' Content-Security-Policy
`report-uri`; browsers send violation reports here. The body is written,
shortened to 2,000 characters, to the PHP error log; at most 30 reports per IP
in 10 minutes, always `204`.

## Health

`GET /api/v1/health` → `{"status": "ok"}`, no DB access - useful for
uptime/readiness checks.
