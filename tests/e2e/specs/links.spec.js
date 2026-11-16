// @ts-check
/**
 * Shared links (shareMap/shareRoute/shareTour): templates/app.php moves the
 * query into sessionStorage and cleans the address bar before anything
 * runs; initMap() then reads it through linkParam(). The link is opened as
 * the FIRST load of a signed-in page (loginInBrowser with a path), like a
 * tapped link.
 */
const { test, expect, apiToken, apiCreate, loginInBrowser } = require('../fixtures');

// Own patch of map, not used by any other spec.
const LAT = 58.1;
const LNG = 16.0;

test.describe('Shared links', () => {
  test('a map link opens at its position and zoom, with a clean address bar', async ({ page, request, baseURL }) => {
    await loginInBrowser(page, request, baseURL, `/?z=12&lat=${LAT}&lng=${LNG}`);

    expect(new URL(page.url()).search).toBe('');
    const view = await page.evaluate(() => ({ zoom: map.getZoom(), lat: map.getCenter().lat(), lng: map.getCenter().lng() }));
    expect(view.zoom).toBe(12);
    expect(Math.abs(view.lat - LAT)).toBeLessThan(0.001);
    expect(Math.abs(view.lng - LNG)).toBeLessThan(0.001);
    // not replayed on a reload
    expect(await page.evaluate(() => sessionStorage.getItem('ytan_pending_link'))).toBeNull();
  });

  test('a tour link starts Tour Mode with only the tour\'s routes', async ({ page, request, baseURL }) => {
    const t = await apiToken(request);
    const s = Date.now().toString(36);
    const inTour = await apiCreate(request, t, 'routes', {
      name: 'E2E Link Tour Route ' + s, description: '', public: 0, length: 1000, color: '#BF409F',
      points: JSON.stringify([{ lat: LAT + 0.1, lng: LNG }, { lat: LAT + 0.11, lng: LNG + 0.01 }]),
    });
    await apiCreate(request, t, 'routes', {
      name: 'E2E Link Other Route ' + s, description: '', public: 0, length: 1000, color: '#BF409F',
      points: JSON.stringify([{ lat: LAT + 0.2, lng: LNG }, { lat: LAT + 0.21, lng: LNG + 0.01 }]),
    });
    const tourName = 'E2E Link Tour ' + s;
    const tour = await apiCreate(request, t, 'tours', { name: tourName, description: '', tags: [] });
    await apiCreate(request, t, 'tours/' + tour.id + '/routes', { route_id: inTour.id });

    await loginInBrowser(page, request, baseURL, '/?tour=' + tour.id);

    await expect(page.locator('#tourModeBadge')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#tourModeBadgeName')).toHaveText(tourName);
    await expect.poll(() => page.evaluate(() => routes.filter((r) => r).map((r) => r.id)), { timeout: 15_000 }).toEqual([inTour.id]);
  });

  test('a link to a tour that does not exist says so and starts no Tour Mode', async ({ page, request, baseURL }) => {
    await loginInBrowser(page, request, baseURL, '/?tour=999999999');

    await expect(page.locator('#toastContainer .toast-error')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#tourModeBadge')).toBeHidden();
    expect(await page.evaluate(() => activeTourModeId)).toBeNull();
  });

  test('a route link shows the route, a missing one says so', async ({ page, request, baseURL }) => {
    const t = await apiToken(request);
    const points = [{ lat: LAT + 0.3, lng: LNG + 0.3 }, { lat: LAT + 0.32, lng: LNG + 0.33 }];
    const route = await apiCreate(request, t, 'routes', {
      name: 'E2E Link Route ' + Date.now().toString(36), description: '', public: 0, length: 3000, color: '#BF409F',
      points: JSON.stringify(points),
    });

    await loginInBrowser(page, request, baseURL, '/?route=' + route.id);
    await expect.poll(() => page.evaluate((p) => {
      const b = map.getBounds();
      return !!b && p.every((pt) => b.contains(pt)) && map.getZoom() >= 10;
    }, points), { timeout: 15_000 }).toBe(true);

    await page.goto('/?route=999999999');
    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
    await expect(page.locator('#toastContainer .toast-error')).toBeVisible({ timeout: 15_000 });
  });
});
