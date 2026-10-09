# YTAN

Paddle. Eat. Sleep. Repeat.

YTAN is a web application for planning kayak touring trips (points of
interest, routes, tours and areas on a map), rebuilt from the ground up as
a fork of PESR. It runs in the browser, as an installable PWA, and as an
**Android app** (see [Android app](#android-app) below).

## Features

**Map & content**
- **POIs** in 17 types (camps, landing sites, drinking water, danger zones,
  portages, lighthouses and sea marks, …), each type
  toggleable; clustered into count bubbles when zoomed out. Camps can carry
  a *wind shelter indicator*, edited on a tappable compass dial.
- **Routes** drawn on the map with live distance labels (optionally
  smoothed), **areas** as polygons. POIs, routes and areas can have photos
  (compressed client-side).
- Create and edit everything directly on the map; right-click / long-press
  context menus on the map and on POIs, routes and areas.
- **Search** for places (Google) or the app's own POIs; share a map view or
  route as a link; pan to your current location and copy its coordinates.

**Tours**
- Group routes into **tours** with description, tags and photos; search and
  filter by name, creator or length; reorder member routes.
- **Tour mode** shows only a tour's routes on the map; tours can be
  published, copied and shared via link.
- **Tour document**: a printable PDF with photos, description and the
  member routes.

**Weather**
- Hourly forecast for any point on the map or your current location
  ([Open-Meteo](https://open-meteo.com/)): temperature, rain, wind, gusts,
  wind direction, waves, tide curve, sunrise/sunset, a week overview with
  frost and thunderstorm hints.
- Rain radar for the current map view (opens [Windy](https://www.windy.com/)).

**On the water (Android app)**
- **GPS track recording** in the background with the screen locked;
  recordings are stored locally and saved as routes once a connection is
  available.
- **Offline use**: map data is cached locally; POIs, routes and areas
  created or changed offline are queued and synced later.
- **Garmin watch** (Fenix 6/7, sideloaded data field in `watch/`): send a
  route to the watch and see bearing and distance to the next waypoint
  while paddling — see `bin\build-watch.bat`.

**Personalization**
- German and English UI, light and dark theme.
- Metric or nautical distances; wind in Bft, m/s, km/h or kn; coordinates as
  decimal degrees, degrees/minutes or degrees/minutes/seconds; route label
  font size.

**Accounts & administration**
- User accounts with invite and password-reset e-mails; private and public
  content.
- Fine-grained rights (create/publish/manage/copy tours, view recording
  details), managed in an admin area that also holds site settings, an
  in-browser translation editor and an orphaned-image cleanup tool.

**Route Navigation on Garmin Watches**
A Garmin ConnectIQ app that provides navigation assistance on routes to the next waypoint, showing direction and distance. Tested with
-- Forerunner 245 music
-- Fenix 6 Pro, Fenix 6S Pro, Fenix 6X Pro
-- Fenix 7, Fenix 7X, Fenix 7S, Fenix 7 Pro, Fenix 7S Pro, Fenix 7X Pro

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
- **Android app**: a [Capacitor 8](https://capacitorjs.com/) shell
  (`android/`, plus the repo-root `package.json`/`capacitor.config.json`)
  that loads the live site in a WebView and adds native capabilities the
  browser can't provide — see [Android app](#android-app). There is no iOS
  app. Because the API returns plain JSON with stateless bearer tokens, a
  fully native client could still talk to `/api/v1` directly.

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

## Android app

The app (`org.pesr.ytan`) does not bundle a copy of the frontend: its
WebView loads `https://ytan.pesr.org/` live (`capacitor.config.json`'s
`server.url`), so web changes reach app users with a normal deploy — a new
APK is only needed when `android/` or `capacitor.config.json` change.

What the native shell adds on top of the web app:

- **Background GPS route recording** with the screen locked
  (`@capacitor-community/background-geolocation`, plus the app-local
  `LocationPermissionsPlugin`) — the main reason the app exists, since
  browsers throttle geolocation in the background.
- **File downloads and sharing** via Android's share sheet
  (`@capacitor/filesystem`, `@capacitor/share`), which a WebView can't do on
  its own.
- **Offline start page** (`public/offline.html`) when launched without a
  connection.
- **In-app updates**: the app compares its own version (`AppInfoPlugin`) with
  `public/app/version.json` on start and installs a newer APK itself
  (`AppUpdatePlugin`).

The JS side lives in `public/js/capacitor-bridge.js` and
`public/js/native-app.js`; both are no-ops in a normal browser.

**Distribution**: not via the Play Store. The signed release APK is published
at `/app/ytan.apk` on the server, and Android users of the web version see an
"Install YTAN App" entry in the menu. Updates only install over an APK signed
with the same release key.

**Building and releasing** (Windows, JDK 21 required — Android Studio's
bundled JBR breaks Gradle):

```bat
bin\build-app-release.bat      &rem signed .apk + .aab, needs android\keystore.properties
bin\publish-app.bat            &rem upload APK + version.json to ytan.pesr.org
bin\publish-app.bat test       &rem ... or to test.pesr.org
```

The version lives in `android/app/build.gradle` (`versionCode` =
major\*10000 + minor\*100 + patch — no leading zeros, Groovy reads those as
octal; `versionName`). The git pre-commit hook bumps it automatically
whenever a commit touches `android/`. The app sends its `versionCode` as an
`X-App-Version` header; setting `MIN_APP_VERSION_CODE` in `.env` makes the
backend block writes from older builds and show an "update required" notice.

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
