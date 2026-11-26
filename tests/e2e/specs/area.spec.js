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

  test('ESC cancels drawing an area', async ({ loggedInPage: page }) => {
    await settleMapAt(page, -20, -161, 15);
    let saved = false;
    page.on('request', (req) => { if (/\/areas$/.test(req.url()) && req.method() === 'POST') saved = true; });

    await page.locator('#areaButton').click();
    const mapBox = await page.locator('#map').boundingBox();
    const centerX = mapBox.x + mapBox.width / 2;
    const centerY = mapBox.y + mapBox.height / 2;
    await page.mouse.move(centerX - 50, centerY + 40);
    await page.waitForTimeout(600); // see route.spec.js: a click right after the button click can get lost
    await page.mouse.click(centerX - 50, centerY + 40);
    await page.waitForTimeout(400);
    await page.mouse.click(centerX + 50, centerY + 40);
    await page.waitForTimeout(400);
    await page.mouse.click(centerX, centerY - 40);
    await expect.poll(() => page.evaluate(() => measureTool.points.length)).toBeGreaterThanOrEqual(3);

    await page.keyboard.press('Escape');
    await expect(page.locator('#secondToolbar')).toBeHidden();
    await expect(page.locator('#areaButton')).not.toHaveClass(/active/);
    expect(await page.evaluate(() => (measureTool.points || []).length)).toBe(0);
    await expect(page.locator('#editAreaName')).toHaveCount(0);
    expect(saved).toBe(false);
  });
});
