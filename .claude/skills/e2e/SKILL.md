---
name: e2e
description: Run the Playwright e2e suite (tests/e2e) and report the result - nothing else. Use when the user types /e2e or "e2e".
---

1. If a migration was added since the last run, run `tests/e2e/seed/reset-e2e-db.php` first (with `/c/dev/php8/php.exe`), else the web server answers 500/times out.
2. `cd tests/e2e && npm test` (long timeout).
3. Report passed/failed counts; for each failure the test name and the decisive error line verbatim. No commit, no deploy.
