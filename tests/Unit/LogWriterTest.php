<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Log\LogRotation;
use Ytan\Service\Log\LogWriter;

/**
 * LogWriter/LogRotation: date and time in front of every line, appending,
 * and archiving the oldest 1000 lines into NAME.N.log once a file has more
 * than 2000 (same rules as tools/ytanlog.py, tested in tools/tests).
 */
final class LogWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-logwriter-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @return list<string> */
    private function numbered(int $from, int $to): array
    {
        return array_map(fn ($i) => "line $i", range($from, $to));
    }

    private function write(string $name, array $lines): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $lines === [] ? '' : implode("\n", $lines) . "\n");

        return $path;
    }

    public function testEveryLineStartsWithDateAndTime(): void
    {
        $this->assertSame(
            "2026-11-11 09:05:07 first\n2026-11-11 09:05:07 second\n",
            LogWriter::stamp("first\r\nsecond\n", mktime(9, 5, 7, 11, 11, 2026))
        );
    }

    public function testLinesAreAppendedNotOverwritten(): void
    {
        $path = $this->write('job.log', ['2026-01-01 00:00:00 old']);
        $log = new LogWriter($path);
        $log->line('new');
        (new LogWriter($path))->line("multi\nline");

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertCount(4, $lines);
        $this->assertSame('2026-01-01 00:00:00 old', $lines[0]);
        foreach (array_slice($lines, 1) as $line) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} /', $line);
        }
        $this->assertStringEndsWith(' line', $lines[3]);
    }

    public function testNothingHappensUpTo2000Lines(): void
    {
        $path = $this->write('job.log', $this->numbered(1, 2000));

        $this->assertSame([], LogRotation::rotate($path));
        $this->assertSame(2000, LogRotation::countLines($path));
    }

    public function testOldest1000LinesGoIntoTheNextNumberedArchive(): void
    {
        $path = $this->write('job.log', $this->numbered(1, 2001));
        $this->write('job.3.log', ['older archive']);
        $this->write('other.7.log', ['another log']);

        $archives = LogRotation::rotate($path);

        $this->assertSame([$this->dir . '/job.4.log'], $archives);
        $this->assertSame($this->numbered(1, 1000), file($this->dir . '/job.4.log', FILE_IGNORE_NEW_LINES));
        $this->assertSame($this->numbered(1001, 2001), file($path, FILE_IGNORE_NEW_LINES));
    }

    public function testAVeryLongFileGetsSeveralArchivesOldestFirst(): void
    {
        $path = $this->write('job.log', $this->numbered(1, 4500));

        $archives = LogRotation::rotate($path);

        $this->assertSame([$this->dir . '/job.1.log', $this->dir . '/job.2.log', $this->dir . '/job.3.log'], $archives);
        $this->assertSame('line 1', file($archives[0], FILE_IGNORE_NEW_LINES)[0]);
        $this->assertSame('line 2001', file($archives[2], FILE_IGNORE_NEW_LINES)[0]);
        $this->assertSame($this->numbered(3001, 4500), file($path, FILE_IGNORE_NEW_LINES));
    }

    public function testWriterRotatesWhileWriting(): void
    {
        $path = $this->write('job.log', $this->numbered(1, 1999));
        $log = new LogWriter($path);
        $log->line('2000');
        $this->assertFileDoesNotExist($this->dir . '/job.1.log');
        $log->line('2001');

        $this->assertFileExists($this->dir . '/job.1.log');
        $this->assertSame(1001, LogRotation::countLines($path));
    }

    public function testRotationKeepsAnAppendHandleWorking(): void
    {
        $path = $this->write('job.log', $this->numbered(1, 2500));
        $handle = fopen($path, 'ab');

        LogRotation::rotate($path);
        fwrite($handle, "after rotation\n");
        fclose($handle);

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertSame('line 1001', $lines[0]);
        $this->assertSame('after rotation', end($lines));
        $this->assertCount(1501, $lines);
    }
}
