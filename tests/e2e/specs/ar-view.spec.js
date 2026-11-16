const { test, expect, apiToken, apiCreate, reloadMap } = require('../fixtures');

/**
 * AR view (ar-view.js): Chromium's fake camera, a fixed GPS position and
 * synthetic 'deviceorientationabsolute' events stand in for the phone. Checks
 * that the POIs in view are drawn (nearest in front, a farther one on the
 * same bearing moved up instead of overlapping), the limit counts the
 * picture, POIs outside the view are counted at the edge, the info card and
 * "Show on map" work, and the pure geometry helpers.
 */
const LAT = 55.5;
const LNG = 13.5;
const NEAR = 'E2E AR Nah Nord';
const FAR = 'E2E AR Fern Nord';
const EAST = 'E2E AR Ost';

test.use({ launchOptions: { args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] } });

test.describe('on a phone', () => {
  test.use({
    viewport: { width: 390, height: 760 },
    hasTouch: true,
    isMobile: true,
    permissions: ['geolocation', 'camera', 'accelerometer', 'gyroscope', 'magnetometer'],
    geolocation: { latitude: LAT, longitude: LNG, accuracy: 8 },
  });

  test('POIs in view are drawn without clutter, the rest is counted at the edge', async ({ loggedInPage: page, request }) => {
    const token = await apiToken(request);
    const poi = (name, lat, lng) => ({ poitype_id: 2, name, description: '', public: 0, latitude: lat, longitude: lng, url: '' });
    await apiCreate(request, token, 'pois', poi(NEAR, LAT + 0.0045, LNG)); // ~500 m north
    await apiCreate(request, token, 'pois', poi(FAR, LAT + 0.018, LNG)); // ~2 km north, same bearing
    await apiCreate(request, token, 'pois', poi(EAST, LAT, LNG + 0.0127)); // ~800 m east
    await reloadMap(page);
    await page.waitForFunction((names) => names.every((n) => pois.some((p) => p && p.name === n)), [NEAR, FAR, EAST], { timeout: 20_000 });
    await page.evaluate(() => { settings.arRadius = 5000; settings.arMaxPois = 10; });

    // the drawer row is there on a touch device with camera + compass
    await page.evaluate(() => toggleMenu());
    await expect(page.locator('#arMenuRow')).toBeVisible();
    await page.locator('#arMenuRow button').click();
    await expect(page.locator('#arView')).toBeVisible();
    await page.waitForFunction(() => document.getElementById('arVideo').srcObject !== null, null, { timeout: 10_000 });

    // looking north, phone upright - sent until the view has picked it up (its listener starts after the permission check)
    await page.waitForFunction(() => {
      window.dispatchEvent(new DeviceOrientationEvent('deviceorientationabsolute', { alpha: 0, beta: 90, gamma: 0, absolute: true }));
      return document.querySelectorAll('.ar-label').length >= 2;
    }, null, { timeout: 10_000, polling: 200 });
    const labels = page.locator('.ar-label');
    await expect(labels.filter({ hasText: NEAR })).toBeVisible({ timeout: 10_000 });
    await expect(labels.filter({ hasText: FAR })).toBeVisible();
    await expect(labels.filter({ hasText: EAST })).toHaveCount(0);
    await expect(page.locator('#arEdgeRight')).toBeVisible();
    await expect(page.locator('#arEdgeRight .ar-edge-count')).toHaveText('1');
    await expect(page.locator('#arStatus')).toContainText('3');

    // same bearing: the nearer one in front, the farther one moved up, no overlap
    await page.waitForTimeout(500); // easing settles
    const near = labels.filter({ hasText: NEAR });
    const far = labels.filter({ hasText: FAR });
    const nearBox = await near.boundingBox();
    const farBox = await far.boundingBox();
    expect(Number(await near.evaluate((el) => el.style.zIndex))).toBeGreaterThan(Number(await far.evaluate((el) => el.style.zIndex)));
    expect(farBox.y + farBox.height).toBeLessThanOrEqual(nearBox.y + 1);
    expect(await far.getAttribute('data-level')).toBe('1');

    // the limit is for the picture: 1 -> only the nearest in view
    await page.evaluate(() => { settings.arMaxPois = 1; });
    await expect(labels).toHaveCount(1);
    await expect(labels.first()).toContainText(NEAR);

    // turning east brings the east POI in and moves the north ones out to the left
    await page.evaluate(() => { settings.arMaxPois = 10; });
    for (let i = 0; i < 30; i++) {
      await page.evaluate(() => window.dispatchEvent(new DeviceOrientationEvent('deviceorientationabsolute', { alpha: 270, beta: 90, gamma: 0, absolute: true })));
    }
    await expect(labels.filter({ hasText: EAST })).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('#arEdgeLeft .ar-edge-count')).toHaveText('2');

    // info card and "show on map"
    await labels.filter({ hasText: EAST }).click();
    await expect(page.locator('#arInfoCard')).toBeVisible();
    await expect(page.locator('#arInfoName')).toHaveText(EAST);
    await expect(page.locator('#arInfoDistance')).toContainText(/9\d°|8\d°/);
    await page.locator('.ar-info-map').click();
    await expect(page.locator('#arView')).toBeHidden();
    expect(await page.evaluate(() => openPanelCount)).toBe(0);
    await page.waitForFunction(() => Math.abs(map.getCenter().lng() - 13.5127) < 0.001, null, { timeout: 10_000 });
    expect(await page.evaluate(() => document.getElementById('arVideo').srcObject)).toBeNull();
  });

  test('pure geometry: projection, layout, camera direction', async ({ loggedInPage: page }) => {
    const result = await page.evaluate(() => {
      const centre = arProject(0, 0, 0, 50, 70, 400, 800);
      const edge = arProject(25, 0, 0, 50, 70, 400, 800);
      const behind = arProject(180, 0, 0, 50, 70, 400, 800);
      const tilted = arProject(0, 0, 10, 50, 70, 400, 800);
      const placed = arLayout([
        { id: 1, x: 200, anchorY: 400, w: 100, h: 60 },
        { id: 2, x: 210, anchorY: 400, w: 100, h: 60 },
        { id: 3, x: 350, anchorY: 400, w: 40, h: 60 },
      ], new Map());
      const crowded = arLayout([0, 1, 2, 3, 4, 5, 6].map((i) => ({ id: i, x: 200, anchorY: 700, w: 100, h: 60 })), new Map());
      return {
        centre, edge, behind, tilted, placed, crowded: crowded.length,
        west: arCameraDirection(90, 90, 0).map((v) => Math.round(v * 1000) / 1000 + 0),
        north: arCameraDirection(0, 90, 0).map((v) => Math.round(v * 1000) / 1000 + 0),
        bearingEast: Math.round(arBearing(55.5, 13.5, 55.5, 13.6)),
      };
    });
    expect(result.centre.x).toBeCloseTo(200, 3);
    expect(result.centre.inView).toBe(true);
    expect(result.edge.x).toBeCloseTo(400, 3);
    expect(result.behind.inView).toBe(false);
    expect(result.tilted.horizonY).toBeGreaterThan(400); // camera tilted up -> horizon lower on the screen
    expect(result.placed.map((p) => [p.id, p.level])).toEqual([[1, 0], [2, 1], [3, 0]]);
    expect(result.crowded).toBe(5); // levels 0..4, the rest is left out
    expect(result.west).toEqual([-1, 0, 0]);
    expect(result.north).toEqual([0, 1, 0]);
    expect(result.bearingEast).toBe(90);
  });
});

test('no AR entry on a desktop without touch', async ({ loggedInPage: page }) => {
  await page.evaluate(() => toggleMenu());
  await expect(page.locator('#arMenuRow')).toBeHidden();
  expect(await page.evaluate(() => mapContextMenuItems.some((i) => i.labelKey === 'ar.context_menu'))).toBe(false);
});
