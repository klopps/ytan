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

    await page.locator('#secondToolbar .second-toolbar-end-btn').click();

    await expect(page.locator('#editRouteName')).toBeVisible();
    await page.locator('#editRouteName').fill('E2E Test Route');

    await expect(page.locator('#editRouteSaveBtn')).toBeEnabled();

    const [response] = await Promise.all([
      page.waitForResponse((res) => res.url().includes('/routes') && res.request().method() === 'POST'),
      page.locator('#editRouteSaveBtn').click(),
    ]);
    expect(response.ok()).toBeTruthy();
    const created = (await response.json()).data;
    expect(created.name).toBe('E2E Test Route');
  });
});
