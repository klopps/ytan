<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\ImageStorageService;

final class ImageStorageServiceTest extends TestCase
{
    private string $storageDir;
    private ImageStorageService $images;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/ytan-image-storage-test-' . uniqid();
        $this->images = new ImageStorageService($this->storageDir);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->storageDir);
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

    private function writeFile(int $entityId, string $filename, string $contents = 'fake'): void
    {
        $dir = $this->storageDir . '/' . $entityId;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $filename, $contents);
    }

    public function testListStoredFilesReturnsEmptyArrayWhenStorageDirDoesNotExist(): void
    {
        $this->assertSame([], $this->images->listStoredFiles());
    }

    public function testListStoredFilesFindsFilesAcrossMultipleEntityDirectories(): void
    {
        $this->writeFile(1, 'a.jpg', 'aaaa');
        $this->writeFile(2, 'b.jpg', 'bb');

        $files = $this->images->listStoredFiles();

        $this->assertCount(2, $files);
        $byFilename = [];
        foreach ($files as $file) {
            $byFilename[$file['filename']] = $file;
        }
        $this->assertSame(1, $byFilename['a.jpg']['entity_id']);
        $this->assertSame(4, $byFilename['a.jpg']['size_bytes']);
        $this->assertSame(2, $byFilename['b.jpg']['entity_id']);
        $this->assertSame(2, $byFilename['b.jpg']['size_bytes']);
    }

    public function testListStoredFilesIgnoresNonNumericEntriesInStorageDir(): void
    {
        mkdir($this->storageDir, 0775, true);
        file_put_contents($this->storageDir . '/.gitkeep', '');
        $this->writeFile(5, 'real.jpg');

        $files = $this->images->listStoredFiles();

        $this->assertCount(1, $files);
        $this->assertSame('real.jpg', $files[0]['filename']);
    }

    public function testDeleteThenListStoredFilesNoLongerReportsTheDeletedFile(): void
    {
        $this->writeFile(3, 'gone.jpg');
        $this->assertCount(1, $this->images->listStoredFiles());

        $this->images->delete(3, 'gone.jpg');

        $this->assertSame([], $this->images->listStoredFiles());
    }
}
