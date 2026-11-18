// @ts-check
/**
 * Live map updates (live-sync.js + GET /map/changes): a POI/route/area that is
 * created, changed or deleted elsewhere (here: straight through the API, as
 * another device would) appears/changes/disappears on the open map without a
 * reload. The test triggers the poll itself (liveSyncTick()) instead of
 * waiting for the 15 s interval.
 */
const { test, expect, apiCreate } = require('../fixtures');

// Own patch of map, not used by any other spec.
const LAT = 56.9;
const LNG = 9.2;

async function poll(page) {
  await page.evaluate(() => new Promise((resolve) => {
    const wait = () => (liveSync.busy ? setTimeout(wait, 50) : resolve(undefined));
    liveSyncTick();
    setTimeout(wait, 50);
  }));
}

test.describe('Live map updates', () => {
  test('changes made elsewhere reach the open map without a reload', async ({ loggedInPage: page, request }) => {
    const token = await page.evaluate(() => localStorage.getItem('ytan_token'));
    const auth = { Authorization: `Bearer ${token}` };
    await page.waitForFunction(() => typeof liveSync !== 'undefined' && liveSync.cursor !== null, null, { timeout: 20_000 });

    const tag = Date.now().toString(36);
    const poiBody = { poitype_id: 2, name: 'E2E Live POI ' + tag, description: '', public: 1, latitude: LAT, longitude: LNG, url: '' };
    const poi = await apiCreate(request, token, 'pois', poiBody);
    const route = await apiCreate(request, token, 'routes', {
      name: 'E2E Live Route ' + tag, description: '', public: 1, length: 1000, color: '#BF409F',
      points: JSON.stringify([{ lat: LAT, lng: LNG }, { lat: LAT + 0.01, lng: LNG + 0.01 }]),
    });
    const area = await apiCreate(request, token, 'areas', {
      name: 'E2E Live Area ' + tag, description: '', public: 1, color: '#00FF30', opacity: 0.1, zindex: 1,
      points: JSON.stringify([{ lat: LAT, lng: LNG }, { lat: LAT + 0.01, lng: LNG }, { lat: LAT, lng: LNG + 0.01 }]),
    });

    await poll(page);
    expect(await page.evaluate(([p, r, a]) => ({
      poi: pois.some((x) => x && x.id === p) && !!markers[p],
      route: routes.findIndex((x) => x && x.id === r) !== -1 && !!routePaths[routes.findIndex((x) => x && x.id === r)],
      area: areas.findIndex((x) => x && x.id === a) !== -1 && !!areaPolygons[areas.findIndex((x) => x && x.id === a)],
    }), [poi.id, route.id, area.id])).toEqual({ poi: true, route: true, area: true });

    // changed elsewhere: the marker is rebuilt with the new data, once
    const renamed = 'E2E Live POI renamed ' + tag;
    const put = await request.put('/api/v1/pois/' + poi.id, { headers: auth, data: { ...poiBody, name: renamed } });
    expect(put.ok()).toBeTruthy();
    await poll(page);
    expect(await page.evaluate((p) => {
      const i = pois.findIndex((x) => x && x.id === p);
      return { name: pois[i].name, title: markers[p].title || markers[p].options?.title || null, copies: pois.filter((x) => x && x.id === p).length };
    }, poi.id)).toMatchObject({ name: renamed, copies: 1 });

    // deleted elsewhere: gone from the map
    for (const [collection, id] of [['pois', poi.id], ['routes', route.id], ['areas', area.id]]) {
      const res = await request.delete(`/api/v1/${collection}/${id}`, { headers: auth });
      expect(res.ok(), `${collection} ${res.status()}`).toBeTruthy();
    }
    await poll(page);
    expect(await page.evaluate(([p, r, a]) => ({
      poi: pois.some((x) => x && x.id === p) || !!markers[p],
      route: routes.some((x) => x && x.id === r),
      area: areas.some((x) => x && x.id === a),
    }), [poi.id, route.id, area.id])).toEqual({ poi: false, route: false, area: false });
  });

  test('an untouched map polls less often, the first touch after a pause polls at once', async ({ loggedInPage: page }) => {
    await page.waitForFunction(() => typeof liveSync !== 'undefined' && liveSync.cursor !== null && !liveSync.busy, null, { timeout: 20_000 });
    expect(await page.evaluate(() => [0, 119_000, 120_000, 599_000, 600_000].map(liveSyncInterval)))
      .toEqual([15_000, 15_000, 60_000, 60_000, 180_000]);

    // untouched for 11 min, last poll 100 s ago: the next poll is still 80 s away ...
    const before = await page.evaluate(() => {
      liveSync.lastActivity = Date.now() - 11 * 60_000;
      liveSync.lastPoll = Date.now() - 100_000;
      liveSyncSchedule();
      return liveSync.lastPoll;
    });
    // ... until the user touches the page
    await page.mouse.click(5, 400);
    await expect.poll(() => page.evaluate(() => liveSync.lastPoll), { timeout: 5_000 }).toBeGreaterThan(before + 90_000);
  });

  test('a POI being edited keeps its form until the edit ends', async ({ loggedInPage: page, request }) => {
    const token = await page.evaluate(() => localStorage.getItem('ytan_token'));
    const auth = { Authorization: `Bearer ${token}` };
    await page.waitForFunction(() => typeof liveSync !== 'undefined' && liveSync.cursor !== null, null, { timeout: 20_000 });
    const tag = Date.now().toString(36);
    const body = { poitype_id: 2, name: 'E2E Live Edit ' + tag, description: '', public: 1, latitude: LAT + 0.05, longitude: LNG, url: '' };
    const poi = await apiCreate(request, token, 'pois', body);
    await poll(page);

    // the user opens the edit window, meanwhile the POI changes elsewhere
    await page.evaluate((p) => editPoi(pois.findIndex((x) => x && x.id === p)), poi.id);
    await request.put('/api/v1/pois/' + poi.id, { headers: auth, data: { ...body, name: 'changed elsewhere ' + tag } });
    await poll(page);
    expect(await page.evaluate((p) => pois.find((x) => x && x.id === p).name, poi.id)).toBe(body.name);

    // edit cancelled: the next poll brings the server's version
    await page.evaluate((p) => cancelEditPoi(pois.findIndex((x) => x && x.id === p)), poi.id);
    await poll(page);
    expect(await page.evaluate((p) => pois.find((x) => x && x.id === p).name, poi.id)).toBe('changed elsewhere ' + tag);

    await request.delete('/api/v1/pois/' + poi.id, { headers: auth });
  });
});
