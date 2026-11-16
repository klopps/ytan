// @ts-check
/**
 * Editing and deleting existing POIs, routes and areas through their context
 * menus - the creating specs (poi/route/area.spec.js) and context-menu.spec.js
 * only open the menus. Plus the captcha that guards deleting a route that is
 * part of a tour, and splitting a route at an inner waypoint. Data is seeded
 * through the API; the result is checked through the API too (a save goes
 * through the offline queue, enqueueChange + sync, so it is polled for).
 */
const {
  test, expect, settleMapAt, apiCreate, apiGet, reloadMap, rightClickMapCentre, showPoiMarker, POI_MARKER_OFFSET,
} = require('../fixtures');

// Own patch of map, not used by any other spec; each test its own spot in it.
const LAT = 56.1;
const LNG = 13.6;

const suffix = () => Date.now().toString(36);
const token = (page) => page.evaluate(() => localStorage.getItem('ytan_token'));

function poiData(name, lat, lng) {
  return { poitype_id: 2, name, description: '', public: 0, latitude: lat, longitude: lng, url: '' };
}

function routeData(name, points) {
  return { name, description: '', public: 0, length: 1000, points: JSON.stringify(points), color: '#BF409F' };
}

function areaData(name, lat, lng) {
  const points = [{ lat, lng }, { lat: lat + 0.01, lng }, { lat, lng: lng + 0.01 }];
  return { name, description: '', public: 0, points: JSON.stringify(points), color: '#3388FF', opacity: 0.35, zindex: 1 };
}

