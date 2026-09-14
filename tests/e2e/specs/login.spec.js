const { test, expect } = require('../fixtures');

/**
 * Drives the real login form (drawer -> Profile -> username/password ->
 * Login), unlike every other spec in this suite which logs in via a
 * direct API call for speed (see fixtures.js's loggedInPage). This is
 * the one place that form is actually exercised end to end.
 */
test.describe('Login', () => {
  test('logs in with valid credentials via the Profile screen', async ({ page, credentials, baseURL }) => {
    await page.context().addCookies([{ name: 'gdpr_accepted', value: 'yes', url: baseURL }]);
    await page.goto('/');
    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });

    await page.locator('#sidemenu-toggle').click();
    await page.locator('button[onclick="showUserWindow();"]').click();

    await expect(page.locator('#userLoginUsername')).toBeVisible();
    await page.locator('#userLoginUsername').fill(credentials.username);
    await page.locator('#userLoginPassword').fill(credentials.password);
    await page.locator('#userLoginBtn').click();

    await expect(page.locator('#profileRowSub')).toContainText(credentials.username, { timeout: 10_000 });
    await expect(page.locator('#poiButton')).not.toHaveClass(/disabled/);
  });

  test('shows an error toast for wrong credentials and does not log in', async ({ page, credentials, baseURL }) => {
    await page.context().addCookies([{ name: 'gdpr_accepted', value: 'yes', url: baseURL }]);
    await page.goto('/');
    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });

    await page.locator('#sidemenu-toggle').click();
    await page.locator('button[onclick="showUserWindow();"]').click();

    await page.locator('#userLoginUsername').fill(credentials.username);
    await page.locator('#userLoginPassword').fill('definitely-the-wrong-password');
    await page.locator('#userLoginBtn').click();

    await expect(page.locator('.toast')).toBeVisible({ timeout: 10_000 });
    expect(await page.evaluate(() => window.user && window.user.id)).toBeNull();
  });
});
