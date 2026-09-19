<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Area\AreaRepository;
use Ytan\Tests\TestCase;

/**
 * Covers the limit/offset pagination added to every find*() method (the
 * "serverseitige Pagination" usability-review recommendation).
 */
final class AreaRepositoryTest extends TestCase
{
    private AreaRepository $areas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->areas = new AreaRepository($this->pdo);
    }

    private function createArea(int $userId, array $overrides = []): array
    {
        return $this->areas->create($userId, array_merge([
            'name' => 'Area ' . bin2hex(random_bytes(3)),
            'public' => 0,
        ], $overrides));
    }

    public function testFindPublicWithoutLimitReturnsEverything(): void
    {
        $userId = $this->createUser();
        $this->createArea($userId, ['public' => 1]);
        $this->createArea($userId, ['public' => 1]);
        $this->createArea($userId, ['public' => 0]);

        $this->assertCount(2, $this->areas->findPublic());
        $this->assertSame(2, $this->areas->countPublic());
    }

    public function testCountAllIncludesPublicAndPrivateAreas(): void
    {
        $userId = $this->createUser();
        $this->createArea($userId, ['public' => 1]);
        $this->createArea($userId, ['public' => 0]);
        $this->createArea($userId, ['public' => 0]);

        $this->assertSame(3, $this->areas->countAll());
    }

    public function testFindPublicLimitAndOffsetPageThroughResultsWithoutOverlap(): void
    {
        $userId = $this->createUser();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = (int) $this->createArea($userId, ['public' => 1])['id'];
        }

        $page1 = array_map('intval', array_column($this->areas->findPublic(2, 0), 'id'));
        $page2 = array_map('intval', array_column($this->areas->findPublic(2, 2), 'id'));
        $page3 = array_map('intval', array_column($this->areas->findPublic(2, 4), 'id'));

        $this->assertSame(array_slice($ids, 0, 2), $page1);
        $this->assertSame(array_slice($ids, 2, 2), $page2);
        $this->assertSame(array_slice($ids, 4, 2), $page3);
    }

    public function testFindByUserRespectsLimitAndOffset(): void
    {
        $userId = $this->createUser();
        $this->createArea($userId);
        $this->createArea($userId);
        $this->createArea($userId);

        $this->assertCount(2, $this->areas->findByUser($userId, 2, 0));
        $this->assertCount(1, $this->areas->findByUser($userId, 2, 2));
        $this->assertSame(3, $this->areas->countByUser($userId));
    }

    public function testFindByUserWithPublicCombinesOwnAndPublic(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $this->createArea($owner, ['public' => 0]);
        $this->createArea($other, ['public' => 1]);
        $this->createArea($other, ['public' => 0]);

        $result = $this->areas->findByUserWithPublic($owner);

        $this->assertCount(2, $result);
        $this->assertSame(2, $this->areas->countByUserWithPublic($owner));
    }

    public function testAddImageAssignsIncrementingSortOrderAndGetImagesReturnsThemInOrder(): void
    {
        $userId = $this->createUser();
        $area = $this->createArea($userId);
        $areaId = (int) $area['id'];

        $this->areas->addImage($areaId, 'first.jpg', 'image/jpeg', 111);
        $images = $this->areas->addImage($areaId, 'second.jpg', 'image/jpeg', 222);

        $this->assertSame(2, $this->areas->countImages($areaId));
        $this->assertSame(['first.jpg', 'second.jpg'], array_column($images, 'filename'));
        $this->assertSame([0, 1], array_map('intval', array_column($images, 'sort_order')));
    }

    public function testFindImageIsScopedToItsOwnArea(): void
    {
        $userId = $this->createUser();
        $areaA = $this->createArea($userId);
        $areaB = $this->createArea($userId);
        $images = $this->areas->addImage((int) $areaA['id'], 'a.jpg', 'image/jpeg', 100);
        $imageId = (int) $images[0]['id'];

        $this->assertNotNull($this->areas->findImage((int) $areaA['id'], $imageId));
        $this->assertNull($this->areas->findImage((int) $areaB['id'], $imageId), 'an image must not be findable through a different area_id');
    }

    public function testRemoveImageDeletesOnlyTheGivenRow(): void
    {
        $userId = $this->createUser();
        $area = $this->createArea($userId);
        $areaId = (int) $area['id'];
        $this->areas->addImage($areaId, 'keep.jpg', 'image/jpeg', 100);
        $images = $this->areas->addImage($areaId, 'remove.jpg', 'image/jpeg', 200);
        $toRemove = (int) $images[1]['id'];

        $this->areas->removeImage($areaId, $toRemove);

        $remaining = $this->areas->getImages($areaId);
        $this->assertCount(1, $remaining);
        $this->assertSame('keep.jpg', $remaining[0]['filename']);
    }

    public function testGetAllImagesReturnsRowsAcrossEveryArea(): void
    {
        $userId = $this->createUser();
        $areaA = $this->createArea($userId);
        $areaB = $this->createArea($userId);
        $this->areas->addImage((int) $areaA['id'], 'a.jpg', 'image/jpeg', 100);
        $this->areas->addImage((int) $areaB['id'], 'b.jpg', 'image/jpeg', 200);

        $all = $this->areas->getAllImages();

        $this->assertCount(2, $all);
        $filenames = array_column($all, 'filename');
        sort($filenames);
        $this->assertSame(['a.jpg', 'b.jpg'], $filenames);
        $this->assertArrayHasKey('entity_id', $all[0]);
    }

    public function testUpdateWithoutExpectedUpdatedAtIsUnaffectedByConcurrentChange(): void
    {
        $userId = $this->createUser();
        $area = $this->createArea($userId);

        $updated = $this->areas->update((int) $area['id'], ['name' => 'Renamed']);

        $this->assertSame('Renamed', $updated['name']);
    }

    public function testUpdateWithMatchingExpectedUpdatedAtSucceeds(): void
    {
        $userId = $this->createUser();
        $area = $this->createArea($userId);

        $updated = $this->areas->update((int) $area['id'], [
            'name' => 'Renamed',
            'expected_updated_at' => $area['updated_at'],
        ]);

        $this->assertSame('Renamed', $updated['name']);
    }

    public function testUpdateWithStaleExpectedUpdatedAtThrowsConflictException(): void
    {
        $userId = $this->createUser();
        $area = $this->createArea($userId);

        $this->expectException(\Ytan\Exception\ConflictException::class);

        $this->areas->update((int) $area['id'], [
            'name' => 'Renamed',
            'expected_updated_at' => '2000-01-01 00:00:00',
        ]);
    }

    public function testDeleteWithStaleExpectedUpdatedAtThrowsConflictException(): void
    {
        $userId = $this->createUser();
        $area = $this->createArea($userId);

        $this->expectException(\Ytan\Exception\ConflictException::class);

        $this->areas->delete((int) $area['id'], '2000-01-01 00:00:00');
    }

    public function testDeleteWithMatchingExpectedUpdatedAtSucceeds(): void
    {
        $userId = $this->createUser();
        $area = $this->createArea($userId);

        $this->areas->delete((int) $area['id'], $area['updated_at']);

        $this->expectException(\Ytan\Exception\NotFoundException::class);
        $this->areas->findById((int) $area['id']);
    }
}
