// @ts-check
/**
 * Autorouting (route.js: routeContextMenuAutoroute() -> POST
 * /routes/autoroute/check -> confirm-dialog.js: showAutorouteDialog() ->
 * POST /routes/autoroute -> the result lands in the route editor). The
 * routing itself is PHPUnit's (AutorouterTest, AutorouteControllerTest) - the
 * e2e database has no water index - so the two routing endpoints are
 * answered by the test; this drives the UI flow.
 */
const { test, expect, settleMapAt } = require('../fixtures');

// Own patch of map, not used by any other spec.
const LAT = 54.6;
const LNG = 12.6;

async function createRoute(request, token, name) {
  const points = [{ lat: LAT, lng: LNG }, { lat: LAT + 0.01, lng: LNG }];
  const res = await request.post('/api/v1/routes', {
    headers: { Authorization: `Bearer ${token}` },
    data: { name, description: '', public: 0, length: 1100, points: JSON.stringify(points), color: '#BF409F' },
  });
  expect(res.ok()).toBeTruthy();
  return (await res.json()).data;
}

async function openAutorouteOnRoute(page) {
  await settleMapAt(page, LAT, LNG, 15);
  const box = await page.locator('#map').boundingBox();
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2, { button: 'right' });
  await page.locator('.contextMenuItem', { hasText: 'alt_route' }).click();
}

test('start or end on land: a notice, the dialog never opens', async ({ loggedInPage: page, request }) => {
  const token = await page.evaluate(() => localStorage.getItem('ytan_token'));
  await createRoute(request, token, 'E2E Autoroute land ' + Date.now().toString(36));
  await page.reload();
  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });

  await page.route('**/api/v1/routes/autoroute/check', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: { ok: false, problems: ['start_on_land'] } }),
  }));

  await openAutorouteOnRoute(page);

  await expect(page.locator('.toast-warning')).toContainText(/Land|land/);
  await expect(page.locator('.route-merge-dialog')).toHaveCount(0);
});

test('dialog, result in the route editor', async ({ loggedInPage: page, request }) => {
  const token = await page.evaluate(() => localStorage.getItem('ytan_token'));
  const route = await createRoute(request, token, 'E2E Autoroute ' + Date.now().toString(36));
  await page.reload();
  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });

  let posted = null;
  await page.route('**/api/v1/routes/autoroute/check', (r) => r.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ok: true, problems: [] } }),
  }));
  await page.route('**/api/v1/routes/autoroute', (r) => {
    posted = r.request().postDataJSON();
    r.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: {
        points: [{ lat: LAT, lng: LNG }, { lat: LAT + 0.005, lng: LNG + 0.004 }, { lat: LAT + 0.01, lng: LNG }],
        changed_sections: 1, length_before: 1100, length_after: 1300, warnings: [],
      } }),
    });
  });

  await openAutorouteOnRoute(page);

  const dialog = page.locator('.route-merge-dialog');
  await expect(dialog).toBeVisible();
  const shore = dialog.locator('#autorouteShore');
  await expect(shore).toHaveValue('100'); // the admin default of the e2e database
  await expect(shore).toBeEnabled();
  await dialog.locator('label[for="autorouteEnabled"]').click();
  await expect(shore).toBeDisabled();
  await dialog.locator('label[for="autorouteEnabled"]').click();
  await shore.fill('150');
  const max = dialog.locator('#autorouteShoreMax');
  await expect(max).toHaveValue('1000'); // the admin default of the e2e database
  await max.fill('600');

  await dialog.locator('button.startbtn').click();
  await expect(dialog).toBeHidden();

  await expect(page.locator('.toast-success')).toBeVisible();
  expect(posted.shore_m).toBe(150);
  expect(posted.max_m).toBe(600);
  expect(posted.enabled).toBe(true);
  expect(posted.points).toHaveLength(2);
  // the new line is in the route editor, the saved route is untouched until the user saves
  await expect(page.locator('#secondToolbar')).toBeVisible();
  const stored = await request.get('/api/v1/routes/' + route.id, { headers: { Authorization: `Bearer ${token}` } });
  expect(JSON.parse((await stored.json()).data.points)).toHaveLength(2);
});

test('cancelling the dialog changes nothing', async ({ loggedInPage: page, request }) => {
  const token = await page.evaluate(() => localStorage.getItem('ytan_token'));
  await createRoute(request, token, 'E2E Autoroute cancel ' + Date.now().toString(36));
  await page.reload();
  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });

  await page.route('**/api/v1/routes/autoroute/check', (r) => r.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ok: true, problems: [] } }),
  }));
  let routed = false;
  await page.route('**/api/v1/routes/autoroute', (r) => { routed = true; r.abort(); });

  await openAutorouteOnRoute(page);
  const dialog = page.locator('.route-merge-dialog');
  await expect(dialog).toBeVisible();
  await dialog.locator('button.button').click();
  await expect(dialog).toBeHidden();
  expect(routed).toBe(false);
});

/* ------------------------------------------------ Autorouting in the route editor
 *
 * Toolbar button, live toggle ("am Wasser entlang"), the question before
 * saving and the narrow-phone layout - routing answers are made up by the
 * test (see top).
 */

