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
use Ytan\Service\Overpass\CurlOverpassClient;

$root = dirname(__DIR__);
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
    try {
        $r = $service->import($country, true);
        echo sprintf("%s: %d fetched, %d new, %d updated, %d skipped (no name/position)\n", strtoupper($country), $r['fetched'], $r['inserted'], $r['updated'], $r['skipped']);
    } catch (Throwable $e) {
        $failed[] = strtoupper($country);
        fwrite(STDERR, strtoupper($country) . ': FAILED - ' . $e->getMessage() . "\n");
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Failed: ' . implode(' ', $failed) . " - rerun with these codes.\n");
    exit(1);
}
