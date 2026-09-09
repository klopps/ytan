<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\CaptchaRequiredException;
use Ytan\Exception\NotFoundException;
use Ytan\Http\Controllers\RouteController;
use Ytan\Service\CaptchaService;
use Ytan\Service\MailService;
use Ytan\Service\TourNotificationService;

/**
 * Covers RouteController's two-phase captcha-gated delete
 * (Touren.md: warn + a simple captcha before deleting a route that belongs
 * to one or more tours) and the total_length recalculation that has to
 * happen on both update() (a route's length changing) and delete().
 *
 * MailService points at an unreachable host on purpose - TourNotificationService
 * swallows send failures, so this proves that behaviour too: a broken mail
 * setup must never break a route update/delete.
 */
final class RouteControllerCaptchaTest extends ControllerTestCase
{
    private RouteController $controller;
    private TourRepository $tours;
    private RouteRepository $routes;
    private CaptchaService $captcha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tours = new TourRepository($this->pdo);
        $this->routes = new RouteRepository($this->pdo);
        $this->captcha = new CaptchaService('unit-test-secret');
        $notifications = new TourNotificationService(
            new UserRepository($this->pdo),
            new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test'),
            'http://localhost'
        );
        $this->controller = new RouteController($this->routes, $this->tours, $this->captcha, $notifications);
    }

    public function testDeletingARouteInNoTourSucceedsImmediately(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId);

        $response = $this->controller->delete(
            $this->request('DELETE', '/routes/' . $routeId, $this->authPayload($userId)),
            $this->response(),
            ['id' => (string) $routeId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->expectException(NotFoundException::class);
        $this->routes->findById($routeId);
    }

    public function testDeletingARouteInATourWithoutACaptchaThrowsAndDoesNotDelete(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId);
        $tour = $this->tours->create($userId, ['name' => 'Guarded']);
        $this->tours->addRoute((int) $tour['id'], $routeId);

        try {
            $this->controller->delete(
                $this->request('DELETE', '/routes/' . $routeId, $this->authPayload($userId)),
                $this->response(),
                ['id' => (string) $routeId]
            );
            $this->fail('Expected CaptchaRequiredException.');
        } catch (CaptchaRequiredException $e) {
            $payload = $e->getPayload();
            $this->assertSame(1, $payload['tour_count']);
            $this->assertNotEmpty($payload['captcha']['token']);
        }

        // Still there - the failed attempt must not have deleted anything.
        $this->assertSame($routeId, $this->routes->findById($routeId)['id']);
    }

    public function testSolvingTheCaptchaCompletesTheDeleteAndRecalculatesTheTour(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId, ['length' => 4000]);
        $tour = $this->tours->create($userId, ['name' => 'Guarded']);
        $this->tours->addRoute((int) $tour['id'], $routeId);

        $challenge = null;
        try {
            $this->controller->delete(
                $this->request('DELETE', '/routes/' . $routeId, $this->authPayload($userId)),
                $this->response(),
                ['id' => (string) $routeId]
            );
        } catch (CaptchaRequiredException $e) {
            $challenge = $e->getPayload()['captcha'];
        }
        $this->assertNotNull($challenge);

        preg_match('/^(\d) \+ (\d) = \?$/', $challenge['question'], $m);
        $answer = (int) $m[1] + (int) $m[2];

        $response = $this->controller->delete(
            $this->request('DELETE', '/routes/' . $routeId, $this->authPayload($userId), null, [
                'captcha_token' => $challenge['token'],
                'captcha_answer' => (string) $answer,
            ]),
            $this->response(),
            ['id' => (string) $routeId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, (int) $this->tours->findById((int) $tour['id'])['total_length']);
    }

    /**
     * A captcha token minted for one route must not work for another -
     * exercised here at the controller level (CaptchaServiceTest already
     * covers it at the unit level).
     */
    public function testACaptchaTokenFromADifferentRouteIsRejected(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId);
        $otherRouteId = $this->createRoute($userId);
        $tour = $this->tours->create($userId, ['name' => 'Guarded']);
        $this->tours->addRoute((int) $tour['id'], $routeId);

        $challenge = $this->captcha->issueChallenge('route:' . $otherRouteId);
        preg_match('/^(\d) \+ (\d) = \?$/', $challenge['question'], $m);
        $answer = (int) $m[1] + (int) $m[2];

        $this->expectException(CaptchaRequiredException::class);
        $this->controller->delete(
            $this->request('DELETE', '/routes/' . $routeId, $this->authPayload($userId), null, [
                'captcha_token' => $challenge['token'],
                'captcha_answer' => (string) $answer,
            ]),
            $this->response(),
            ['id' => (string) $routeId]
        );
    }

    public function testUpdatingARoutesLengthRecalculatesEveryTourItBelongsTo(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId, ['length' => 1000]);
        $tour = $this->tours->create($userId, ['name' => 'Recalc On Edit']);
        $this->tours->addRoute((int) $tour['id'], $routeId);

        $this->controller->update(
            $this->request('PUT', '/routes/' . $routeId, $this->authPayload($userId), [
                'name' => 'Renamed',
                'description' => '',
                'public' => 0,
                'length' => 8000,
                'points' => '[]',
                'color' => '#BF409F',
            ]),
            $this->response(),
            ['id' => (string) $routeId]
        );

        $this->assertSame(8000, (int) $this->tours->findById((int) $tour['id'])['total_length']);
    }
}
