# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

YTAN is a web app for planning kayak touring trips (POIs, routes, tours, areas on a map). It's a from-scratch rebuild of an older app called PESR, structured so a future native mobile client can talk to the same backend. See `README.md` for the full "notable differences from PESR" list (REST API replacing a `service.php?cmd=...` RPC dispatcher, JWT bearer auth replacing PHP sessions, a path-traversal fix in `WsiRenderer`, etc.) and `docs/API.md` for the REST API reference.

## Commands

```bash
composer install
cp .env.example .env              # fill in DB credentials, JWT_SECRET, MAPS_API_KEY, MAIL_*, APP_URL
php bin/migrate.php               # applies database/migrations/*.sql in filename order, tracked in a schema_migrations table
php -S localhost:8000 -t public public/index.php
```

- Adding a schema change: add a new plain-SQL file `database/migrations/NNN_description.sql` (zero-padded, next number) — there is no migration framework, just `bin/migrate.php` running un-applied files in sorted order once each.
- There is no test suite yet (`phpunit/phpunit` is a dev dependency and `tests/` is declared in `composer.json`'s `autoload-dev` PSR-4 map, but the directory doesn't exist and nothing currently runs).
- No frontend build step — `public/js/*.js` are plain scripts loaded directly by `templates/app.php`, no bundler/transpiler/package.json.
- This machine has multiple PHP installs; the composer.json requires PHP >=8.1 but a plain `php` on PATH may resolve to an older 7.x build. Prefer an explicit 8.1+ binary (e.g. `/c/dev/php8/php.exe` on this Windows box) for anything version-sensitive (composer, migrations, `php -l`).

## Backend architecture

