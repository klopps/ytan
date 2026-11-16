const base = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const CREDENTIALS_FILE = path.join(__dirname, '.e2e-credentials.json');

function readCredentials() {
  return JSON.parse(fs.readFileSync(CREDENTIALS_FILE, 'utf8'));
}

/**
 * Extends Playwright's base test with:
 * - `credentials`: the e2e test user's username/password/userId, seeded
 *   by global-setup.js into ytan_test.
 * - `loggedInPage`: a `page` that is already logged in AND has the GDPR
 *   consent cookie pre-set (so loadGoogleMaps() fires immediately on
 *   load instead of waiting behind the consent screen) AND has waited
 *   for both the real Google Maps map and the app's own `user` object to
 *   be ready. Logging in via a direct API call (not the login form) is
 *   deliberately faster/more reliable for every spec EXCEPT login.spec.js
 *   itself, which drives the real form.
 */
const test = base.test.extend({
  /**
   * Every page fails its test on a Content-Security-Policy violation or a
   * handler csp-events.js couldn't run (e.g. a new onclick="..." that
   * slipped in, or a data-on-click calling a non-global function) - the
   * browser only logs those to the console, the UI just silently does
   * nothing.
   */
  page: async ({ page }, use) => {
    const problems = [];
    page.on('console', (msg) => {
      const text = msg.text();
      if (msg.type() === 'error' && /Content Security Policy|csp-events/.test(text)) {
        problems.push(text);
      }
    });
    page.on('pageerror', (err) => {
      if (/csp-events/.test(String(err))) {
        problems.push(String(err));
      }
    });
    await use(page);
    base.expect(problems, 'CSP violations / failed data-on-* handlers').toEqual([]);
  },

  credentials: async ({}, use) => {
    await use(readCredentials());
  },

  loggedInPage: async ({ page, request, baseURL }, use) => {
    await loginInBrowser(page, request, baseURL);
    await use(page);
  },
});

/** The e2e user's JWT via the API (no browser involved). */
async function apiToken(request) {
  const creds = readCredentials();
  const loginResponse = await request.post('/api/v1/auth/login', {
    data: { username: creds.username, password: creds.password },
  });
  base.expect(loginResponse.ok(), 'e2e user login via API should succeed').toBeTruthy();
  return (await loginResponse.json()).token;
}

/**
 * Logs the page in (token via the API, GDPR consent cookie) and opens
 * `path` - '/' for loggedInPage, '/?tour=12' etc. for shared links (the
 * query is read on the first load: app.php moves it into sessionStorage
 * before anything else runs, so the token must be in localStorage BEFORE
 * that load). Waits for the map and the signed-in user.
 */
async function loginInBrowser(page, request, baseURL, path = '/') {
  const token = await apiToken(request);
  await page.context().addCookies([
    { name: 'gdpr_accepted', value: 'yes', url: baseURL },
  ]);
  if (path === '/') {
    await page.goto('/');
    await page.evaluate((t) => localStorage.setItem('ytan_token', t), token);
    await page.reload();
  } else {
    await page.addInitScript((t) => localStorage.setItem('ytan_token', t), token);
    await page.goto(path);
  }

  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
  await page.waitForFunction(() => window.user && window.user.id !== null, null, { timeout: 20_000 });
}

/** POST /api/v1/{collection} as the e2e user; returns the created row (data). */
async function apiCreate(request, token, collection, data) {
  const res = await request.post('/api/v1/' + collection, { headers: { Authorization: `Bearer ${token}` }, data });
  base.expect(res.ok(), `creating ${collection}: ${res.status()} ${await res.text()}`).toBeTruthy();
  return (await res.json()).data;
}

/** GET /api/v1/{path} as the e2e user: {status, data}. */
async function apiGet(request, token, path) {
  const res = await request.get('/api/v1/' + path, { headers: { Authorization: `Bearer ${token}` } });
  const body = res.ok() ? await res.json() : null;
  return { status: res.status(), data: body ? body.data : null };
}

