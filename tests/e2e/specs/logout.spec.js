// @ts-check
/**
 * Signing out and in again in the same page: the user's private POI, route
 * and area must leave the map (until 2026-11-15 the private areas stayed -
 * nothing removed the old polygons) and come back after signing in through
 * the form (signing in used to reload POIs and routes only).
 */
const { test, expect, apiCreate, readCredentials } = require('../fixtures');

// Own patch of map, not used by any other spec.
const LAT = 57.1;
const LNG = 14.8;

/** Which of the named private things are loaded, and which of them are drawn on the map. */
function presence(page, names) {
  return page.evaluate((n) => {
    const routeIndex = routes.findIndex((r) => r && r.name === n.route);
    const areaIndex = areas.findIndex((a) => a && a.name === n.area);
    return {
      poi: pois.some((p) => p && p.name === n.poi),
      route: routeIndex >= 0,
      area: areaIndex >= 0,
      routeDrawn: routePaths.some((p) => p && p.routePathLine.getMap() && routes[routePaths.indexOf(p)]?.name === n.route),
      // polygons of areas no longer in areas[] would still be on the map without deleteAreas()
      areaPolygonsOnMap: areaPolygons.filter((p) => p && p.getMap()).length,
      areasLoaded: areas.filter((a) => a).length,
    };
  }, names);
}

test('signing out removes the private POI, route and area; signing in brings them back', async ({ loggedInPage: page, request }) => {
  const t = await page.evaluate(() => localStorage.getItem('ytan_token'));
  const s = Date.now().toString(36);
  const names = { poi: 'E2E Private POI ' + s, route: 'E2E Private Route ' + s, area: 'E2E Private Area ' + s };
  await apiCreate(request, t, 'pois', { poitype_id: 2, name: names.poi, description: '', public: 0, latitude: LAT, longitude: LNG, url: '' });
  await apiCreate(request, t, 'routes', {
    name: names.route, description: '', public: 0, length: 1000, color: '#BF409F',
    points: JSON.stringify([{ lat: LAT, lng: LNG }, { lat: LAT + 0.01, lng: LNG + 0.01 }]),
  });
  await apiCreate(request, t, 'areas', {
    name: names.area, description: '', public: 0, color: '#3388FF', opacity: 0.35, zindex: 1,
    points: JSON.stringify([{ lat: LAT, lng: LNG }, { lat: LAT + 0.01, lng: LNG }, { lat: LAT, lng: LNG + 0.01 }]),
  });

  await page.reload();
  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
  await expect.poll(async () => {
    const p = await presence(page, names);
    return p.poi && p.route && p.area;
  }, { timeout: 20_000 }).toBe(true);

  // sign out through the drawer
  await page.locator('#sidemenu-toggle').click();
  await page.locator('button[data-on-click="showUserWindow();"]').click();
  await page.locator('#userLogoutBtn').click();

  await expect.poll(() => page.evaluate(() => user.id), { timeout: 10_000 }).toBeNull();
  expect(await page.evaluate(() => localStorage.getItem('ytan_token'))).toBeFalsy();
  await expect(page.locator('#adminMenuBtn')).toBeHidden();
  await expect.poll(async () => {
    const p = await presence(page, names);
    return { poi: p.poi, route: p.route, area: p.area, routeDrawn: p.routeDrawn, extraPolygons: p.areaPolygonsOnMap > p.areasLoaded };
  }, { timeout: 20_000 }).toEqual({ poi: false, route: false, area: false, routeDrawn: false, extraPolygons: false });

  // sign in again through the form - private things are back, areas included
  const creds = readCredentials();
  // logoutUser() closes the profile screen; the drawer may still be open on its main screen
  const profileRow = page.locator('button[data-on-click="showUserWindow();"]');
  if (!(await profileRow.isVisible())) {
    await page.locator('#sidemenu-toggle').click();
  }
  await profileRow.click();
  await expect(page.locator('#userLoginUsername')).toBeVisible();
  await page.locator('#userLoginUsername').fill(creds.username);
  await page.locator('#userLoginPassword').fill(creds.password);
  await page.locator('#userLoginBtn').click();
  await expect.poll(() => page.evaluate(() => user.id), { timeout: 10_000 }).not.toBeNull();
  await expect.poll(async () => {
    const p = await presence(page, names);
    return p.poi && p.route && p.area;
  }, { timeout: 20_000 }).toBe(true);
});
