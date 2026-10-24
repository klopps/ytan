<?php

declare(strict_types=1);

/**
 * Helper of bin\publish-watch.bat:
 *
 *   php bin/watch-datafields-json.php --devices     the product ids of watch/manifest.xml, one per line
 *   php bin/watch-datafields-json.php DIR           the index.json for the ytan-DEVICE.prg files in DIR
 *
 * Format and display names: Ytan\Service\WatchDatafieldIndex.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Ytan\Service\WatchDatafieldIndex;

$devices = WatchDatafieldIndex::devices((string) file_get_contents(dirname(__DIR__) . '/watch/manifest.xml'));
$arg = $argv[1] ?? '';

if ($arg === '--devices') {
    echo implode("\n", $devices), "\n";
    exit(0);
}
if ($arg === '' || !is_dir($arg)) {
    fwrite(STDERR, "Usage: php bin/watch-datafields-json.php --devices | DIR\n");
    exit(1);
}

$deviceDir = getenv('APPDATA') . '/Garmin/ConnectIQ/Devices';
$names = [];
$sizes = [];
foreach ($devices as $device) {
    $compiler = $deviceDir . '/' . $device . '/compiler.json';
    $names[$device] = WatchDatafieldIndex::displayName($device, is_file($compiler) ? (string) file_get_contents($compiler) : null);
    $prg = $arg . '/ytan-' . $device . '.prg';
    if (is_file($prg)) {
        $sizes[$device] = (int) filesize($prg);
    }
}

echo json_encode(WatchDatafieldIndex::build($names, $sizes, gmdate('YmdHi')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
