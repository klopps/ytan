const { test, expect, apiToken, apiCreate, reloadMap } = require('../fixtures');

/**
 * Routencheck: consent, the bottom sheet with the traffic light the server
 * really computes (text: false), the river gauge lines, the experience level
 * (chips in the sheet and the profile row). The AI text is NEVER requested
 * from the server: the second request (text: true) is sent on as text: false
 * and gets a fixed text attached - no AI costs. Gauges are added to the real
 * answer, since the test route lies in the open Pacific (no river, no
 * PEGELONLINE call).
 */
test.describe('Routencheck', () => {
  let token;

  test.beforeAll(async ({ request }) => {
    token = await apiToken(request);
    // the feature is switched off in a fresh e2e database
    const res = await request.put('/api/v1/settings/ai', {
      headers: { Authorization: `Bearer ${token}` },
      data: { enabled: true, daily_limit: 20, cache_hours: 3 },
    });
    expect(res.ok(), `enabling the Routencheck: ${res.status()}`).toBeTruthy();
  });

  test.afterAll(async ({ request }) => {
    await request.put('/api/v1/settings/ai', {
      headers: { Authorization: `Bearer ${token}` },
      data: { enabled: false, daily_limit: 20, cache_hours: 3 },
    });
  });

  /** Answers the briefing requests: text: false from the server plus a gauge, text: true faked. */
  async function stubBriefing(page, bodies) {
    await page.route('**/api/v1/routes/*/briefing', async (route) => {
      const body = route.request().postDataJSON();
      bodies.push(body);
      const response = await route.fetch({ postData: JSON.stringify({ ...body, text: false }) });
      const json = await response.json();
      json.data.current.gauges = [{ station: 'Hitzacker', water: 'Elbe', distance_m: 4200, value_cm: 512, time: '2026-11-19 04:50', state: 'high' }];
      json.data.current.gauges_rated = true;
      if (body.text) {
        Object.assign(json.data, { text: '**E2E-Einschätzung** ohne KI.', text_status: 'ok', text_cached: false });
      }
      await route.fulfill({ response, json });
    });
  }

  test('opens the sheet with traffic light, gauges, level chips and the faked text', async ({ loggedInPage: page, request }) => {
    const points = [{ lat: -15.0, lng: -150.0 }, { lat: -15.03, lng: -150.0 }, { lat: -15.06, lng: -150.01 }];
    await apiCreate(request, token, 'routes', { name: 'E2E Routencheck', description: '', public: 0, length: 6700, points: JSON.stringify(points), color: '#BF409F' });
    const bodies = [];
    await stubBriefing(page, bodies);
    await page.evaluate(() => { try { localStorage.removeItem(ROUTE_CHECK_CONSENT_KEY); } catch (e) { /* none */ } });
    await reloadMap(page);
    await page.waitForFunction(() => window.YTAN_ROUTE_CHECK_ENABLED === true && routes.some((r) => r && r.name === 'E2E Routencheck'));

    // not awaited inside the page: openRouteCheck() waits for the consent dialog
    await page.evaluate(() => { openRouteCheck(routes.findIndex((r) => r && r.name === 'E2E Routencheck')); });
    // first use: the consent dialog
    await expect(page.locator('.confirm-dialog-message')).toBeVisible();
    await page.locator('.confirm-dialog .startbtn').click();

    const sheet = page.locator('#routeCheckSheet');
    await expect(sheet).toBeVisible();
    await expect(sheet.locator('.rc-verdict-label')).toBeVisible({ timeout: 20_000 });
    await expect(sheet.locator('.rc-note', { hasText: 'Hitzacker' })).toContainText('Elbe');
    await expect(sheet).toContainText('WSV, PEGELONLINE');
    await expect(sheet.locator('.rc-text')).toContainText('E2E-Einschätzung', { timeout: 20_000 });
    expect(bodies.map((b) => b.text)).toEqual([false, true]);

    // the level chips send the chosen level and store it
    const stored = page.waitForResponse((res) => res.url().includes('/auth/paddle-level') && res.request().method() === 'PUT');
    // by its handler, not its label (the page language follows the browser)
    await sheet.locator('.rc-chip[data-on-click*="routeCheckSetLevel(\'expert\')"]').click();
    expect((await stored).ok()).toBeTruthy();
    await expect(sheet.locator('.rc-chip.active[data-on-click*="routeCheckSetLevel(\'expert\')"]')).toBeVisible({ timeout: 20_000 });
    expect(bodies.some((b) => b.level === 'expert')).toBeTruthy();

    await page.evaluate(() => closeRouteCheck());
    await expect(sheet).toBeHidden();
  });

  test('the experience level can be set in the profile too', async ({ loggedInPage: page, request }) => {
    await page.evaluate(() => { toggleMenu(); showUserWindow(); });
    await page.locator('#userPaddleLevelBtn').click();
    await expect(page.locator('#userPaddleLevel-beginner')).toBeAttached();
    // the stored level is checked; pick another one (a click on the checked one changes nothing)
    const current = await page.locator('input[name="userPaddleLevel"]:checked').getAttribute('value');
    const next = current === 'beginner' ? 'experienced' : 'beginner';

    const saved = page.waitForResponse((res) => res.url().includes('/auth/paddle-level') && res.request().method() === 'PUT');
    await page.locator(`label[for="userPaddleLevel-${next}"]`).click();
    expect((await saved).ok()).toBeTruthy();

    const res = await request.get('/api/v1/auth/paddle-level', { headers: { Authorization: `Bearer ${token}` } });
    expect((await res.json()).data.paddle_level).toBe(next);
  });
});
