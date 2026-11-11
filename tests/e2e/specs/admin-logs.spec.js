const fs = require('fs');
const path = require('path');
const { test, expect } = require('../fixtures');

/**
 * Admin log viewer (/admin/logs, admin-logs.js + LogController): a log file
 * written into storage/logs shows up with its size, opens in the viewer,
 * auto-follow picks up appended lines, and single/ZIP downloads start.
 * Mobile viewport, the admin area is used on phones too.
 */
const LOG_DIR = path.join(__dirname, '..', '..', '..', 'storage', 'logs');
const LOG_NAME = 'e2e-admin-logs.log';
const LOG_FILE = path.join(LOG_DIR, LOG_NAME);
const ZIP_NAME = 'e2e-admin-logs.1-11.zip';
const ZIP_FILE = path.join(LOG_DIR, ZIP_NAME);

test.use({ viewport: { width: 390, height: 660 } });

test.beforeEach(() => {
  fs.mkdirSync(LOG_DIR, { recursive: true });
  fs.writeFileSync(LOG_FILE, 'first line\nsecond line\n');
  fs.writeFileSync(ZIP_FILE, 'PK not really a zip');
});

test.afterEach(() => {
  fs.rmSync(LOG_FILE, { force: true });
  fs.rmSync(ZIP_FILE, { force: true });
});

async function openAdminLogs(page, request, credentials) {
  const login = await request.post('/api/v1/auth/login', {
    data: { username: credentials.username, password: credentials.password },
  });
  expect(login.ok()).toBeTruthy();
  const { token } = await login.json();
  await page.goto('/admin/logs');
  await page.evaluate((t) => localStorage.setItem('ytan_token', t), token);
  await page.reload();
}

test('lists, shows and follows a log file', async ({ page, request, credentials }) => {
  await openAdminLogs(page, request, credentials);

  const row = page.locator('#logsList tr', { hasText: LOG_NAME });
  await expect(row).toBeVisible();
  await expect(row).toContainText('23 B');

  await row.getByRole('link', { name: LOG_NAME }).click();
  const content = page.locator('#logsContent');
  await expect(content).toHaveText('first line\nsecond line\n');
  await expect(page.locator('#logsViewerName')).toHaveText(LOG_NAME);

  await page.locator('label[for="logsFollow"]').click();
  await expect(page.locator('#logsFollow')).toBeChecked();
  fs.appendFileSync(LOG_FILE, 'appended while following\n');
  await expect(content).toContainText('appended while following', { timeout: 10_000 });
  await expect(row).toContainText('48 B', { timeout: 20_000 });

  // The viewer stays scrolled to the end while following.
  const atEnd = await content.evaluate((el) => el.scrollHeight - el.scrollTop - el.clientHeight < 5);
  expect(atEnd).toBe(true);
});

test('downloads one file as is and several as a ZIP', async ({ page, request, credentials }) => {
  await openAdminLogs(page, request, credentials);
  const row = page.locator('#logsList tr', { hasText: LOG_NAME });
  await expect(row).toBeVisible();

  await row.getByRole('checkbox').check();
  let download = page.waitForEvent('download');
  await page.locator('#logsDownloadSelected').click();
  expect((await download).suggestedFilename()).toBe(LOG_NAME);

  await page.locator('#logsList thead input[type="checkbox"]').check();
  download = page.waitForEvent('download');
  await page.locator('#logsDownloadSelected').click();
  expect((await download).suggestedFilename()).toMatch(/^ytan-logs-\d{8}-\d{6}\.zip$/);

  // A packed archive (NAME.A-B.zip) is downloaded on click, not shown as text.
  download = page.waitForEvent('download');
  await page.locator('#logsList tr', { hasText: ZIP_NAME }).getByRole('link').click();
  expect((await download).suggestedFilename()).toBe(ZIP_NAME);
  await expect(page.locator('#logsViewer')).toBeHidden();
});
