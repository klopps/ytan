<?php

declare(strict_types=1);

// Writes storage/radar/config.json anew from the settings, so that the priority lists of 081
// ("job_workers", plus the old "external_jobs" derived from them) are in it from the moment of the
// deploy: a worker that is updated before an admin saves any radar/wind setting would otherwise find a
// config without lists and stop ("the server is older than this worker"). Only where such a file
// exists (a system whose radar/wind job never ran has none); storage/workers/ and storage/logs/worker/
// are created too, the first worker upload needs them.

use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Service\JobPlacement;
use Ytan\Service\RadarConfig;

return function (PDO $pdo): void {
    $storage = dirname(__DIR__, 2) . '/storage';
    $radarDir = $storage . '/radar';
    if (!is_file($radarDir . '/config.json')) {
        echo "  no storage/radar/config.json yet: nothing to rewrite\n";

        return;
    }
    $settings = new SettingsRepository($pdo);
    JobPlacement::ensureWorkersDir($storage);
    RadarConfig::writeJobConfig($radarDir, $settings->radarSettings(), $settings->windSettings(), $settings->jobWorkers());
    echo "  storage/radar/config.json rewritten with the job lists\n";
};
