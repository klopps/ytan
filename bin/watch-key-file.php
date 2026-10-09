<?php

declare(strict_types=1);

/**
 * Writes the Connect IQ settings file (.SET) that gives the YTAN data field
 * on a sideloaded watch its watch key without a new build:
 *
 *   php bin/watch-key-file.php WATCH-KEY [NAME] [OUTPUT-DIR]
 *
 * YTAN's Profile > Garmin watch offers the same file for download right
 * after "create key" (WatchController::settingsFile()); this CLI does it
 * for a key you already have. The format is WatchSettingsFile's.
 *
 * NAME is the name of the watch's settings file for the data field - NOT
 * necessarily the name of the .prg you copy now: the watch keeps the name of
 * the FIRST installation of the app (same app id), e.g. ytan-fenix7pro
 * although later builds were copied as ytan-fenix7x.prg. After the first
 * start the watch creates it itself in GARMIN\Apps\SETTINGS - use that name.
 * Copy NAME.SET there (replacing the old one); the data field reads it when
 * the activity starts. See bin\watch-key-file.bat for the Windows wrapper.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Ytan\Service\WatchSettingsFile;

$key = $argv[1] ?? '';
$name = $argv[2] ?? 'ytan-fenix7x';
$dir = $argv[3] ?? dirname(__DIR__) . '/watch/bin';

if ($key === '' || !preg_match('/^[A-Za-z0-9]{1,64}$/', $key) || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
    fwrite(STDERR, "Usage: php bin/watch-key-file.php WATCH-KEY [NAME] [OUTPUT-DIR]\n"
        . "  WATCH-KEY: the key from YTAN (Profile, Garmin watch), letters and digits\n"
        . "  NAME: the settings file the watch created in GARMIN\\Apps\\SETTINGS after the first start, without .SET\n"
        . "        (the name of the first installed .prg, default ytan-fenix7x)\n");
    exit(1);
}

if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}
$path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . '.SET';
file_put_contents($path, WatchSettingsFile::build($key));
echo "Wrote $path\nCopy it to the watch: GARMIN\\Apps\\SETTINGS\\$name.SET\n";
