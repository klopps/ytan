const { test, expect, settleMapAt } = require('../fixtures');

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
});
