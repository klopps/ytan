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
Area body: `name, description, public, points (JSON-encoded array of {lat,lng}), color, opacity, zindex`.

## Tours

`GET /tours?scope=public|mine|mine_public`, `GET /tours/{id}`. `GET /tours`
also takes `search` (matches name/description/creator username) and
`min_length`/`max_length` (meters) filters, on top of the shared
`limit`/`offset` from Pagination above.

`GET /tours/{id}/document?maptype=hybrid|terrain|satellite` - generates and
returns a PDF (`application/pdf`, as an attachment) containing the tour's
own description/photos/map, one section per POI within 200m of any of the
tour's routes, and one section per Area any route segment crosses (or
starts/ends inside). Same visibility rule as `GET /tours/{id}/images/...`
(public tour, or owner/admin/tour_manage). `maptype` defaults to `hybrid`;
map images are omitted (not an error) if `MAPS_STATIC_API_KEY` isn't
configured or a Static Maps request fails.

## Users (admin only)

`GET /users` - optional exact-match filters `is_admin`/`tour_create`/
`tour_publish`/`tour_manage`/`tour_copy`/`route_view_recording` (each `0`
or `1`), plus the shared `limit`/`offset` from Pagination above.

## WSI (wind shelter indicator)

`GET /wsi/{code}` where `code` is a 16-character string of `0`/`1`/`2` (one
per 22.5° compass sector). Returns `image/svg+xml`, rendered server-side and
cached to disk. No auth required (same as POIs).

## Health

`GET /api/v1/health` → `{"status": "ok"}`, no DB access - useful for
uptime/readiness checks.
