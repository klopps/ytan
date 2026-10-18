const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { test, expect, settleMapAt } = require('../fixtures');

/**
 * Wind layer (wind.js): context menu toggle, panel, canvases, hour slider
 * and the exclusion with the rain radar.
 *
 * The data normally comes from the Python job (tools/wind/) into
 * storage/wind. Here a tiny deterministic fixture takes its place - a steady
 * 8 m/s westerly in two L1 blocks over the North Sea - so the test needs
 * neither the job nor network access to the DWD. An existing storage/wind
 * is moved aside for the run and put back afterwards.
 */
const WIND_DIR = path.join(__dirname, '..', '..', '..', 'storage', 'wind');
const BACKUP_DIR = WIND_DIR + '.e2e-backup';
const LAT = 58.5;
const LNG = 3.0;
const RUN = '202601010000';
const POINTS = 129;

function gz(buffer) {
  return zlib.gzipSync(buffer);
}

/** `points` points, every one u = 8 m/s (byte 152), v = 0 (byte 127), with a third channel gust 20 m/s (byte 85). */
function steady(points, channels) {
  const data = Buffer.alloc(points * channels);
  for (let k = 0; k < points; k += 1) {
    data[k * channels] = 152;
    data[k * channels + 1] = 127;
    if (channels > 2) {
      data[k * channels + 2] = 85;
    }
  }
  return data;
}

function writeFixture(channels = 2) {
  const now = new Date();
  now.setUTCMinutes(0, 0, 0);
  const hours = [0, 1, 2].map((h) => new Date(now.getTime() + (h - 1) * 3600000).toISOString().replace(/:\d\d\.\d{3}Z$/, 'Z'));
  const l0 = { res: 0.25, south: 50, west: -10, rows: 81, cols: 101 };
  const blocks = ['22_18', '23_18'];
  const runDir = path.join(WIND_DIR, 'runs', RUN);
  hours.forEach((_, h) => {
    const hh = String(h).padStart(3, '0');
    fs.mkdirSync(path.join(runDir, 'L0'), { recursive: true });
    fs.writeFileSync(path.join(runDir, 'L0', hh + '.bin.gz'), gz(steady(l0.rows * l0.cols, channels)));
    fs.mkdirSync(path.join(runDir, 'L1', hh), { recursive: true });
    blocks.forEach((b) => fs.writeFileSync(path.join(runDir, 'L1', hh, b + '.bin.gz'), gz(steady(POINTS * POINTS, channels))));
  });
  const eu = now.toISOString().slice(0, 13).replace(/[-T]/g, '');
  fs.writeFileSync(path.join(WIND_DIR, 'manifest.json'), JSON.stringify({
    run: RUN,
    generated: new Date().toISOString().replace(/:\d\d\.\d{3}Z$/, 'Z'),
    key: {},
    sources: { eu: eu, d2: null },
    hours: hours,
    scale: { uv: [-40, 40], gust: [0, 60], nodata: 255 },
    channels: channels,
    levels: { L0: l0, L1: { res: 0.0625, size: POINTS, blocks: blocks }, L2: { res: 0.02, size: POINTS, blocks: [] } },
  }));
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  if (fs.existsSync(BACKUP_DIR)) { // a crashed earlier run: undo it first
    fs.rmSync(WIND_DIR, { recursive: true, force: true });
    fs.renameSync(BACKUP_DIR, WIND_DIR);
  }
  if (fs.existsSync(WIND_DIR)) {
    fs.renameSync(WIND_DIR, BACKUP_DIR);
  }
  fs.mkdirSync(WIND_DIR, { recursive: true });
  writeFixture();
});

test.afterAll(() => {
  fs.rmSync(WIND_DIR, { recursive: true, force: true });
  if (fs.existsSync(BACKUP_DIR)) {
    fs.renameSync(BACKUP_DIR, WIND_DIR);
  } else {
    fs.mkdirSync(WIND_DIR, { recursive: true });
  }
});

/** Number of canvas pixels that are not fully transparent. */
async function paintedPixels(page, selector) {
  return page.evaluate((sel) => {
    const canvas = document.querySelector(sel);
    if (!canvas || canvas.width === 0) {
      return 0;
    }
    const data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
    let n = 0;
    for (let i = 3; i < data.length; i += 4) {
      if (data[i] > 0) {
        n++;
      }
    }
    return n;
  }, selector);
}

