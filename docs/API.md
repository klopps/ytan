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

## POIs

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/pois?scope=public\|mine\|mine_public\|all` | scope-dependent | `mine`/`mine_public`/`all` require auth; `all` requires `is_admin` |
| GET | `/pois/bounds` | - | `{max_lat, min_lat, max_lng, min_lng}` across all POIs |
| GET | `/pois/{id}` | - | |
| POST | `/pois` | required | body: `poitype_id, name, description, public, latitude, longitude, url`, plus `direction` (camps/campsites, 16 chars of `0`/`1`/`2`) or `characteristic`/`sector_characteristic` (lighthouses) |
| PUT | `/pois/{id}` | required, owner or admin | same body as POST |
| DELETE | `/pois/{id}` | required, owner or admin | |

## Routes / Areas

Same CRUD shape as POIs, under `/routes` and `/areas`:
`GET ?scope=public|mine|mine_public`, `GET /{id}`, `POST`, `PUT /{id}`, `DELETE /{id}`.
`GET /routes?tour_id={id}` returns the routes belonging to a tour instead of
filtering by scope.

Route body: `name, description, public, length, points (JSON-encoded array of {lat,lng}), color`.
Area body: `name, description, public, points (JSON-encoded array of {lat,lng}), color, opacity, zindex`.

## Tours

Read-only (no UI to create/edit tours yet, matching PESR):
`GET /tours?scope=public|mine|mine_public`, `GET /tours/{id}`.

## WSI (wind shelter indicator)

`GET /wsi/{code}` where `code` is a 16-character string of `0`/`1`/`2` (one
per 22.5° compass sector). Returns `image/svg+xml`, rendered server-side and
cached to disk. No auth required (same as POIs).

## Health

`GET /api/v1/health` → `{"status": "ok"}`, no DB access - useful for
uptime/readiness checks.
