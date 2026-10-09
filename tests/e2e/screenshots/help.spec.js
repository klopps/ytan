const path = require('path');
const fs = require('fs');
const { test, expect, settleMapAt } = require('../fixtures');

/**
 * Takes the screenshots of the user guide (docs/user/*, rendered at /help)
 * into public/images/help/. Run with `npm run screenshots` (own config,
 * not part of `npm test`). Everything shown is demo data created here for
 * the e2e user in the isolated ytan_e2e database: the Kiel fjord, so the
 * pictures show a recognisable coast instead of open ocean.
 *
 * Every image the Markdown refers to must be produced here -
 * UserGuideServiceTest fails for a reference without a file.
 */
const OUT = path.join(__dirname, '..', '..', '..', 'public', 'images', 'help');
const KIEL = { lat: 54.4060, lng: 10.2000, zoom: 13 };

async function shot(page, name, options = {}) {
  await page.waitForTimeout(options.settle ?? 700);
  await page.screenshot({ path: path.join(OUT, name + '.jpg'), type: 'jpeg', quality: 80, ...options.shot });
}

/** Demo data through the API, as the e2e user. */
async function seed(request, token) {
  const headers = { Authorization: 'Bearer ' + token };
  const post = async (url, data) => {
    const response = await request.post('/api/v1' + url, { headers, data });
    expect(response.ok(), url + ' ' + (await response.text())).toBeTruthy();
    return (await response.json()).data;
  };

  const pois = {};
  const poiData = [
    ['camp', 1, 'Zeltplatz Falckenstein', 'Kleiner Platz hinter dem Strand, Trinkwasser am Wanderparkplatz.', 54.4062, 10.1895, { direction: '2222221000000122' }],
    ['landing', 2, 'Anleger Laboe', 'Gute Anlandung am Südstrand, bei Westwind geschützt.', 54.4061, 10.2236, {}],
    ['water', 3, 'Trinkwasser Friedrichsort', 'Zapfstelle am Hafenmeister.', 54.4005, 10.1856, {}],
    ['hazard', 6, 'Flachstelle Bülk', 'Steine knapp unter der Wasseroberfläche.', 54.4480, 10.1965, {}],
    ['light', 14, 'Leuchtturm Friedrichsort', 'Oberfeuer der Kieler Förde.', 54.4003, 10.1919, { characteristic: 'Iso WRG 6s', sector_characteristic: '' }],
    ['toilet', 4, 'Toiletten Strande', '', 54.4338, 10.1667, {}],
    ['shop', 8, 'Supermarkt Schilksee', 'Auch am Sonntag geöffnet.', 54.4257, 10.1665, {}],
  ];
  for (const [key, type, name, description, latitude, longitude, extra] of poiData) {
    pois[key] = await post('/pois', { poitype_id: type, name, description, public: 1, latitude, longitude, url: '', ...extra });
  }

  const routePoints = [
    { lat: 54.3320, lng: 10.1500 }, { lat: 54.3490, lng: 10.1570 }, { lat: 54.3700, lng: 10.1610 },
    { lat: 54.3880, lng: 10.1760 }, { lat: 54.3960, lng: 10.1960 }, { lat: 54.4020, lng: 10.2080 },
    { lat: 54.4050, lng: 10.2200 },
  ];
  const route = await post('/routes', {
    name: 'Kiel - Laboe', description: 'Einmal die Förde hinunter, an den Fähranlegern vorbei.',
    public: 1, length: 12400, points: JSON.stringify(routePoints), color: '#BF409F',
  });
  const route2 = await post('/routes', {
    name: 'Laboe - Strande', description: 'Rückweg am Westufer.', public: 1, length: 11800, color: '#2F6FB0',
    points: JSON.stringify([{ lat: 54.4050, lng: 10.2200 }, { lat: 54.4120, lng: 10.2000 }, { lat: 54.4250, lng: 10.1820 }, { lat: 54.4340, lng: 10.1700 }]),
  });
  const area = await post('/areas', {
    name: 'Vogelschutzgebiet Bülk', description: 'In der Brutzeit nicht anlanden.', public: 1,
    color: '#E53935', opacity: 0.25, zindex: 1,
    points: JSON.stringify([{ lat: 54.4560, lng: 10.1800 }, { lat: 54.4640, lng: 10.1830 }, { lat: 54.4650, lng: 10.2000 }, { lat: 54.4560, lng: 10.2020 }]),
  });
  const tour = await post('/tours', { name: 'Kieler Förde in zwei Tagen', description: 'Zwei Tagesetappen mit Übernachtung in Laboe.' });
  await post(`/tours/${tour.id}/routes`, { route_id: route.id });
  await post(`/tours/${tour.id}/routes`, { route_id: route2.id });
  const publish = await request.put(`/api/v1/tours/${tour.id}/publish`, { headers, data: { public: 1 } });
  expect(publish.ok()).toBeTruthy();

  return { pois, route, route2, area, tour };
}

