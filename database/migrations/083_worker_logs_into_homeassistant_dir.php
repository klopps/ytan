<?php

declare(strict_types=1);

// Before the worker registry the YTAN worker (then only the Home Assistant add-on) uploaded its logs loose
// into storage/logs/worker/*.log; since 081 every worker has its own directory storage/logs/worker/{name}/
// and /admin/logs shows "YTAN-Worker {name}". The loose files of the one worker there was belong to
// "homeassistant": moved into its directory (where its new uploads overwrite them), or deleted when the
// directory already has a file of that name (the newer one wins).

return function (PDO $pdo): void {
    $dir = dirname(__DIR__, 2) . '/storage/logs/worker';
    if (!is_dir($dir)) {
        echo "  no storage/logs/worker: nothing to move\n";

        return;
    }
    $target = $dir . '/homeassistant';
    if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
        echo "  cannot create $target: loose worker logs stay where they are\n";

        return;
    }
    $moved = 0;
    $dropped = 0;
    foreach (glob($dir . '/*.log') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $destination = $target . '/' . basename($file);
        if (file_exists($destination)) {
            @unlink($file);
            $dropped++;
        } elseif (@rename($file, $destination)) {
            $moved++;
        }
    }
    echo "  loose worker logs: $moved moved into worker/homeassistant/, $dropped dropped (newer ones exist)\n";
};
