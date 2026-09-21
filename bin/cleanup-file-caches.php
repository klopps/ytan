#!/usr/bin/env php
<?php

declare(strict_types=1);

// Deletes stale files from the disk caches under storage/ that have no
// cleanup routine of their own (todo.md's "Cache-Bereinigung" item) - see
// FileCacheCleanupService's doc comment for exactly which storage/
// subdirectories are (and aren't) in scope and why.
//
// This is the second scheduled/recurring task in this project, after
// bin/cleanup-weather-data.php - nothing invokes it automatically. Add a
// cron entry (or systemd timer) on each deployed host yourself, e.g. once
// a day at a quiet hour:
//   0 3 * * * /usr/bin/php /path/to/ytan/bin/cleanup-file-caches.php >> /path/to/ytan/storage/logs/file-cache-cleanup.log 2>&1
// See CLAUDE.md's Commands section.

require dirname(__DIR__) . '/vendor/autoload.php';

use Ytan\Service\FileCacheCleanupService;
use Ytan\Service\GeocodingService;
use Ytan\Service\StaticMapImageService;

$root = dirname(__DIR__);
$cleaner = new FileCacheCleanupService();

$caches = [
    'geocoding-cache' => GeocodingService::CACHE_TTL_SECONDS,
    'static-maps-cache' => StaticMapImageService::CACHE_MAX_AGE_SECONDS,
];

$total = 0;
foreach ($caches as $dirName => $maxAgeSeconds) {
    $deleted = $cleaner->cleanDirectory($root . '/storage/' . $dirName, $maxAgeSeconds);
    echo "Deleted $deleted stale file(s) from storage/$dirName/.\n";
    $total += $deleted;
}

echo "Total: $total file(s) deleted.\n";
