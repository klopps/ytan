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
  credentials: async ({}, use) => {
    await use(readCredentials());
  },

  loggedInPage: async ({ page, request, baseURL }, use) => {
    const creds = readCredentials();
    const loginResponse = await request.post('/api/v1/auth/login', {
      data: { username: creds.username, password: creds.password },
    });
    base.expect(loginResponse.ok(), 'e2e user login via API should succeed').toBeTruthy();
    const { token } = await loginResponse.json();

    await page.context().addCookies([
      { name: 'gdpr_accepted', value: 'yes', url: baseURL },
    ]);
    await page.goto('/');
    await page.evaluate((t) => localStorage.setItem('ytan_token', t), token);
    await page.reload();

    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
    await page.waitForFunction(() => window.user && window.user.id !== null, null, { timeout: 20_000 });

    await use(page);
  },
});

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

module.exports = { test, expect, settleMapAt };
