<?php

declare(strict_types=1);

// One-time backfill: distances to the nearest sea, river/canal and large lake
// (poi_water_distance) for every existing POI of the types enabled on
// /admin/settings (since migration 058 all but 2 landing sites, 6 danger
// spots, 9 portages, 14 lights, 16 fishing). New and moved POIs get them on
// save (PoiWaterDistanceService::afterSave()); this covers the POIs that
// existed before. Same as `php bin/poi-water-distance.php`, which stays the
// way to run it again - e.g. when this host had no water index yet
// (storage/water/water-index.bin, bin\publish-water.bat): then this only says
// so and changes nothing.

use Ytan\Domain\Poi\PoiWaterDistanceRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Service\Water\PoiWaterDistanceService;
use Ytan\Service\Water\WaterDistanceService;

return function (PDO $pdo): void {
    $root = dirname(__DIR__, 2);
    $water = new WaterDistanceService($root . '/storage/water/water-index.bin', $root . '/storage/water/water-index.json');
    if (!$water->available()) {
        echo "  no water index at storage/water/water-index.bin - skipped, run bin/poi-water-distance.php once it is there\n";

        return;
    }

    $settings = new SettingsRepository($pdo);
    $repository = new PoiWaterDistanceRepository($pdo);
    $service = new PoiWaterDistanceService($water, $repository, $settings);

    $types = $settings->waterDistancePoiTypes();
    $pois = $repository->poisToCompute($types, $water->version(), false);
    $started = microtime(true);
    foreach ($pois as $poi) {
        $service->compute($poi);
    }
    echo sprintf(
        "  POI types %s: %d POIs computed in %d s (index %s)\n",
        implode(', ', $types),
        count($pois),
        (int) round(microtime(true) - $started),
        $water->version() ?? '?'
    );
};
