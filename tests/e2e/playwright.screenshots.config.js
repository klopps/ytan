// @ts-check
const base = require('./playwright.config.js');
const { defineConfig, devices } = require('@playwright/test');

/**
 * Regenerates the user guide's screenshots (public/images/help/*.jpg) -
 * `npm run screenshots`. Same isolated ytan_e2e database and web server as
 * the regular suite (so the guide never shows real users' data), but its
 * own spec directory: the screenshots are a documentation build step, not
 * a test, and must not run with `npm test`.
 */
module.exports = defineConfig({
  ...base,
  testDir: './screenshots',
  webServer: { ...base.webServer, stdout: 'ignore', stderr: 'ignore' },
  timeout: 240_000,
  projects: [
    {
      name: 'mobile',
      use: {
        ...devices['Pixel 5'],
        viewport: { width: 390, height: 760 },
        deviceScaleFactor: 2,
        locale: 'de-DE',
        timezoneId: 'Europe/Berlin',
      },
    },
  ],
});
