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

  test('AR route mode: route on the water, next waypoint like the watch, nav bar', async ({ loggedInPage: page, request, context }) => {
    const token = await apiToken(request);
    const points = [
      { lat: LAT + 0.0005, lng: LNG }, // ~56 m north of the position
      { lat: LAT + 0.009, lng: LNG }, // ~1 km north
      { lat: LAT + 0.009, lng: LNG + 0.016 }, // then ~1 km east
    ];
    await apiCreate(request, token, 'routes', { name: 'E2E AR Route', description: '', public: 0, length: 2000, points: JSON.stringify(points), color: '#BF409F' });
    await reloadMap(page);
    await page.waitForFunction(() => routes.some((r) => r && r.name === 'E2E AR Route'), null, { timeout: 20_000 });
    await page.evaluate(() => { settings.arRouteId = null; settings.arRouteRange = 3000; });

    // the route's context menu offers "AR navigation"
    await page.evaluate(() => {
      const i = routes.findIndex((r) => r && r.name === 'E2E AR Route');
      showRouteContextMenu({ latLng: map.getCenter() }, i);
    });
    await page.locator('.contextMenuItem', { hasText: /AR-Navigation|AR navigation/ }).click();
    await expect(page.locator('#arView')).toBeVisible();
    await expect(page.locator('#arNavBar')).toBeVisible();

    // looking north: the route is drawn, the first waypoint is next, straight ahead
    await page.waitForFunction(() => {
      window.dispatchEvent(new DeviceOrientationEvent('deviceorientationabsolute', { alpha: 0, beta: 90, gamma: 0, absolute: true }));
      const canvas = document.getElementById('arRouteCanvas');
      if (!canvas.width) {
        return false;
      }
      const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
      let painted = 0;
      for (let i = 3; i < data.length; i += 4 * 7) {
        if (data[i] > 0) {
          painted++;
        }
      }
      return painted > 50;
    }, null, { timeout: 10_000, polling: 200 });
    await expect(page.locator('#arNavSub')).toContainText('1/3');
    await expect(page.locator('#arNavMain')).toHaveText(/^0° · \d+m$/);
    await expect(page.locator('#arTarget')).toBeVisible();
    await expect(page.locator('#arTarget')).toHaveText('1');
    expect(await page.evaluate(() => settings.arRouteId)).not.toBeNull();

    // at the second waypoint the counter moves on (shortcut past the first, then arrival)
    await context.setGeolocation({ latitude: LAT + 0.009, longitude: LNG, accuracy: 5 });
    await expect(page.locator('#arNavSub')).toContainText(/[23]\/3/, { timeout: 10_000 });

    // on the last leg looking east (along the route): the line runs from the bottom up to the horizon
    await context.setGeolocation({ latitude: LAT + 0.009, longitude: LNG + 0.003, accuracy: 5 });
    await page.waitForFunction(() => {
      window.dispatchEvent(new DeviceOrientationEvent('deviceorientationabsolute', { alpha: 270, beta: 85, gamma: 0, absolute: true }));
      const canvas = document.getElementById('arRouteCanvas');
      const ctx = canvas.getContext('2d');
      const column = ctx.getImageData(Math.round(canvas.width / 2), 0, 1, canvas.height).data;
      let painted = 0;
      for (let i = 3; i < column.length; i += 4) {
        if (column[i] > 0) {
          painted++;
        }
      }
      return painted > canvas.height * 0.15; // a long vertical stretch in the middle of the picture
    }, null, { timeout: 10_000, polling: 200 });
    await expect(page.locator('#arNavSub')).toContainText('3/3');
    await expect(page.locator('#arWarning')).toBeHidden();

    // x ends the route mode, the view stays open
    await page.locator('.ar-nav-close').click();
    await expect(page.locator('#arNavBar')).toBeHidden();
    await expect(page.locator('#arView')).toBeVisible();
    expect(await page.evaluate(() => settings.arRouteId)).toBeNull();
    await page.evaluate(() => stopArView());
  });

  test('pure route helpers: next waypoint like the watch, ground projection, clipping', async ({ loggedInPage: page }) => {
    const r = await page.evaluate(() => {
      const line = [55.50, 55.51, 55.52, 55.53].map((lat) => ({ lat, lng: 13.5 }));
      const mid = arNextWaypoint({ next: -1 }, 55.515, 13.5, line);
      const arrival = arNextWaypoint({ next: 2 }, 55.52, 13.5, line);
      const outAndBack = [{ lat: 55.5, lng: 13.5 }, { lat: 55.51, lng: 13.5 }, { lat: 55.5, lng: 13.5001 }];
      const onTheWayOut = arNextWaypoint({ next: -1 }, 55.5005, 13.5, outAndBack);
      const u = [{ lat: 55.50, lng: 13.50 }, { lat: 55.52, lng: 13.50 }, { lat: 55.52, lng: 13.53 }, { lat: 55.50, lng: 13.53 }];
      const shortcut = arNextWaypoint({ next: 1 }, 55.51, 13.53, u);
      const finished = arNextWaypoint({ next: 3 }, 55.53, 13.5, line);
      const ahead = arProjectGround(0, 100, [0, 1, 0], 50, 70, 400, 800);
      const behind = arProjectGround(0, -100, [0, 1, 0], 50, 70, 400, 800);
      const right = arProjectGround(50, 100, [0, 1, 0], 50, 70, 400, 800);
      const clipped = arClipSegment([0, 0, -5], [0, 0, 10]);
      const gone = arClipSegment([0, 0, -5], [0, 0, 1]);
      return { mid: mid.next, arrival: arrival.next, out: onTheWayOut.next, shortcut: shortcut.next, finished: finished.done, ahead, behind, right, clipped, gone };
    });
    expect(r.mid).toBe(2);
    expect(r.arrival).toBe(3);
    expect(r.out).toBe(1); // starts on the way out, not on the way back
    expect(r.shortcut).toBe(3);
    expect(r.finished).toBe(true);
    expect(r.ahead.x).toBeCloseTo(200, 3);
    expect(r.ahead.y).toBeGreaterThan(400); // the water is below the horizon
    expect(r.behind).toBeNull();
    expect(r.right.x).toBeGreaterThan(200);
    expect(r.clipped[0][2]).toBe(2);
    expect(r.gone).toBeNull();
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
