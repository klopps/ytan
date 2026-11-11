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

$root = dirname(__DIR__);
$log = LogWriter::cli($root, 'club-import'); // storage/logs/club-import.log (also the admin tool's)
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$countries = array_slice($argv, 1) ?: ClubOsmImportService::EUROPE;
$service = new ClubOsmImportService(
    CurlOverpassClient::fromSetting((string) ($_ENV['OVERPASS_ENDPOINTS'] ?? '')),
    new ClubImportRepository(Connection::fromEnv()),
    new ClubOsmMapper(),
    $root . '/storage/overpass-last-request'
);

$failed = [];
foreach ($countries as $country) {
    $started = microtime(true);
    try {
        $r = $service->import($country, true);
        $log->line(sprintf('cli: load OpenStreetMap %s: %d fetched, %d new, %d updated, %d skipped (no name/position) (%.1f s)', strtoupper($country), $r['fetched'], $r['inserted'], $r['updated'], $r['skipped'], microtime(true) - $started));
    } catch (Throwable $e) {
        $failed[] = strtoupper($country);
        $log->error(sprintf('cli: load OpenStreetMap %s: FAILED - %s (%.1f s)', strtoupper($country), $e->getMessage(), microtime(true) - $started));
    }
}

if ($failed !== []) {
    $log->error('cli: failed: ' . implode(' ', $failed) . ' - rerun with these codes.');
    exit(1);
}
