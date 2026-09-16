<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\TranslationUsageScanner;

final class TranslationUsageScannerTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir() . '/ytan-usage-scanner-test-' . uniqid();
        mkdir($this->rootDir . '/public/js', 0777, true);
        mkdir($this->rootDir . '/templates', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->rootDir);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testFindsAJsCallSiteWithItsLineNumber(): void
    {
        file_put_contents($this->rootDir . '/public/js/fake.js', "line one\nshowToast(t('poi.save_failed'), 'error');\n");

        $usage = (new TranslationUsageScanner($this->rootDir))->scan();

        $this->assertSame([['file' => 'public/js/fake.js', 'line' => 2]], $usage['poi.save_failed']);
    }

    public function testFindsAPhpCallSiteWithItsLineNumber(): void
    {
        file_put_contents($this->rootDir . '/templates/fake.php', "<h1><?= \$t('app.nav.pois') ?></h1>\n");

        $usage = (new TranslationUsageScanner($this->rootDir))->scan();

        $this->assertSame([['file' => 'templates/fake.php', 'line' => 1]], $usage['app.nav.pois']);
    }

    /**
     * templates/partials/ (the shared /admin/* page shell) is one
     * directory level deeper than the plain templates/*.php a naive glob()
     * would find - real regression caught during the AdminLTE rebuild,
     * where every key only used in admin-shell-header.php silently showed
     * up as "not referenced anywhere" in the translate tool.
     */
    public function testFindsAPhpCallSiteInsideTemplatesPartialsSubdirectory(): void
    {
        mkdir($this->rootDir . '/templates/partials', 0777, true);
        file_put_contents($this->rootDir . '/templates/partials/admin-shell-header.php', "<?= \$t('admin.nav.dashboard') ?>\n");

        $usage = (new TranslationUsageScanner($this->rootDir))->scan();

        $this->assertSame([['file' => 'templates/partials/admin-shell-header.php', 'line' => 1]], $usage['admin.nav.dashboard']);
    }

    public function testARepeatedKeyInOneFileRecordsEveryOccurrence(): void
    {
        file_put_contents($this->rootDir . '/public/js/fake.js', "t('common.cancel');\nt('common.cancel');\n");

        $usage = (new TranslationUsageScanner($this->rootDir))->scan();

        $this->assertSame(
            [['file' => 'public/js/fake.js', 'line' => 1], ['file' => 'public/js/fake.js', 'line' => 2]],
            $usage['common.cancel']
        );
    }

    public function testAKeyUsedInBothJsAndPhpRecordsBothEntries(): void
    {
        file_put_contents($this->rootDir . '/public/js/fake.js', "t('common.save');\n");
        file_put_contents($this->rootDir . '/templates/fake.php', "<?= \$t('common.save') ?>\n");

        $usage = (new TranslationUsageScanner($this->rootDir))->scan();

        $this->assertCount(2, $usage['common.save']);
        $this->assertSame('public/js/fake.js', $usage['common.save'][0]['file']);
        $this->assertSame('templates/fake.php', $usage['common.save'][1]['file']);
    }

    public function testAComputedKeyIsNotMatched(): void
    {
        file_put_contents($this->rootDir . '/public/js/fake.js', "var key = 'dynamic.key';\nt(key);\n");

        $usage = (new TranslationUsageScanner($this->rootDir))->scan();

        $this->assertSame([], $usage);
    }

    public function testBothSingleAndDoubleQuotedKeysAreMatched(): void
    {
        file_put_contents($this->rootDir . '/public/js/fake.js', "t('single.quoted');\nt(\"double.quoted\");\n");

        $usage = (new TranslationUsageScanner($this->rootDir))->scan();

        $this->assertArrayHasKey('single.quoted', $usage);
        $this->assertArrayHasKey('double.quoted', $usage);
    }

    public function testAKeyWithNoUsagesIsSimplyAbsentFromTheIndex(): void
    {
        file_put_contents($this->rootDir . '/public/js/fake.js', "t('used.key');\n");

        $usage = (new TranslationUsageScanner($this->rootDir))->scan();

        $this->assertArrayNotHasKey('unused.key', $usage);
    }

    public function testScanPlaceholdersFindsAJsObjectLiteralVarName(): void
    {
        file_put_contents($this->rootDir . '/public/js/fake.js', "t('about.p1', { app: appName });\n");

        $placeholders = (new TranslationUsageScanner($this->rootDir))->scanPlaceholders();

        $this->assertSame(['app'], $placeholders['about.p1']);
    }

    public function testScanPlaceholdersFindsAPhpArrayLiteralVarName(): void
    {
        file_put_contents($this->rootDir . '/templates/fake.php', "<?= \$t('about.p1', ['app' => \$appName]) ?>\n");

        $placeholders = (new TranslationUsageScanner($this->rootDir))->scanPlaceholders();

        $this->assertSame(['app'], $placeholders['about.p1']);
    }

    public function testScanPlaceholdersHandlesMultipleVarsSortedAlphabetically(): void
    {
        file_put_contents($this->rootDir . '/public/js/fake.js', "t('route.info.recorded', { by: x, start: y, end: z });\n");

        $placeholders = (new TranslationUsageScanner($this->rootDir))->scanPlaceholders();

        $this->assertSame(['by', 'end', 'start'], $placeholders['route.info.recorded']);
    }

    public function testScanPlaceholdersHandlesAVarsObjectSpanningMultipleLines(): void
    {
        file_put_contents(
            $this->rootDir . '/public/js/fake.js',
            "t('route.info.recorded', {\n    by: x,\n    start: y,\n    end: z,\n});\n"
        );

        $placeholders = (new TranslationUsageScanner($this->rootDir))->scanPlaceholders();

        $this->assertSame(['by', 'end', 'start'], $placeholders['route.info.recorded']);
    }

    public function testScanPlaceholdersDeduplicatesAcrossMultipleCallSites(): void
    {
        file_put_contents($this->rootDir . '/public/js/fake.js', "t('common.save_failed', { error: err1 });\n");
        file_put_contents($this->rootDir . '/templates/fake.php', "<?= \$t('common.save_failed', ['error' => \$err2]) ?>\n");

        $placeholders = (new TranslationUsageScanner($this->rootDir))->scanPlaceholders();

        $this->assertSame(['error'], $placeholders['common.save_failed']);
    }

    public function testScanPlaceholdersDoesNotMistakeATernaryForAnObjectKey(): void
    {
        file_put_contents($this->rootDir . '/public/js/fake.js', "t('some.key', { label: cond ? a : b });\n");

        $placeholders = (new TranslationUsageScanner($this->rootDir))->scanPlaceholders();

        $this->assertSame(['label'], $placeholders['some.key']);
    }

    public function testScanPlaceholdersOmitsAKeyWithNoVarsArgument(): void
    {
        file_put_contents($this->rootDir . '/public/js/fake.js', "t('common.cancel');\n");

        $placeholders = (new TranslationUsageScanner($this->rootDir))->scanPlaceholders();

        // Same convention as scan() itself (see
        // testAKeyWithNoUsagesIsSimplyAbsentFromTheIndex above) - "no
        // placeholders found" is an absent key, not an empty array;
        // TranslationController::index() is what turns that into a `[]` in
        // the API response for every key, found or not.
        $this->assertArrayNotHasKey('common.cancel', $placeholders);
    }
}
