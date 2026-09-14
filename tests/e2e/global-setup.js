const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const PHP_BIN = process.env.PHP_BIN || 'C:/dev/php8/php.exe';
const CREDENTIALS_FILE = path.join(__dirname, '.e2e-credentials.json');

/**
 * Runs once before the whole suite: seeds/resets the e2e test user (and
 * its previously-created data) in the ytan_test database via
 * seed/reset-e2e-db.php, then stashes the resulting credentials where
 * fixtures.js can read them synchronously (Playwright's globalSetup has
 * no direct return channel into test files).
 */
module.exports = async function globalSetup() {
  const output = execFileSync(PHP_BIN, [path.join(__dirname, 'seed', 'reset-e2e-db.php')], {
    encoding: 'utf8',
  });
  const lastLine = output.trim().split('\n').pop();
  const credentials = JSON.parse(lastLine);
  fs.writeFileSync(CREDENTIALS_FILE, JSON.stringify(credentials, null, 2));
};
