<?php

declare(strict_types=1);

// Recomputes the distances to water (poi_water_distance) with the water index
// that knows its covered area (water-index.json "coverage", DK/NO/SE/FI/DE
// since 2026-10-23): POIs outside it lose their values - before, a harbour in
// Germany got 21.5 km to the nearest Danish coast, a POI in the Netherlands a
// distance to wherever the nearest water in the index was. The new index has a
// new version, so every POI of the enabled types is computed again.
//
// Needs the new index on this host first (bin\publish-water.bat, then deploy);
// with an older one this only says so - run bin/poi-water-distance.php later.

use Ytan\Domain\Poi\PoiWaterDistanceRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Service\Water\PoiWaterDistanceService;
use Ytan\Service\Water\WaterDistanceService;

return function (PDO $pdo): void {
    $root = dirname(__DIR__, 2);
    $metaPath = $root . '/storage/water/water-index.json';
    $meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;
    if (!is_array($meta) || empty($meta['coverage'])) {
        echo "  water index without coverage here - skipped, run bin/poi-water-distance.php after bin\\publish-water.bat\n";

        return;
    }
    $water = new WaterDistanceService($root . '/storage/water/water-index.bin', $metaPath);
    if (!$water->available()) {
        echo "  no water index at storage/water/water-index.bin - skipped\n";

        return;
    }

    $settings = new SettingsRepository($pdo);
    $repository = new PoiWaterDistanceRepository($pdo);
    $service = new PoiWaterDistanceService($water, $repository, $settings);

    $types = $settings->waterDistancePoiTypes();
    $pois = $repository->poisToCompute($types, $water->version(), false);
    $started = microtime(true);
    $outside = 0;
    foreach ($pois as $poi) {
        if (!$water->covers((float) $poi['latitude'], (float) $poi['longitude'])) {
            $outside++;
        }
        $service->compute($poi);
    }
    echo sprintf(
        "  POI types %s: %d POIs computed in %d s (index %s, %d outside %s - no distances)\n",
        implode(', ', $types),
        count($pois),
        (int) round(microtime(true) - $started),
        $water->version() ?? '?',
        $outside,
        implode('/', $meta['countries'] ?? [])
    );
};
