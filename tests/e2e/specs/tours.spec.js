const { test, expect } = require('../fixtures');

test.describe('Tours', () => {
  test('creates a tour and adds an existing route to it', async ({ loggedInPage: page, request }) => {
    const token = await page.evaluate(() => localStorage.getItem('ytan_token'));

    // The route itself isn't what's under test here (route.spec.js covers
    // creation) - seeding it directly keeps this test focused on the
    // Tours flow and avoids re-doing two map clicks just to get a route
    // to add.
    const routeResponse = await request.post('/api/v1/routes', {
      headers: { Authorization: `Bearer ${token}` },
      data: {
        name: 'E2E Tour Candidate Route',
        description: '',
        public: 0,
        length: 500,
        points: JSON.stringify([{ lat: 54.2, lng: 12.2 }, { lat: 54.21, lng: 12.21 }]),
        color: '#3388FF',
      },
    });
    expect(routeResponse.ok()).toBeTruthy();
    const route = (await routeResponse.json()).data;

    await page.locator('#sidemenu-toggle').click();
    await page.locator('button[onclick="openTourAdminMenu();"]').click();

    await page.locator('[onclick="showTourCreateForm();"]').click();
    await expect(page.locator('#tourFormName')).toBeVisible();
    await page.locator('#tourFormName').fill('E2E Test Tour');

    const [createResponse] = await Promise.all([
      page.waitForResponse((res) => res.url().endsWith('/tours') && res.request().method() === 'POST'),
      page.locator('#tourFormSaveBtn').click(),
    ]);
    expect(createResponse.ok()).toBeTruthy();
    const tour = (await createResponse.json()).data;

    await page.locator(`[onclick="manageTourRoutes(${tour.id});"]`).click();
    await expect(page.locator(`[onclick="addRouteToTour(${route.id});"]`)).toBeVisible({ timeout: 10_000 });
    await page.locator(`[onclick="addRouteToTour(${route.id});"]`).click();

    const [addResponse] = await Promise.all([
      page.waitForResponse((res) => res.url().includes(`/tours/${tour.id}/routes`) && res.request().method() === 'POST'),
      page.locator('#tourRouteManagerSaveBtn').click(),
    ]);
    expect(addResponse.ok()).toBeTruthy();

    const tourRoutes = await request.get(`/api/v1/routes?tour_id=${tour.id}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const routeIds = ((await tourRoutes.json()).data || []).map((r) => r.id);
    expect(routeIds).toContain(route.id);
  });
});