/** Page coordinates of a lat/lng on the (full-screen) map. */
async function pixelOf(page, lat, lng) {
  return page.evaluate(({ lat, lng }) => {
    const projection = map.getProjection();
    const bounds = map.getBounds();
    const topRight = projection.fromLatLngToPoint(bounds.getNorthEast());
    const bottomLeft = projection.fromLatLngToPoint(bounds.getSouthWest());
    const point = projection.fromLatLngToPoint(new google.maps.LatLng(lat, lng));
    const scale = Math.pow(2, map.getZoom());
    return { x: (point.x - bottomLeft.x) * scale, y: (point.y - topRight.y) * scale };
  }, { lat, lng });
}

/** Closes an open InfoWindow/menu/edit state by clicking empty map. */
async function resetMap(page) {
  await page.keyboard.press('Escape');
  await page.evaluate(() => { if (typeof closeAllInfoWindows === 'function') closeAllInfoWindows(); });
}

test.describe.configure({ mode: 'serial' });

test('user guide screenshots', async ({ loggedInPage: page, request, credentials }) => {
  fs.mkdirSync(OUT, { recursive: true });
  const login = await request.post('/api/v1/auth/login', { data: { username: credentials.username, password: credentials.password } });
  const token = (await login.json()).token;
  const demo = await seed(request, token);

  // The seeded rows only show after the map reloaded its data.
  await page.reload();
  await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
  await settleMapAt(page, KIEL.lat, KIEL.lng, KIEL.zoom);

  await page.waitForTimeout(2500);
  await shot(page, 'karte');

  // ---- menu ----
  await page.locator('#sidemenu-toggle').click();
  await shot(page, 'menue', { settle: 900 });
  await page.evaluate(() => navMenuGoTo('pois'));
  await shot(page, 'menue-pois', { settle: 900 });
  await page.evaluate(() => navMenuReset());
  await page.evaluate(() => navMenuGoTo('preferences'));
  await shot(page, 'einstellungen', { settle: 900 });
  await page.evaluate(() => { navMenuReset(); closeMenu(); });
  await page.waitForTimeout(700);

  // ---- toolbar ----
  await shot(page, 'werkzeugleiste', { shot: { clip: { x: 244, y: 8, width: 140, height: 56 } } });

  // ---- route: click, double click, right click ----
  const routeSpot = { lat: 54.3960, lng: 10.1960 };
  async function onRoute() {
    await resetMap(page);
    await settleMapAt(page, KIEL.lat, KIEL.lng, KIEL.zoom);
    return pixelOf(page, routeSpot.lat, routeSpot.lng);
  }
  let at = await onRoute();
  await page.mouse.click(at.x, at.y);
  await shot(page, 'route-entfernungen', { settle: 900 });
  at = await onRoute();
  await page.mouse.dblclick(at.x, at.y);
  await shot(page, 'route-info', { settle: 1200 });
  at = await onRoute();
  await page.mouse.click(at.x, at.y, { button: 'right' });
  await shot(page, 'route-kontextmenue', { settle: 1000 });

  // ---- route: edit the line (waypoints) ----
  await page.getByText('Route bearbeiten').first().click();
  await shot(page, 'route-bearbeiten', { settle: 1200 });
  await page.locator('#secondToolbar .second-toolbar-cancel-btn, #secondToolbar button').last().click();
  await page.waitForTimeout(500);

  // ---- route: info dialog ----
  at = await onRoute();
  await page.mouse.click(at.x, at.y, { button: 'right' });
  await page.getByText('Info bearbeiten').first().click();
  await shot(page, 'route-dialog', { settle: 1200 });

  // ---- POIs ----
  async function freshMap(lat = KIEL.lat, lng = KIEL.lng, zoom = KIEL.zoom) {
    await page.reload();
    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
    await settleMapAt(page, lat, lng, zoom);
    await page.waitForTimeout(1800);
  }
  const camp = demo.pois.camp;
  await freshMap();
  at = await pixelOf(page, camp.latitude, camp.longitude);
  await page.mouse.click(at.x, at.y - 20);
  await shot(page, 'poi-info', { settle: 1200 });
  await freshMap();
  at = await pixelOf(page, camp.latitude, camp.longitude);
  await page.mouse.click(at.x, at.y - 20, { button: 'right' });
  await shot(page, 'poi-kontextmenue', { settle: 1000 });

  await freshMap();
  await page.locator('#poiButton').click();
  at = await pixelOf(page, 54.4150, 10.2100);
  await page.mouse.click(at.x, at.y);
  await page.locator('#editPoiName').fill('Rastplatz am Strand');
  await page.locator('#editPoiType').selectOption('1');
  await shot(page, 'poi-dialog', { settle: 1200 });
  await page.getByText('WSI Editor').first().click();
  await page.waitForTimeout(600);
  // A camp that is sheltered from the west: sectors 9-15 (SW-NW), the middle ones fully, the outer ones partly.
  for (const [sector, taps] of [[9, 1], [10, 2], [11, 2], [12, 2], [13, 2], [14, 2], [15, 1]]) {
    const angle = ((sector * 22.5 + 11.25) * Math.PI) / 180;
    for (let n = 0; n < taps; n++) {
      await page.mouse.click(195 + 71 * Math.sin(angle), 316 - 71 * Math.cos(angle));
    }
  }
  await shot(page, 'poi-wsi-editor', { settle: 800 });

  // ---- areas ----
  await freshMap(54.4610, 10.1910, 13);
  at = await pixelOf(page, 54.4600, 10.1920);
  await page.mouse.click(at.x, at.y);
  await shot(page, 'gebiet-info', { settle: 1200 });
  await freshMap(54.4610, 10.1910, 13);
  at = await pixelOf(page, 54.4600, 10.1920);
  await page.mouse.click(at.x, at.y, { button: 'right' });
  await shot(page, 'gebiet-kontextmenue', { settle: 1000 });
  await page.locator('text=Bearbeiten >> visible=true').first().click();
  await page.waitForTimeout(800);
  await page.evaluate(() => { var b = document.querySelector('#secondToolbar .second-toolbar-end-btn'); if (b) b.click(); });
  await shot(page, 'gebiet-dialog', { settle: 1200 });

  // ---- search ----
  await freshMap();
  await page.evaluate(() => toggleMapSearch());
  await page.locator('#mapSearchInput').fill('Laboe');
  await page.waitForTimeout(2500);
  await shot(page, 'suche', { settle: 500 });

  // ---- tours ----
  await freshMap();
  await page.evaluate(() => openTourAdminMenu());
  await page.waitForTimeout(1500);
  await shot(page, 'touren', { settle: 800 });
  await page.getByText('Kieler Förde in zwei Tagen').first().click();
  await page.waitForTimeout(1500);
  await shot(page, 'tour-details', { settle: 800 });
  await page.getByText('Tour-Modus aktivieren').first().click();
  await page.waitForTimeout(2500);
  await shot(page, 'tour-modus', { settle: 800 });

  // ---- GPX import ----
  await freshMap();
  await page.evaluate(() => openGpxImportMenu());
  await shot(page, 'gpx-import', { settle: 900 });
  const gpx = '<?xml version="1.0" encoding="UTF-8"?><gpx version="1.1" creator="Beispiel" xmlns="http://www.topografix.com/GPX/1/1">' +
    '<metadata><name>Beispieldatei vom GPS-Gerät</name></metadata>' +
    '<wpt lat="54.4062" lon="10.1895"><name>Rastplatz</name><desc>Schöner Strand</desc></wpt>' +
    '<trk><name>Rundtour Friedrichsort</name><trkseg>' +
    [[54.3975, 10.1900], [54.4010, 10.1990], [54.4075, 10.2040], [54.4120, 10.1980], [54.4090, 10.1890], [54.4030, 10.1860]]
      .map(([la, lo], i) => `<trkpt lat="${la}" lon="${lo}"><time>2026-08-1${1}T0${8 + Math.floor(i / 3)}:${i % 3}0:00Z</time></trkpt>`).join('') +
    '</trkseg></trk></gpx>';
  await page.locator('#gpxImportFile').setInputFiles({ name: 'Rundtour-Friedrichsort.gpx', mimeType: 'application/gpx+xml', buffer: Buffer.from(gpx) });
  await page.waitForTimeout(1500);
  await shot(page, 'gpx-vorschau', { settle: 800 });

  // ---- weather ----
  await freshMap();
  await page.mouse.click(195, 330, { button: 'right' });
  await page.waitForTimeout(800);
  await page.getByText('Wetterdaten für diesen Ort').first().click();
  await page.waitForTimeout(5000);
  await shot(page, 'wetter', { settle: 800 });

  // ---- profile ----
  await freshMap();
  await page.evaluate(() => { openMenu && openMenu(); });
  await page.evaluate(() => showUserWindow());
  await shot(page, 'profil', { settle: 900 });
  await page.evaluate(() => showWatchSection());
  await page.waitForTimeout(1200);
  await page.getByText('Uhr-Schlüssel erzeugen').first().click();
  await page.waitForTimeout(1500);
  await shot(page, 'profil-uhr', { settle: 600 });
  await page.evaluate(() => { logoutUser(); });
  await page.waitForTimeout(1200);
  await page.evaluate(() => { showUserWindow(); });
  await shot(page, 'anmeldung', { settle: 900 });

  expect(demo.route.id).toBeGreaterThan(0);
});
