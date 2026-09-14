const { test, expect, settleMapAt } = require('../fixtures');

/**
 * POI/Route/Area creation is already covered by poi.spec.js/route.spec.js/
 * area.spec.js, so here the three overlays are seeded directly via the
 * API (fast, deterministic) and the test focuses purely on the
 * right-click -> contextMenu behavior itself (map-core.js/poi.js/route.js/
 * area.js's 'contextmenu' listeners - see CLAUDE.md's long-press notes:
 * desktop right-click uses the native browser contextmenu event, the
 * custom long-press hit-testing is a touch-only fallback not exercised
 * here).
 */
const LAT = 54.1;
const LNG = 12.1;

test.describe('Context menus', () => {
  test('right-clicking a POI marker opens its context menu with Edit/Delete', async ({ loggedInPage: page, request, baseURL }) => {
    const token = await page.evaluate(() => localStorage.getItem('ytan_token'));
    const created = await request.post('/api/v1/pois', {
      headers: { Authorization: `Bearer ${token}` },
      data: {
        poitype_id: 2,
        name: 'E2E Context Menu POI',
        description: '',
        public: 0,
        latitude: LAT,
        longitude: LNG,
        url: '',
      },
    });
    expect(created.ok()).toBeTruthy();

    await page.reload();
    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
    await settleMapAt(page, LAT, LNG, 15);

    // A POI marker's `position` LatLng maps to the BOTTOM-CENTER of its
    // icon (setPoi()'s Marker only sets `icon: {url}`, no explicit
    // `anchor` - that's the google.maps.Marker default for a plain-url
    // icon), not its visual center - clicking exactly at the map's
    // center pixel (== the marker's LatLng pixel, since settleMapAt()
    // centered on it) lands just below the icon, on the empty map
    // underneath, and hits the generic map context menu instead. The
    // vendored icon is 32x37px (markers/poi_2_mapicons.png) - offsetting
    // up by half its height lands inside the drawn icon.
    const mapBox = await page.locator('#map').boundingBox();
    await page.mouse.click(mapBox.x + mapBox.width / 2, mapBox.y + mapBox.height / 2 - 18, { button: 'right' });

    const items = page.locator('.contextMenuItem');
    await expect(items.first()).toBeVisible({ timeout: 10_000 });
    // weather + edit + delete + cancel, since this POI is owned by the
    // logged-in e2e user - see poi.js's showPoiContextMenu().
    expect(await items.count()).toBeGreaterThanOrEqual(4);
    const icons = await page.locator('.contextMenuItem .material-icons-round').allTextContents();
    expect(icons).toContain('edit');
    expect(icons).toContain('delete');
  });

  test('right-clicking a route polyline opens its context menu', async ({ loggedInPage: page, request }) => {
    const token = await page.evaluate(() => localStorage.getItem('ytan_token'));
    const points = [{ lat: LAT, lng: LNG }, { lat: LAT + 0.01, lng: LNG + 0.01 }];
    const created = await request.post('/api/v1/routes', {
      headers: { Authorization: `Bearer ${token}` },
      data: {
        name: 'E2E Context Menu Route',
        description: '',
        public: 0,
        length: 1000,
        points: JSON.stringify(points),
        color: '#BF409F',
      },
    });
    expect(created.ok()).toBeTruthy();

    await page.reload();
    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
    // Center exactly on the route's first vertex - guaranteed to sit ON
    // the polyline itself, unlike an arbitrary point along the segment.
    await settleMapAt(page, points[0].lat, points[0].lng, 15);

    const mapBox = await page.locator('#map').boundingBox();
    await page.mouse.click(mapBox.x + mapBox.width / 2, mapBox.y + mapBox.height / 2, { button: 'right' });

    const items = page.locator('.contextMenuItem');
    await expect(items.first()).toBeVisible({ timeout: 10_000 });
  });

  test('right-clicking an area polygon opens its context menu', async ({ loggedInPage: page, request }) => {
    const token = await page.evaluate(() => localStorage.getItem('ytan_token'));
    const points = [
      { lat: LAT, lng: LNG },
      { lat: LAT + 0.01, lng: LNG },
      { lat: LAT, lng: LNG + 0.01 },
    ];
    const created = await request.post('/api/v1/areas', {
      headers: { Authorization: `Bearer ${token}` },
      data: {
        name: 'E2E Context Menu Area',
        description: '',
        public: 0,
        points: JSON.stringify(points),
        color: '#3388FF',
        opacity: 0.35,
        zindex: 1,
      },
    });
    expect(created.ok()).toBeTruthy();

    await page.reload();
    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
    await settleMapAt(page, points[0].lat, points[0].lng, 15);

    const mapBox = await page.locator('#map').boundingBox();
    await page.mouse.click(mapBox.x + mapBox.width / 2, mapBox.y + mapBox.height / 2, { button: 'right' });

    const items = page.locator('.contextMenuItem');
    await expect(items.first()).toBeVisible({ timeout: 10_000 });
  });
});
