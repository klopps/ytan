const { test, expect, settleMapAt } = require('../fixtures');

/**
 * Route animation (route-animation.js): the route context menu's
 * "Zeige Routenanimation" opens the animation's own tilted VECTOR map
 * (needs 'wasm-unsafe-eval' in the CSP - without it that map stays blank),
 * the line is drawn over the route, a marker lands on the last waypoint, and
 * afterwards - here: the stop button - the main map is where it was.
 */
// Headless Chromium has no WebGL by default, and without it Maps falls back to raster (no tilt).
test.use({ launchOptions: { args: ['--use-gl=angle', '--ignore-gpu-blocklist'] } });

const LAT = 54.3;
const LNG = 12.3;

test('the route animation follows the route on a tilted map and leaves the main map as it was', async ({ loggedInPage: page, request }) => {
  const token = await page.evaluate(() => localStorage.getItem('ytan_token'));
  const points = [{ lat: LAT, lng: LNG }, { lat: LAT + 0.003, lng: LNG + 0.002 }, { lat: LAT + 0.006, lng: LNG }];
  const created = await request.post('/api/v1/routes', {
    headers: { Authorization: `Bearer ${token}` },
    data: { name: 'E2E Animation Route', description: '', public: 0, length: 700, points: JSON.stringify(points), color: '#BF409F' },
  });
  expect(created.ok()).toBeTruthy();

  await page.reload();
  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
  await page.waitForFunction((name) => routes.some((r) => r && r.name === name), 'E2E Animation Route', { timeout: 20_000 });
  await settleMapAt(page, LAT, LNG, 15);
  // A long pause at the end, so the finished picture is still there to be checked and the stop button ends it.
  await page.evaluate(() => { window.YTAN_ROUTE_ANIMATION = Object.assign({}, window.YTAN_ROUTE_ANIMATION, { end_delay_s: 30 }); });
  const before = await page.evaluate(() => ({ center: map.getCenter().toJSON(), zoom: map.getZoom() }));

  const mapBox = await page.locator('#map').boundingBox();
  await page.mouse.click(mapBox.x + mapBox.width / 2, mapBox.y + mapBox.height / 2, { button: 'right' });
  await page.locator('.contextMenuItem', { hasText: /Routenanimation|route animation/ }).click();

  await expect(page.locator('#routeAnimation')).toHaveClass(/visible/, { timeout: 10_000 });
  // The whole route is short, so it ends quickly: marker on the last waypoint, line drawn to the end.
  await page.waitForFunction(() => routeAnimation !== null && routeAnimation.done, null, { timeout: 30_000 });
  const state = await page.evaluate(() => ({
    renderingType: routeAnimation.map.getRenderingType(),
    tilt: routeAnimation.map.getTilt(),
    markers: routeAnimation.markers.length,
    drawn: routeAnimation.legs[0].line.getPath().getLength(),
    toolbarHidden: document.getElementById('editToolbar').style.visibility,
  }));
  expect(state.renderingType).toBe('VECTOR');
  expect(state.tilt).toBeGreaterThan(30);
  expect(state.markers).toBe(1);
  expect(state.drawn).toBeGreaterThanOrEqual(3);
  expect(state.toolbarHidden).toBe('hidden');

  // Backwards from the end: the end pause is off, the marker of the route goes again, the line shrinks.
  await page.locator('#routeAnimationBackward').click();
  await expect(page.locator('#routeAnimationBackward')).toHaveAttribute('aria-pressed', 'true');
  await page.waitForFunction(() => routeAnimation.markers.length === 0 && routeAnimation.legs[0].line.getPath().getLength() < 3, null, { timeout: 5_000 });
  expect(await page.evaluate(() => routeAnimation.done || routeAnimation.endTimer !== null)).toBe(false);
  await page.locator('#routeAnimationPauseBtn').click();

  // Speed -/+: a factor on the admin's default speed, shown between the buttons.
  const base = await page.evaluate(() => routeAnimation.speed);
  await page.locator('#routeAnimationFaster').click();
  await expect(page.locator('#routeAnimationSpeedLabel')).toHaveText(/1[.,]5×/);
  expect(await page.evaluate(() => routeAnimation.speed)).toBeCloseTo(base * 1.5, 6);
  for (let k = 0; k < 3; k++) {
    await page.locator('#routeAnimationSlower').click();
  }
  await expect(page.locator('#routeAnimationSpeedLabel')).toHaveText(/0[.,]25×/);
  await expect(page.locator('#routeAnimationSlower')).toBeDisabled();

  await page.locator('.route-animation-stop').click();
  await expect(page.locator('#routeAnimation')).toBeHidden({ timeout: 5_000 });
  const after = await page.evaluate(() => ({ center: map.getCenter().toJSON(), zoom: map.getZoom(), tilt: map.getTilt(), panels: openPanelCount }));
  expect(after.zoom).toBe(before.zoom);
  expect(after.center.lat).toBeCloseTo(before.center.lat, 6);
  expect(after.center.lng).toBeCloseTo(before.center.lng, 6);
  expect(after.tilt).toBe(0);
  expect(after.panels).toBe(0);
});

