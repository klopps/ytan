<?php

declare(strict_types=1);

// Deletes every log file in storage/logs that no program writes any more,
// since the logs are written by the programs themselves (LogWriter,
// tools/ytanlog.py - done.md "Logfiles"): the hand-named redirection targets
// of before (water-build.log, water-index-build.log, shelter-places.log,
// shelter-distances.log, ...). Kept: the logs in WRITTEN and their archives
// (NAME.N.log). No schema change - a migration so it runs once on every host
// during the deploy.

return function (PDO $pdo): void {
    $written = [
        'jobs', 'radar', 'wind', 'water-index', 'place-names', 'shelter-import', 'campsite-import',
        'poi-water-distance', 'weather-cleanup', 'file-cache-cleanup', 'console',
    ];
    $dir = dirname(__DIR__, 2) . '/storage/logs';
    foreach (glob($dir . '/*.log') ?: [] as $file) {
        $name = preg_replace('/(\.\d+)?\.log$/', '', basename($file));
        if (!in_array($name, $written, true) && is_file($file) && @unlink($file)) {
            echo '  deleted storage/logs/' . basename($file) . "\n";
        }
    }
};
