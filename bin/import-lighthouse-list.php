#!/usr/bin/env php
<?php

declare(strict_types=1);

// Reads an official lighthouse list (PDF) into the staging table
// lighthouse_import, which the admin tool /admin/lighthouse-import then
// filters, lets the admin select from, and imports as lighthouse POIs.
//
//   php bin/import-lighthouse-list.php dk "Dansk Fyrliste 2022.pdf" [--dry-run]
//   php bin/import-lighthouse-list.php nga NGA_LoL_Pub116.pdf [--dry-run]
//
// Sources: "dk" = Dansk Fyrliste (Danish Maritime Authority), "nga" = the NGA
// List of Lights (Publication 116 and its sister volumes). Run it again
// with a newer list to refresh the staged entries - entries are matched by
// number + position, already imported ones stay marked as imported.
// --dry-run parses and prints a summary without touching the database.
//
// Needs the dev dependency smalot/pdfparser (composer install), so run it on
// a developer machine against the database you want to fill; the web app
// never reads PDFs. (A PDF of 250-350 pages takes 10-20 seconds and 1-2 GB.)

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Ytan\Database\Connection;
use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Service\LighthouseList\DanishLighthouseListParser;
use Ytan\Service\LighthouseList\NgaLighthouseListParser;
use Ytan\Service\LighthouseList\PdfTextFragments;

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$args = array_values(array_filter($args, fn ($a) => $a !== '--dry-run'));

$parsers = ['dk' => DanishLighthouseListParser::class, 'nga' => NgaLighthouseListParser::class];
if (count($args) !== 2 || !isset($parsers[$args[0]]) || !is_file($args[1])) {
    fwrite(STDERR, "Usage: php bin/import-lighthouse-list.php dk|nga <list.pdf> [--dry-run]\n");
    exit(1);
}
[$source, $pdfPath] = $args;

ini_set('memory_limit', '4G');

$entries = (new $parsers[$source]())->parse(PdfTextFragments::pagesOf($pdfPath));

$withSector = count(array_filter($entries, fn ($e) => $e['sector_characteristic'] !== null));
$missingRange = count(array_filter($entries, fn ($e) => $e['sector_missing_range']));
echo count($entries) . " entries read ($withSector with sectors, $missingRange of them with sectors that have no range in the list - the default range chosen at import applies to those).\n";

if ($dryRun) {
    echo "Dry run - nothing written.\n";
    exit(0);
}

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$result = (new LighthouseImportRepository(Connection::fromEnv()))->upsertMany($source, $entries);
echo "Staged: {$result['inserted']} new, {$result['updated']} refreshed.\n";
