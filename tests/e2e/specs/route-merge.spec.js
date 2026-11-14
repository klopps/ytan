// @ts-check
/**
 * Merging two routes (route.js: routeContextMenuMergeRoute() -> pick the
 * second route on the map -> confirm-dialog.js: showRouteMergeDialog() ->
 * POST /routes/{id}/merge). The geometry rules themselves are covered by
 * PHPUnit (RouteMergerTest, RouteMergeControllerTest); this drives the UI
 * flow: context menu item, pick banner, dialog (name order follows the
 * resulting line), result.
 */
const { test, expect, settleMapAt } = require('../fixtures');

// Own patch of map, not used by any other spec.
const LAT = 54.9;
const LNG = 12.9;

async function createRoute(request, token, name, points) {
  const res = await request.post('/api/v1/routes', {
    headers: { Authorization: `Bearer ${token}` },
    data: { name, description: '', public: 0, length: 1000, points: JSON.stringify(points), color: '#BF409F' },
  });
  expect(res.ok()).toBeTruthy();
  return (await res.json()).data;
}

test('merging two routes via context menu, map pick and dialog', async ({ loggedInPage: page, request }) => {
  const token = await page.evaluate(() => localStorage.getItem('ytan_token'));
  const suffix = Date.now().toString(36);
  const nameA = 'E2E Merge A ' + suffix;
  const nameB = 'E2E Merge B ' + suffix;
  // A runs north to the shared point, B runs south to it (opposite direction).
  const a = await createRoute(request, token, nameA, [{ lat: LAT, lng: LNG }, { lat: LAT + 0.01, lng: LNG }]);
  const b = await createRoute(request, token, nameB, [{ lat: LAT + 0.02, lng: LNG }, { lat: LAT + 0.01, lng: LNG }]);

  await page.reload();
  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });

  // Right-click route A on its first vertex (only A is there).
  await settleMapAt(page, LAT, LNG, 15);
  let mapBox = await page.locator('#map').boundingBox();
  await page.mouse.click(mapBox.x + mapBox.width / 2, mapBox.y + mapBox.height / 2, { button: 'right' });
  await page.locator('.contextMenuItem', { hasText: 'call_merge' }).click();

  const banner = page.locator('#routeMergeBanner');
  await expect(banner).toBeVisible();
  await expect(banner).toContainText(nameA);

  // Tap route B on its first vertex.
  await settleMapAt(page, LAT + 0.02, LNG, 15);
  mapBox = await page.locator('#map').boundingBox();
  await page.mouse.click(mapBox.x + mapBox.width / 2, mapBox.y + mapBox.height / 2);

  await expect(banner).toBeHidden();
  const dialog = page.locator('.route-merge-dialog');
  await expect(dialog).toBeVisible();
  const name = dialog.locator('#routeMergeName');
  // A kept: A supplies the start point, so A comes first.
  await expect(name).toHaveValue(nameA + ' + ' + nameB);
  // B kept: B keeps its direction (south), A is turned round behind it.
  await dialog.locator('label[for="routeMergeKept1"]').click();
  await expect(name).toHaveValue(nameB + ' + ' + nameA);
  await expect(dialog).toContainText(nameA); // delete hint names A
  await dialog.locator('label[for="routeMergeKept0"]').click();
  await expect(name).toHaveValue(nameA + ' + ' + nameB);

  await dialog.locator('button.startbtn').click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('.toast-success')).toBeVisible();

  const kept = await request.get('/api/v1/routes/' + a.id, { headers: { Authorization: `Bearer ${token}` } });
  expect(kept.ok()).toBeTruthy();
  const route = (await kept.json()).data;
  expect(route.name).toBe(nameA + ' + ' + nameB);
  expect(JSON.parse(route.points).map((p) => p.lat)).toEqual([LAT, LAT + 0.01, LAT + 0.02]);

  const gone = await request.get('/api/v1/routes/' + b.id, { headers: { Authorization: `Bearer ${token}` } });
  expect(gone.status()).toBe(404);
});

test('the pick mode can be cancelled', async ({ loggedInPage: page, request }) => {
  const token = await page.evaluate(() => localStorage.getItem('ytan_token'));
  await createRoute(request, token, 'E2E Merge Cancel', [{ lat: LAT + 0.1, lng: LNG }, { lat: LAT + 0.11, lng: LNG }]);

  await page.reload();
  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
  await settleMapAt(page, LAT + 0.1, LNG, 15);
  const mapBox = await page.locator('#map').boundingBox();
  await page.mouse.click(mapBox.x + mapBox.width / 2, mapBox.y + mapBox.height / 2, { button: 'right' });
  await page.locator('.contextMenuItem', { hasText: 'call_merge' }).click();

  await expect(page.locator('#routeMergeBanner')).toBeVisible();
  await page.locator('.route-merge-banner-close').click();
  await expect(page.locator('#routeMergeBanner')).toBeHidden();
  await expect(page.locator('.route-merge-dialog')).toHaveCount(0);
});
