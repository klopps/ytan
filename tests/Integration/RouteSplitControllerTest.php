<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\RouteSplitController;
use Ytan\Service\ImageStorageService;

final class RouteSplitControllerTest extends ControllerTestCase
{
    private RouteSplitController $controller;
    private RouteRepository $routes;
    private TourRepository $tours;
    private ImageStorageService $images;
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir() . '/ytan-test-split-' . bin2hex(random_bytes(4));
        $this->routes = new RouteRepository($this->pdo);
        $this->tours = new TourRepository($this->pdo);
        $this->images = new ImageStorageService($this->storageDir);
        $this->controller = new RouteSplitController($this->routes, $this->tours, $this->images, $this->pdo);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storageDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storageDir);
        }
        parent::tearDown();
    }

    private function points(): array
    {
        return [
            ['lat' => 54.00, 'lng' => 10.0],
            ['lat' => 54.01, 'lng' => 10.0],
            ['lat' => 54.02, 'lng' => 10.0],
            ['lat' => 54.03, 'lng' => 10.0],
        ];
    }

    private function split(int $routeId, array $auth, array $body): array
    {
        return $this->decode($this->controller->split(
            $this->request('POST', '/api/v1/routes/' . $routeId . '/split', $auth, $body + [
                'points' => $this->points(),
                'name_suffixes' => [' (Teil 1)', ' (Teil 2)'],
            ]),
            $this->response(),
            ['id' => (string) $routeId]
        ));
    }

    public function testSplitsAtWaypointCopyingAllDetails(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId, [
            'name' => 'Schlei',
            'description' => 'Ab Kappeln',
            'public' => 1,
            'color' => '#1E88E5',
            'points' => json_encode([['lat' => 54.0, 'lng' => 10.0], ['lat' => 54.03, 'lng' => 10.0]]),
            'recorded_at' => '2026-07-01 07:00:00',
            'recording_duration_seconds' => 3600,
        ]);

        $result = $this->split($routeId, $this->authPayload($userId), ['index' => 2]);

        $this->assertSame(201, $result['status']);
        [$first, $second] = $result['data']['routes'];
        $this->assertSame($routeId, $first['id']);

        $part1 = $this->routes->findById($routeId);
        $part2 = $this->routes->findById($second['id']);

        $this->assertSame('Schlei (Teil 1)', $part1['name']);
        $this->assertSame('Schlei (Teil 2)', $part2['name']);
        // Edited geometry is saved; both parts share the split waypoint.
        $this->assertEquals([54.0, 54.01, 54.02], array_column(json_decode($part1['points'], true), 'lat'));
        $this->assertEquals([54.02, 54.03], array_column(json_decode($part2['points'], true), 'lat'));
        $this->assertEqualsWithDelta(2224, (int) $part1['length'], 5);
        $this->assertEqualsWithDelta(1112, (int) $part2['length'], 5);

        foreach (['description', 'public', 'color', 'recorded_at', 'recording_duration_seconds', 'user_id'] as $field) {
            $this->assertEquals($part1[$field], $part2[$field], $field);
        }
        $this->assertSame('2026-07-01 07:00:00', $part2['recorded_at']);
    }

    public function testPartTwoFollowsPartOneInEveryTour(): void
    {
        $userId = $this->createUser();
        $before = $this->createRoute($userId, ['name' => 'Davor']);
        $routeId = $this->createRoute($userId, ['name' => 'Mitte', 'length' => 10]);
        $after = $this->createRoute($userId, ['name' => 'Danach']);
        $tour = $this->tours->create($userId, ['name' => 'T']);
        foreach ([$before, $routeId, $after] as $id) {
            $this->tours->addRoute((int) $tour['id'], $id);
        }

        $result = $this->split($routeId, $this->authPayload($userId), ['index' => 1]);

        $order = array_column($this->routes->findByTour((int) $tour['id']), 'name');
        $this->assertSame(['Davor', 'Mitte (Teil 1)', 'Mitte (Teil 2)', 'Danach'], $order);
        $total = (int) $this->tours->findById((int) $tour['id'])['total_length'];
        $this->assertSame(
            (int) $this->routes->findById($before)['length'] + (int) $this->routes->findById($after)['length']
                + (int) $this->routes->findById($routeId)['length'] + (int) $this->routes->findById($result['data']['routes'][1]['id'])['length'],
            $total
        );
    }

    public function testPhotosAreCopiedAsSeparateFiles(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId);
        mkdir($this->storageDir . '/' . $routeId, 0775, true);
        file_put_contents($this->storageDir . '/' . $routeId . '/abc.jpg', 'jpegbytes');
        $this->routes->addImage($routeId, 'abc.jpg', 'image/jpeg', 9);

        $result = $this->split($routeId, $this->authPayload($userId), ['index' => 1]);

        $newId = $result['data']['routes'][1]['id'];
        $copies = $this->routes->getImages($newId);
        $this->assertCount(1, $copies);
        $this->assertNotSame('abc.jpg', $copies[0]['filename']);
        $this->assertSame('jpegbytes', file_get_contents($this->storageDir . '/' . $newId . '/' . $copies[0]['filename']));
        $this->assertCount(1, $this->routes->getImages($routeId), 'part 1 keeps its own');
    }

    public function testOnlyOwnerOrAdmin(): void
    {
        $routeId = $this->createRoute($this->createUser());

        $this->expectException(ForbiddenException::class);
        $this->split($routeId, $this->authPayload($this->createUser()), ['index' => 1]);
    }

    public function testAdminSplitKeepsTheOwner(): void
    {
        $ownerId = $this->createUser();
        $routeId = $this->createRoute($ownerId);
        $admin = $this->createUser(['is_admin' => 1]);

        $result = $this->split($routeId, $this->authPayload($admin, ['is_admin' => true]), ['index' => 1]);

        $this->assertSame($ownerId, (int) $this->routes->findById($result['data']['routes'][1]['id'])['user_id']);
    }

    public function testRejectsEndpointsAsSplitPoint(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId);
        foreach ([0, 3, 7, '1'] as $index) {
            try {
                $this->split($routeId, $this->authPayload($userId), ['index' => $index]);
                $this->fail('Accepted index ' . var_export($index, true));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLongNameIsShortenedNotTheSuffix(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId, ['name' => str_repeat('x', 150)]);

        $this->split($routeId, $this->authPayload($userId), ['index' => 1]);

        $name = $this->routes->findById($routeId)['name'];
        $this->assertSame(150, mb_strlen($name));
        $this->assertStringEndsWith(' (Teil 1)', $name);
    }
}
