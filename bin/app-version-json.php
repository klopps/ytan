<?php

declare(strict_types=1);

/**
 * Prints the version.json for an Android APK (used by bin\publish-app.bat):
 *
 *   {"versionCode":604,"versionName":"0.6.4","url":"app/ytan.apk","publishedAt":"2026-10-06T10:00:00Z"}
 *
 * The version is read from the APK itself (aapt2 dump badging), not from
 * android/app/build.gradle - the pre-commit hook bumps build.gradle on every
 * android/ commit, so it's routinely ahead of the last actually-built APK,
 * and advertising that number would make every installed app see an
 * "update" it can never reach. "url" is relative to the site's base URL
 * (subdirectory deployments), public/js/native-app.js resolves it.
 *
 * Usage: php bin/app-version-json.php path\to\app.apk
 */

$apk = $argv[1] ?? '';
if ($apk === '' || !is_file($apk)) {
    fwrite(STDERR, "APK not found: $apk\n");
    exit(1);
}

$sdk = getenv('ANDROID_HOME') ?: (getenv('LOCALAPPDATA') . '\\Android\\Sdk');
$candidates = glob($sdk . '/build-tools/*/aapt2.exe') ?: glob($sdk . '/build-tools/*/aapt2') ?: [];
if ($candidates === []) {
    fwrite(STDERR, "aapt2 not found under $sdk/build-tools\n");
    exit(1);
}
natsort($candidates);
$aapt2 = end($candidates);

$output = [];
exec(escapeshellarg($aapt2) . ' dump badging ' . escapeshellarg($apk), $output, $code);
$package = implode("\n", $output);
if ($code !== 0 || preg_match("/^package: .*versionCode='(\\d+)' versionName='([^']+)'/m", $package, $m) !== 1) {
    fwrite(STDERR, "Could not read the APK's version (aapt2 exit $code)\n");
    exit(1);
}

echo json_encode([
    'versionCode' => (int) $m[1],
    'versionName' => $m[2],
    'url' => 'app/ytan.apk',
    'publishedAt' => gmdate('Y-m-d\TH:i:s\Z'),
], JSON_UNESCAPED_SLASHES), "\n";
