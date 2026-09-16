<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\ImageReconciliationService;
use Ytan\Service\ImageStorageService;

/**
 * Uses a minimal in-memory stub instead of a real repository (getAllImages/
 * removeImage is all ImageReconciliationService ever calls, and it isn't
 * type-hinted to a concrete repository class) - keeps this a fast, DB-free
 * Unit test while still exercising the exact same duck-typed contract the
 * real TourRepository/PoiRepository/RouteRepository/AreaRepository provide.
 */
final class FakeImageRepositoryStub
{
    /** @var list<array{id:int, entity_id:int, filename:string, size_bytes:int}> */
    public array $rows;
    private int $nextId;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
        $this->nextId = (max(array_column($rows, 'id') ?: [0]) + 1);
    }

    public function getAllImages(): array
    {
        return $this->rows;
    }

    public function removeImage(int $entityId, int $imageId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            fn (array $row) => !($row['id'] === $imageId && $row['entity_id'] === $entityId)
        ));
    }
}

final class ImageReconciliationServiceTest extends TestCase
{
    private string $tourStorageDir;
    private string $poiStorageDir;

    protected function setUp(): void
    {
        $this->tourStorageDir = sys_get_temp_dir() . '/ytan-reconcile-tour-' . uniqid();
        $this->poiStorageDir = sys_get_temp_dir() . '/ytan-reconcile-poi-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tourStorageDir);
        $this->rmrf($this->poiStorageDir);
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

    private function writeFile(string $storageDir, int $entityId, string $filename): void
    {
        $dir = $storageDir . '/' . $entityId;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $filename, 'fake bytes');
    }

    private function makeService(FakeImageRepositoryStub $tourRepo, FakeImageRepositoryStub $poiRepo): ImageReconciliationService
    {
        return new ImageReconciliationService([
            'tour' => ['repository' => $tourRepo, 'images' => new ImageStorageService($this->tourStorageDir)],
            'poi' => ['repository' => $poiRepo, 'images' => new ImageStorageService($this->poiStorageDir)],
        ]);
    }

    public function testScanFindsAnOrphanedFileWithNoMatchingRow(): void
    {
        $this->writeFile($this->tourStorageDir, 7, 'orphan.jpg');
        $service = $this->makeService(new FakeImageRepositoryStub([]), new FakeImageRepositoryStub([]));

        $result = $service->scan();

        $this->assertCount(1, $result['orphaned_files']);
        $this->assertSame('tour', $result['orphaned_files'][0]['type']);
        $this->assertSame(7, $result['orphaned_files'][0]['entity_id']);
        $this->assertSame('orphan.jpg', $result['orphaned_files'][0]['filename']);
        $this->assertSame([], $result['dangling_rows']);
    }

    public function testScanFindsADanglingRowWithNoMatchingFile(): void
    {
        $tourRepo = new FakeImageRepositoryStub([
            ['id' => 42, 'entity_id' => 9, 'filename' => 'missing.jpg', 'size_bytes' => 123],
        ]);
        $service = $this->makeService($tourRepo, new FakeImageRepositoryStub([]));

        $result = $service->scan();

        $this->assertSame([], $result['orphaned_files']);
        $this->assertCount(1, $result['dangling_rows']);
        $this->assertSame('tour', $result['dangling_rows'][0]['type']);
        $this->assertSame(9, $result['dangling_rows'][0]['entity_id']);
        $this->assertSame(42, $result['dangling_rows'][0]['image_id']);
        $this->assertSame('missing.jpg', $result['dangling_rows'][0]['filename']);
    }

    public function testScanConsidersAFileWithAMatchingRowNotOrphaned(): void
    {
        $this->writeFile($this->tourStorageDir, 3, 'ok.jpg');
        $tourRepo = new FakeImageRepositoryStub([
            ['id' => 1, 'entity_id' => 3, 'filename' => 'ok.jpg', 'size_bytes' => 10],
        ]);
        $service = $this->makeService($tourRepo, new FakeImageRepositoryStub([]));

        $result = $service->scan();

        $this->assertSame([], $result['orphaned_files']);
        $this->assertSame([], $result['dangling_rows']);
    }

    public function testScanCoversEachEntityTypeIndependently(): void
    {
        $this->writeFile($this->poiStorageDir, 5, 'poi-orphan.jpg');
        $tourRepo = new FakeImageRepositoryStub([
            ['id' => 1, 'entity_id' => 1, 'filename' => 'tour-dangling.jpg', 'size_bytes' => 10],
        ]);
        $service = $this->makeService($tourRepo, new FakeImageRepositoryStub([]));

        $result = $service->scan();

        $this->assertCount(1, $result['orphaned_files']);
        $this->assertSame('poi', $result['orphaned_files'][0]['type']);
        $this->assertCount(1, $result['dangling_rows']);
        $this->assertSame('tour', $result['dangling_rows'][0]['type']);
    }

    public function testDeleteOrphanedFileRemovesItFromDisk(): void
    {
        $this->writeFile($this->tourStorageDir, 7, 'orphan.jpg');
        $service = $this->makeService(new FakeImageRepositoryStub([]), new FakeImageRepositoryStub([]));
        $this->assertCount(1, $service->scan()['orphaned_files']);

        $service->deleteOrphanedFile('tour', 7, 'orphan.jpg');

        $this->assertSame([], $service->scan()['orphaned_files']);
    }

    public function testDeleteDanglingRowRemovesItFromTheRepository(): void
    {
        $tourRepo = new FakeImageRepositoryStub([
            ['id' => 42, 'entity_id' => 9, 'filename' => 'missing.jpg', 'size_bytes' => 123],
        ]);
        $service = $this->makeService($tourRepo, new FakeImageRepositoryStub([]));
        $this->assertCount(1, $service->scan()['dangling_rows']);

        $service->deleteDanglingRow('tour', 9, 42);

        $this->assertSame([], $service->scan()['dangling_rows']);
        $this->assertSame([], $tourRepo->getAllImages());
    }
}
