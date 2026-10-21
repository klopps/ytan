#!/usr/bin/env php
<?php

declare(strict_types=1);

// Computes the distances to the nearest sea, river/canal and large lake for
// existing POIs and stores them in poi_water_distance (WaterDistanceService,
// local index storage/water/water-index.bin). New and moved POIs of the types
// enabled on /admin/settings get them automatically - this is for the rest:
// after enabling another type, or after a new index (bin\publish-water.bat).
//
//   php bin/poi-water-distance.php           the enabled types, POIs without
//                                            distances or from an older index
//   php bin/poi-water-distance.php 10 14     these POI types instead
//   php bin/poi-water-distance.php --all     every POI of those types again

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Ytan\Database\Connection;
use Ytan\Domain\Poi\PoiWaterDistanceRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Service\Water\PoiWaterDistanceService;
use Ytan\Service\Water\WaterDistanceService;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$water = new WaterDistanceService($root . '/storage/water/water-index.bin', $root . '/storage/water/water-index.json');
if (!$water->available()) {
    fwrite(STDERR, "No water index at storage/water/water-index.bin.\n");
    exit(1);
}

$pdo = Connection::fromEnv();
$settings = new SettingsRepository($pdo);
$repository = new PoiWaterDistanceRepository($pdo);
$service = new PoiWaterDistanceService($water, $repository, $settings);

$args = array_slice($argv, 1);
$all = in_array('--all', $args, true);
$types = array_values(array_map('intval', array_filter($args, 'ctype_digit')));
if ($types === []) {
    $types = $settings->waterDistancePoiTypes();
}
if ($types === []) {
    fwrite(STDERR, "No POI types given and none enabled on /admin/settings.\n");
    exit(1);
}

$pois = $repository->poisToCompute($types, $water->version(), $all);
echo sprintf("POI types %s: %d POIs to compute (index %s)\n", implode(', ', $types), count($pois), $water->version() ?? '?');
$started = microtime(true);
foreach ($pois as $i => $poi) {
    $service->compute($poi);
    if (($i + 1) % 1000 === 0) {
        echo sprintf("%d ...\n", $i + 1);
    }
}
echo sprintf("%d computed in %d s\n", count($pois), (int) round(microtime(true) - $started));
