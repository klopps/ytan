// @ts-check
/**
 * Selecting several waypoints in the route editor and deleting them together
 * (route-selection.js): Ctrl+drag rectangle, Ctrl+click, Delete + question,
 * Escape, a plain click on the empty map that only ends the selection, and the
 * selection mode with "select stretch" (what the touch menu starts).
 */
const { test, expect, settleMapAt } = require('../fixtures');

// Own patch of map, not used by any other spec.
const LAT = -15;
const LNG = -152;

/** Draws four waypoints in a row; returns their pixel positions (page coordinates). */
async function drawFourWaypoints(page) {
  await settleMapAt(page, LAT, LNG, 15);
  await page.locator('#routeButton').click();
  const box = await page.locator('#map').boundingBox();
  const cx = box.x + box.width / 2;
  const cy = box.y + box.height / 2;
  const offsets = [[-150, 0], [-50, 40], [50, 0], [150, 40]];
  for (const [dx, dy] of offsets) {
    await page.mouse.click(cx + dx, cy + dy);
    await page.waitForTimeout(400);
  }
  await page.waitForFunction(() => measureTool.points && measureTool.points.length === 4);
  return offsets.map(([dx, dy]) => ({ x: cx + dx, y: cy + dy }));
}

const selectedCount = (page) => page.evaluate(() => routeSel.coords.length);
const pointCount = (page) => page.evaluate(() => measureTool.points.length);

test('Ctrl+drag selects the waypoints inside, Delete asks and deletes them', async ({ loggedInPage: page }) => {
  const p = await drawFourWaypoints(page);

  // rectangle around waypoints 2 and 3 (index 1 and 2)
  await page.keyboard.down('Control');
  await page.mouse.move(p[1].x - 30, p[1].y - 60);
  await page.mouse.down();
  await page.mouse.move(p[2].x + 30, p[2].y + 60, { steps: 5 });
  await page.mouse.up();
  await page.keyboard.up('Control');

  expect(await selectedCount(page)).toBe(2);
  expect(await pointCount(page)).toBe(4); // the drag did not add a waypoint
  await expect(page.locator('#routeSelBar')).toBeVisible();
  await expect(page.locator('.route-sel-ring')).toHaveCount(2);

  // "No" keeps everything
  await page.keyboard.press('Delete');
  await expect(page.locator('.confirm-dialog')).toBeVisible();
  await page.locator('.confirm-dialog .confirm-dialog-cancel').click();
  expect(await pointCount(page)).toBe(4);

  await page.keyboard.press('Delete');
  await page.locator('.confirm-dialog .confirm-dialog-confirm').click();
  await page.waitForFunction(() => measureTool.points.length === 2);
  await expect(page.locator('#routeSelBar')).toHaveCount(0);
  expect(await selectedCount(page)).toBe(0);
});

test('right-click on a section of the line opens the autorouting menu for it', async ({ loggedInPage: page }) => {
  const p = await drawFourWaypoints(page);
  const mx = (p[1].x + p[2].x) / 2;
  const my = (p[1].y + p[2].y) / 2;
  await page.mouse.click(mx, my, { button: 'right' });
  await expect(page.locator('.contextMenuItem', { hasText: /Autorouting/ })).toBeVisible();
  expect(await pointCount(page)).toBe(4);
  expect(await page.evaluate(() => routeSplitPending.index)).toBe(1);
});

test('Ctrl+click toggles, Escape ends, a plain click on the empty map only ends the selection', async ({ loggedInPage: page }) => {
  const p = await drawFourWaypoints(page);

  await page.keyboard.down('Control');
  await page.mouse.click(p[1].x, p[1].y);
  await page.mouse.click(p[2].x, p[2].y);
  await page.mouse.click(p[1].x, p[1].y); // off again
  await page.keyboard.up('Control');
  expect(await selectedCount(page)).toBe(1);
  expect(await pointCount(page)).toBe(4);

  await page.keyboard.press('Escape');
  expect(await selectedCount(page)).toBe(0);
  await expect(page.locator('#routeSelBar')).toHaveCount(0);

  // select again, then click the empty map: no new waypoint, selection gone
  await page.keyboard.down('Control');
  await page.mouse.click(p[0].x, p[0].y);
  await page.keyboard.up('Control');
  expect(await selectedCount(page)).toBe(1);
  await page.waitForTimeout(500); // two quick clicks would be a double-click (zoom) for Maps
  await page.mouse.click(p[3].x + 60, p[3].y + 120);
  await page.waitForTimeout(500);
  expect(await selectedCount(page)).toBe(0);
  expect(await pointCount(page)).toBe(4);

  // the next plain click adds a waypoint again
  await page.mouse.click(p[3].x + 60, p[3].y + 120);
  await page.waitForFunction(() => measureTool.points.length === 5);
});

test('selection mode: "select stretch" takes everything between two waypoints; too few left is refused', async ({ loggedInPage: page }) => {
  const p = await drawFourWaypoints(page);

  await page.evaluate(() => routeSelToggleRange()); // what the waypoint menu's entry leads to
  await expect(page.locator('#routeSelBar')).toBeVisible();
  await page.mouse.click(p[0].x, p[0].y);
  await page.mouse.click(p[2].x, p[2].y);
  expect(await selectedCount(page)).toBe(3);
  expect(await pointCount(page)).toBe(4);

  // a tap on the empty map in selection mode does nothing
  await page.mouse.click(p[3].x + 60, p[3].y + 120);
  await page.waitForTimeout(300);
  expect(await pointCount(page)).toBe(4);

  // select the last one too: only 0 would remain -> refused
  await page.mouse.click(p[3].x, p[3].y);
  expect(await selectedCount(page)).toBe(4);
  await page.locator('.route-sel-danger').click();
  await expect(page.locator('.toast-warning')).toBeVisible();
  await expect(page.locator('.confirm-dialog')).toHaveCount(0);
  expect(await pointCount(page)).toBe(4);

  // deselect the last one -> three of four are deleted ... one left is also refused
  await page.mouse.click(p[3].x, p[3].y);
  await page.mouse.click(p[0].x, p[0].y); // off
  expect(await selectedCount(page)).toBe(2);
  await page.locator('.route-sel-danger').click();
  await page.locator('.confirm-dialog .confirm-dialog-confirm').click();
  await page.waitForFunction(() => measureTool.points.length === 2);
  await expect(page.locator('#routeSelBar')).toHaveCount(0);
});
