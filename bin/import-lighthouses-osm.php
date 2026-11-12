#!/usr/bin/env php
<?php

declare(strict_types=1);

// Loads the lights of one area from OpenStreetMap (OpenSeaMap tags, Overpass
// API) into the staging table lighthouse_import (source "osm"), the way the
// admin tool /admin/lighthouse-import does - which starts this script in the
// background (OsmImportJob), so the import goes on when the page is left.
//
//   php bin/import-lighthouses-osm.php --south=54.9 --west=14.6 --north=55.4 --east=15.3 --label=Bornholm
//   (--label64=BASE64 instead of --label: how the admin page passes it)
//
// At most 10 x 10 degrees; an Overpass failure is tried again 3 times, 30 s apart.

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Ytan\Database\Connection;
use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Service\LighthouseList\OsmLightMapper;
use Ytan\Service\Log\LogWriter;
use Ytan\Service\OsmImportService;
use Ytan\Service\Overpass\CurlOverpassClient;
use Ytan\Service\Overpass\OsmImportCliRun;
use Ytan\Service\Overpass\OsmImportJob;
use Ytan\Service\Overpass\OverpassRetry;

$root = dirname(__DIR__);
$log = LogWriter::cli($root, 'lighthouse-import'); // storage/logs/lighthouse-import.log (also the admin tool's)
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

// --user=NAME: started in the background from the admin page (OsmImportJob)
// --resume: tools/ytan_jobs.py runs it again after its process was killed (OsmImportCliRun)
[$args, $user, $resume] = OsmImportCliRun::parseArgs(array_slice($argv, 1));
$area = [];
foreach ($args as $arg) {
    if (preg_match('/^--(south|west|north|east|label)=(.*)$/s', $arg, $m)) {
        $area[$m[1]] = $m[2];
    } elseif (str_starts_with($arg, '--label64=')) {
        // how OsmImportJob passes it (umlauts and the Windows command line)
        $area['label'] = (string) base64_decode(substr($arg, 10), true);
    }
}
foreach (['south', 'west', 'north', 'east'] as $key) {
    if (!isset($area[$key]) || !is_numeric($area[$key])) {
        $log->error("cli: --$key=<degrees> is missing or not a number.");
        exit(2);
    }
}
$label = trim((string) ($area['label'] ?? ''));
$describe = sprintf('%s (%s,%s,%s,%s)', $label, $area['south'], $area['west'], $area['north'], $area['east']);

$service = new OsmImportService(
    CurlOverpassClient::fromSetting((string) ($_ENV['OVERPASS_ENDPOINTS'] ?? '')),
    new LighthouseImportRepository(Connection::fromEnv()),
    new OsmLightMapper(),
    $root . '/storage/overpass-last-request'
);

// One OSM import at a time (shared Overpass gap); the admin page reads the status it writes.
$run = new OsmImportCliRun(new OsmImportJob($root), 'lighthouse', $user, $resume, $args, __FILE__, fn (string $line) => $log->line($line));
if (!$run->begin()) {
    $log->error('cli: another OpenStreetMap import is running - nothing done.');
    exit(1);
}
if ($user !== null && !$resume) {
    $log->line(sprintf('cli: started in the background by %s: %s', $user, $describe));
}

$run->country($label, 1);
$started = microtime(true);
$ok = true;
try {
    // Overpass didn't deliver: up to 3 retries 30 s apart, each one logged.
    $r = OverpassRetry::logged(
        fn () => $service->import((float) $area['south'], (float) $area['west'], (float) $area['north'], (float) $area['east'], $label, true),
        fn (string $line) => $log->line('cli: ' . $line),
        'load OpenStreetMap ' . $describe,
        null,
        fn (int $attempt, int $max, int $delay, Throwable $e) => $run->retry(0, $attempt, $max, $delay, $e)
    );
    $run->tileDone(0, $r);
    $log->line(sprintf('cli: load OpenStreetMap %s: %d fetched, %d new, %d updated, %d skipped (%.1f s)', $describe, $r['fetched'], $r['inserted'], $r['updated'], $r['skipped'], microtime(true) - $started));
} catch (Throwable $e) {
    $ok = false;
    $run->tileFailed(0, $e);
    $log->error(sprintf('cli: load OpenStreetMap %s: FAILED - %s (%.1f s)', $describe, $e->getMessage(), microtime(true) - $started));
}
$run->finish($ok);
$log->line('cli: finished' . ($ok ? '' : ' with errors'));

exit($ok ? 0 : 1);
