<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Poi\PoiRepository;
use Ytan\Tests\TestCase;

/**
 * Covers the limit/offset pagination added to every find*() method (the
 * "serverseitige Pagination" usability-review recommendation) - each
 * existing scope method must keep returning everything when no limit is
 * given, and page deterministically (ORDER BY id) when one is.
 */
final class PoiRepositoryTest extends TestCase
{
    private PoiRepository $pois;

    protected function setUp(): void
    {
        parent::setUp();
        // poitype is a lookup table; bin/setup-test-db.php clones structure
        // only, so the test DB starts with none of the live seed rows -
        // poi.poitype_id's FK needs at least one to exist.
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (2, 'Test Type')");
        $this->pois = new PoiRepository($this->pdo);
    }

    private function createPoi(int $userId, array $overrides = []): array
    {
        return $this->pois->create($userId, array_merge([
            'poitype_id' => 2,
            'name' => 'Poi ' . bin2hex(random_bytes(3)),
            'latitude' => 54.0,
            'longitude' => 10.0,
            'public' => 0,
        ], $overrides));
    }

    public function testFindPublicWithoutLimitReturnsEverything(): void
    {
        $userId = $this->createUser();
        $this->createPoi($userId, ['public' => 1]);
        $this->createPoi($userId, ['public' => 1]);
        $this->createPoi($userId, ['public' => 0]);

        $this->assertCount(2, $this->pois->findPublic());
        $this->assertSame(2, $this->pois->countPublic());
    }

    public function testFindPublicLimitAndOffsetPageThroughResultsWithoutOverlap(): void
    {
        $userId = $this->createUser();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = (int) $this->createPoi($userId, ['public' => 1])['id'];
        }

        $page1 = array_map('intval', array_column($this->pois->findPublic(2, 0), 'id'));
        $page2 = array_map('intval', array_column($this->pois->findPublic(2, 2), 'id'));
        $page3 = array_map('intval', array_column($this->pois->findPublic(2, 4), 'id'));

        $this->assertSame(array_slice($ids, 0, 2), $page1);
        $this->assertSame(array_slice($ids, 2, 2), $page2);
        $this->assertSame(array_slice($ids, 4, 2), $page3);
        $this->assertSame(5, $this->pois->countPublic());
    }

    public function testFindByUserRespectsLimitAndOffset(): void
    {
        $userId = $this->createUser();
        $this->createPoi($userId);
        $this->createPoi($userId);
        $this->createPoi($userId);

        $this->assertCount(2, $this->pois->findByUser($userId, 2, 0));
        $this->assertCount(1, $this->pois->findByUser($userId, 2, 2));
        $this->assertSame(3, $this->pois->countByUser($userId));
    }

    public function testFindByUserWithPublicCombinesOwnAndPublic(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $this->createPoi($owner, ['public' => 0]);
        $this->createPoi($other, ['public' => 1]);
        $this->createPoi($other, ['public' => 0]);

        $result = $this->pois->findByUserWithPublic($owner);

        $this->assertCount(2, $result);
        $this->assertSame(2, $this->pois->countByUserWithPublic($owner));
    }

    public function testFindAllIsUnfilteredAndSupportsPagination(): void
    {
        $userId = $this->createUser();
        $this->createPoi($userId, ['public' => 0]);
        $this->createPoi($userId, ['public' => 1]);

        $this->assertCount(2, $this->pois->findAll());
        $this->assertCount(1, $this->pois->findAll(1, 0));
        $this->assertSame(2, $this->pois->countAll());
    }
}
