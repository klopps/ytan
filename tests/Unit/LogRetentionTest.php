<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Log\LogRetention;
use Ytan\Service\Log\LogRotation;

final class LogRetentionTest extends TestCase
{
    private string $dir;
    private int $now;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-log-retention-' . uniqid();
        mkdir($this->dir);
        $this->now = strtotime('2026-11-19 03:00:00');
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/*') ?: [], glob($this->dir . '/.*') ?: []) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    private function file(string $name, string $content, string $modified): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);
        touch($path, strtotime($modified));

        return $path;
    }

    public function testArchivesOlderThanTwelveMonthsAreDeletedAndTheCounterKept(): void
    {
        $this->file('radar.1-11.zip', 'packed', '2025-06-01');
        $this->file('radar.12.log', "old\n", '2025-11-18 12:00');
        $this->file('radar.13.log', "recent\n", '2025-11-20');
        $this->file('wind.4.log', "old\n", '2024-01-01');

        $result = LogRetention::purge($this->dir, $this->now);

        $this->assertSame(['radar.1-11.zip', 'radar.12.log', 'wind.4.log'], $result['archives']);
        $this->assertFileExists($this->dir . '/radar.13.log');
        $this->assertSame('12', file_get_contents($this->dir . '/.radar.archive-counter'));
        $this->assertSame('4', file_get_contents($this->dir . '/.wind.archive-counter'));

        // the next rotation of wind.log goes on at 5, not at 1
        file_put_contents($this->dir . '/wind.log', str_repeat("2026-11-19 02:00:00 line\n", 2001));
        $this->assertSame([$this->dir . '/wind.5.log'], LogRotation::rotate($this->dir . '/wind.log'));
    }

    public function testOldLeadingLinesOfTheCurrentLogAreRemovedInPlace(): void
    {
        $path = $this->file('shelter-import.log', implode("\n", [
            '2025-10-01 10:00:00 admin: import DK',
            '2025-11-18 23:59:59 ERROR something',
            '    #0 traceback line without a stamp',
            '2025-11-19 03:00:01 admin: import NO',
            '    #0 a younger traceback stays',
            '2026-11-18 12:00:00 cli: finished',
        ]) . "\n", '2026-11-18 12:00');
        $handle = fopen($path, 'ab'); // a writer holding the file open

        $result = LogRetention::purge($this->dir, $this->now);
        fwrite($handle, "2026-11-19 03:00:05 appended afterwards\n");
        fclose($handle);

        $this->assertSame(['shelter-import.log' => 3], $result['trimmed']);
        $this->assertSame(
            "2025-11-19 03:00:01 admin: import NO\n    #0 a younger traceback stays\n2026-11-18 12:00:00 cli: finished\n2026-11-19 03:00:05 appended afterwards\n",
            file_get_contents($path)
        );
    }

    public function testAnEntirelyOldLogIsEmptiedAndOthersLeftAlone(): void
    {
        $old = $this->file('ai.log', "2025-01-01 00:00:00 a\n2025-02-01 00:00:00 b\n", '2025-02-01');
        $fresh = $this->file('jobs.log', "2026-11-01 00:00:00 tick\n", '2026-11-01');
        $plain = $this->file('console.log', "no time stamp here\n2020-01-01 00:00:00 x\n", '2020-01-01');

        $result = LogRetention::purge($this->dir, $this->now);

        $this->assertSame('', file_get_contents($old));
        $this->assertSame("2026-11-01 00:00:00 tick\n", file_get_contents($fresh));
        $this->assertSame("no time stamp here\n2020-01-01 00:00:00 x\n", file_get_contents($plain), 'first line without a stamp: untouched');
        $this->assertSame(['ai.log' => 2], $result['trimmed']);
    }

    public function testPhpErrorLogFormatIsRecognised(): void
    {
        $this->assertSame(strtotime('2025-11-01 05:00:00 UTC'), LogRetention::lineTime('[01-Nov-2025 05:00:00 UTC] PHP Warning: x'));
        $this->assertSame(strtotime('2025-11-01 05:00:00'), LogRetention::lineTime('2025-11-01 05:00:00 cli: y'));
        $this->assertNull(LogRetention::lineTime('  #1 {main}'));
    }
}
