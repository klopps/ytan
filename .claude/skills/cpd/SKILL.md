---
name: cpd
description: Commit, git push and deploy to PRODUCTION (bin\deploy.bat) - without the e2e suite. Use when the user types /cpd or "cpd".
---

1. Do everything of the `cp` skill (review, commit without `Co-Authored-By` trailer, push).
2. Deploy to production from the repo root: `cmd /c "bin\deploy.bat"` (PowerShell, long timeout - it builds `vendor/`, uploads, runs `merge-i18n.php` + `migrate.php` on the server).
3. Report commit hash and the deploy result (i18n/migration lines, errors verbatim).

Do NOT run the e2e suite (user decision 2026-10-22). PHPUnit after code changes stays as always.