/** Reloads the page and waits until the map is up again. */
async function reloadMap(page) {
  await page.reload();
  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
}

/**
 * Right-clicks the map centre (after settleMapAt() put a route/area vertex
 * there, or - with markerOffset - a POI marker, whose icon sits ABOVE its
 * position) and returns the context menu's items.
 */
async function rightClickMapCentre(page, markerOffset = 0) {
  const box = await page.locator('#map').boundingBox();
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2 - markerOffset, { button: 'right' });
  const items = page.locator('.contextMenuItem');
  await base.expect(items.first()).toBeVisible({ timeout: 10_000 });
  return items;
}

/**
 * Centres the map on the POI called `name` and waits until its marker is
 * on the map with a loaded icon (markers are built lazily around the view,
 * and an icon <img> without its file is 0x0 px - a click would hit the map).
 */
async function showPoiMarker(page, name, lat, lng) {
  await page.waitForFunction((n) => pois.some((p) => p && p.name === n), name, { timeout: 20_000 });
  await settleMapAt(page, lat, lng, 15);
  await page.waitForFunction((n) => {
    const i = pois.findIndex((p) => p && p.name === n);
    const marker = markers[pois[i].id];
    const img = marker && marker.getMap() && marker.real.advanced.querySelector('img');
    return img && img.complete && img.getBoundingClientRect().height > 0;
  }, name, { timeout: 20_000 });
}

/** Half the height of the vendored POI icon (32x37): its marker position is the icon's bottom. */
const POI_MARKER_OFFSET = 18;

const expect = base.expect;

/**
 * Forces the map to a known, deterministic center/zoom, overriding
 * whatever `fitToPoiBounds()` (map-core.js:714, called unconditionally
 * from initMap()) put it at. That function fits the view to
 * `GET /pois/bounds`, which spans EVERY POI in the database with no
 * scope filter (PoiRepository::bounds() - no WHERE clause at all) - so
 * on a shared ytan_test database, whatever another spec created earlier
 * in the same run silently shifts where "the center of the map" is,
 * which breaks any test that clicks at a fixed pixel offset expecting
 * open water underneath. Real, observed failure: poi.spec.js's map
 * click missed the empty map and landed on a route context-menu.spec.js
 * had left behind, because fitToPoiBounds() had re-centered there.
 *
 * fitToPoiBounds()'s own `/pois/bounds` fetch is async and not awaited
 * by initMap(), so it can still resolve AFTER this runs - setting the
 * center twice, a beat apart, makes this call's value the one that
 * sticks either way.
 */
async function settleMapAt(page, lat, lng, zoom) {
  for (let i = 0; i < 2; i++) {
    await page.evaluate(({ lat, lng, zoom }) => {
      map.setCenter({ lat, lng });
      map.setZoom(zoom);
    }, { lat, lng, zoom });
    await page.waitForTimeout(300);
  }
}

/**
 * map.setZoom(zoom), then waits for the map's next 'idle' plus two
 * animation frames (overlays redraw on 'idle') - instead of a fixed sleep.
 * A zoom equal to the current one fires no 'idle'; maxMs ends the wait then.
 */
async function setZoomAndWaitIdle(page, zoom, maxMs = 3000) {
  await page.evaluate(({ zoom, maxMs }) => new Promise((resolve) => {
    const done = () => requestAnimationFrame(() => requestAnimationFrame(resolve));
    const timer = setTimeout(done, maxMs);
    google.maps.event.addListenerOnce(map, 'idle', () => { clearTimeout(timer); done(); });
    map.setZoom(zoom);
  }), { zoom, maxMs });
}

module.exports = {
  test, expect, settleMapAt, setZoomAndWaitIdle, readCredentials,
  apiToken, loginInBrowser, apiCreate, apiGet, reloadMap, rightClickMapCentre, showPoiMarker, POI_MARKER_OFFSET,
};
