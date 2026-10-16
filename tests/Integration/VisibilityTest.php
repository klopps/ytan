<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Ytan\Domain\Area\AreaRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\AreaController;
use Ytan\Http\Controllers\PoiController;
use Ytan\Http\Controllers\RouteController;
use Ytan\Http\Controllers\TourController;
use Ytan\Service\CaptchaService;
use Ytan\Service\ImageStorageService;
use Ytan\Service\MailService;
use Ytan\Service\StaticMapImageService;
use Ytan\Service\TourDocumentService;
use Ytan\Service\TourNotificationService;
use Ytan\Service\Translator;

/**
 * Who may READ a single POI/route/area/tour (security audit 2026-10-16: the
 * show() endpoints and GET /routes?tour_id= used to answer anyone, private
 * records included), plus POST /tours/{id}/routes, which must not take a
 * private route of someone else, and the input checks that keep stored
 * values from becoming HTML/JS (POI url, route/area color).
 *
 * Rule: public, own or admin; tours additionally tour_manage. A record the
 * caller may not see answers 404, like a missing one.
 */
final class VisibilityTest extends ControllerTestCase
{
    private PoiRepository $pois;
    private RouteRepository $routes;
    private AreaRepository $areas;
    private TourRepository $tours;
    private PoiController $poiController;
    private RouteController $routeController;
    private AreaController $areaController;
    private TourController $tourController;
    private int $owner;
    private int $other;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $imageDir = sys_get_temp_dir() . '/ytan-test-images';
        $images = new ImageStorageService($imageDir);

        $this->pois = new PoiRepository($this->pdo);
        $this->routes = new RouteRepository($this->pdo);
        $this->areas = new AreaRepository($this->pdo);
        $this->tours = new TourRepository($this->pdo);

