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
}
