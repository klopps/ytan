# YTAN REST API (`/api/v1`)

JSON in, JSON out. Successful responses are `{"data": ...}`; errors are
`{"error": {"message": "..."}}` with a non-2xx HTTP status.

## Auth

Stateless JWT bearer tokens (no server-side session). Include
`Authorization: Bearer <token>` on every request that needs a logged-in user.

| Method | Path | Auth | Body | Notes |
|---|---|---|---|---|
| POST | `/auth/login` | - | `{username, password}` | Returns `{token, expires_at, user}` |
| GET | `/auth/me` | required | - | Returns the decoded token payload |
| GET | `/auth/default-speed` | required | - | `{data: {default_speed_kmh: number\|null}}` |
| PUT | `/auth/default-speed` | required | `{default_speed_kmh}` | Usual paddling speed in km/h (0.5-30, one decimal) for the watch's initial ETA; empty/0/null clears it |

There is no `/auth/logout` endpoint: since tokens are stateless, "logging
out" just means the client discards the token it's holding.

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
| GET | `/pois?scope=public\|mine\|mine_public\|all` | scope-dependent | `mine`/`mine_public`/`all` require auth; `all` requires `is_admin`; see Pagination above |
| GET | `/pois/bounds` | - | `{max_lat, min_lat, max_lng, min_lng}` across all POIs |
| GET | `/pois/{id}` | - | |
| POST | `/pois` | required | body: `poitype_id, name, description, public, latitude, longitude, url`, plus `direction` (camps/campsites, 16 chars of `0`/`1`/`2`) or `characteristic`/`sector_characteristic` (lighthouses) |
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

## Garmin watch

For the sideloaded Connect IQ data field in `watch/`. All but the last
endpoint require a logged-in user (JWT).

- `GET /watch` → `{"data": {"has_token", "route": {"id", "name"} | null, "unit", "colors", "default_colors"}}`
- `POST /watch/token` → `201 {"data": {"token"}}` - a new 32-hex-char watch
  key, shown only in this response (stored as SHA-256 hash). Replaces and
  invalidates any previous key.
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

## WSI (wind shelter indicator)

`GET /wsi/{code}` where `code` is a 16-character string of `0`/`1`/`2` (one
per 22.5° compass sector). Returns `image/svg+xml`, rendered server-side and
cached to disk. No auth required (same as POIs).

## Health

`GET /api/v1/health` → `{"status": "ok"}`, no DB access - useful for
uptime/readiness checks.
