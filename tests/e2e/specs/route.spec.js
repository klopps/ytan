const { test, expect, settleMapAt, apiToken, apiCreate, reloadMap } = require('../fixtures');

test.describe('Route creation', () => {
  test('draws a route via two map clicks and saves it', async ({ loggedInPage: page }) => {
    await settleMapAt(page, -15, -150, 15); // open South Pacific water - see settleMapAt()'s doc comment
    await page.locator('#routeButton').click();
    await expect(page.locator('#routeButton')).toHaveClass(/active/);

    const mapBox = await page.locator('#map').boundingBox();
    const centerX = mapBox.x + mapBox.width / 2;
    const centerY = mapBox.y + mapBox.height / 2;
    await page.mouse.click(centerX - 40, centerY - 20);
    // A beat between the two clicks so Maps' own click/dblclick
    // disambiguation (~250-300ms) treats them as two separate vertices
    // instead of collapsing them into a dblclick (which zooms the map
    // instead of adding a second point).
    await page.waitForTimeout(400);
    await page.mouse.click(centerX + 40, centerY + 20);

    await page.locator('#secondToolbar [data-on-click^="showRouteEditWindow"]').click();

    await expect(page.locator('#editRouteName')).toBeVisible();
    await page.locator('#editRouteName').fill('E2E Test Route');

    await expect(page.locator('#editRouteSaveBtn')).toBeEnabled();

    const [response] = await Promise.all([
      // not the land check (/routes/autoroute/check) that runs first
      page.waitForResponse((res) => /\/routes$/.test(res.url()) && res.request().method() === 'POST'),
      page.locator('#editRouteSaveBtn').click(),
    ]);
    expect(response.ok()).toBeTruthy();
    const created = (await response.json()).data;
    expect(created.name).toBe('E2E Test Route');
  });

  test('a new route starts at length 0 after an unsaved one was closed', async ({ loggedInPage: page }) => {
    await settleMapAt(page, -15, -151, 15);
    const mapBox = await page.locator('#map').boundingBox();
    const centerX = mapBox.x + mapBox.width / 2;
    const centerY = mapBox.y + mapBox.height / 2;

    await page.locator('#routeButton').click();
    await page.mouse.click(centerX - 80, centerY - 20);
    await page.waitForTimeout(400);
    await page.mouse.click(centerX + 80, centerY + 20);
    await page.locator('#secondToolbar [data-on-click^="showRouteEditWindow"]').click();
    await expect(page.locator('#editRouteName')).toBeVisible();
    await page.locator('#editRouteSaveBtn').locator('xpath=..').locator('button', { hasText: /Abbrechen|Cancel/ }).click();
    await expect(page.locator('#editRouteName')).toBeHidden();

    await page.locator('#routeButton').click();
    await expect(page.locator('#secondToolbarLength')).toBeVisible();
    await expect(page.locator('#secondToolbarLength')).toHaveText(/^0(\.0+)?\s/);
  });

  test('ESC cancels drawing a new route, but not while the edit window is open', async ({ loggedInPage: page }) => {
    await settleMapAt(page, -15, -152, 15);
    const mapBox = await page.locator('#map').boundingBox();
    const centerX = mapBox.x + mapBox.width / 2;
    const centerY = mapBox.y + mapBox.height / 2;

    let saved = false;
    page.on('request', (req) => { if (/\/routes$/.test(req.url()) && req.method() === 'POST') saved = true; });

    await page.locator('#routeButton').click();
    await page.mouse.click(centerX - 60, centerY - 20);
    await page.waitForTimeout(400);
    await page.mouse.click(centerX + 60, centerY + 20);
    await expect.poll(() => page.evaluate(() => measureTool.points.length)).toBe(2);

    await page.keyboard.press('Escape');
    await expect(page.locator('#secondToolbar')).toBeHidden();
    await expect(page.locator('#routeButton')).not.toHaveClass(/active/);
    expect(await page.evaluate(() => (measureTool.points || []).length)).toBe(0);
    expect(saved).toBe(false);

    // a new line can be drawn right after an ESC
    await page.locator('#routeButton').click();
    // Maps sometimes drops a click that comes within milliseconds of the button click (also without
    // an ESC before) - a pause like a real user's
    await page.mouse.move(centerX - 60, centerY - 20);
    await page.waitForTimeout(600);
    await page.mouse.click(centerX - 60, centerY - 20);
    await page.waitForTimeout(400);
    await page.mouse.click(centerX + 60, centerY + 20);
    await expect.poll(() => page.evaluate(() => measureTool.points.length)).toBe(2);

    // with the edit window open our handler leaves the editor alone (called directly: Google Maps
    // closes an InfoWindow on ESC by itself, which is not ours to test)
    await page.locator('#secondToolbar [data-on-click^="showRouteEditWindow"]').click();
    await expect(page.locator('#editRouteName')).toBeVisible();
    await page.evaluate(() => editorKeydown({ key: 'Escape', target: document.body, preventDefault() {} }));
    await expect(page.locator('#editRouteName')).toBeVisible();
    await expect(page.locator('#routeButton')).toHaveClass(/active/);
  });

  test('ESC cancels changing an existing route and keeps its old line', async ({ loggedInPage: page, request }) => {
    const name = 'E2E ESC Route ' + Date.now();
    const points = [{ lat: -15, lng: -153.01 }, { lat: -15.005, lng: -152.99 }];
    await apiCreate(request, await apiToken(request), 'routes', {
      name, description: '', public: 0, length: 2400, points: JSON.stringify(points), color: '#BF409F',
    });
    await reloadMap(page);
    await page.waitForFunction((n) => routes.some((r) => r && r.name === n), name, { timeout: 20_000 });
    await settleMapAt(page, -15, -153, 14);

    await page.evaluate((n) => editRoute(routes.findIndex((r) => r && r.name === n), null, null), name);
    await expect(page.locator('#secondToolbarLength')).toBeVisible();
    const mapBox = await page.locator('#map').boundingBox();
    await page.mouse.click(mapBox.x + mapBox.width / 2 + 120, mapBox.y + mapBox.height / 2 - 80);
    await expect.poll(() => page.evaluate(() => measureTool.points.length)).toBe(3);

    await page.keyboard.press('Escape');
    await expect(page.locator('#secondToolbar')).toBeHidden();
    await expect(page.locator('#routeButton')).not.toHaveClass(/active/);
    expect(await page.evaluate((n) => routes.find((r) => r && r.name === n).points.length, name)).toBe(2);
  });
});