        $this->poiController = new PoiController($this->pois, $images);
        $this->areaController = new AreaController($this->areas, $images);
        $this->routeController = new RouteController(
            $this->routes,
            $this->tours,
            new CaptchaService('unit-test-secret-at-least-32-bytes-long'),
            new TourNotificationService(new UserRepository($this->pdo), new MailService('', 587, '', '', 'noreply@example.test', 'tls', 'YTAN'), 'http://localhost'),
            $images
        );
        $documents = new TourDocumentService(
            $this->tours,
            $this->routes,
            $this->pois,
            $this->areas,
            $images,
            $images,
            new StaticMapImageService(sys_get_temp_dir() . '/ytan-test-maps', ''),
            new GithubFlavoredMarkdownConverter(),
            dirname(__DIR__, 2) . '/public/markers',
            new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'de'),
            dirname(__DIR__, 2) . '/public/images/ytan-logo.png',
            'https://example.com',
        );
        $this->tourController = new TourController($this->tours, $images, $documents);

        $this->owner = $this->createUser(['tour_create' => 1]);
        $this->other = $this->createUser(['tour_create' => 1]);
        $this->admin = $this->createUser(['is_admin' => 1]);

        $this->pdo->exec("INSERT IGNORE INTO poitype (id, name) VALUES (2, 'Test Type')");
    }

    /* ------------------------------------------------------------ POI */

    private function createPoi(int $public, array $overrides = []): int
    {
        return (int) $this->pois->create($this->owner, array_merge(
            ['poitype_id' => 2, 'name' => 'P', 'latitude' => 54.0, 'longitude' => 10.0, 'public' => $public],
            $overrides
        ))['id'];
    }

    public function testPrivatePoiIsHiddenFromAnonymousVisitor(): void
    {
        $id = $this->createPoi(0);

        $this->expectException(NotFoundException::class);
        $this->poiController->show($this->request('GET', "/pois/$id"), $this->response(), ['id' => (string) $id]);
    }

    public function testPrivatePoiIsHiddenFromOtherUser(): void
    {
        $id = $this->createPoi(0);

        $this->expectException(NotFoundException::class);
        $this->poiController->show($this->request('GET', "/pois/$id", $this->authPayload($this->other)), $this->response(), ['id' => (string) $id]);
    }

    public function testPrivatePoiIsVisibleToOwnerAndAdmin(): void
    {
        $id = $this->createPoi(0);

        foreach ([$this->authPayload($this->owner), $this->authPayload($this->admin, ['is_admin' => true])] as $auth) {
            $result = $this->decode($this->poiController->show($this->request('GET', "/pois/$id", $auth), $this->response(), ['id' => (string) $id]));
            $this->assertSame($id, (int) $result['data']['id']);
        }
    }

    public function testPublicPoiIsVisibleToAnonymousVisitor(): void
    {
        $id = $this->createPoi(1);

        $result = $this->decode($this->poiController->show($this->request('GET', "/pois/$id"), $this->response(), ['id' => (string) $id]));
        $this->assertSame($id, (int) $result['data']['id']);
    }

    public function testPoiUrlMustBeHttp(): void
    {
        $this->expectException(ValidationException::class);
        $this->createPoi(1, ['url' => 'javascript:alert(1)']);
    }

    public function testPoiUrlAcceptsHttpsAndEmpty(): void
    {
        $this->assertGreaterThan(0, $this->createPoi(1, ['url' => 'https://example.com/x']));
        $this->assertGreaterThan(0, $this->createPoi(1, ['url' => '']));
    }

    /* ---------------------------------------------------------- Route */

    public function testPrivateRouteIsHiddenFromAnonymousVisitorAndOtherUser(): void
    {
        $id = $this->createRoute($this->owner);

        foreach ([null, $this->authPayload($this->other)] as $auth) {
            try {
                $this->routeController->show($this->request('GET', "/routes/$id", $auth), $this->response(), ['id' => (string) $id]);
                $this->fail('A private route must not be readable by ' . ($auth === null ? 'an anonymous visitor' : 'another user') . '.');
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPrivateRouteIsVisibleToOwnerAndAdmin(): void
    {
        $id = $this->createRoute($this->owner);

        foreach ([$this->authPayload($this->owner), $this->authPayload($this->admin, ['is_admin' => true])] as $auth) {
            $result = $this->decode($this->routeController->show($this->request('GET', "/routes/$id", $auth), $this->response(), ['id' => (string) $id]));
            $this->assertSame($id, (int) $result['data']['id']);
        }
    }

    public function testRouteColorMustBeHex(): void
    {
        $this->expectException(ValidationException::class);
        $this->routes->create($this->owner, ['name' => 'R', 'color' => '"><img src=x onerror=alert(1)>']);
    }

    /* ----------------------------------------------------------- Area */

    public function testPrivateAreaIsHiddenFromOtherUserButVisibleToOwner(): void
    {
        $id = (int) $this->areas->create($this->owner, ['name' => 'A', 'public' => 0])['id'];

        $result = $this->decode($this->areaController->show($this->request('GET', "/areas/$id", $this->authPayload($this->owner)), $this->response(), ['id' => (string) $id]));
        $this->assertSame($id, (int) $result['data']['id']);

        $this->expectException(NotFoundException::class);
        $this->areaController->show($this->request('GET', "/areas/$id", $this->authPayload($this->other)), $this->response(), ['id' => (string) $id]);
    }

    public function testAreaColorMustBeHex(): void
    {
        $this->expectException(ValidationException::class);
        $this->areas->create($this->owner, ['name' => 'A', 'color' => 'red" onmouseover="x']);
    }

    /* ----------------------------------------------------------- Tour */

    public function testPrivateTourIsHiddenFromOtherUserButVisibleToTourManage(): void
    {
        $id = (int) $this->tours->create($this->owner, ['name' => 'Private tour'])['id'];
        $manager = $this->createUser(['tour_manage' => 1]);

        $result = $this->decode($this->tourController->show($this->request('GET', "/tours/$id", $this->authPayload($manager, ['tour_manage' => true])), $this->response(), ['id' => (string) $id]));
        $this->assertSame($id, (int) $result['data']['id']);

        $this->expectException(NotFoundException::class);
        $this->tourController->show($this->request('GET', "/tours/$id", $this->authPayload($this->other)), $this->response(), ['id' => (string) $id]);
    }

    public function testRoutesOfPrivateTourAreNotListedForAnonymousVisitor(): void
    {
        $tourId = (int) $this->tours->create($this->owner, ['name' => 'Private tour'])['id'];
        $this->tours->addRoute($tourId, $this->createRoute($this->owner));

        $this->expectException(NotFoundException::class);
        $this->routeController->index($this->request('GET', '/routes', null, null, ['tour_id' => (string) $tourId]), $this->response());
    }

    public function testRoutesOfPublicTourIncludeItsCreatorsPrivateRoutes(): void
    {
        $tourId = (int) $this->tours->create($this->owner, ['name' => 'Public tour'])['id'];
        $this->tours->addRoute($tourId, $this->createRoute($this->owner)); // private
        $this->tours->setPublished($tourId, true, $this->owner);

        $result = $this->decode($this->routeController->index($this->request('GET', '/routes', null, null, ['tour_id' => (string) $tourId]), $this->response()));
        $this->assertCount(1, $result['data']);
    }

    public function testOthersPrivateRouteCannotBeAddedToOwnTour(): void
    {
        $tourId = (int) $this->tours->create($this->other, ['name' => 'Their tour'])['id'];
        $privateRoute = $this->createRoute($this->owner);

        $this->expectException(ForbiddenException::class);
        $this->tourController->addRoute(
            $this->request('POST', "/tours/$tourId/routes", $this->authPayload($this->other, ['tour_create' => true]), ['route_id' => $privateRoute]),
            $this->response(),
            ['id' => (string) $tourId]
        );
    }

    public function testOwnPrivateAndOthersPublicRoutesCanBeAddedToOwnTour(): void
    {
        $tourId = (int) $this->tours->create($this->other, ['name' => 'Their tour'])['id'];
        $ownPrivate = $this->createRoute($this->other);
        $othersPublic = $this->createRoute($this->owner, ['public' => 1]);
        $auth = $this->authPayload($this->other, ['tour_create' => true]);

        foreach ([$ownPrivate, $othersPublic] as $routeId) {
            $this->tourController->addRoute($this->request('POST', "/tours/$tourId/routes", $auth, ['route_id' => $routeId]), $this->response(), ['id' => (string) $tourId]);
        }

        $this->assertCount(2, $this->routes->findByTour($tourId));
    }

    public function testAddingMissingRouteToTourIs404(): void
    {
        $tourId = (int) $this->tours->create($this->other, ['name' => 'Their tour'])['id'];

        $this->expectException(NotFoundException::class);
        $this->tourController->addRoute(
            $this->request('POST', "/tours/$tourId/routes", $this->authPayload($this->other, ['tour_create' => true]), ['route_id' => 999999999]),
            $this->response(),
            ['id' => (string) $tourId]
        );
    }
}
