<?php

declare(strict_types=1);

/**
 * Helper of bin\deploy.bat (runs LOCALLY, with the 8.x PHP): lets a deploy upload
 * only the files that differ from the server instead of all ~6,500 (a small change
 * used to cost two minutes of upload + extraction, mostly for vendor/).
 *
 *   php deploy-manifest.php manifest BUILD_DIR OUT [VENDOR_HASHES]
 *       VENDOR_HASHES = file with the hashes of vendor/ kept next to the vendor
 *       cache (read when it exists, written when not; "-" = none).
 *       OUT = "sha1  relative/path" for every file of the package, sorted - the
 *       format `sha1sum -c` reads on the server, which tells deploy.bat which
 *       of them are missing or different there (stateless: no state file on the
 *       server that could drift away from reality).
 *
 *   php deploy-manifest.php select BUILD_DIR MANIFEST RAW OUT
 *       RAW = what the server printed (one path per failed file, or the line
 *       "*FULL*" when it could not compare). OUT = the files `tar -T` uploads:
 *       the failed ones, resources/i18n-incoming/ (bin/merge-i18n.php reads it on
 *       every deploy) and .deploy-full.list - written into BUILD_DIR here: the
 *       complete file list of this deploy, which bin/deploy-prune.sh needs to
 *       find what dropped out (it used to take the list `tar -xv` printed).
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: see the file header\n");
    exit(2);
}

$mode = $argv[1];
$build = rtrim(str_replace('\\', '/', $argv[2]), '/');

if ($mode === 'manifest') {
    // Hashing vendor/ costs ~100 s here (17 ms per file, 5,800 files - the virus scanner
    // looks at every one) while the app's own files take 0.1 ms. vendor/ is the cached
    // production build, identical until composer.json/lock change, so its hashes are kept
    // next to that cache (argv[4]; "-" = none) and only computed the first time.
    $vendorList = ($argv[4] ?? '-') === '-' ? null : $argv[4];
    $lines = [];
    $vendorKnown = false;
    if ($vendorList !== null && is_file($vendorList)) {
        foreach (file($vendorList, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $lines[substr($line, 42)] = $line;
        }
        $vendorKnown = $lines !== [];
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($build, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        $relative = substr($path, strlen($build) + 1);
        // the key file (bin\deploy.bat tells the server "this vendor/ is that cache entry") is
        // not part of the cached hashes
        if ($vendorKnown && str_starts_with($relative, 'vendor/') && $relative !== 'vendor/.ytan-vendor-key') {
            continue;
        }
        if (str_contains($relative, "\n") || str_contains($relative, '\\')) {
            fwrite(STDERR, "file name not supported by the deploy diff: $relative\n");
            exit(1);
        }
        $lines[$relative] = sha1_file($path) . '  ' . $relative;
    }
    // vendor/ not copied into the package (the server has it): the key file is still part of the
    // deploy, or the prune would delete it (placeholder hash - it is never compared in that case)
    if ($vendorKnown && !isset($lines['vendor/.ytan-vendor-key'])) {
        $lines['vendor/.ytan-vendor-key'] = str_repeat('0', 40) . '  vendor/.ytan-vendor-key';
    }
    if ($vendorList !== null && !$vendorKnown && is_dir(dirname($vendorList))) {
        $vendorLines = array_filter($lines, static fn (string $l, string $p): bool => str_starts_with($p, 'vendor/') && $p !== 'vendor/.ytan-vendor-key', ARRAY_FILTER_USE_BOTH);
        file_put_contents($vendorList, implode("\n", $vendorLines) . "\n");
    }
    ksort($lines, SORT_STRING);
    file_put_contents($argv[3], implode("\n", $lines) . "\n");
    // the same without vendor/: what the server compares when it already has this vendor/
    $appLines = array_filter($lines, static fn (string $l, string $p): bool => !str_starts_with($p, 'vendor/'), ARRAY_FILTER_USE_BOTH);
    file_put_contents($argv[3] . '.app', implode("\n", $appLines) . "\n");
    echo count($lines) . " files in the package\n";
    exit(0);
}

if ($mode === 'select' && $argc >= 6) {
    $known = [];
    foreach (file($argv[3], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $known[substr($line, 42)] = true; // 40 hex characters + two blanks
    }
    $raw = is_file($argv[4]) ? file($argv[4], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : ['*FULL*'];
    $raw = array_map(static fn (string $l): string => rtrim($l, "\r"), $raw);

    if (in_array('*FULL*', $raw, true)) {
        $upload = array_keys($known);
        $reason = 'full upload';
    } else {
        $upload = [];
        foreach ($raw as $path) {
            if (isset($known[$path])) {
                $upload[$path] = $path;
            }
        }
        foreach (array_keys($known) as $path) {
            if (str_starts_with($path, 'resources/i18n-incoming/')) {
                $upload[$path] = $path;
            }
        }
        $upload = array_values($upload);
        $reason = 'only what differs';
    }

    file_put_contents($build . '/.deploy-full.list', implode("\n", array_keys($known)) . "\n");
    // vendor/ is not in the package when the server already has it: nothing to pack there
    $upload = array_values(array_filter($upload, static fn ($path): bool => is_file($build . '/' . $path)));
    $upload[] = '.deploy-full.list';
    file_put_contents($argv[5], implode("\n", $upload) . "\n");
    echo count($upload) - 1 . ' of ' . count($known) . " files are uploaded ($reason)\n";
    exit(0);
}

fwrite(STDERR, "usage: see the file header\n");
exit(2);
