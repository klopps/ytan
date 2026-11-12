#!/usr/bin/env bash
# Daily cleanups, one crontab line per host, e.g.
#   2 0 * * * /path/to/ytan/bin/cron.sh
# (the rain radar / wind jobs have their own entry: tools/ytan_jobs.py every 5 minutes).
#
# Cron starts user crontabs with PATH=/usr/bin:/bin, and PHP is often elsewhere
# (/usr/local/bin/php on prod) - the hand-written predecessor of this script
# called a bare `php` and so never ran (found 2026-11-12 by the admin dashboard:
# old weather_forecast rows, no cleanup logs). PHP_CLI overrides the lookup.
# Both scripts write their own logs (storage/logs/weather-cleanup.log,
# file-cache-cleanup.log); output that is not logged (a crash) goes to console.log.
cd "$(dirname "$0")/.." || exit 1
PHP="${PHP_CLI:-$(PATH="$PATH:/usr/local/bin:/opt/php/bin" command -v php)}"
if [ -z "$PHP" ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') bin/cron.sh: no php found (set PHP_CLI)" >> storage/logs/console.log
    exit 1
fi
"$PHP" bin/cleanup-file-caches.php >> storage/logs/console.log 2>&1
"$PHP" bin/cleanup-weather-data.php >> storage/logs/console.log 2>&1
