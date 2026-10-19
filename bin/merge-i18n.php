#!/usr/bin/env php
<?php

declare(strict_types=1);

// Run by the deploy scripts on the server after the upload: the package
// carries the locale files as resources/i18n-incoming/ (so the upload never
// overwrites resources/i18n/), this merges them into resources/i18n/ - new
// keys added, removed keys dropped, existing values kept. See
// TranslationMerger.

require dirname(__DIR__) . '/vendor/autoload.php';

use Ytan\Service\TranslationMerger;

$root = dirname(__DIR__);
$incoming = $root . '/resources/i18n-incoming';
if (!is_dir($incoming)) {
    echo "No resources/i18n-incoming - nothing to merge.\n";
    exit(0);
}

try {
    $report = TranslationMerger::mergeDirectory($incoming, $root . '/resources/i18n');
} catch (RuntimeException $e) {
    fwrite(STDERR, 'i18n merge FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
foreach ($report as $locale => $r) {
    echo "i18n $locale: {$r['added']} added, {$r['removed']} removed, {$r['kept']} kept\n";
}
