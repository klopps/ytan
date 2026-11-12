#!/usr/bin/env php
<?php

declare(strict_types=1);

// Loads canoe/kayak and rowing clubs from OpenStreetMap (Overpass API) into
// the staging table club_import, one country after the other (the public
// Overpass instances want 30 s between requests, so Europe takes about 30
// minutes). Afterwards the admin tool /admin/club-import filters, selects and
// imports them as POIs.
//
//   php bin/import-clubs-osm.php              all of Europe (ClubOsmImportService::EUROPE)
//   php bin/import-clubs-osm.php DE DK SE     only these ISO 3166-1 codes
//
// Meant for dev; prod gets the result as a transfer file (admin tool:
// "Exportieren" here, "Datei einlesen" there), or runs this script itself.

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Ytan\Database\Connection;
use Ytan\Domain\ClubImport\ClubImportRepository;
use Ytan\Service\ClubList\ClubOsmMapper;
use Ytan\Service\ClubOsmImportService;
use Ytan\Service\Log\LogWriter;
use Ytan\Service\Overpass\CurlOverpassClient;
use Ytan\Service\Overpass\OsmImportCliRun;
use Ytan\Service\Overpass\OsmImportJob;
use Ytan\Service\Overpass\OverpassRetry;

$root = dirname(__DIR__);
$log = LogWriter::cli($root, 'club-import'); // storage/logs/club-import.log (also the admin tool's)
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

// --user=NAME: started in the background from the admin page (OsmImportJob)
// --resume: tools/ytan_jobs.py continues a run whose process was killed (OsmImportCliRun)
[$args, $user, $resume] = OsmImportCliRun::parseArgs(array_slice($argv, 1));
$countries = $args ?: ClubOsmImportService::EUROPE;
$service = new ClubOsmImportService(
    CurlOverpassClient::fromSetting((string) ($_ENV['OVERPASS_ENDPOINTS'] ?? ''), ClubOsmImportService::REQUEST_TIMEOUT_SECONDS),
    new ClubImportRepository(Connection::fromEnv()),
    new ClubOsmMapper(),
    $root . '/storage/overpass-last-request'
);

// One OSM import at a time (shared Overpass gap); the admin pages read the status it writes.
$run = new OsmImportCliRun(new OsmImportJob($root), 'club', $user, $resume, $args, __FILE__, fn (string $line) => $log->line($line));
if (!$run->begin()) {
    $log->error('cli: another OpenStreetMap import is running - nothing done.');
    exit(1);
}
if ($user !== null && !$resume) {
    $log->line(sprintf('cli: started in the background by %s: %s', $user, implode(' ', $countries)));
}

$failed = [];
foreach ($countries as $country) {
    // Big countries come in tiles (ClubOsmImportService::tiles()); a failed tile doesn't stop the others.
    $code = strtoupper($country);
    $tiles = count(ClubOsmImportService::tiles($code));
    $run->country($code, $tiles);
    for ($tile = $run->firstTile($code); $tile < $tiles; $tile++) {
        $started = microtime(true);
        try {
            // Overpass didn't deliver: up to 3 retries 30 s apart, each one logged.
            $r = OverpassRetry::logged(
                fn () => $service->import($code, true, $tile),
                fn (string $line) => $log->line('cli: ' . $line),
                sprintf('load OpenStreetMap %s tile %d/%d', $code, $tile + 1, $tiles),
                null,
                fn (int $attempt, int $max, int $delay, Throwable $e) => $run->retry($tile, $attempt, $max, $delay, $e)
            );
            $run->tileDone($tile, $r);
            $log->line(sprintf('cli: load OpenStreetMap %s tile %d/%d: %d fetched, %d new, %d updated, %d skipped (no name/position) (%.1f s)', $code, $tile + 1, $tiles, $r['fetched'], $r['inserted'], $r['updated'], $r['skipped'], microtime(true) - $started));
        } catch (Throwable $e) {
            $failed[] = $code;
            $run->tileFailed($tile, $e);
            $log->error(sprintf('cli: load OpenStreetMap %s tile %d/%d: FAILED - %s (%.1f s)', $code, $tile + 1, $tiles, $e->getMessage(), microtime(true) - $started));
        }
    }
}

$run->finish($failed === [], $failed === [] ? '' : 'failed: ' . implode(' ', array_unique($failed)));
$log->line('cli: finished' . ($failed === [] ? '' : ' with errors'));
if ($failed !== []) {
    $log->error('cli: failed: ' . implode(' ', array_unique($failed)) . ' - rerun with these codes.');
    exit(1);
}
