<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\RouteMergeController;
use Ytan\Service\ImageStorageService;
use Ytan\Service\MailService;
use Ytan\Service\TourNotificationService;

final class RouteMergeControllerTest extends ControllerTestCase
{
    private RouteMergeController $controller;
    private RouteRepository $routes;
    private TourRepository $tours;
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        // Structure-only test DB: app_settings has no row yet.
        $this->pdo->exec('INSERT INTO app_settings (id, route_merge_midpoint_m) VALUES (1, 10)');

        $this->storageDir = sys_get_temp_dir() . '/ytan-test-merge-' . bin2hex(random_bytes(4));
        $this->routes = new RouteRepository($this->pdo);
        $this->tours = new TourRepository($this->pdo);
        $mail = new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test');
        $this->controller = new RouteMergeController(
            $this->routes,
            $this->tours,
            new ImageStorageService($this->storageDir),
            new SettingsRepository($this->pdo),
            new TourNotificationService(new UserRepository($this->pdo), $mail, 'http://localhost'),
            $this->pdo,
        );
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

    private function user(array $overrides = []): int
    {
        return $this->createUser($overrides);
    }

    private function route(int $userId, array $lats, array $overrides = []): int
    {
        $points = array_map(static fn (float $lat) => ['lat' => $lat, 'lng' => 10.0], $lats);

        return $this->createRoute($userId, $overrides + ['points' => json_encode($points), 'length' => 1]);
    }

    private function merge(int $keptId, array $auth, array $body): array
    {
        return $this->decode($this->controller->merge(
            $this->request('POST', '/api/v1/routes/' . $keptId . '/merge', $auth, $body),
            $this->response(),
            ['id' => (string) $keptId]
        ));
    }

    private function addPhoto(int $routeId, string $filename): void
    {
        if (!is_dir($this->storageDir . '/' . $routeId)) {
            mkdir($this->storageDir . '/' . $routeId, 0775, true);
        }
        file_put_contents($this->storageDir . '/' . $routeId . '/' . $filename, 'bytes-' . $filename);
        $this->routes->addImage($routeId, $filename, 'image/jpeg', 10);
    }

    public function testMergesIntoTheKeptRouteAndDeletesTheOther(): void
    {
        $userId = $this->user();
        $kept = $this->route($userId, [54.0, 54.01], ['name' => 'A', 'description' => 'bleibt', 'color' => '#1E88E5', 'public' => 1]);
        $other = $this->route($userId, [54.02, 54.01], ['name' => 'B', 'color' => '#000000']);

        $result = $this->merge($kept, $this->authPayload($userId), ['other_id' => $other, 'name' => 'A + B']);

        $this->assertSame(200, $result['status']);
        $this->assertSame($kept, $result['data']['route']['id']);
        $this->assertSame('shared', $result['data']['join']);
        $this->assertFalse($result['data']['other_first']);

        $route = $this->routes->findById($kept);
        $this->assertSame('A + B', $route['name']);
        $this->assertSame('bleibt', $route['description']);
        $this->assertSame('#1E88E5', $route['color']);
        $this->assertSame(1, (int) $route['public']);
        // The other route ran the other way and was turned round.
        $this->assertEquals([54.0, 54.01, 54.02], array_column(json_decode($route['points'], true), 'lat'));
        $this->assertEqualsWithDelta(2224, (int) $route['length'], 5);

        $this->expectException(NotFoundException::class);
        $this->routes->findById($other);
    }

    public function testUsesTheMidpointSetting(): void
    {
        $userId = $this->user();
        $kept = $this->route($userId, [54.0, 54.01]);
        $other = $this->route($userId, [54.0101, 54.02]); // ~11 m from the kept end

        $this->pdo->exec('UPDATE app_settings SET route_merge_midpoint_m = 20 WHERE id = 1');
        $result = $this->merge($kept, $this->authPayload($userId), ['other_id' => $other, 'name' => 'X']);

        $this->assertSame('midpoint', $result['data']['join']);
        $this->assertSame(11, $result['data']['gap_m']);
        $this->assertCount(3, json_decode($this->routes->findById($kept)['points'], true));
    }

    public function testFarEndsGetAConnectingSegment(): void
    {
        $userId = $this->user();
        $kept = $this->route($userId, [54.1, 54.2]);
        $other = $this->route($userId, [54.0, 54.09]);

        $result = $this->merge($kept, $this->authPayload($userId), ['other_id' => $other, 'name' => 'X']);

        $this->assertSame('segment', $result['data']['join']);
        $this->assertTrue($result['data']['other_first']);
        $this->assertEquals([54.0, 54.09, 54.1, 54.2], array_column(json_decode($this->routes->findById($kept)['points'], true), 'lat'));
    }