test('the route animation can be paused, resumed and played backwards to the start', async ({ loggedInPage: page }) => {
  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
  // Slow, so it is still running while it is paused.
  await page.evaluate(() => {
    window.YTAN_ROUTE_ANIMATION = Object.assign({}, window.YTAN_ROUTE_ANIMATION, { speed: 10 });
    startRouteAnimation([{ color: '#BF409F', points: [{ lat: 54.4, lng: 12.4 }, { lat: 54.403, lng: 12.402 }, { lat: 54.406, lng: 12.4 }] }]);
  });
  await expect(page.locator('#routeAnimation')).toHaveClass(/visible/, { timeout: 10_000 });
  await expect(page.locator('#routeAnimationForward')).toHaveAttribute('aria-pressed', 'true');
  await page.waitForFunction(() => routeAnimation.distance > 0, null, { timeout: 10_000 });

  await page.locator('#routeAnimationPauseBtn').click();
  await expect(page.locator('#routeAnimationPauseBtn')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('#routeAnimationForward')).toHaveAttribute('aria-pressed', 'false');
  const paused = await page.evaluate(() => routeAnimation.distance);
  await page.waitForTimeout(800);
  expect(await page.evaluate(() => routeAnimation.distance)).toBe(paused);

  // The space bar resumes it, without a jump for the time spent paused.
  await page.keyboard.press(' ');
  await expect(page.locator('#routeAnimationForward')).toHaveAttribute('aria-pressed', 'true');
  await page.waitForFunction((d) => routeAnimation.distance > d, paused, { timeout: 5_000 });
  expect(await page.evaluate(() => routeAnimation.distance)).toBeLessThan(paused + 200);

  // Backwards (left arrow key) it runs back to the start and stops there by itself.
  await page.keyboard.press('ArrowLeft');
  await expect(page.locator('#routeAnimationBackward')).toHaveAttribute('aria-pressed', 'true');
  await page.waitForFunction(() => routeAnimation.paused && routeAnimation.distance === 0, null, { timeout: 15_000 });
  await expect(page.locator('#routeAnimationPauseBtn')).toHaveAttribute('aria-pressed', 'true');
  expect(await page.evaluate(() => routeAnimation.legs[0].line.getPath().getLength())).toBeLessThanOrEqual(2);

  await page.locator('.route-animation-stop').click();
  await expect(page.locator('#routeAnimation')).toBeHidden({ timeout: 5_000 });
});

test('at the end the route animation stays open, swings to the view from before, and jumps back to the start', async ({ loggedInPage: page }) => {
  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
  await settleMapAt(page, 54.5, 12.5, 11);
  await page.evaluate(() => {
    window.YTAN_ROUTE_ANIMATION = Object.assign({}, window.YTAN_ROUTE_ANIMATION, { end_delay_s: 0 });
    startRouteAnimation([{ color: '#BF409F', points: [{ lat: 54.5, lng: 12.5 }, { lat: 54.503, lng: 12.502 }, { lat: 54.506, lng: 12.5 }] }]);
  });
  await expect(page.locator('#routeAnimation')).toHaveClass(/visible/, { timeout: 10_000 });
  await page.waitForFunction(() => routeAnimation !== null && routeAnimation.overviewDone, null, { timeout: 30_000 });

  // Still open, straight from above at the main map's position and zoom; ⏸ marks the finished state.
  await page.waitForTimeout(1000);
  await expect(page.locator('#routeAnimation')).toBeVisible();
  const end = await page.evaluate(() => ({
    open: routeAnimation !== null,
    tilt: routeAnimation.map.getTilt(),
    zoom: routeAnimation.map.getZoom(),
    lat: routeAnimation.map.getCenter().lat(),
    mainZoom: map.getZoom(),
    mainLat: map.getCenter().lat(),
    markers: routeAnimation.markers.length,
  }));
  expect(end.open).toBe(true);
  expect(end.tilt).toBe(0);
  expect(end.zoom).toBeCloseTo(end.mainZoom, 3);
  expect(end.lat).toBeCloseTo(end.mainLat, 4);
  expect(end.markers).toBe(1);
  await expect(page.locator('#routeAnimationPauseBtn')).toHaveAttribute('aria-pressed', 'true');

  // ⏮: line and marker reset, it plays again from the first waypoint.
  await page.locator('#routeAnimationRestart').click();
  const restarted = await page.evaluate(() => ({ leg: routeAnimation.legIndex, markers: routeAnimation.markers.length, done: routeAnimation.done, overview: routeAnimation.overviewDone }));
  expect(restarted).toEqual({ leg: 0, markers: 0, done: false, overview: false });
  await expect(page.locator('#routeAnimationForward')).toHaveAttribute('aria-pressed', 'true');
  await page.waitForFunction(() => routeAnimation.done, null, { timeout: 30_000 });

  await page.locator('.route-animation-stop').click();
  await expect(page.locator('#routeAnimation')).toBeHidden({ timeout: 5_000 });
});
