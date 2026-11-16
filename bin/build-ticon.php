<?php

declare(strict_types=1);

/**
 * Builds resources/tide/ticon4.json, the gauge constants TiconTideSource
 * predicts high and low water from, out of the TICON-4 dataset (DGFI-TUM,
 * CC BY 4.0, https://doi.org/10.17882/109129):
 *
 *   php bin/build-ticon.php [TICON_4.csv]
 *
 * Without an argument it downloads the CSV from SEANOE (~47 MB). Run it on a
 * developer machine and commit the result; it is only needed again for a new
 * TICON release.
 *
 * What it keeps: Coastal and River gauges (not Lake), constituents that
 * HarmonicTide knows, amplitude in mm and phase in 0.1 degrees. Many gauges
 * are in the dataset twice or more (e.g. REFMAR and the CMEMS copy of the
 * same French gauge, UHSLC fast-delivery and research-quality): gauges within
 * DUPLICATE_METERS of each other count as one, and the best record wins -
 * fewest quality-control remarks (datum remarks do not matter, the heights
 * are used relative to the mean), then a national provider before the CMEMS
 * and UHSLC copies (better names), then the longest record.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Ytan\Service\GeometryService;
use Ytan\Service\Tide\HarmonicTide;

const SOURCE_URL = 'https://www.seanoe.org/data/00980/109129/data/122848.csv';
const DUPLICATE_METERS = 500;
const WSV_HOURS_AHEAD_OF_UTC = 1.0;

$csv = $argv[1] ?? null;
if ($csv === null) {
    $csv = sys_get_temp_dir() . '/TICON_4.csv';
    if (!is_file($csv)) {
        echo "Downloading " . SOURCE_URL . " ...\n";
        $body = @file_get_contents(SOURCE_URL);
        if ($body === false || strlen($body) < 1000000) {
            fwrite(STDERR, "Download failed.\n");
            exit(1);
        }
        file_put_contents($csv, $body);
    }
}
$handle = @fopen($csv, 'r');
if ($handle === false) {
    fwrite(STDERR, "Cannot read $csv\n");
    exit(1);
}

$known = array_flip(HarmonicTide::names());
$header = fgetcsv($handle, escape: '');
$column = array_flip($header ?: []);
foreach (['lat', 'lon', 'con', 'amp', 'pha', 'years_of_obs', 'gesla_source', 'tide_gauge_name', 'type', 'country', 'record_quality'] as $name) {
    if (!isset($column[$name])) {
        fwrite(STDERR, "Column $name missing - not a TICON-4 file?\n");
        exit(1);
    }
}

/** @var array<string, array<string, mixed>> $gauges */
$gauges = [];
$skipped = [];
while (($row = fgetcsv($handle, escape: '')) !== false) {
    if (count($row) < count($column)) {
        continue;
    }
    $id = $row[$column['tide_gauge_name']] . '|' . $row[$column['gesla_source']];
    if ($row[$column['type']] === 'Lake') {
        continue;
    }
    // the data spell two of Table 1's names differently
    $constituent = ['EP2' => 'EPS2', 'SGM' => 'SIGMA1'][$row[$column['con']]] ?? $row[$column['con']];
    if (!isset($known[$constituent])) {
        $skipped[$constituent] = true;
        continue;
    }
    if (!isset($gauges[$id])) {
        $lng = (float) $row[$column['lon']];
        $gauges[$id] = [
            'lat' => (float) $row[$column['lat']],
            'lng' => $lng > 180 ? $lng - 360 : $lng,
            'raw' => $row[$column['tide_gauge_name']],
            'source' => str_replace('gesla4.', '', $row[$column['gesla_source']]),
            'country' => $row[$column['country']],
            'type' => $row[$column['type']],
            'quality' => $row[$column['record_quality']],
            'years' => (float) $row[$column['years_of_obs']],
            'constants' => [],
        ];
    }
    $amplitude = (int) round((float) $row[$column['amp']] * 10);
    if ($amplitude > 0) {
        $phase = (float) $row[$column['pha']];
        if ($gauges[$id]['source'] === 'WSV') {
            // the WSV records are in CET, not UTC: every German WSV gauge came out 60-75 min late
            // against the BSH predictions, the CMEMS/BfG copies of the same gauges did not
            $phase -= HarmonicTide::speed($constituent) * WSV_HOURS_AHEAD_OF_UTC;
        }
        $phase = fmod($phase, 360.0);
        $gauges[$id]['constants'][$constituent] = [$amplitude, (int) round(($phase < 0 ? $phase + 360 : $phase) * 10) % 3600];
    }
}
fclose($handle);
if ($skipped !== []) {
    echo 'Constituents HarmonicTide does not know (skipped): ' . implode(', ', array_keys($skipped)) . "\n";
}

