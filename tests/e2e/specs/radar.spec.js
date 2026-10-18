const { test, expect, settleMapAt } = require('../fixtures');

/**
 * Rain radar (radar.js) at fractional zoom levels. After a pinch Google draws
 * the map at e.g. zoom 7.5 while map.getZoom() and map.getBounds() still answer
 * for the rounded zoom 8; the radar once derived its canvas and tile range from
 * those and left bare strips at the screen edges. The manifest and the tiles
 * are faked here (the real ones come from the Python job), only the geometry
 * is under test.
 */
const PNG_1X1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64'
);

test.describe('Rain radar geometry', () => {
  test('the canvas covers the whole map and enough tiles are wanted, also at fractional zoom', async ({ loggedInPage: page }) => {
    const now = new Date();
    now.setUTCMinutes(0, 0, 0);
    const iso = now.toISOString().replace(/\.\d+Z$/, 'Z');
    await page.route('**/api/v1/radar/manifest', (route) => route.fulfill({
      json: {
        data: {
          version: 1, generated: iso, latest_observation: iso, step_minutes: 5,
          zoom: { min: 5, max: 8 },
          bounds: { south: 30, west: -20, north: 70, east: 40 },
          regions: [{ name: 'x', south: 30, west: -20, north: 70, east: 40 }],
          forecast_minutes: 0, legend: [{ mm_h: 0.1, color: '#9fd4ff' }],
          frames: [{ id: 'o202601010000', time: iso, type: 'observation' }],
        },
      },
    }));
    await page.route('**/api/v1/radar/tiles/**', (route) => route.fulfill({ body: PNG_1X1, contentType: 'image/png' }));

    await settleMapAt(page, 59.5, -1.0, 7);
    await page.evaluate(() => setRadarEnabled(true));
    await page.waitForFunction(() => typeof radarOverlay !== 'undefined' && radarOverlay && radarOverlay.getProjection(), null, { timeout: 15_000 });

    for (const zoom of [7, 6.63, 7.5, 8.4]) {
      await page.evaluate((z) => map.setZoom(z), zoom);
      await page.waitForTimeout(1500);
      const r = await page.evaluate(() => {
        const projection = radarOverlay.getProjection();
        const a = projection.fromLatLngToDivPixel(new google.maps.LatLng(0, 0));
        const b = projection.fromLatLngToDivPixel(new google.maps.LatLng(0, 90));
        const real = Math.log2((b.x - a.x) * 4 / 256);
        const div = map.getDiv().getBoundingClientRect();
        const canvas = radarOverlay.canvas.getBoundingClientRect();
        const view = radarCurrentView();
        const tileScreenPx = 256 * Math.pow(2, real - view.zoom);
        return {
          real,
          left: div.left - canvas.left, top: div.top - canvas.top,
          right: canvas.right - div.right, bottom: canvas.bottom - div.bottom,
          tiles: [view.x1 - view.x0 + 1, view.y1 - view.y0 + 1],
          need: [Math.ceil(div.width / tileScreenPx) + 1, Math.ceil(div.height / tileScreenPx) + 1],
        };
      });
      expect(Math.abs(r.real - zoom), 'real zoom ' + zoom).toBeLessThan(0.05);
      // the canvas reaches beyond every edge of the map (it was 6 px short at zoom 7.5)
      for (const side of ['left', 'top', 'right', 'bottom']) {
        expect(r[side], side + ' at zoom ' + zoom).toBeGreaterThanOrEqual(100);
      }
      expect(r.tiles[0], 'tile columns at zoom ' + zoom).toBeGreaterThanOrEqual(r.need[0]);
      expect(r.tiles[1], 'tile rows at zoom ' + zoom).toBeGreaterThanOrEqual(r.need[1]);
    }

    await page.evaluate(() => setRadarEnabled(false));
  });
});
