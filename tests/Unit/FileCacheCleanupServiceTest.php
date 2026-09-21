<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\FileCacheCleanupService;

final class FileCacheCleanupServiceTest extends TestCase
{
    private string $dir;
    private FileCacheCleanupService $cleanup;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-file-cache-cleanup-test-' . uniqid();
        mkdir($this->dir, 0775, true);
        $this->cleanup = new FileCacheCleanupService();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
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

    private function writeFile(string $filename, int $ageSeconds): void
    {
        $path = $this->dir . '/' . $filename;
        file_put_contents($path, 'fake');
        touch($path, time() - $ageSeconds);
    }

    public function testReturnsZeroWhenDirectoryDoesNotExist(): void
    {
        $this->assertSame(0, $this->cleanup->cleanDirectory($this->dir . '/missing', 3600));
    }

    public function testDeletesOnlyFilesOlderThanMaxAge(): void
    {
        $this->writeFile('old.json', 7200);
        $this->writeFile('fresh.json', 60);

        $deleted = $this->cleanup->cleanDirectory($this->dir, 3600);

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($this->dir . '/old.json');
        $this->assertFileExists($this->dir . '/fresh.json');
    }

    public function testNeverDeletesGitkeep(): void
    {
        $this->writeFile('.gitkeep', 999999);

        $deleted = $this->cleanup->cleanDirectory($this->dir, 3600);

        $this->assertSame(0, $deleted);
        $this->assertFileExists($this->dir . '/.gitkeep');
    }

    public function testIgnoresSubdirectories(): void
    {
        mkdir($this->dir . '/subdir', 0775, true);
        touch($this->dir . '/subdir', time() - 999999);

        $deleted = $this->cleanup->cleanDirectory($this->dir, 3600);

        $this->assertSame(0, $deleted);
        $this->assertDirectoryExists($this->dir . '/subdir');
    }
}
