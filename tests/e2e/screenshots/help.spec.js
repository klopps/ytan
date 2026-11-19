const path = require('path');
const fs = require('fs');
const { test, expect, settleMapAt, apiAdminToken } = require('../fixtures');

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

// AR view picture: Chromium's fake camera so getUserMedia succeeds (its test pattern is
// hidden behind a drawn scene, see arScene()).
test.use({ launchOptions: { args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] } });

/**
 * The "camera picture" of the AR screenshot: sky, water and the edge of an island
 * on the left whose tip lies on the right, as an SVG data URL. The horizon is at the
 * middle of the screen (phone held upright, beta 90), where the AR labels' stems end.
 */
function arScene(width, height) {
  const h = height / 2;
  const waves = [];
  for (let i = 0; i < 26; i++) {
    const y = h + 6 + Math.pow(i / 25, 1.7) * (height - h - 10);
    const x = (i * 97) % width;
    const len = 18 + i * 3;
    waves.push(`<path d="M${x - len} ${y.toFixed(1)} q${len / 2} -${2 + i / 6} ${len} 0" stroke="#d6e8f2" stroke-opacity="${(0.18 + i / 90).toFixed(2)}" stroke-width="${(1 + i / 12).toFixed(1)}" fill="none"/>`);
  }
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
    <defs>
      <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#6f9fca"/><stop offset="1" stop-color="#d7e7f1"/></linearGradient>
      <linearGradient id="sea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#5d8aa3"/><stop offset="1" stop-color="#1d4258"/></linearGradient>
    </defs>
    <rect width="${width}" height="${h}" fill="url(#sky)"/>
    <rect y="${h}" width="${width}" height="${height - h}" fill="url(#sea)"/>
    <path d="M0 ${h - 52} C30 ${h - 64} 60 ${h - 58} 90 ${h - 50} C120 ${h - 60} 150 ${h - 46} 185 ${h - 40} C215 ${h - 44} 245 ${h - 28} 275 ${h - 18} C290 ${h - 12} 305 ${h - 6} 318 ${h} L0 ${h} Z" fill="#3c5a32"/>
    <path d="M0 ${h - 30} C60 ${h - 34} 140 ${h - 24} 200 ${h - 20} C240 ${h - 14} 285 ${h - 8} 318 ${h} L0 ${h} Z" fill="#4e6e3c"/>
    <path d="M0 ${h - 5} C80 ${h - 7} 200 ${h - 4} 318 ${h} L0 ${h} Z" fill="#d9c79a"/>
    <path d="M0 ${h} C70 ${h + 2} 180 ${h + 1.5} 330 ${h} " stroke="#eef5f8" stroke-opacity="0.7" stroke-width="1.5" fill="none"/>
    ${waves.join('')}
  </svg>`;
  return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}

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

  // ---- Routencheck ----
  // Switched on for the e2e database only for this picture. The traffic light
  // uses the real forecast; the AI text is a fixed example (no paid request,
  // and the picture doesn't change with whatever the model writes).
  // an admin setting - the e2e user has every right except admin
  const adminToken = await apiAdminToken(request);
  const aiSettings = (enabled) => request.put('/api/v1/settings/ai', {
    headers: { Authorization: 'Bearer ' + adminToken }, data: { enabled, daily_limit: 20, cache_hours: 0 },
  });
  expect((await aiSettings(true)).ok()).toBeTruthy();
  await page.route('**/briefing', async (route) => {
    const body = JSON.parse(route.request().postData() || '{}');
    if (!body.text) {
      return route.continue();
    }
    const response = await route.fetch({ postData: JSON.stringify({ ...body, text: false }) });
    const json = await response.json();
    json.data.text_status = 'ok';
    json.data.text_created_at = new Date().toISOString();
    json.data.text = '**Fazit:** Entscheidend ist der Wind - starte in einer Stunde, die in der Leiste gelb oder grün ist.\n\n' +
      '- **Friedrichsort:** Hier ist die Förde am engsten - auf Fähren und Berufsschifffahrt achten.\n' +
      '- **Ausweichen:** Am Anleger Laboe kannst du jederzeit anlanden.';
    return route.fulfill({ response, json });
  });
  // The checked route (Kiel - Laboe) in the part of the map above the sheet.
  await freshMap(54.335, 10.185, 11);
  await page.evaluate(() => { try { localStorage.setItem('ytan_route_check_consent', '1'); } catch (e) { /* ignore */ } });
  await page.evaluate((id) => openRouteCheck(routes.findIndex((r) => r && Number(r.id) === id)), demo.route.id);
  await page.waitForSelector('#routeCheckSheet .rc-text:not(.rc-muted)', { timeout: 30_000 });
  await shot(page, 'routencheck', { settle: 1200 });
  await page.evaluate(() => closeRouteCheck());
  await page.unroute('**/briefing');
  expect((await aiSettings(false)).ok()).toBeTruthy();

  // ---- profile ----
  await freshMap();
  await page.evaluate(() => { openMenu && openMenu(); });
  await page.evaluate(() => showUserWindow());
  await shot(page, 'profil', { settle: 900 });
  await page.evaluate(() => showWatchSection());
  await page.waitForTimeout(1200);
  await page.getByText('Uhr-Schlüssel erzeugen').first().click();
  // The e2e user keeps its key from earlier runs, so the button is "Neuen
  // Uhr-Schlüssel erzeugen" and asks first - confirm it, or the dialog stays
  // open on this shot AND on the login shot below.
  const regenerate = page.locator('.confirm-dialog .startbtn');
  if (await regenerate.waitFor({ state: 'visible', timeout: 1500 }).then(() => true, () => false)) {
    await regenerate.click();
  }
  await expect(page.locator('.confirm-dialog-overlay')).toHaveCount(0);
  await page.waitForTimeout(1500);
  await shot(page, 'profil-uhr', { settle: 600 });
  await page.evaluate(() => { logoutUser(); });
  await page.waitForTimeout(1200);
  await page.evaluate(() => { showUserWindow(); });
  await shot(page, 'anmeldung', { settle: 900 });

  expect(demo.route.id).toBeGreaterThan(0);
});

test.describe('AR view', () => {
  // The observer floats off an island, looking north: a landing site and a camp on its shore,
  // a lighthouse on its tip whose red sector the observer is in.
  const HERE = { lat: 54.6000, lng: 10.6000 };
  test.use({
    permissions: ['geolocation', 'camera', 'accelerometer', 'gyroscope', 'magnetometer'],
    geolocation: { latitude: HERE.lat, longitude: HERE.lng, accuracy: 4 },
  });

  test('user guide AR screenshot', async ({ loggedInPage: page, request, credentials }) => {
    const login = await request.post('/api/v1/auth/login', { data: { username: credentials.username, password: credentials.password } });
    const token = (await login.json()).token;
    const headers = { Authorization: 'Bearer ' + token };
    // bearing (degrees from north) + distance (m) -> position, flat (fine for 1 km)
    const at = (bearing, meters) => ({
      latitude: HERE.lat + (meters * Math.cos(bearing * Math.PI / 180)) / 111320,
      longitude: HERE.lng + (meters * Math.sin(bearing * Math.PI / 180)) / (111320 * Math.cos(HERE.lat * Math.PI / 180)),
    });
    for (const [type, name, bearing, meters, extra] of [
      [2, 'Anleger Inselhafen', -15, 300, {}],
      [1, 'Zeltplatz Westerstrand', -5, 600, { direction: '2222221000000122' }],
      [14, 'Leuchtturm Inselspitze', 12, 1200, { characteristic: 'Iso WRG 6s', sector_characteristic: 'W 10M 330-005\nR 8M 005-060\nG 8M 060-120' }],
    ]) {
      const response = await request.post('/api/v1/pois', { headers, data: { poitype_id: type, name, description: '', public: 1, url: '', ...at(bearing, meters), ...extra } });
      expect(response.ok(), await response.text()).toBeTruthy();
    }
    await page.reload();
    await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
    await page.waitForFunction(() => pois.some((p) => p && p.name === 'Leuchtturm Inselspitze'), null, { timeout: 20_000 });
    await page.evaluate(() => { settings.arRadius = 2000; settings.arMaxPois = 10; settings.arRouteId = null; });

    await page.evaluate(() => startArPoiView());
    await page.waitForFunction(() => document.getElementById('arVideo').srcObject !== null, null, { timeout: 10_000 });
    // the drawn scene instead of the fake camera's test pattern
    await page.evaluate((scene) => {
      document.getElementById('arVideo').style.visibility = 'hidden';
      const view = document.getElementById('arView');
      view.style.backgroundImage = 'url("' + scene + '")';
      view.style.backgroundSize = '100% 100%';
    }, arScene(390, 760));
    // looking north, phone upright
    await page.waitForFunction(() => {
      window.dispatchEvent(new DeviceOrientationEvent('deviceorientationabsolute', { alpha: 0, beta: 90, gamma: 0, absolute: true }));
      return document.querySelectorAll('.ar-label').length >= 3;
    }, null, { timeout: 15_000, polling: 200 });
    await page.waitForTimeout(1500); // label easing settles
    // the moment the lighthouse is lit (Iso 6s: 3 s on, 3 s off)
    await page.waitForFunction(() => {
      const flash = document.querySelector('.ar-light-flash');
      return flash && Number(getComputedStyle(flash).opacity) > 0.9;
    }, null, { timeout: 10_000, polling: 50 });
    await shot(page, 'ar-ansicht', { settle: 50 });
    await page.evaluate(() => stopArView());
  });
});