test.describe('Wind layer', () => {
  test('context menu switches the layer on with fill, particles and panel, and off again', async ({ loggedInPage: page }) => {
    await settleMapAt(page, LAT, LNG, 8);
    await page.evaluate(() => {
      settings.detailwind = false;
      settings.detailradar = false;
    });

    // the map context menu has the wind item (right-click on the map)
    const box = await page.locator('#map').boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2, { button: 'right' });
    const item = page.locator('.contextMenuItem', { hasText: 'Show/hide wind' });
    await expect(item).toBeVisible();
    await item.click();

    await expect(page.locator('#windControls')).toBeVisible();
    await page.waitForFunction(() => windGrid !== null && windParticles !== null, null, { timeout: 15_000 });
    expect(await page.evaluate(() => settings.detailwind)).toBe(true);

    // colour fill drawn, particles drawn after a few frames
    await expect.poll(() => paintedPixels(page, '.wind-canvas-fill'), { timeout: 10_000 }).toBeGreaterThan(1000);
    await expect.poll(() => paintedPixels(page, '.wind-canvas:not(.wind-canvas-fill)'), { timeout: 10_000 }).toBeGreaterThan(50);

    // the steady 8 m/s westerly: particles' grid says east-pointing vectors of 8 m/s
    const sample = await page.evaluate(() => ({ vx: windGrid.vx[windGrid.vx.length >> 1], vy: windGrid.vy[windGrid.vy.length >> 1] }));
    expect(sample.vx).toBeGreaterThan(7.5);
    expect(sample.vx).toBeLessThan(8.5);
    expect(Math.abs(sample.vy)).toBeLessThan(0.5);

    // x closes it
    await page.locator('#windControls .radar-controls-close').click();
    await expect(page.locator('#windControls')).toBeHidden();
    await expect(page.locator('.wind-canvas')).toHaveCount(0);
    expect(await page.evaluate(() => settings.detailwind)).toBe(false);
  });

  test('the wind sits where the map is, also at fractional zoom levels (after a pinch)', async ({ loggedInPage: page }) => {
    await settleMapAt(page, LAT, LNG, 8);
    await page.evaluate(() => setWindEnabled(true));
    await page.waitForFunction(() => windGrid !== null, null, { timeout: 15_000 });
    for (const zoom of [7.63, 8.4, 8]) {
      await page.evaluate((z) => map.setZoom(z), zoom);
      // wait for the rebuild at the new scale: the grid's zoom follows the PROJECTION's scale
      await page.waitForFunction((z) => windGrid && Math.abs(windGrid.zoom - z) < 0.02, zoom, { timeout: 15_000 });
      // the grid is built for the whole canvas: the map's centre must be where the grid's own
      // geography says it is (a scale taken from the rounded map.getZoom() puts it far off)
      const off = await page.evaluate(() => {
        const g = windGrid;
        const div = map.getDiv();
        const cx = WIND_MARGIN + div.clientWidth / 2;
        const cy = WIND_MARGIN + div.clientHeight / 2;
        const lng = windLngAt(windWorldX(g.cornerLng, g.scale) + cx, g.scale);
        const lat = windLatAt(windWorldY(g.cornerLat, g.scale) + cy, g.scale);
        const c = map.getCenter();
        return { dLng: Math.abs(lng - c.lng()), dLat: Math.abs(lat - c.lat()) };
      });
      expect(off.dLng, 'zoom ' + zoom).toBeLessThan(0.02);
      expect(off.dLat, 'zoom ' + zoom).toBeLessThan(0.02);
    }
    await page.evaluate(() => setWindEnabled(false));
  });

  test('a full-screen panel (weather forecast) pauses the animation and it carries on when the panel closes', async ({ loggedInPage: page }) => {
    await settleMapAt(page, LAT, LNG, 8);
    await page.evaluate(() => setWindEnabled(true));
    await page.waitForFunction(() => windGrid !== null && windParticles !== null, null, { timeout: 15_000 });
    await expect.poll(() => paintedPixels(page, '.wind-canvas:not(.wind-canvas-fill)'), { timeout: 10_000 }).toBeGreaterThan(50);

    await page.evaluate(() => {
      panelOpened();
      // the panel stays open "for two minutes": no map movement meanwhile
      windLastActivity = performance.now() - 120_000;
    });
    await page.waitForTimeout(500);
    await page.evaluate(() => panelClosed());
    await page.waitForTimeout(1500);

    // still running, trails still being drawn (not stopped by the idle timer, not frozen)
    expect(await page.evaluate(() => windRaf !== 0)).toBe(true);
    const snapshot = () => page.evaluate(() => {
      const c = windOverlay.particles;
      return Array.from(c.getContext('2d').getImageData(0, 0, c.width, c.height).data.filter((v, i) => i % 4 === 3)).join(',');
    });
    const a = await snapshot();
    await page.waitForTimeout(400);
    expect(await snapshot()).not.toBe(a);

    // after a minute without any movement the particles stop AND disappear (no frozen trails); the fill stays
    await page.evaluate(() => {
      windLastActivity = performance.now() - 61_000;
    });
    await expect.poll(() => page.evaluate(() => windRaf), { timeout: 5_000 }).toBe(0);
    expect(await paintedPixels(page, '.wind-canvas:not(.wind-canvas-fill)')).toBe(0);
    expect(await paintedPixels(page, '.wind-canvas-fill')).toBeGreaterThan(1000);

    // a tap on the map wakes it again
    await page.evaluate(() => google.maps.event.trigger(map, 'click', { latLng: map.getCenter() }));
    await expect.poll(() => page.evaluate(() => windRaf !== 0), { timeout: 3_000 }).toBe(true);
    await expect.poll(() => paintedPixels(page, '.wind-canvas:not(.wind-canvas-fill)'), { timeout: 5_000 }).toBeGreaterThan(50);

    await page.evaluate(() => setWindEnabled(false));
  });

  test('hour slider and play button change the shown hour', async ({ loggedInPage: page }) => {
    await settleMapAt(page, LAT, LNG, 8);
    await page.evaluate(() => setWindEnabled(true));
    await page.waitForFunction(() => windGrid !== null, null, { timeout: 15_000 });

    const slider = page.locator('#windHourSlider');
    expect(await slider.getAttribute('max')).toBe('2');
    const before = await page.locator('#windControlsTime').textContent();
    await slider.evaluate((el) => {
      el.value = '2';
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await expect(page.locator('#windControlsTime')).not.toHaveText(before);
    expect(await page.evaluate(() => windHourIndex)).toBe(2);
    await page.waitForFunction(() => windGrid !== null && windHourIndex === 2, null, { timeout: 10_000 });

    await page.locator('#windPlayPauseBtn').click();
    await expect(page.locator('#windPlayPauseIcon')).toHaveText('pause');
    await page.locator('#windPlayPauseBtn').click();
    await expect(page.locator('#windPlayPauseIcon')).toHaveText('play_arrow');

    await page.evaluate(() => setWindEnabled(false));
  });

  test('wind and rain radar exclude each other', async ({ loggedInPage: page }) => {
    await settleMapAt(page, LAT, LNG, 8);
    await page.evaluate(() => setWindEnabled(true));
    await expect(page.locator('#windControls')).toBeVisible();

    await page.evaluate(() => setRadarEnabled(true));
    await expect(page.locator('#radarControls')).toBeVisible();
    await expect(page.locator('#windControls')).toBeHidden();
    expect(await page.evaluate(() => [settings.detailwind, settings.detailradar])).toEqual([false, true]);

    await page.evaluate(() => setWindEnabled(true));
    await expect(page.locator('#windControls')).toBeVisible();
    await expect(page.locator('#radarControls')).toBeHidden();
    expect(await page.evaluate(() => [settings.detailwind, settings.detailradar])).toEqual([true, false]);

    await page.evaluate(() => setWindEnabled(false));
  });

  test('with gust data a chip colours the fill by gusts and leaves the particles alone', async ({ loggedInPage: page }) => {
    writeFixture(3);
    try {
      await page.reload();
      await page.waitForFunction(() => typeof mapInitialized !== 'undefined' && mapInitialized === true, null, { timeout: 20_000 });
      await settleMapAt(page, LAT, LNG, 8);
      await page.evaluate(() => {
        settings.windGusts = false;
        setWindEnabled(true);
      });
      await page.waitForFunction(() => windGrid !== null && windGrid.gust !== null, null, { timeout: 15_000 });
      await expect(page.locator('#windGustBtn')).toBeVisible();
      const mid = () => page.evaluate(() => {
        const k = windGrid.speed.length >> 1;
        return { speed: windGrid.speed[k], gust: windGrid.gust[k], vx: windGrid.vx[k], shown: windShowsGusts() };
      });
      let g = await mid();
      expect(g.speed).toBeGreaterThan(7.5);
      expect(g.speed).toBeLessThan(8.5);
      expect(g.gust).toBeGreaterThan(19);
      expect(g.gust).toBeLessThan(21);
      expect(g.shown).toBe(false);

      await page.locator('#windGustBtn').click();
      g = await mid();
      expect(g.shown).toBe(true);
      expect(g.vx).toBeGreaterThan(7.5); // particles still follow the mean wind
      expect(g.vx).toBeLessThan(8.5);
      await expect(page.locator('#windGustBtn')).toHaveClass(/wind-gust-btn-on/);
      await expect.poll(() => paintedPixels(page, '.wind-canvas-fill'), { timeout: 5_000 }).toBeGreaterThan(1000);
      await page.evaluate(() => {
        settings.windGusts = false;
        setWindEnabled(false);
      });
    } finally {
      writeFixture(2);
    }
  });

  test('without wind data the layer says so and switches itself off', async ({ loggedInPage: page }) => {
    fs.renameSync(path.join(WIND_DIR, 'manifest.json'), path.join(WIND_DIR, 'manifest.hidden'));
    try {
      await page.evaluate(() => setWindEnabled(true));
      await page.waitForFunction(() => settings.detailwind === false, null, { timeout: 10_000 });
      await expect(page.locator('#windControls')).toBeHidden();
    } finally {
      fs.renameSync(path.join(WIND_DIR, 'manifest.hidden'), path.join(WIND_DIR, 'manifest.json'));
    }
  });
});
