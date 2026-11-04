#!/usr/bin/env php
<?php

declare(strict_types=1);

// Loads campsites (OpenStreetMap tourism=camp_site) into the staging table
// campsite_import, computing their distances to the sea, rivers and large
// lakes from the local water index (storage/water/water-index.bin - only for
// its countries, elsewhere they stay empty), so the admin tool
// /admin/campsite-import only has to filter, select and import them.
//
//   php bin/import-campsites.php                  every European country (about
//                                                 25 minutes: 30 s between requests)
//   php bin/import-campsites.php DK SE            only these countries
//   php bin/import-campsites.php distances        compute the distances still missing
//   php bin/import-campsites.php distances --all  compute every distance again (new index)
//
// Meant for dev; prod gets the result as a transfer file (admin tool:
// "Exportieren" here, "Datei einlesen" there), or runs this script itself.

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Ytan\Database\Connection;
use Ytan\Domain\CampsiteImport\CampsiteImportRepository;
use Ytan\Service\Campsite\CampsiteOsmMapper;
use Ytan\Service\CampsiteOsmImportService;
use Ytan\Service\ClubOsmImportService;
use Ytan\Service\Overpass\CurlOverpassClient;
use Ytan\Service\OverpassThrottle;
use Ytan\Service\Water\WaterDistanceService;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$water = new WaterDistanceService($root . '/storage/water/water-index.bin', $root . '/storage/water/water-index.json');
if (!$water->available()) {
    fwrite(STDERR, "No water index at storage/water/water-index.bin - distances stay empty (run tools/water/build_index.py).\n");
}
$staging = new CampsiteImportRepository(Connection::fromEnv(), $water);
$osm = new CampsiteOsmImportService(
    CurlOverpassClient::fromSetting((string) ($_ENV['OVERPASS_ENDPOINTS'] ?? ''), CampsiteOsmImportService::REQUEST_TIMEOUT_SECONDS),
    $staging,
    new CampsiteOsmMapper(),
    new OverpassThrottle($root . '/storage/overpass-last-request')
);

$args = array_slice($argv, 1);
$all = in_array('--all', $args, true);
$steps = array_values(array_filter($args, fn ($a) => $a !== '--all'));
if ($steps === []) {
    $steps = ClubOsmImportService::EUROPE;
}

$failed = [];
foreach ($steps as $step) {
    try {
        if ($step === 'distances') {
            $started = microtime(true);
            $n = $staging->recomputeDistances($all, function (int $done) {
                echo "distances: $done ...\n";
            });
            echo sprintf("distances: %d computed in %d s\n", $n, (int) round(microtime(true) - $started));
            continue;
        }
        $r = $osm->import($step, true);
        echo sprintf("%s: %d read, %d skipped, %d new, %d updated\n", strtoupper($step), $r['fetched'], $r['skipped'], $r['inserted'], $r['updated']);
    } catch (Throwable $e) {
        $failed[] = $step;
        fwrite(STDERR, $step . ': FAILED - ' . $e->getMessage() . "\n");
    }
}
echo sprintf("%d entries without distances to water\n", $staging->countUnchecked());
if ($failed !== []) {
    fwrite(STDERR, 'Failed: ' . implode(' ', $failed) . " - run them again (big countries: Overpass may time out).\n");
}

exit($failed === [] ? 0 : 1);
