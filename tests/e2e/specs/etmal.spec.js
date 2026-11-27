const { test, expect, settleMapAt, apiToken } = require('../fixtures');

/**
 * Etmal: the user's distance per day in the profile, and the reach lines over
 * water from the map context menu (POST /water/reach, computed from the local
 * water index - so the point must lie inside its coverage: the Kiel Fjord).
 */
test.describe('Etmal', () => {
  test('the etmal is set in the profile and must be a valid number', async ({ loggedInPage: page, request }) => {
    await page.evaluate(() => { toggleMenu(); showUserWindow(); });
    await page.locator('#userEtmalBtn').click();
    const input = page.locator('#userEtmal');
    await expect(input).toBeVisible();
    expect(Number(await input.inputValue())).toBeGreaterThanOrEqual(5);

    // empty or out of range: the save button is off
    await input.fill('');
    await expect(page.locator('#userEtmalSaveBtn')).toBeDisabled();
    await input.fill('61');
    await expect(page.locator('#userEtmalSaveBtn')).toBeDisabled();

    await input.fill('20');
    await expect(page.locator('#userEtmalSaveBtn')).toBeEnabled();
    const saved = page.waitForResponse((res) => res.url().includes('/auth/etmal') && res.request().method() === 'PUT');
    await page.locator('#userEtmalSaveBtn').click();
    expect((await saved).ok()).toBeTruthy();

    const res = await request.get('/api/v1/auth/etmal', { headers: { Authorization: `Bearer ${await apiToken(request)}` } });
    expect((await res.json()).data.etmal_km).toBe(20);
  });

  test('the context menu draws the reach lines on the water, the x removes them', async ({ loggedInPage: page, request }) => {
    const token = await apiToken(request);
    await request.put('/api/v1/auth/etmal', { headers: { Authorization: `Bearer ${token}` }, data: { etmal_km: 10 } });
    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
    // the outer Kiel Fjord, on the water
    await settleMapAt(page, 54.43, 10.22, 11);

    const answer = page.waitForResponse((res) => res.url().includes('/water/reach'), { timeout: 60_000 });
    await page.evaluate(() => etmalShowAt(new google.maps.LatLng(54.43, 10.22)));
    const response = await answer;
    expect(response.ok()).toBeTruthy();
    const data = (await response.json()).data;
    expect(data.lines.map((l) => l.km)).toEqual([5, 10]);

    await expect(page.locator('#etmalBanner')).toBeVisible();
    await expect.poll(() => page.evaluate(() => etmalOverlays.length)).toBeGreaterThan(2);
    await expect(page.locator('.etmal-label').first()).toBeAttached();

    // ESC never removes them - also not when it cancels a route drawn over them
    const count = await page.evaluate(() => etmalOverlays.length);
    await page.locator('#routeButton').click();
    await expect(page.locator('#routeButton')).toHaveClass(/active/);
    await page.keyboard.press('Escape');
    await expect(page.locator('#routeButton')).not.toHaveClass(/active/);
    await page.keyboard.press('Escape');
    await expect(page.locator('#etmalBanner')).toBeVisible();
    expect(await page.evaluate(() => etmalOverlays.length)).toBe(count);

    await page.locator('#etmalBanner .route-merge-banner-close').click();
    await expect(page.locator('#etmalBanner')).toBeHidden();
    expect(await page.evaluate(() => etmalOverlays.length)).toBe(0);
  });

  test('with wind and current: the sheet asks again on every change, the lines are hours', async ({ loggedInPage: page, request }) => {
    const token = await apiToken(request);
    await request.put('/api/v1/auth/etmal', { headers: { Authorization: `Bearer ${token}` }, data: { etmal_km: 10 } });
    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
    await settleMapAt(page, 54.43, 10.22, 11);

    const isTimed = (res) => res.url().includes('/water/reach') && (res.request().postData() || '').includes('"timed"');
    let answer = page.waitForResponse((res) => res.url().includes('/water/reach'), { timeout: 60_000 });
    await page.evaluate(() => etmalShowAt(new google.maps.LatLng(54.43, 10.22)));
    await answer;
    await expect(page.locator('#etmalBannerTimed')).toBeVisible();

    answer = page.waitForResponse(isTimed, { timeout: 90_000 });
    await page.locator('#etmalBannerTimed').click();
    await expect(page.locator('#etmalSheet')).toBeVisible();
    await expect(page.locator('#etmalBanner')).toBeHidden();
    const response = await answer;
    expect(response.ok()).toBeTruthy();
    const data = (await response.json()).data;
    expect(data.mode).toBe('timed');
    expect(data.end - data.start).toBe(data.duration_min * 60);
    expect(data.lines[data.lines.length - 1].main).toBe(true);
    await expect(page.locator('.etmal-summary')).toBeVisible();
    await expect.poll(() => page.locator('.etmal-label').allTextContents()).toContain('1 h');

    // a later start: a new request with a start one hour later
    answer = page.waitForResponse(isTimed, { timeout: 90_000 });
    await page.locator('#etmalSheet .rc-chip', { hasText: '+1 h' }).click();
    const later = (await (await answer).json()).data;
    expect(later.start).toBe(data.start + 3600);

    // closing the sheet keeps the lines, the banner offers to change them; its x removes everything
    await page.locator('#etmalSheet .rc-close').click();
    await expect(page.locator('#etmalSheet')).toBeHidden();
    await expect(page.locator('#etmalBanner')).toBeVisible();
    expect(await page.evaluate(() => etmalOverlays.length)).toBeGreaterThan(2);
    await page.locator('#etmalBanner .route-merge-banner-close').click();
    await expect(page.locator('#etmalBanner')).toBeHidden();
    expect(await page.evaluate(() => etmalOverlays.length)).toBe(0);
  });

  test('a point on land gets a notice, no lines', async ({ loggedInPage: page }) => {
    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
    const answer = page.waitForResponse((res) => res.url().includes('/water/reach'), { timeout: 60_000 });
    // Kiel city centre
    await page.evaluate(() => etmalShowAt(new google.maps.LatLng(54.3233, 10.1228)));
    expect((await answer).status()).toBe(422);
    await expect(page.locator('#etmalBanner')).toBeHidden();
  });
});
