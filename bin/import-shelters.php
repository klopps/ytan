#!/usr/bin/env php
<?php

declare(strict_types=1);

// Loads shelters and huts into the staging table shelter_import, computing
// their distances to the sea, rivers and large lakes from the local water
// index (storage/water/water-index.bin, tools/water/build_index.py), so the
// admin tool /admin/shelter-import only has to filter, select and import them.
//
//   php bin/import-shelters.php                  everything: the official lists
//                                                (geofa nvv kv lipas), then OSM
//                                                for DK NO SE FI
//   php bin/import-shelters.php geofa osm:DK     only these steps
//   php bin/import-shelters.php distances        compute the distances still missing
//   php bin/import-shelters.php distances --all  compute every distance again (new index)
//   php bin/import-shelters.php places           names for shelters without one: smallest
//                                                protected area, else nearest named place
//                                                (tools/water/place_names.py, needs its venv);
//                                                --all looks them up again
//
// Meant for dev; prod gets the result as a transfer file (admin tool:
// "Exportieren" here, "Datei einlesen" there), or runs this script itself.

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Ytan\Database\Connection;
use Ytan\Domain\ShelterImport\ShelterImportRepository;
use Ytan\Service\Overpass\CurlOverpassClient;
use Ytan\Service\OverpassThrottle;
use Ytan\Service\ShelterList\GeoFaShelterSource;
use Ytan\Service\ShelterList\KartverketShelterSource;
use Ytan\Service\ShelterList\LipasShelterSource;
use Ytan\Service\ShelterList\NvvShelterSource;
use Ytan\Service\ShelterList\ShelterOsmMapper;
use Ytan\Service\ShelterOsmImportService;
use Ytan\Service\ShelterSourceImportService;
use Ytan\Service\Water\WaterDistanceService;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$water = new WaterDistanceService($root . '/storage/water/water-index.bin', $root . '/storage/water/water-index.json');
if (!$water->available()) {
    fwrite(STDERR, "No water index at storage/water/water-index.bin - distances stay empty (run tools/water/build_index.py).\n");
}
$staging = new ShelterImportRepository(Connection::fromEnv(), $water);
$overpass = CurlOverpassClient::fromSetting((string) ($_ENV['OVERPASS_ENDPOINTS'] ?? ''));
$throttle = new OverpassThrottle($root . '/storage/overpass-last-request');
$sources = new ShelterSourceImportService($staging, [
    new GeoFaShelterSource(),
    new NvvShelterSource(),
    new KartverketShelterSource(),
    new LipasShelterSource(),
]);
$osm = new ShelterOsmImportService($overpass, $staging, new ShelterOsmMapper(), $throttle);

$args = array_slice($argv, 1);
$all = in_array('--all', $args, true);
$steps = array_values(array_filter($args, fn ($a) => $a !== '--all'));
if ($steps === []) {
    $steps = array_merge($sources->codes(), array_map(fn ($c) => 'osm:' . $c, ShelterOsmImportService::SCANDINAVIA), ['places']);
}

$failed = [];
foreach ($steps as $step) {
    try {
        if ($step === 'places') {
            $n = findPlaces($root, $staging, $all);
            echo sprintf("places: %d shelters without a name got one
", $n);
            continue;
        }
        if ($step === 'distances') {
            $started = microtime(true);
            $n = $staging->recomputeDistances($all, function (int $done) {
                echo "distances: $done ...\n";
            });
            echo sprintf("distances: %d computed in %d s\n", $n, (int) round(microtime(true) - $started));
            continue;
        }
        if (str_starts_with($step, 'osm:')) {
            $r = $osm->import(substr($step, 4), true);
        } else {
            $r = $sources->import($step);
        }
        echo sprintf("%s: %d read, %d new, %d updated\n", $step, $r['fetched'], $r['inserted'], $r['updated']);
    } catch (Throwable $e) {
        $failed[] = $step;
        fwrite(STDERR, $step . ': FAILED - ' . $e->getMessage() . "\n");
    }
}
echo sprintf("%d entries without distances to water\n", $staging->countUnchecked());

exit($failed === [] ? 0 : 1);

/**
 * Looks up a place (smallest protected area, else nearest named place) for
 * every shelter without a name of its own, via tools/water/place_names.py
 * (its own venv), and stores it; the name shown and imported is then
 * "type place". Returns how many got a place.
 */
function findPlaces(string $root, ShelterImportRepository $staging, bool $all): int
{
    $python = getenv('WATER_PYTHON') ?: (PHP_OS_FAMILY === 'Windows'
        ? $root . '/tools/water/.venv/Scripts/python.exe'
        : $root . '/tools/water/.venv/bin/python');
    if (!is_file($python)) {
        throw new RuntimeException("No Python for tools/water at $python (see tools/water/README.md).");
    }
    $rows = $staging->findWithoutName($all);
    if ($rows === []) {
        return 0;
    }
    $in = $root . '/storage/water/place-requests.json';
    $out = $root . '/storage/water/place-results.json';
    file_put_contents($in, json_encode(array_map(fn ($r) => [
        'id' => (int) $r['id'], 'lat' => (float) $r['latitude'], 'lng' => (float) $r['longitude'],
    ], $rows)));
    @unlink($out);
    $command = escapeshellarg($python) . ' ' . escapeshellarg($root . '/tools/water/place_names.py') . ' '
        . escapeshellarg($in) . ' ' . escapeshellarg($out);
    passthru($command, $exitCode);
    if ($exitCode !== 0 || !is_file($out)) {
        throw new RuntimeException('place_names.py failed (exit code ' . $exitCode . ').');
    }
    $places = json_decode((string) file_get_contents($out), true);
    if (!is_array($places)) {
        throw new RuntimeException('place_names.py wrote no valid result.');
    }
    $staging->setPlaces(array_map('strval', $places));
    @unlink($in);
    @unlink($out);

    return count($places);
}
