<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Psr\Http\Message\ResponseInterface;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Http\Controllers\GpxExportController;

/**
 * GET /routes/{id}/gpx and /tours/{id}/gpx: visibility AND (site setting
 * gpx_export_public OR admin OR public + export_routes_public OR own +
 * export_routes_own).
 */
final class GpxExportControllerTest extends ControllerTestCase
{
    private GpxExportController $controller;
    private SettingsRepository $settings;
    private TourRepository $tours;

    protected function setUp(): void
    {
        parent::setUp();

        // Singleton config row - the structure-only test DB starts empty.
        $this->pdo->exec('INSERT INTO app_settings (id) VALUES (1)');
        $this->settings = new SettingsRepository($this->pdo);
        $this->tours = new TourRepository($this->pdo);
        $this->controller = new GpxExportController(new RouteRepository($this->pdo), $this->tours, $this->settings, 'YTAN');
    }

    private function export(int $routeId, ?array $auth): ResponseInterface
    {
        return $this->controller->routeGpx(
            $this->request('GET', '/api/v1/routes/' . $routeId . '/gpx', $auth),
            $this->response(),
            ['id' => (string) $routeId]
        );
    }

    private function points(): string
    {
        return json_encode([['lat' => 54.1, 'lng' => 10.1], ['lat' => 54.2, 'lng' => 10.2]]);
    }

    public function testOwnRightExportsOwnRouteAsGpx(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId, ['name' => 'Eigene', 'points' => $this->points()]);

        $response = $this->export($routeId, $this->authPayload($userId, ['export_routes_own' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/gpx+xml', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));
        $body = (string) $response->getBody();
        $this->assertStringContainsString('<name>Eigene</name>', $body);
        $this->assertSame(2, substr_count($body, '<trkpt '));
    }

    public function testOwnRightDoesNotCoverSomeoneElsesPublicRoute(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $routeId = $this->createRoute($owner, ['public' => 1]);

        $this->expectException(ForbiddenException::class);
        $this->export($routeId, $this->authPayload($other, ['export_routes_own' => true]));
    }

    public function testPublicRightCoversSomeoneElsesPublicRoute(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $routeId = $this->createRoute($owner, ['public' => 1]);

        $response = $this->export($routeId, $this->authPayload($other, ['export_routes_public' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPublicRightDoesNotCoverSomeoneElsesPrivateRoute(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $routeId = $this->createRoute($owner, ['public' => 0]);

        $this->expectException(ForbiddenException::class);
        $this->export($routeId, $this->authPayload($other, ['export_routes_public' => true]));
    }

    public function testPublicRightDoesNotCoverOwnPrivateRoute(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId, ['public' => 0]);

        $this->expectException(ForbiddenException::class);
        $this->export($routeId, $this->authPayload($userId, ['export_routes_public' => true]));
    }

    public function testPublicRightCoversOwnPublicRoute(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId, ['public' => 1]);

        $response = $this->export($routeId, $this->authPayload($userId, ['export_routes_public' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNoRightAndNoSettingIsForbiddenEvenForOwnRoute(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId);

        $this->expectException(ForbiddenException::class);
        $this->export($routeId, $this->authPayload($userId));
    }

    public function testAdminMayExportAnyRoute(): void
    {
        $owner = $this->createUser();
        $admin = $this->createUser(['is_admin' => 1]);
        $routeId = $this->createRoute($owner, ['public' => 0]);

        $response = $this->export($routeId, $this->authPayload($admin, ['is_admin' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPublicSettingLetsAnonymousExportPublicRoute(): void
    {
        $this->settings->setGpxExportPublic(true);
        $routeId = $this->createRoute($this->createUser(), ['public' => 1]);

        $response = $this->export($routeId, null);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPublicSettingStillHidesPrivateRoutes(): void
    {
        $this->settings->setGpxExportPublic(true);
        $routeId = $this->createRoute($this->createUser(), ['public' => 0]);

        $this->expectException(ForbiddenException::class);
        $this->export($routeId, null);
    }

    public function testAnonymousWithoutSettingIsForbidden(): void
    {
        $routeId = $this->createRoute($this->createUser(), ['public' => 1]);

        $this->expectException(ForbiddenException::class);
        $this->export($routeId, null);
    }

    public function testUnknownRouteIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->export(999999999, null);
    }

    private function exportTour(int $tourId, ?array $auth): ResponseInterface
    {
        return $this->controller->tourGpx(
            $this->request('GET', '/api/v1/tours/' . $tourId . '/gpx', $auth),
            $this->response(),
            ['id' => (string) $tourId]
        );
    }

    /** A tour of $ownerId with two of their routes ("Etappe 1" private, "Etappe 2" public). */
    private function createTour(int $ownerId, bool $public): int
    {
        $tour = $this->tours->create($ownerId, ['name' => 'Schlei-Woche']);
        $tourId = (int) $tour['id'];
        $this->tours->addRoute($tourId, $this->createRoute($ownerId, ['name' => 'Etappe 1', 'public' => 0, 'points' => $this->points()]));
        $this->tours->addRoute($tourId, $this->createRoute($ownerId, ['name' => 'Etappe 2', 'public' => 1, 'points' => $this->points()]));
        if ($public) {
            $this->pdo->prepare('UPDATE tour SET public = 1 WHERE id = ?')->execute([$tourId]);
        }

        return $tourId;
    }

    public function testOwnTourWithOwnRightExportsAllRoutesInTourOrder(): void
    {
        $userId = $this->createUser();
        $tourId = $this->createTour($userId, false);

        $response = $this->exportTour($tourId, $this->authPayload($userId, ['export_routes_own' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/gpx+xml', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $this->assertSame(2, substr_count($body, '<trk>'));
        $this->assertStringContainsString('<name>Schlei-Woche</name>', $body);
        $this->assertLessThan(strpos($body, '<name>Etappe 2</name>'), strpos($body, '<name>Etappe 1</name>'));
    }

    public function testPublicTourWithPublicRightIncludesItsPrivateRoutes(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $tourId = $this->createTour($owner, true);

        $response = $this->exportTour($tourId, $this->authPayload($other, ['export_routes_public' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<name>Etappe 1</name>', (string) $response->getBody());
    }

    public function testPrivateForeignTourIsForbiddenEvenWithSetting(): void
    {
        $this->settings->setGpxExportPublic(true);
        $tourId = $this->createTour($this->createUser(), false);

        $this->expectException(ForbiddenException::class);
        $this->exportTour($tourId, $this->authPayload($this->createUser(), ['export_routes_public' => true]));
    }

    public function testAnonymousTourExportNeedsSetting(): void
    {
        $tourId = $this->createTour($this->createUser(), true);

        $this->expectException(ForbiddenException::class);
        $this->exportTour($tourId, null);
    }

    public function testAnonymousMayExportPublicTourWithSetting(): void
    {
        $this->settings->setGpxExportPublic(true);
        $tourId = $this->createTour($this->createUser(), true);

        $response = $this->exportTour($tourId, null);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTourManageSeesPrivateTourButNeedsAnExportRight(): void
    {
        $tourId = $this->createTour($this->createUser(), false);
        $manager = $this->createUser(['tour_manage' => 1]);

        $this->expectException(ForbiddenException::class);
        $this->exportTour($tourId, $this->authPayload($manager, ['tour_manage' => true, 'export_routes_public' => true]));
    }

    public function testUnknownTourIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->exportTour(999999999, null);
    }
}
