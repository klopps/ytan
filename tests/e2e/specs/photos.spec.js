// @ts-check
/**
 * Photos of a POI through its edit window (photo-upload.js + the shared
 * EntityImageEndpoints): a picked file is only staged (pending tile), Save
 * uploads it; an existing photo is shown through the authenticated blob
 * fetch and removed on the next Save.
 */
const {
  test, expect, apiCreate, apiGet, reloadMap, rightClickMapCentre, showPoiMarker, POI_MARKER_OFFSET,
} = require('../fixtures');

// Own patch of map, not used by any other spec.
const LAT = 56.6;
const LNG = 14.2;

const PNG_1X1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64'
);

async function openEditWindow(page, name) {
  await showPoiMarker(page, name, LAT, LNG);
  await rightClickMapCentre(page, POI_MARKER_OFFSET);
  await page.locator('.contextMenuItem', { hasText: 'edit' }).first().click();
  await expect(page.locator('#editPoiName')).toBeVisible();
}

test('a photo is added to a POI on save, shown again and removed again', async ({ loggedInPage: page, request }) => {
  const t = await page.evaluate(() => localStorage.getItem('ytan_token'));
  const name = 'E2E Photo POI ' + Date.now().toString(36);
  const poi = await apiCreate(request, t, 'pois', {
    poitype_id: 2, name, description: '', public: 0, latitude: LAT, longitude: LNG, url: '',
  });

  await reloadMap(page);
  await openEditWindow(page, name);

  await page.locator('#photoUploadInput').setInputFiles({ name: 'steg.png', mimeType: 'image/png', buffer: PNG_1X1 });
  await expect(page.locator('#photoUploadGrid .tour-photo-tile-pending')).toHaveCount(1);
  // nothing is uploaded before Save
  expect((await apiGet(request, t, 'pois/' + poi.id + '/images')).data).toEqual([]);

  await page.locator('#editPoiSaveBtn').click();
  await expect.poll(async () => (await apiGet(request, t, 'pois/' + poi.id + '/images')).data?.length, { timeout: 15_000 }).toBe(1);

  // reopened: the stored photo is fetched (Bearer token, blob URL) and shown
  await reloadMap(page);
  await openEditWindow(page, name);
  const stored = page.locator('#photoUploadGrid .tour-photo-tile img[data-image-id]');
  await expect(stored).toHaveCount(1);
  await expect.poll(() => stored.evaluate((img) => img.src.startsWith('blob:') && img.complete && img.naturalWidth > 0), { timeout: 10_000 }).toBe(true);

  await page.locator('#photoUploadGrid .tour-photo-remove').first().click();
  await page.locator('#editPoiSaveBtn').click();
  await expect.poll(async () => (await apiGet(request, t, 'pois/' + poi.id + '/images')).data?.length, { timeout: 15_000 }).toBe(0);
});
