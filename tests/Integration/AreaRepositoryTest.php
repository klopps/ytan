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
}
