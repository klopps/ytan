const { test, expect, settleMapAt } = require('../fixtures');

test.describe('POI creation', () => {
  test('creates a POI via the toolbar and a single map click', async ({ loggedInPage: page }) => {
    await settleMapAt(page, -10, -140, 15); // open South Pacific water - see settleMapAt()'s doc comment
    await page.locator('#poiButton').click();
    await expect(page.locator('#poiButton')).toHaveClass(/active/);

    const mapBox = await page.locator('#map').boundingBox();
    const clickX = mapBox.x + mapBox.width / 2;
    const clickY = mapBox.y + mapBox.height / 2;
    await page.mouse.click(clickX, clickY);

    await expect(page.locator('#editPoiName')).toBeVisible();
    await page.locator('#editPoiName').fill('E2E Test POI');
    // value="2" = "Landing Site" in the vendored poitype list - a plain
    // type with no extra required fields (unlike 1/11, which need a WSI
    // atlas code - see validatePoiEditForm() in poi.js).
    await page.locator('#editPoiType').selectOption('2');

    await expect(page.locator('#editPoiSaveBtn')).toBeEnabled();

    const [response] = await Promise.all([
      page.waitForResponse((res) => res.url().includes('/pois') && res.request().method() === 'POST'),
      page.locator('#editPoiSaveBtn').click(),
    ]);
    expect(response.ok()).toBeTruthy();
    const created = (await response.json()).data;
    expect(created.name).toBe('E2E Test POI');
    expect(created.poitype_id).toBe(2);
  });
});