test.describe('Edit and delete', () => {
  test('a POI is renamed and described through its edit window', async ({ loggedInPage: page, request }) => {
    const t = await token(page);
    const lat = LAT;
    const lng = LNG;
    const name = 'E2E Edit POI ' + suffix();
    const poi = await apiCreate(request, t, 'pois', poiData(name, lat, lng));

    await reloadMap(page);
    await showPoiMarker(page, name, lat, lng);
    await rightClickMapCentre(page, POI_MARKER_OFFSET);
    await page.locator('.contextMenuItem', { hasText: 'edit' }).first().click();

    await expect(page.locator('#editPoiName')).toBeVisible();
    await page.locator('#editPoiName').fill(name + ' renamed');
    await page.locator('#editPoiDescription').fill('Steg mit Wasserstelle');
    await page.locator('#editPoiSaveBtn').click();

    await expect.poll(async () => (await apiGet(request, t, 'pois/' + poi.id)).data?.name, { timeout: 15_000 }).toBe(name + ' renamed');
    expect((await apiGet(request, t, 'pois/' + poi.id)).data.description).toBe('Steg mit Wasserstelle');
  });

  test('a route\'s and an area\'s name are changed through "Edit info"', async ({ loggedInPage: page, request }) => {
    const t = await token(page);
    const routeLat = LAT + 0.05;
    const areaLat = LAT + 0.1;
    const routeName = 'E2E Edit Route ' + suffix();
    const areaName = 'E2E Edit Area ' + suffix();
    const route = await apiCreate(request, t, 'routes', routeData(routeName, [{ lat: routeLat, lng: LNG }, { lat: routeLat + 0.01, lng: LNG + 0.01 }]));
    const area = await apiCreate(request, t, 'areas', areaData(areaName, areaLat, LNG));

    await reloadMap(page);
    await page.waitForFunction((n) => routes.some((r) => r && r.name === n), routeName, { timeout: 20_000 });
    await settleMapAt(page, routeLat, LNG, 15);
    await rightClickMapCentre(page);
    await page.locator('.contextMenuItem', { hasText: 'description' }).click();
    await expect(page.locator('#editRouteName')).toBeVisible();
    await page.locator('#editRouteName').fill(routeName + ' renamed');
    await page.locator('#editRouteSaveBtn').click();
    await expect.poll(async () => (await apiGet(request, t, 'routes/' + route.id)).data?.name, { timeout: 15_000 }).toBe(routeName + ' renamed');

    await page.waitForFunction((n) => areas.some((a) => a && a.name === n), areaName, { timeout: 20_000 });
    await settleMapAt(page, areaLat, LNG, 15);
    await rightClickMapCentre(page);
    await page.locator('.contextMenuItem', { hasText: 'description' }).click();
    await expect(page.locator('#editAreaName')).toBeVisible();
    await page.locator('#editAreaName').fill(areaName + ' renamed');
    await page.locator('#editAreaSaveBtn').click();
    await expect.poll(async () => (await apiGet(request, t, 'areas/' + area.id)).data?.name, { timeout: 15_000 }).toBe(areaName + ' renamed');
  });

  test('a POI and an area are deleted after confirming', async ({ loggedInPage: page, request }) => {
    const t = await token(page);
    const poiLat = LAT + 0.15;
    const areaLat = LAT + 0.2;
    const poiName = 'E2E Delete POI ' + suffix();
    const areaName = 'E2E Delete Area ' + suffix();
    const poi = await apiCreate(request, t, 'pois', poiData(poiName, poiLat, LNG));
    const area = await apiCreate(request, t, 'areas', areaData(areaName, areaLat, LNG));

    await reloadMap(page);
    await showPoiMarker(page, poiName, poiLat, LNG);
    await rightClickMapCentre(page, POI_MARKER_OFFSET);
    await page.locator('.contextMenuItem', { hasText: 'delete' }).click();
    await page.locator('.confirm-dialog .startbtn').click();
    await expect.poll(async () => (await apiGet(request, t, 'pois/' + poi.id)).status, { timeout: 15_000 }).toBe(404);

    await page.waitForFunction((n) => areas.some((a) => a && a.name === n), areaName, { timeout: 20_000 });
    await settleMapAt(page, areaLat, LNG, 15);
    await rightClickMapCentre(page);
    await page.locator('.contextMenuItem', { hasText: 'delete' }).click();
    await page.locator('.confirm-dialog .startbtn').click();
    await expect.poll(async () => (await apiGet(request, t, 'areas/' + area.id)).status, { timeout: 15_000 }).toBe(404);
  });

  test('deleting a route of a tour asks for the captcha first', async ({ loggedInPage: page, request }) => {
    const t = await token(page);
    const lat = LAT + 0.25;
    const name = 'E2E Captcha Route ' + suffix();
    const route = await apiCreate(request, t, 'routes', routeData(name, [{ lat, lng: LNG }, { lat: lat + 0.01, lng: LNG + 0.01 }]));
    const tour = await apiCreate(request, t, 'tours', { name: 'E2E Captcha Tour ' + suffix(), description: '', tags: [] });
    await apiCreate(request, t, 'tours/' + tour.id + '/routes', { route_id: route.id });

    await reloadMap(page);
    await page.waitForFunction((n) => routes.some((r) => r && r.name === n), name, { timeout: 20_000 });
    await settleMapAt(page, lat, LNG, 15);
    await rightClickMapCentre(page);
    await page.locator('.contextMenuItem', { hasText: 'delete' }).click();
    await page.locator('.confirm-dialog .startbtn').click();

    // the server answers 422 with a question "a + b = ?"; the dialog asks it
    const captcha = page.locator('.confirm-dialog-input');
    await expect(captcha).toBeVisible({ timeout: 10_000 });
    const question = await page.locator('.confirm-dialog-message').textContent();
    const m = /(\d+) \+ (\d+)/.exec(question || '');
    expect(m, 'captcha question: ' + question).not.toBeNull();
    await captcha.fill(String(Number(m[1]) + Number(m[2])));
    await page.locator('.confirm-dialog .startbtn').click();

    await expect.poll(async () => (await apiGet(request, t, 'routes/' + route.id)).status, { timeout: 15_000 }).toBe(404);
    const inTour = await apiGet(request, t, 'routes?tour_id=' + tour.id);
    expect(inTour.data.map((r) => r.id)).not.toContain(route.id);
  });

  test('a route is split in two at an inner waypoint while it is edited', async ({ loggedInPage: page, request }) => {
    const t = await token(page);
    const lat = LAT + 0.3;
    const name = 'E2E Split ' + suffix();
    const points = [{ lat, lng: LNG }, { lat: lat + 0.01, lng: LNG }, { lat: lat + 0.02, lng: LNG }];
    const route = await apiCreate(request, t, 'routes', routeData(name, points));

    await reloadMap(page);
    await page.waitForFunction((n) => routes.some((r) => r && r.name === n), name, { timeout: 20_000 });
    await settleMapAt(page, points[0].lat, LNG, 15);
    await rightClickMapCentre(page);
    await page.locator('.contextMenuItem', { hasText: 'edit' }).first().click();
    await page.waitForFunction(() => measureTool.index !== null, null, { timeout: 10_000 });

    // the inner waypoint in the middle of the map: right-click it
    await settleMapAt(page, points[1].lat, LNG, 15);
    const box = await page.locator('#map').boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2, { button: 'right' });
    await page.locator('.contextMenuItem', { hasText: 'call_split' }).click();
    await expect(page.locator('#toastContainer .toast-success')).toBeVisible({ timeout: 15_000 });

    const kept = (await apiGet(request, t, 'routes/' + route.id)).data;
    expect(JSON.parse(kept.points).map((p) => p.lat)).toEqual([points[0].lat, points[1].lat]);
    const mine = (await apiGet(request, t, 'routes?scope=mine')).data;
    const second = mine.find((r) => r.id !== route.id && r.name.startsWith(name) && /\d\)$/.test(r.name));
    expect(second, 'the second part exists').toBeTruthy();
    expect(JSON.parse(second.points).map((p) => p.lat)).toEqual([points[1].lat, points[2].lat]);
  });
});
