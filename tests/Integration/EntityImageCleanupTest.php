<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Ytan\Domain\Area\AreaRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Http\Controllers\AreaController;
use Ytan\Http\Controllers\PoiController;
use Ytan\Http\Controllers\RouteController;
use Ytan\Http\Controllers\TourController;
use Ytan\Service\CaptchaService;
use Ytan\Service\ImageStorageService;
use Ytan\Service\StaticMapImageService;
use Ytan\Service\TourDocumentService;
use Ytan\Service\TourNotificationService;
use Ytan\Service\Translator;
use Ytan\Domain\User\UserRepository;
use Ytan\Service\MailService;

/**
 * Deleting an entity must remove its photo *files* from disk, not just the
 * DB rows (those already vanish on their own via each poi_image/route_image/
 * area_image/tour_image table's ON DELETE CASCADE foreign key). Files
 * outlive that cascade unless a controller explicitly deletes them via
 * ImageStorageService::delete() first - TourController::delete() always
 * did this; PoiController/RouteController/AreaController::delete() did not,
 * silently leaking a file per photo on every POI/route/area deletion until
 * fixed alongside this test.
 *
 * Bypasses the real upload endpoint (which needs a PSR-7 UploadedFile) by
 * writing the file directly to the path ImageStorageService's own store()
 * would have used, then registering a matching DB row via the repository's
 * addImage() - exercises the same delete()-time cleanup loop either way.
 */
final class EntityImageCleanupTest extends ControllerTestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir() . '/ytan-test-images-cleanup-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        // Only ever populated by a test that fails an assertion before
        // reaching the delete() call under test - a passing test always
        // leaves this empty already, which is exactly what's being checked.
        if (is_dir($this->storageDir)) {
            array_map('unlink', glob($this->storageDir . '/*/*') ?: []);
            array_map('rmdir', glob($this->storageDir . '/*') ?: []);
            rmdir($this->storageDir);
        }
        parent::tearDown();
    }

    private function writeFakeImageFile(int $entityId, string $filename): string
    {
        $dir = $this->storageDir . '/' . $entityId;
        mkdir($dir, 0775, true);
        $path = $dir . '/' . $filename;
        file_put_contents($path, 'fake image bytes');

        return $path;
    }

    public function testDeletingATourRemovesItsImageFilesFromDisk(): void
    {
        $images = new ImageStorageService($this->storageDir);
        $tours = new TourRepository($this->pdo);
        $documents = new TourDocumentService(
            $tours,
            new RouteRepository($this->pdo),
            new PoiRepository($this->pdo),
            new AreaRepository($this->pdo),
            $images,
            $images,
            new StaticMapImageService(sys_get_temp_dir() . '/ytan-test-maps', ''),
            new GithubFlavoredMarkdownConverter(),
            dirname(__DIR__, 2) . '/public/markers',
            new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'de'),
        );
        $controller = new TourController($tours, $images, $documents);

        $userId = $this->createUser();
        $tour = $tours->create($userId, ['name' => 'With Photo']);
        $tourId = (int) $tour['id'];
        $path = $this->writeFakeImageFile($tourId, 'photo.jpg');
        $tours->addImage($tourId, 'photo.jpg', 'image/jpeg', 17);

        $this->assertFileExists($path);

        $controller->delete($this->request('DELETE', '/tours/' . $tourId, $this->authPayload($userId, ['tour_manage' => true])), $this->response(), ['id' => (string) $tourId]);

        $this->assertFileDoesNotExist($path);
    }

    public function testDeletingAPoiRemovesItsImageFilesFromDisk(): void
    {
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (2, 'Test Type')");
        $images = new ImageStorageService($this->storageDir);
        $pois = new PoiRepository($this->pdo);
        $controller = new PoiController($pois, $images);

        $userId = $this->createUser();
        $poi = $pois->create($userId, ['poitype_id' => 2, 'name' => 'With Photo', 'latitude' => 54.0, 'longitude' => 10.0]);
        $poiId = (int) $poi['id'];
        $path = $this->writeFakeImageFile($poiId, 'photo.jpg');
        $pois->addImage($poiId, 'photo.jpg', 'image/jpeg', 17);

        $this->assertFileExists($path);

        $controller->delete($this->request('DELETE', '/pois/' . $poiId, $this->authPayload($userId)), $this->response(), ['id' => (string) $poiId]);

        $this->assertFileDoesNotExist($path);
    }

    public function testDeletingARouteRemovesItsImageFilesFromDisk(): void
    {
        $images = new ImageStorageService($this->storageDir);
        $routes = new RouteRepository($this->pdo);
        $tours = new TourRepository($this->pdo);
        $mail = new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test');
        $notifications = new TourNotificationService(new UserRepository($this->pdo), $mail, 'http://localhost');
        $controller = new RouteController($routes, $tours, new CaptchaService('unit-test-secret'), $notifications, $images);

        $userId = $this->createUser();
        $routeId = $this->createRoute($userId);
        $path = $this->writeFakeImageFile($routeId, 'photo.jpg');
        $routes->addImage($routeId, 'photo.jpg', 'image/jpeg', 17);

        $this->assertFileExists($path);

        $controller->delete($this->request('DELETE', '/routes/' . $routeId, $this->authPayload($userId)), $this->response(), ['id' => (string) $routeId]);

        $this->assertFileDoesNotExist($path);
    }

    public function testDeletingAnAreaRemovesItsImageFilesFromDisk(): void
    {
        $images = new ImageStorageService($this->storageDir);
        $areas = new AreaRepository($this->pdo);
        $controller = new AreaController($areas, $images);

        $userId = $this->createUser();
        $area = $areas->create($userId, ['name' => 'With Photo']);
        $areaId = (int) $area['id'];
        $path = $this->writeFakeImageFile($areaId, 'photo.jpg');
        $areas->addImage($areaId, 'photo.jpg', 'image/jpeg', 17);

        $this->assertFileExists($path);

        $controller->delete($this->request('DELETE', '/areas/' . $areaId, $this->authPayload($userId)), $this->response(), ['id' => (string) $areaId]);

        $this->assertFileDoesNotExist($path);
    }
}
