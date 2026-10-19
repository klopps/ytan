const { test, expect, settleMapAt } = require('../fixtures');

/**
 * Drawer row "Weather": expands a submenu (forecast for the current location,
 * rain radar on/off, wind on/off) and pushes the rest of the menu down; one of
 * the three entries closes the drawer, the row itself collapses the submenu again.
 */
test.describe('Weather menu', () => {
  test('the row expands and collapses its submenu and pushes the menu down', async ({ loggedInPage: page }) => {
    await settleMapAt(page, 54.5, 11.5, 8);
    await page.locator('#sidemenu-toggle').click();
    const row = page.locator('#weatherMenuRow');
    const submenu = page.locator('#weatherSubmenu');
    const next = page.locator('button[data-on-click="showUserWindow();"]');

    await expect(submenu).toBeHidden();
    const before = (await next.boundingBox()).y;

    await row.click();
    await expect(submenu).toBeVisible();
    await expect(row).toHaveAttribute('aria-expanded', 'true');
    await expect(submenu.locator('button')).toHaveCount(3);
    expect((await next.boundingBox()).y).toBeGreaterThan(before + 60);

    await row.click();
    await expect(submenu).toBeHidden();
    await expect(row).toHaveAttribute('aria-expanded', 'false');
    expect((await next.boundingBox()).y).toBeCloseTo(before, 0);
  });

  test('an entry closes the drawer and switches the layer', async ({ loggedInPage: page }) => {
    await settleMapAt(page, 54.5, 11.5, 8);
    // the layers themselves are covered by radar.spec.js/wind.spec.js: only that the entries call them is under test
    await page.evaluate(() => {
        window.toggled = [];
        window.radarContextMenuToggle = () => window.toggled.push('radar');
        window.windContextMenuToggle = () => window.toggled.push('wind');
    });
    await page.locator('#sidemenu-toggle').click();
    await page.locator('#weatherMenuRow').click();
    await page.locator('button[data-on-click="weatherMenuRadar();"]').click();

    await expect.poll(() => page.evaluate(() => document.getElementById('sidemenu').style.marginLeft)).toBe('-335px');
    expect(await page.evaluate(() => window.toggled)).toEqual(['radar']);

    // reopened: back on the main menu with the submenu collapsed
    await page.waitForTimeout(700);
    await page.locator('#sidemenu-toggle').click();
    await expect(page.locator('#weatherSubmenu')).toBeHidden();
    await page.locator('#weatherMenuRow').click();
    await page.locator('button[data-on-click="weatherMenuWind();"]').click();
    await expect.poll(() => page.evaluate(() => document.getElementById('sidemenu').style.marginLeft)).toBe('-335px');
    expect(await page.evaluate(() => window.toggled)).toEqual(['radar', 'wind']);

    // the check mark follows the layer's state
    await page.evaluate(() => { settings.detailwind = true; });
    await page.waitForTimeout(700);
    await page.locator('#sidemenu-toggle').click();
    await page.locator('#weatherMenuRow').click();
    await expect(page.locator('#weatherMenuWindState')).toBeVisible();
    await expect(page.locator('#weatherMenuRadarState')).toBeHidden();
  });
});
