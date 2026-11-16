---
name: cpdtest
description: Commit, git push and deploy to the TEST system (bin\deploy.bat test) - without the e2e suite. Use when the user types /cpdtest or "cpdtest".
---

1. Do everything of the `cp` skill (review, commit without `Co-Authored-By` trailer, push).
2. Deploy to the test system from the repo root: `cmd /c "bin\deploy.bat test"` (same as `bin\deploy_test.bat`; PowerShell, long timeout).
3. Report commit hash and the deploy result (errors verbatim).

Do NOT run the e2e suite.
