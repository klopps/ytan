<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Psr\Http\Message\ResponseInterface;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Http\Controllers\RouteExportController;

/**
 * GET /routes/{id}/gpx: visibility (public/own/admin) AND (site setting
 * gpx_export_public OR public + export_routes_public OR own + export_routes_own).
 */
final class RouteExportControllerTest extends ControllerTestCase
{
    private RouteExportController $controller;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();

        // Singleton config row - the structure-only test DB starts empty.
        $this->pdo->exec('INSERT INTO app_settings (id) VALUES (1)');
        $this->settings = new SettingsRepository($this->pdo);
        $this->controller = new RouteExportController(new RouteRepository($this->pdo), $this->settings, 'YTAN');
    }

    private function export(int $routeId, ?array $auth): ResponseInterface
    {
        return $this->controller->gpx(
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
}