    public function testKeptRouteTakesTheOthersPlaceInTours(): void
    {
        $userId = $this->user();
        $before = $this->route($userId, [53.0, 53.1], ['name' => 'Davor']);
        $kept = $this->route($userId, [54.0, 54.01], ['name' => 'A']);
        $other = $this->route($userId, [54.01, 54.02], ['name' => 'B']);
        $after = $this->route($userId, [55.0, 55.1], ['name' => 'Danach']);

        $onlyOther = $this->tours->create($userId, ['name' => 'nur B']);
        foreach ([$before, $other, $after] as $id) {
            $this->tours->addRoute((int) $onlyOther['id'], $id);
        }
        $both = $this->tours->create($userId, ['name' => 'A und B']);
        foreach ([$kept, $before, $other] as $id) {
            $this->tours->addRoute((int) $both['id'], $id);
        }

        $this->merge($kept, $this->authPayload($userId), ['other_id' => $other, 'name' => 'A + B']);

        $this->assertSame(['Davor', 'A + B', 'Danach'], array_column($this->routes->findByTour((int) $onlyOther['id']), 'name'));
        $this->assertSame(['A + B', 'Davor'], array_column($this->routes->findByTour((int) $both['id']), 'name'));

        $keptLength = (int) $this->routes->findById($kept)['length'];
        $this->assertSame(
            1 + $keptLength + 1,
            (int) $this->tours->findById((int) $onlyOther['id'])['total_length']
        );
        $this->assertSame($keptLength + 1, (int) $this->tours->findById((int) $both['id'])['total_length']);
    }

    public function testPhotosAreCopiedUpToTheLimit(): void
    {
        $userId = $this->user();
        $kept = $this->route($userId, [54.0, 54.01]);
        $other = $this->route($userId, [54.01, 54.02]);
        foreach (['k1.jpg', 'k2.jpg', 'k3.jpg'] as $f) {
            $this->addPhoto($kept, $f);
        }
        foreach (['o1.jpg', 'o2.jpg', 'o3.jpg'] as $f) {
            $this->addPhoto($other, $f);
        }

        $result = $this->merge($kept, $this->authPayload($userId), ['other_id' => $other, 'name' => 'X']);

        $this->assertSame(1, $result['data']['images_skipped']);
        $images = $this->routes->getImages($kept);
        $this->assertCount(5, $images);
        $copied = array_slice($images, 3);
        $this->assertSame('bytes-o1.jpg', file_get_contents($this->storageDir . '/' . $kept . '/' . $copied[0]['filename']));
        $this->assertSame('bytes-o2.jpg', file_get_contents($this->storageDir . '/' . $kept . '/' . $copied[1]['filename']));
        $this->assertFileDoesNotExist($this->storageDir . '/' . $other . '/o1.jpg', 'the deleted route\'s files are removed');
    }

    public function testCallerMustBeAllowedToEditBothRoutes(): void
    {
        $userId = $this->user();
        $kept = $this->route($userId, [54.0, 54.01]);
        $foreign = $this->route($this->user(), [54.01, 54.02]);

        try {
            $this->merge($kept, $this->authPayload($userId), ['other_id' => $foreign, 'name' => 'X']);
            $this->fail('Merged a route of somebody else.');
        } catch (ForbiddenException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($foreign, (int) $this->routes->findById($foreign)['id'], 'nothing deleted');
    }

    public function testAdminMayMergeRoutesOfOthersOwnerOfKeptStays(): void
    {
        $ownerA = $this->user();
        $kept = $this->route($ownerA, [54.0, 54.01]);
        $other = $this->route($this->user(), [54.01, 54.02]);
        $admin = $this->user(['is_admin' => 1]);

        $this->merge($kept, $this->authPayload($admin, ['is_admin' => true]), ['other_id' => $other, 'name' => 'X']);

        $this->assertSame($ownerA, (int) $this->routes->findById($kept)['user_id']);
    }

    public function testRejectsInvalidRequests(): void
    {
        $userId = $this->user();
        $kept = $this->route($userId, [54.0, 54.01]);
        $other = $this->route($userId, [54.01, 54.02]);
        $bodies = [
            ['other_id' => $kept, 'name' => 'X'],
            ['other_id' => (string) $other, 'name' => 'X'],
            ['other_id' => $other, 'name' => '   '],
            ['other_id' => $other, 'name' => str_repeat('x', 151)],
        ];
        foreach ($bodies as $body) {
            try {
                $this->merge($kept, $this->authPayload($userId), $body);
                $this->fail('Accepted ' . json_encode($body));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
