// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * E2E config for the YTAN frontend. See CLAUDE.md's Testing section for
 * the full picture (why a dedicated ytan_test database, why a custom
 * router script, how to get a MAPS_API_KEY, etc).
 *
 * This machine has multiple PHP installs and a plain `php` on PATH can
 * resolve to an older 7.x build (same caveat as CLAUDE.md's Commands
 * section) - PHP_BIN lets that be overridden per-machine/CI without
 * editing this file.
 */
const PHP_BIN = process.env.PHP_BIN || 'C:/dev/php8/php.exe';
const PORT = 8123;
const BASE_URL = `http://localhost:${PORT}`;

module.exports = defineConfig({
  testDir: './specs',
  fullyParallel: false, // all specs share one seeded e2e user + the same ytan_test rows - parallel workers would race each other
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list']],
  globalSetup: require.resolve('./global-setup.js'),
  timeout: 30_000,
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    video: 'off', // avoids an ffmpeg dependency this environment couldn't auto-download; not needed for these smoke-style assertions
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: {
    command: `"${PHP_BIN}" -S localhost:${PORT} -t ../../public server/e2e-router.php`,
    cwd: __dirname,
    url: BASE_URL,
    reuseExistingServer: !process.env.CI,
    timeout: 20_000,
  },
});
