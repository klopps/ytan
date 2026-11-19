// @ts-check
const base = require('./playwright.config.js');

// The guide shows the e2e user's profile (chapter Profil): reset-e2e-db.php
// then copies PaulPaddler's real e-mail from the dev database too - only for
// this run, a normal e2e run sends mails that must not reach it.
process.env.YTAN_E2E_REAL_EMAIL = '1';
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
