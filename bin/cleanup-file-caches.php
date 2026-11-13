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
//   0 3 * * * /usr/bin/php /path/to/ytan/bin/cleanup-file-caches.php >> /path/to/ytan/storage/logs/console.log 2>&1
// The script writes storage/logs/file-cache-cleanup.log itself (LogWriter); console.log only
// catches what goes wrong before that.
// See CLAUDE.md's Commands section.

require dirname(__DIR__) . '/vendor/autoload.php';

use Ytan\Service\Log\LogRotation;
use Ytan\Service\Log\LogWriter;
use Ytan\Service\FileCacheCleanupService;
use Ytan\Service\GeocodingService;
use Ytan\Service\StaticMapImageService;

$root = dirname(__DIR__);
$log = LogWriter::cli($root, 'file-cache-cleanup'); // storage/logs/file-cache-cleanup.log
$cleaner = new FileCacheCleanupService();

$caches = [
    'geocoding-cache' => GeocodingService::CACHE_TTL_SECONDS,
    'static-maps-cache' => StaticMapImageService::CACHE_MAX_AGE_SECONDS,
    'ai-cache' => \Ytan\Service\Ai\AiTextCache::MAX_AGE_SECONDS,
];

$total = 0;
foreach ($caches as $dirName => $maxAgeSeconds) {
    $deleted = $cleaner->cleanDirectory($root . '/storage/' . $dirName, $maxAgeSeconds);
    $log->line("Deleted $deleted stale file(s) from storage/$dirName/.");
    $total += $deleted;
}

$log->line("Total: $total file(s) deleted.");

// PHP's own error log: on prod public/.user.ini points error_log at
// storage/logs/error.log. PHP only appends to it, so it gets the same rotation
// as the logs YTAN writes itself (LogRotation: > 2000 lines -> error.N.log,
// > 20 archives -> ZIP). Fixed path, not ini_get('error_log') - the CLI does
// not read .user.ini.
$errorLog = $root . '/storage/logs/error.log';
if (is_file($errorLog)) {
    $archives = LogRotation::rotate($errorLog);
    $log->line($archives === []
        ? 'storage/logs/error.log: nothing to rotate.'
        : 'storage/logs/error.log: rotated into ' . implode(', ', array_map('basename', $archives)) . '.');
}