const DETOUR = [{ lat: LAT, lng: LNG }, { lat: LAT + 0.002, lng: LNG + 0.004 }, { lat: LAT + 0.004, lng: LNG }];

async function drawTwoWaypoints(page) {
  await settleMapAt(page, LAT, LNG, 15);
  await page.locator('#routeButton').click();
  await expect(page.locator('#routeButton')).toHaveClass(/active/);
  const box = await page.locator('#map').boundingBox();
  await page.mouse.click(box.x + box.width / 2 - 60, box.y + box.height / 2);
  await page.waitForTimeout(400);
  await page.mouse.click(box.x + box.width / 2 + 60, box.y + box.height / 2);
  await page.waitForFunction(() => measureTool.points && measureTool.points.length === 2);
}

test('the edit window button puts the drawn line onto water', async ({ loggedInPage: page }) => {
  await page.route('**/api/v1/routes/autoroute/check', (r) => r.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ok: true, problems: [] } }),
  }));
  await page.route('**/api/v1/routes/autoroute', (r) => r.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: { points: DETOUR, changed_sections: 1, length_before: 900, length_after: 1000, warnings: [] } }),
  }));
  await drawTwoWaypoints(page);

  // the button of the route edit window (the toolbar has none)
  await page.locator('#secondToolbar [data-on-click^="showRouteEditWindow"]').click();
  await page.locator('[data-on-click="autorouteEditorLine()"]').click();
  await page.locator('.route-merge-dialog button.startbtn').click();

  await expect(page.locator('.toast-success')).toBeVisible();
  expect(await page.evaluate(() => measureTool.points.length)).toBe(3);
});

test('live mode connects a new last waypoint over water: one segment, saved preferences', async ({ loggedInPage: page }) => {
  let posted = null;
  await page.route('**/api/v1/routes/autoroute', (r) => {
    posted = r.request().postDataJSON();
    r.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { points: [posted.points[0], { lat: LAT + 0.001, lng: LNG + 0.001 }, posted.points[1]], changed_sections: 1, length_before: 100, length_after: 130, warnings: [] } }),
    });
  });
  await settleMapAt(page, LAT, LNG, 15);
  await page.locator('#routeButton').click();
  await page.locator('label[for="secondToolbarLiveBtn"]').click();
  await expect(page.locator('#secondToolbarLiveBtn')).toBeChecked();

  const box = await page.locator('#map').boundingBox();
  await page.mouse.click(box.x + box.width / 2 - 60, box.y + box.height / 2);
  await page.waitForTimeout(400);
  await page.mouse.click(box.x + box.width / 2 + 60, box.y + box.height / 2);

  await page.waitForFunction(() => measureTool.points && measureTool.points.length === 3);
  expect(posted.segment).toBe(true);
  expect(posted.points).toHaveLength(2);
  expect(posted.shore_m).toBeUndefined(); // the server uses the saved preferences
});

test('the question before saving: a line over land can be put on water', async ({ loggedInPage: page }) => {
  await page.route('**/api/v1/routes/autoroute/check', (r) => r.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ok: true, problems: [], land_m: 800 } }),
  }));
  await page.route('**/api/v1/routes/autoroute', (r) => r.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data: { points: DETOUR, changed_sections: 1, length_before: 900, length_after: 1000, warnings: [] } }),
  }));
  await drawTwoWaypoints(page);
  await page.locator('#secondToolbar [data-on-click^="showRouteEditWindow"]').click();
  await page.locator('#editRouteName').fill('E2E land question');

  await page.locator('#editRouteSaveBtn').click();
  const question = page.locator('.confirm-dialog');
  await expect(question).toBeVisible();
  await expect(question).toContainText('800');
  await question.locator('button.startbtn').click(); // yes, put it on water
  await page.locator('.route-merge-dialog button.startbtn').click();

  await expect(page.locator('.toast-success').last()).toBeVisible();
  expect(await page.evaluate(() => measureTool.points.length)).toBe(3);
});

test('narrow phone: the editor toolbar keeps clear of the search button', async ({ loggedInPage: page }) => {
  await page.setViewportSize({ width: 360, height: 640 });
  await settleMapAt(page, LAT, LNG, 15);
  await page.locator('#routeButton').click();
  await expect(page.locator('#secondToolbar')).toBeVisible();

  const toolbar = await page.locator('#secondToolbar').boundingBox();
  const search = await page.locator('#mapSearchWrapper').boundingBox();
  // old position: top row, directly left of the edit toolbar (the English "DONE"
  // makes it a bit wider than the space beside the search button at 360 px)
  const edit = await page.locator('#editToolbar').boundingBox();
  expect(Math.abs(toolbar.x + toolbar.width + 8 - edit.x)).toBeLessThan(2);
  expect(toolbar.y).toBeLessThan(edit.y + 4);
  expect(search.width).toBeGreaterThan(0);
  await expect(page.locator('#secondToolbar .second-toolbar-end-btn', { hasText: /DONE|BEENDEN/ })).toBeVisible();
  await expect(page.locator('label[for="secondToolbarLiveBtn"]')).toBeVisible();
});
