---
name: cpde2e
description: Run the e2e suite; only if it passes completely, commit, push and deploy to PRODUCTION. Use when the user types /cpde2e or "cpde2e".
---

1. Run the `e2e` skill.
2. Any failure: stop and report it. No commit, no push, no deploy.
3. All green: do the `cpd` skill (commit without `Co-Authored-By` trailer, push, `cmd /c "bin\deploy.bat"`).
