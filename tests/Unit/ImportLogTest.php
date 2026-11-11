<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ytan\Exception\ValidationException;
use Ytan\Service\Log\ImportLog;

/**
 * ImportLog: one line per import action with who, parameters, result and
 * duration, a "started" line for fetching actions, errors with HTTP status,
 * never an exception from the logging itself.
 */
final class ImportLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-importlog-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/storage/logs/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/storage/logs');
        rmdir($this->dir . '/storage');
        rmdir($this->dir);
    }

    /** @return list<string> the lines without their date/time */
    private function lines(): array
    {
        $file = $this->dir . '/storage/logs/test-import.log';
        if (!is_file($file)) {
            return [];
        }

        return array_map(function (string $line): string {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} /', $line);

            return substr($line, 20);
        }, file($file, FILE_IGNORE_NEW_LINES));
    }

    public function testSuccessIsOneLineWithUserParamsResultAndDuration(): void
    {
        $log = ImportLog::file($this->dir, 'test-import');
        $result = $log->run(['username' => 'anna', 'sub' => 3], 'import as POIs', ['selected' => 12, 'public' => false], fn () => [
            'imported' => 10, 'skipped' => 2, 'conflicts' => [['id' => 1], ['id' => 2]],
        ]);

        $this->assertSame(10, $result['imported']);
        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $this->assertMatchesRegularExpression(
            '/^anna: import as POIs selected=12 public=no - result imported=10 skipped=2 conflicts=2 \(\d+\.\d s\)$/',
            $lines[0]
        );
    }

    public function testAnnouncedActionsGetAStartedLine(): void
    {
        $log = ImportLog::file($this->dir, 'test-import');
        $log->run(['sub' => 7], 'load OpenStreetMap', ['country' => 'SE'], fn () => ['fetched' => 5], true);

        $lines = $this->lines();
        $this->assertSame('user #7: load OpenStreetMap country=SE - started', $lines[0]);
        $this->assertStringStartsWith('user #7: load OpenStreetMap country=SE - result fetched=5 (', $lines[1]);
    }

    public function testErrorsAreLoggedAndRethrown(): void
    {
        $log = ImportLog::file($this->dir, 'test-import');
        try {
            $log->run(['username' => 'anna'], 'load OpenStreetMap', ['country' => 'XX'], function () {
                throw new ValidationException('country must be the ISO 3166-1 code of a European country.');
            });
            $this->fail('expected the exception');
        } catch (ValidationException) {
        }
        try {
            $log->run(['username' => 'anna'], 'load OpenStreetMap', ['country' => 'SE'], function () {
                throw new RuntimeException('Overpass API not available (overpass-api.de: HTTP 504).');
            });
        } catch (RuntimeException) {
        }

        $lines = $this->lines();
        $this->assertStringStartsWith('ERROR anna: load OpenStreetMap country=XX - FAILED (HTTP 422): country must be', $lines[0]);
        $this->assertStringStartsWith('ERROR anna: load OpenStreetMap country=SE - FAILED: Overpass API not available', $lines[1]);
    }

    public function testSummaryLongValuesAndNoLog(): void
    {
        $log = ImportLog::file($this->dir, 'test-import');
        $log->run(null, 'import GPX', ['name' => str_repeat('x', 200)], fn () => ['routes' => [1, 2], 'tour' => ['id' => 9, 'name' => 'T']], false, fn (array $r) => ['routes' => $r['routes'], 'tour' => $r['tour']['id']]);
        $this->assertMatchesRegularExpression('/^cli: import GPX name=x{117}\.\.\. - result routes=2 tour=9 \(/', $this->lines()[0]);

        // ImportLog::none() and an unwritable file never throw.
        $this->assertSame(1, ImportLog::none()->run(null, 'x', [], fn () => 1));
        $blocker = $this->dir . '/storage/logs/blocker'; // a file where the log directory would have to be
        file_put_contents($blocker, '');
        $this->assertSame(2, ImportLog::file($blocker, 'x')->run(null, 'x', [], fn () => 2));
    }
}
