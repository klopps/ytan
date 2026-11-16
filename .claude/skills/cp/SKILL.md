---
name: cp
description: Commit the current changes and git push - no deploy, no e2e. Use when the user types /cp or "cp".
---

1. Look at `git status` and `git diff --stat`. Split unrelated changes into separate logical commits.
2. Commit in the project's style: German summary, prefix `NEW:`/`CHANGE:`/`FIX:`, umlauts written as ae/oe/ue. No `Co-Authored-By` trailer.
3. `git push`.
4. Report the commit hash(es). Nothing else - no deploy, no e2e.