- **Framework**: Slim 4, PSR-7 (`slim/psr7`). No DI container — everything is wired by hand in `src\App.php`'s `App::create()`: PDO connection, repositories, services and controllers are constructed and closed over directly, then routes are registered as flat `$app->get/post/put/delete(...)` calls (no route groups).
- **Autoloading**: PSR-4, `Ytan\` → `src/`.
- **Request flow**: `public/index.php` → `App::create($rootDir)->run()`. `AuthMiddleware` runs globally and decodes a `Bearer` JWT (if present) into the PSR-7 request attribute `"auth"` — it never rejects a request itself, so every route sees either a decoded payload or `null` there.
- **Controllers** (`src/Http/Controllers/*Controller.php`) extend `BaseController`, which provides:
  - `json($response, $data, $status=200)` — wraps success payloads as `{"data": ...}`.
  - `requireAuthUser($request)` — 401s if `auth` attribute is null, else returns the JWT payload array.
  - `requireAdmin($request)` — like above, plus 403s unless `is_admin` is true on the payload.
  - `assertOwnerOrAdmin($authUser, $ownerId)` — 403s unless the caller owns the resource or is admin.
  - `jsonBody($request)` — decoded JSON request body as an array.
- **Errors**: `src/Exception/*` (`ApiException` base + `NotFoundException`/`ValidationException`/`UnauthorizedException`/`ForbiddenException`) carry an HTTP status; the error handler registered in `App::create()` turns any thrown `ApiException` (or Slim's own HTTP exceptions) into `{"error": {"message": ...}}` with that status, plus a `trace` array when `APP_DEBUG=true` and the status is 500.
- **Data access**: `src/Domain/{Area,Poi,Route,Tour,User}/*Repository.php` take a raw `PDO` via constructor injection and use plain `prepare()`/`execute()`/`fetch()`/`fetchAll()` — no query builder/ORM. `Connection::fromEnv()` (`src/Database/Connection.php`) builds the PDO instance from env vars.
- **Auth/JWT**: `AuthService` (`src/Service/AuthService.php`) issues and verifies JWTs (`firebase/php-jwt`), holds the password policy (`validatePasswordFormat`), and login/self-change/admin-set/invite-token-redemption all funnel through a shared private `issueToken()` so the JWT claim shape (`sub`, `username`, `email`, `firstname`, `lastname`, `is_admin`, `iat`, `exp`) stays consistent everywhere a token is minted.
- **User invite / password-reset tokens**: `user_token` table (single-use, `purpose` = `invite` or `reset`, expiry-checked in SQL) backs both the admin-invite-new-user flow and "forgot password" — both resolve through the same `/set-password?token=...` landing page (`templates/set-password.php`), which is server-rendered (not part of the SPA) since it must work for a signed-out visitor arriving from an email link. `MailService` (`src/Service/MailService.php`, PHPMailer/SMTP) sends those emails; SMTP + `APP_URL` (needed to build absolute links outside of a request context) come from env vars.
- **Subdirectory deployment**: the app can be served from a path prefix (e.g. `http://localhost/ytan/public/`) instead of a vhost root. `App::create()` derives `$baseUrl` per-request from `$_SERVER['SCRIPT_NAME']` and calls `$app->setBasePath()`; that same `$baseUrl` is threaded into `templates/*.php` and injected client-side as `window.YTAN_API_BASE` (`public/js/api-client.js` reads it, falling back to `/api/v1`). Don't hardcode absolute paths in templates/JS — always go through `$baseUrl` / `window.YTAN_API_BASE`.

## Frontend architecture

- Plain ES6, no bundler. `templates/app.php` loads vendor libs then `public/js/*.js` in a fixed, load-order-dependent `<script>` sequence (`config.js → helper.js → api-client.js → settings.js → ui.js → toast.js → confirm-dialog.js → map-core.js → poi.js → route.js → area.js → tour.js → user.js → admin-user.js`) — later files rely on globals (`log`, `LOG_ERROR`/`LOG_WARN`/`LOG_INFO`/`LOG_DEBUG`, `user`, `map`, `settings`, `Ytan`, etc.) defined by earlier ones, and most functions are attached implicitly to `window` rather than namespaced.
- **API access**: all backend calls go through the `Ytan` singleton (`public/js/api-client.js`): `Ytan.get/post/put/del(path[, body])`, `Ytan.isLoggedIn()`, `Ytan.setToken/getToken` (JWT kept in `localStorage['ytan_token']`, not a cookie). Don't `fetch()` the API directly from feature code.
- **Session bootstrap**: on page load (`templates/app.php`'s inline `<script>`), `user` is set to `initUser()`'s empty shape first, then — if a token exists — revalidated against `GET /api/v1/auth/me` (rather than trusting a cached `sessionStorage['user']` blindly) before the UI reflects logged-in state.
- **Full-screen overlay panels** (cookie consent, Impressum/Datenschutz, user-admin): all three share one CSS pattern — a `<div class="cookiemenu">` slid in via a `style.width` transition (`0` ↔ `100%`), plus a matching `openXMenu()`/`closeXMenu()` JS function pair (see `openCookieMenu`/`openLegalMenu` in `public/js/ui.js`, `openUserAdminMenu` in `public/js/admin-user.js`). Every open/close call also goes through the shared `panelOpened()`/`panelClosed()` counter in `ui.js`, which hides `#editToolbar` (it visually sits above these panels) while any panel is open — use that pair rather than toggling it directly, since the counter is what keeps two panels opened back-to-back from clobbering each other's close. Each panel also ends its own `.cm_content` with a `<img class="panel-logo">` — deliberately a normal flow element, not `position: fixed`, since a fixed logo stays pinned to the same screen position for as long as the panel is open and covers whatever content scrolls underneath it, not just content past the very end. New panels should follow this same pattern (including their own trailing `.panel-logo`) rather than introducing a new one.
- **Icon font**: `.material-icons-round` uses a vendored, older/classic Material Icons glyph set (`public/fonts/MaterialIconsRound-Regular.*`), not the newer Material Symbols set — some newer icon names (e.g. `key`) don't exist in it and silently render as nothing. When adding an icon, verify the ligature name is actually present in the vendored font rather than assuming current Material Symbols naming.
- User-admin-panel markup uses its own `.admin-user-form` / `.adminFormRow` / `.adminFormLabel` / `.adminFormField` classes rather than the POI/Route/Area edit-window's `.infoWindowElement`/`.leftCol`/`.rightCol`, so styling one doesn't affect the other.
- Custom checkboxes app-wide rely on a CSS rule that hides the real `<input type="checkbox">` and renders a Material-icon glyph via `::before` on an immediately-following sibling `<label>` (see `input[type="checkbox"] + label::before` in `public/css/style.css`) — the `<label>` must be the checkbox's next DOM sibling or the checkbox becomes invisible with no fallback rendering.