$rank = static function (array $gauge): array {
    $quality = str_contains($gauge['quality'], 'quality control') ? 1 : 0;
    $copy = in_array($gauge['source'], ['CMEMS', 'UHSLC_FD', 'UHSLC_RQ', 'GLOSS', 'ESEAS'], true) ? 1 : 0;

    return [$quality, $copy, -$gauge['years']];
};
$all = array_values(array_filter($gauges, static fn ($g) => isset($g['constants']['M2']) || isset($g['constants']['K1'])));
usort($all, static fn ($a, $b) => $rank($a) <=> $rank($b));

// keep the best of every cluster: a 0.01 degree grid finds the neighbours
$kept = [];
$grid = [];
foreach ($all as $gauge) {
    $cellLat = (int) floor($gauge['lat'] * 100);
    $cellLng = (int) floor($gauge['lng'] * 100);
    $duplicate = false;
    for ($dy = -1; $dy <= 1 && !$duplicate; $dy++) {
        for ($dx = -1; $dx <= 1 && !$duplicate; $dx++) {
            foreach ($grid[($cellLat + $dy) . ',' . ($cellLng + $dx)] ?? [] as $index) {
                if (GeometryService::distanceMeters($gauge['lat'], $gauge['lng'], $kept[$index]['lat'], $kept[$index]['lng']) <= DUPLICATE_METERS) {
                    $duplicate = true;
                    break;
                }
            }
        }
    }
    if (!$duplicate) {
        $grid[$cellLat . ',' . $cellLng][] = count($kept);
        $kept[] = $gauge;
    }
}

$names = HarmonicTide::names();
$stations = [];
foreach ($kept as $gauge) {
    $amplitudes = [];
    $phases = [];
    foreach ($names as $name) {
        $amplitudes[] = $gauge['constants'][$name][0] ?? 0;
        $phases[] = $gauge['constants'][$name][1] ?? 0;
    }
    $stations[] = [
        round($gauge['lat'], 5),
        round($gauge['lng'], 5),
        displayName($gauge['raw']),
        $gauge['country'],
        $gauge['type'] === 'River' ? 1 : 0,
        $amplitudes,
        $phases,
    ];
}
usort($stations, static fn ($a, $b) => $a[0] <=> $b[0]);

$target = dirname(__DIR__) . '/resources/tide/ticon4.json';
if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0777, true);
}
$json = json_encode([
    'dataset' => 'TICON-4',
    'doi' => '10.17882/109129',
    'license' => 'CC BY 4.0',
    'built' => gmdate('Y-m-d'),
    'note' => 'stations: [lat, lng, name, country, river (1/0), amplitudes in mm, Greenwich phase lags in 0.1 degrees] in the order of constituents',
    'constituents' => $names,
    'stations' => $stations,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($target, $json);
printf("%d records, %d gauges after merging duplicates within %d m -> %s (%.1f MB)\n", count($gauges), count($stations), DUPLICATE_METERS, $target, strlen($json) / 1e6);

/** "nantes_salorges-6143-fra-refmar" -> "Nantes Salorges", "saintmalotg_60minute-sai-fra-cmems" -> "Saintmalo" */
function displayName(string $raw): string
{
    $parts = explode('-', $raw);
    $name = $parts[0];
    $name = preg_replace('/(tg)?(_\d+minute)?$/', '', $name) ?? $name;
    $name = str_replace('_', ' ', $name);

    return ucwords(trim($name)) ?: $raw;
}
