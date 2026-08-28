# YTAN

Paddle. Eat. Sleep. Repeat.

YTAN is a web application for planning kayak touring trips (points of
interest, routes, tours and areas on a map), rebuilt from the ground up as
a fork of PESR. It's designed so a future Android/iOS app can be built
against the same backend.

## Architecture

- **Backend**: PHP 8.1+, [Slim 4](https://www.slimframework.com/) micro-framework,
  PDO/MySQL, JWT bearer auth (`firebase/php-jwt`). All data access goes
  through a REST/JSON API under `/api/v1` — see [docs/API.md](docs/API.md).
- **Web frontend**: plain HTML/JS (no build step, no SPA framework), talking
  to the REST API via `public/js/api-client.js`. Organized into small
  per-feature files under `public/js/` (`poi.js`, `route.js`, `area.js`,
  `tour.js`, `user.js`, `map-core.js`, `ui.js`, `settings.js`) instead of one
  monolithic script.
- **Database**: MySQL/MariaDB, schema in `database/migrations/*.sql`,
  applied with `bin/migrate.php` (no external migration tool required).
- **Mobile**: there is currently no native app. Any future Android/iOS client
  should talk to the same `/api/v1` REST API described in `docs/API.md`
  rather than embedding a webview — the API returns plain JSON and uses
  stateless bearer tokens, so it isn't tied to the web session model.

## Local setup

Requirements: PHP 8.1+, Composer, MySQL/MariaDB.

```bash
composer install
cp .env.example .env        # then fill in DB credentials, JWT_SECRET, MAPS_API_KEY
php bin/migrate.php         # creates tables + seeds the poitype lookup table
php -S localhost:8000 -t public public/index.php
```

Then open `http://localhost:8000/`.

`JWT_SECRET` should be a long random value in every environment (generate one
with `php -r "echo bin2hex(random_bytes(32));"`); `MAPS_API_KEY` is a Google
Maps JavaScript API key restricted to your domain (get a new one for YTAN,
don't reuse PESR's).

## Notable differences from PESR

- Single-file `service.php?cmd=...` RPC dispatcher replaced by a
  resource-oriented REST API (`/api/v1/pois`, `/routes`, `/tours`, `/areas`,
  `/wsi/{code}`, `/auth/login`).
- PHP session cookie + shared static API key replaced by per-login JWT
  bearer tokens, since native apps and stateless clients can't rely on a
  browser session cookie.
- Real DB credentials/API keys are no longer committed to the repo — see
  `.env.example`.
- `wsi.php`'s image-cache path was built directly from an unvalidated
  request parameter (path traversal risk); the code (`WsiRenderer`) now
  validates the code against `^[012]{16}$` before it ever touches the
  filesystem.
- Dead/diagnostic files from PESR (`test.php`, `info.php`/`phpinfo()`, the
  broken `datenschutz.html` duplicate) were not carried over.

## Legal pages

`templates/impressum.php` and `templates/datenschutz.php` were carried over
from PESR largely as-is (same responsible party). Search both files for
`TODO` — the contact e-mail still points at `info@pesr.org` and should be
updated if YTAN uses a different address.
