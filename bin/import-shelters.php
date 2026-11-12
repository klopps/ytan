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

use Ytan\Service\Log\LogWriter;
use Dotenv\Dotenv;
use Ytan\Database\Connection;
use Ytan\Domain\ShelterImport\ShelterImportRepository;
use Ytan\Service\Overpass\CurlOverpassClient;
use Ytan\Service\Overpass\OsmImportCliRun;
use Ytan\Service\Overpass\OsmImportJob;
use Ytan\Service\Overpass\OverpassRetry;
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
$log = LogWriter::cli($root, 'shelter-import'); // storage/logs/shelter-import.log
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$water = new WaterDistanceService($root . '/storage/water/water-index.bin', $root . '/storage/water/water-index.json');
if (!$water->available()) {
    $log->error("No water index at storage/water/water-index.bin - distances stay empty (run tools/water/build_index.py).");
}
$staging = new ShelterImportRepository(Connection::fromEnv(), $water);
$overpass = CurlOverpassClient::fromSetting((string) ($_ENV['OVERPASS_ENDPOINTS'] ?? ''), ShelterOsmImportService::REQUEST_TIMEOUT_SECONDS);
$throttle = new OverpassThrottle($root . '/storage/overpass-last-request');
$sources = new ShelterSourceImportService($staging, [
    new GeoFaShelterSource(),
    new NvvShelterSource(),
    new KartverketShelterSource(),
    new LipasShelterSource(),
]);
$osm = new ShelterOsmImportService($overpass, $staging, new ShelterOsmMapper(), $throttle);

// --user=NAME: started in the background from the admin page (OsmImportJob)
// --resume: tools/ytan_jobs.py continues a run whose process was killed (OsmImportCliRun)
[$args, $user, $resume] = OsmImportCliRun::parseArgs(array_slice($argv, 1));
$all = in_array('--all', $args, true);
$steps = array_values(array_filter($args, fn ($a) => $a !== '--all'));
if ($steps === []) {
    $steps = array_merge($sources->codes(), array_map(fn ($c) => 'osm:' . $c, ShelterOsmImportService::SCANDINAVIA), ['places']);
}

// One OSM import at a time (shared Overpass gap) - only when OSM steps are asked for;
// the admin pages read the status it writes.
$run = new OsmImportCliRun(new OsmImportJob($root), 'shelter', $user, $resume, $args, __FILE__, fn (string $line) => $log->line($line));
$osmSteps = array_filter($steps, fn ($s) => str_starts_with($s, 'osm:'));
if ($osmSteps !== [] && !$run->begin()) {
    $log->error('cli: another OpenStreetMap import is running - nothing done.');
    exit(1);
}
if ($user !== null && !$resume) {
    $log->line(sprintf('cli: started in the background by %s: %s', $user, implode(' ', $steps)));
}

$failed = [];
foreach ($steps as $step) {
    $started = microtime(true);
    try {
        if ($step === 'places') {
            $n = findPlaces($root, $staging, $all);
            $log->line(sprintf("cli: places: %d shelters without a name got one (%.1f s)", $n, microtime(true) - $started));
            continue;
        }
        if ($step === 'distances') {
            $n = $staging->recomputeDistances($all, function (int $done) use ($log) {
                $log->line("cli: distances: $done ...");
            });
            $log->line(sprintf("cli: distances: %d computed in %d s", $n, (int) round(microtime(true) - $started)));
            continue;
        }
        if (str_starts_with($step, 'osm:')) {
            // Big countries come in tiles (ShelterOsmImportService::tiles()); a failed tile doesn't stop the others.
            $code = strtoupper(substr($step, 4));
            $tiles = count(ShelterOsmImportService::tiles($code));
            $run->country($code, $tiles);
            for ($tile = $run->firstTile($code); $tile < $tiles; $tile++) {
                $tileStarted = microtime(true);
                try {
                    // Overpass didn't deliver: up to 3 retries 30 s apart, each one logged.
                    $r = OverpassRetry::logged(
                        fn () => $osm->import($code, true, $tile),
                        fn (string $line) => $log->line('cli: ' . $line),
                        sprintf('%s tile %d/%d', $step, $tile + 1, $tiles),
                        null,
                        fn (int $attempt, int $max, int $delay, Throwable $e) => $run->retry($tile, $attempt, $max, $delay, $e)
                    );
                    $run->tileDone($tile, $r);
                    $log->line(sprintf("cli: %s tile %d/%d: %d read, %d new, %d updated (%.1f s)", $step, $tile + 1, $tiles, $r['fetched'], $r['inserted'], $r['updated'], microtime(true) - $tileStarted));
                } catch (Throwable $e) {
                    $failed[] = $step;
                    $run->tileFailed($tile, $e);
                    $log->error(sprintf('cli: %s tile %d/%d: FAILED - %s (%.1f s)', $step, $tile + 1, $tiles, $e->getMessage(), microtime(true) - $tileStarted));
                }
            }
            continue;
        }
        $r = $sources->import($step);
        $log->line(sprintf("cli: %s: %d read, %d new, %d updated (%.1f s)", $step, $r['fetched'], $r['inserted'], $r['updated'], microtime(true) - $started));
    } catch (Throwable $e) {
        $failed[] = $step;
        $log->error(sprintf('cli: %s: FAILED - %s (%.1f s)', $step, $e->getMessage(), microtime(true) - $started));
    }
}
$log->line(sprintf("cli: %d entries without distances to water", $staging->countUnchecked()));
$run->finish($failed === [], $failed === [] ? '' : 'failed: ' . implode(' ', array_unique($failed)));
$log->line('cli: finished' . ($failed === [] ? '' : ' with errors'));

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
