// @ts-check
/**
 * Offline behaviour: loadCollection() (offline-cache.js) falls back to the
 * IndexedDB copy when the list requests fail, and a change saved while the
 * server is unreachable waits in the queue (offline-sync.js) until the
 * 'online' event syncs it. "Offline" = the API requests are aborted with
 * page.route() - not context.setOffline(), which would also cut Google Maps.
 */
const {
  test, expect, apiCreate, apiGet, reloadMap, rightClickMapCentre, showPoiMarker, POI_MARKER_OFFSET,
} = require('../fixtures');

// Own patch of map, not used by any other spec.
const LAT = 57.6;
const LNG = 15.4;

const LIST_REQUESTS = /\/api\/v1\/(pois|routes|areas|tours)\?scope=/;

test.describe('Offline', () => {
  test('without the server the lists come from the offline cache, with a notice', async ({ loggedInPage: page, request }) => {
    const t = await page.evaluate(() => localStorage.getItem('ytan_token'));
    const name = 'E2E Offline Route ' + Date.now().toString(36);
    await apiCreate(request, t, 'routes', {
      name, description: '', public: 0, length: 1000, color: '#BF409F',
      points: JSON.stringify([{ lat: LAT, lng: LNG }, { lat: LAT + 0.01, lng: LNG + 0.01 }]),
    });

    // online load: the lists (with the new route) land in the cache
    await reloadMap(page);
    await expect.poll(() => page.evaluate((n) => getCachedCollection('routes', 'mine_public')
      .then((c) => !!c && c.data.some((r) => r.name === n)), name), { timeout: 20_000 }).toBe(true);

    await page.route(LIST_REQUESTS, (route) => route.abort());
    await reloadMap(page);

    await expect(page.locator('#toastContainer .toast-info')).toBeVisible({ timeout: 15_000 });
    await expect.poll(() => page.evaluate((n) => routes.some((r) => r && r.name === n), name), { timeout: 15_000 }).toBe(true);
    // the cached routes are drawn like loaded ones (points already parsed)
    expect(await page.evaluate((n) => {
      const i = routes.findIndex((r) => r && r.name === n);
      return Array.isArray(routes[i].points) && !!routePaths[i];
    }, name)).toBe(true);
  });

  test('without the server and without a cache an error notice says so', async ({ loggedInPage: page }) => {
    await page.route(LIST_REQUESTS, (route) => route.abort());
    // empty the offline cache, then load again
    const deleted = await page.evaluate(() => new Promise((resolve) => {
      // this page's open connection (offline-cache.js) would block the delete
      if (offlineCacheDb) {
        offlineCacheDb.close();
        offlineCacheDb = null;
      }
      const req = indexedDB.deleteDatabase('ytan-offline-cache');
      req.onsuccess = () => resolve('deleted');
      req.onerror = () => resolve('error');
      req.onblocked = () => resolve('blocked');
    }));
    expect(deleted).toBe('deleted');
    await reloadMap(page);

    await expect(page.locator('#toastContainer .toast-error')).toBeVisible({ timeout: 15_000 });
  });

  test('a change saved while the server is unreachable is queued and synced when back online', async ({ loggedInPage: page, request }) => {
    const t = await page.evaluate(() => localStorage.getItem('ytan_token'));
    const name = 'E2E Offline POI ' + Date.now().toString(36);
    const poi = await apiCreate(request, t, 'pois', {
      poitype_id: 2, name, description: '', public: 0, latitude: LAT + 0.1, longitude: LNG, url: '',
    });
    await reloadMap(page);

    const block = (route) => route.abort();
    await page.route(/\/api\/v1\/pois\/\d+(\?|$)/, block);

    await showPoiMarker(page, name, LAT + 0.1, LNG);
    await rightClickMapCentre(page, POI_MARKER_OFFSET);
    await page.locator('.contextMenuItem', { hasText: 'edit' }).first().click();
    await page.locator('#editPoiName').fill(name + ' offline');
    await page.locator('#editPoiSaveBtn').click();

    // queued: the pending-changes row counts it, the server still has the old name
    await expect.poll(() => page.evaluate(() => pendingChangesCache.length), { timeout: 10_000 }).toBe(1);
    expect(await page.evaluate(() => document.getElementById('pendingChangesMenuRow').style.display)).toBe('');
    expect((await apiGet(request, t, 'pois/' + poi.id)).data.name).toBe(name);

    await page.unroute(/\/api\/v1\/pois\/\d+(\?|$)/, block);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));

    await expect(page.locator('#toastContainer .toast-success')).toBeVisible({ timeout: 15_000 });
    await expect.poll(async () => (await apiGet(request, t, 'pois/' + poi.id)).data?.name, { timeout: 15_000 }).toBe(name + ' offline');
    await expect.poll(() => page.evaluate(() => pendingChangesCache.length), { timeout: 10_000 }).toBe(0);
  });
});
