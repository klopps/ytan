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

use Ytan\Service\Log\LogWriter;
use Dotenv\Dotenv;
use Ytan\Database\Connection;
use Ytan\Domain\CampsiteImport\CampsiteImportRepository;
use Ytan\Service\Campsite\CampsiteOsmMapper;
use Ytan\Service\CampsiteOsmImportService;
use Ytan\Service\ClubOsmImportService;
use Ytan\Service\Overpass\CurlOverpassClient;
use Ytan\Service\Overpass\OsmImportCliRun;
use Ytan\Service\Overpass\OsmImportJob;
use Ytan\Service\Overpass\OverpassRetry;
use Ytan\Service\OverpassThrottle;
use Ytan\Service\Water\WaterDistanceService;

$root = dirname(__DIR__);
$log = LogWriter::cli($root, 'campsite-import'); // storage/logs/campsite-import.log
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$water = new WaterDistanceService($root . '/storage/water/water-index.bin', $root . '/storage/water/water-index.json');
if (!$water->available()) {
    $log->error("No water index at storage/water/water-index.bin - distances stay empty (run tools/water/build_index.py).");
}
$staging = new CampsiteImportRepository(Connection::fromEnv(), $water);
$osm = new CampsiteOsmImportService(
    CurlOverpassClient::fromSetting((string) ($_ENV['OVERPASS_ENDPOINTS'] ?? ''), CampsiteOsmImportService::REQUEST_TIMEOUT_SECONDS),
    $staging,
    new CampsiteOsmMapper(),
    new OverpassThrottle($root . '/storage/overpass-last-request')
);

// --user=NAME: started in the background from the admin page (OsmImportJob)
[$args, $user] = OsmImportCliRun::parseArgs(array_slice($argv, 1));
$all = in_array('--all', $args, true);
$steps = array_values(array_filter($args, fn ($a) => $a !== '--all'));
if ($steps === []) {
    $steps = ClubOsmImportService::EUROPE;
}

// One OSM import at a time (shared Overpass gap); the admin pages read the status it writes.
$run = new OsmImportCliRun(new OsmImportJob($root), 'campsite', $user);
if (!$run->begin()) {
    $log->error('cli: another OpenStreetMap import is running - nothing done.');
    exit(1);
}
if ($user !== null) {
    $log->line(sprintf('cli: started in the background by %s: %s', $user, implode(' ', $args)));
}

$failed = [];
foreach ($steps as $step) {
    $started = microtime(true);
    try {
        if ($step === 'distances') {
            $n = $staging->recomputeDistances($all, function (int $done) use ($log) {
                $log->line("cli: distances: $done ...");
            });
            $log->line(sprintf("cli: distances: %d computed in %d s", $n, (int) round(microtime(true) - $started)));
            continue;
        }
        // Big countries come in tiles (CampsiteOsmImportService::tiles()); a failed tile doesn't stop the others.
        $tiles = count(CampsiteOsmImportService::tiles(strtoupper($step)));
        $run->country(strtoupper($step), $tiles);
        for ($tile = 0; $tile < $tiles; $tile++) {
            $tileStarted = microtime(true);
            try {
                // Overpass didn't deliver: up to 3 retries 30 s apart, each one logged.
                $r = OverpassRetry::logged(
                    fn () => $osm->import($step, true, $tile),
                    fn (string $line) => $log->line('cli: ' . $line),
                    sprintf('load OpenStreetMap %s tile %d/%d', strtoupper($step), $tile + 1, $tiles),
                    null,
                    fn (int $attempt, int $max, int $delay, Throwable $e) => $run->retry($tile, $attempt, $max, $delay, $e)
                );
                $run->tileDone($tile, $r);
                $log->line(sprintf("cli: load OpenStreetMap %s tile %d/%d: %d read, %d skipped, %d new, %d updated (%.1f s)", strtoupper($step), $tile + 1, $tiles, $r['fetched'], $r['skipped'], $r['inserted'], $r['updated'], microtime(true) - $tileStarted));
            } catch (Throwable $e) {
                $failed[] = $tiles > 1 ? $step . ' (tile ' . ($tile + 1) . '/' . $tiles . ')' : $step;
                $run->tileFailed($tile, $e);
                $log->error(sprintf('cli: %s tile %d/%d: FAILED - %s (%.1f s)', strtoupper($step), $tile + 1, $tiles, $e->getMessage(), microtime(true) - $tileStarted));
            }
        }
    } catch (Throwable $e) {
        $failed[] = $step;
        $log->error(sprintf('cli: %s: FAILED - %s (%.1f s)', $step, $e->getMessage(), microtime(true) - $started));
    }
}
$log->line(sprintf("cli: %d entries without distances to water", $staging->countUnchecked()));
if ($failed !== []) {
    $log->error('cli: failed: ' . implode(' ', $failed) . " - run them again (big countries: Overpass may time out).");
}
$run->finish($failed === [], $failed === [] ? '' : 'failed: ' . implode(' ', $failed));
$log->line('cli: finished' . ($failed === [] ? '' : ' with errors'));

exit($failed === [] ? 0 : 1);
