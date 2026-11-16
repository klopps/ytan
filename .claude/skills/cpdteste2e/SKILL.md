---
name: cpdteste2e
description: Run the e2e suite; only if it passes completely, commit, push and deploy to the TEST system. Use when the user types /cpdteste2e or "cpdteste2e".
---

1. Run the `e2e` skill.
2. Any failure: stop and report it. No commit, no push, no deploy.
3. All green: do the `cpdtest` skill (commit without `Co-Authored-By` trailer, push, `cmd /c "bin\deploy.bat test"`).
