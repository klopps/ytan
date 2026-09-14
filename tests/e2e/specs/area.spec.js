const { test, expect, settleMapAt } = require('../fixtures');

test.describe('Area creation', () => {
  test('draws a triangular area via three map clicks and saves it', async ({ loggedInPage: page }) => {
    await settleMapAt(page, -20, -160, 15); // open South Pacific water - see settleMapAt()'s doc comment
    await page.locator('#areaButton').click();
    await expect(page.locator('#areaButton')).toHaveClass(/active/);

    const mapBox = await page.locator('#map').boundingBox();
    const centerX = mapBox.x + mapBox.width / 2;
    const centerY = mapBox.y + mapBox.height / 2;
    await page.mouse.click(centerX - 50, centerY + 40);
    await page.waitForTimeout(400); // see route.spec.js's identical comment on click/dblclick disambiguation
    await page.mouse.click(centerX + 50, centerY + 40);
    await page.waitForTimeout(400);
    await page.mouse.click(centerX, centerY - 40);

    await page.locator('#secondToolbar .second-toolbar-end-btn').click();

    await expect(page.locator('#editAreaName')).toBeVisible();
    await page.locator('#editAreaName').fill('E2E Test Area');

    await expect(page.locator('#editAreaSaveBtn')).toBeEnabled();

    const [response] = await Promise.all([
      page.waitForResponse((res) => res.url().includes('/areas') && res.request().method() === 'POST'),
      page.locator('#editAreaSaveBtn').click(),
    ]);
    expect(response.ok()).toBeTruthy();
    const created = (await response.json()).data;
    expect(created.name).toBe('E2E Test Area');
  });
});
