<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Tests\TestCase;

final class RouteRepositoryTest extends TestCase
{
    public function testFindByTourRespectsTourRouteSortOrder(): void
    {
        $routes = new RouteRepository($this->pdo);
        $tours = new TourRepository($this->pdo);

        $userId = $this->createUser();
        $tour = $tours->create($userId, ['name' => 'Ordered']);
        $first = $this->createRoute($userId, ['name' => 'First']);
        $second = $this->createRoute($userId, ['name' => 'Second']);
        $third = $this->createRoute($userId, ['name' => 'Third']);

        // Add out of the order we expect back, then explicitly reorder -
        // findByTour() must follow sort_order, not insertion or route id.
        $tours->addRoute((int) $tour['id'], $third);
        $tours->addRoute((int) $tour['id'], $first);
        $tours->addRoute((int) $tour['id'], $second);
        $tours->reorderRoutes((int) $tour['id'], [$first, $second, $third]);

        $result = $routes->findByTour((int) $tour['id']);

        $this->assertSame(['First', 'Second', 'Third'], array_column($result, 'name'));
    }

    public function testFindPublicWithoutLimitReturnsEverything(): void
    {
        $routes = new RouteRepository($this->pdo);
        $userId = $this->createUser();
        $this->createRoute($userId, ['public' => 1]);
        $this->createRoute($userId, ['public' => 1]);
        $this->createRoute($userId, ['public' => 0]);

        $this->assertCount(2, $routes->findPublic());
        $this->assertSame(2, $routes->countPublic());
    }

    public function testFindPublicLimitAndOffsetPageThroughResultsWithoutOverlap(): void
    {
        $routes = new RouteRepository($this->pdo);
        $userId = $this->createUser();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = (int) $this->createRoute($userId, ['public' => 1]);
        }

        $page1 = array_map('intval', array_column($routes->findPublic(2, 0), 'id'));
        $page2 = array_map('intval', array_column($routes->findPublic(2, 2), 'id'));
        $page3 = array_map('intval', array_column($routes->findPublic(2, 4), 'id'));

        $this->assertSame(array_slice($ids, 0, 2), $page1);
        $this->assertSame(array_slice($ids, 2, 2), $page2);
        $this->assertSame(array_slice($ids, 4, 2), $page3);
    }

    public function testFindByUserRespectsLimitAndOffset(): void
    {
        $routes = new RouteRepository($this->pdo);
        $userId = $this->createUser();
        $this->createRoute($userId);
        $this->createRoute($userId);
        $this->createRoute($userId);

        $this->assertCount(2, $routes->findByUser($userId, 2, 0));
        $this->assertCount(1, $routes->findByUser($userId, 2, 2));
        $this->assertSame(3, $routes->countByUser($userId));
    }

    public function testFindByUserWithPublicCombinesOwnAndPublic(): void
    {
        $routes = new RouteRepository($this->pdo);
        $owner = $this->createUser();
        $other = $this->createUser();
        $this->createRoute($owner, ['public' => 0]);
        $this->createRoute($other, ['public' => 1]);
        $this->createRoute($other, ['public' => 0]);

        $result = $routes->findByUserWithPublic($owner);

        $this->assertCount(2, $result);
        $this->assertSame(2, $routes->countByUserWithPublic($owner));
    }

    public function testCreateStoresRecordingMetadataWhenProvided(): void
    {
        $routes = new RouteRepository($this->pdo);
        $userId = $this->createUser();

        $route = $routes->create($userId, [
            'name' => 'Morning Paddle',
            'length' => 5000,
            'points' => '[]',
            'recorded_at' => '2026-09-14 08:00:00',
            'recording_duration_seconds' => 5400,
        ]);

        $this->assertSame('2026-09-14 08:00:00', $route['recorded_at']);
        $this->assertSame(5400, (int) $route['recording_duration_seconds']);
    }

    public function testCreateLeavesRecordingMetadataNullForManuallyDrawnRoutes(): void
    {
        $routes = new RouteRepository($this->pdo);
        $userId = $this->createUser();

        $route = $routes->create($userId, ['name' => 'Hand-drawn Route', 'points' => '[]']);

        $this->assertNull($route['recorded_at']);
        $this->assertNull($route['recording_duration_seconds']);
    }

    public function testUpdateNeverClearsExistingRecordingMetadata(): void
    {
        $routes = new RouteRepository($this->pdo);
        $userId = $this->createUser();

        $route = $routes->create($userId, [
            'name' => 'Morning Paddle',
            'points' => '[]',
            'recorded_at' => '2026-09-14 08:00:00',
            'recording_duration_seconds' => 5400,
        ]);

        // Simulate a routine rename via the normal edit form, which never
        // sends recorded_at/recording_duration_seconds at all - update()
        // must not null them out just because they're absent from $data.
        $updated = $routes->update((int) $route['id'], [
            'name' => 'Morning Paddle (renamed)',
            'points' => '[]',
        ]);

        $this->assertSame('Morning Paddle (renamed)', $updated['name']);
        $this->assertSame('2026-09-14 08:00:00', $updated['recorded_at']);
        $this->assertSame(5400, (int) $updated['recording_duration_seconds']);
    }
}
