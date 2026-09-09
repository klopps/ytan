<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\CaptchaRequiredException;
use Ytan\Service\CaptchaService;
use Ytan\Service\TourNotificationService;

final class RouteController extends BaseController
{
    public function __construct(
        private readonly RouteRepository $routes,
        private readonly TourRepository $tours,
        private readonly CaptchaService $captcha,
        private readonly TourNotificationService $notifications,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $params = $request->getQueryParams();
        $scope = $params['scope'] ?? ($auth !== null ? 'mine_public' : 'public');

        if (isset($params['tour_id'])) {
            $data = $this->routes->findByTour((int) $params['tour_id']);
        } else {
            $data = match ($scope) {
                'mine' => $this->routes->findByUser($this->requireAuthUser($request)['sub']),
                'mine_public' => $this->routes->findByUserWithPublic($this->requireAuthUser($request)['sub']),
                default => $this->routes->findPublic(),
            };
        }

        return $this->json($response, ['data' => $data]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['data' => $this->routes->findById((int) $args['id'])]);
    }

    public function create(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $route = $this->routes->create((int) $auth['sub'], $this->jsonBody($request));

        return $this->json($response, ['data' => $route], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->routes->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);

        $updated = $this->routes->update($id, $this->jsonBody($request));

        // A route's length can change here (re-drawn geometry) - any tour
        // containing it caches a total_length that's now stale (Touren.md:
        // "... ändert sich die Länge einer Route, muss die Gesamtlänge neu
        // berechnet werden"), unlike TourController's own add/remove/reorder
        // endpoints which recalculate as part of the same request.
        $affectedTours = $this->tours->findByRoute($id);
        foreach ($affectedTours as $tour) {
            $this->tours->recalculateTotalLength((int) $tour['id']);
        }
        if ($affectedTours !== []) {
            $this->notifications->notifyRouteChanged($affectedTours, $updated);
        }

        return $this->json($response, ['data' => $updated]);
    }

    /**
     * Two-phase when the route belongs to >=1 tours (Touren.md's "warn +
     * simple captcha before deleting a tour route" flow): a first call with
     * no (or an invalid/expired) captcha_token/captcha_answer throws
     * CaptchaRequiredException, which App.php's error handler turns into a
     * 422 carrying a fresh challenge; the client re-issues the same DELETE
     * with that token + the user's answer attached to actually proceed.
     * Routes not in any tour delete immediately, same as before.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->routes->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);

        $affectedTours = $this->tours->findByRoute($id);

        if ($affectedTours !== []) {
            $params = $request->getQueryParams();
            $verified = isset($params['captcha_token'], $params['captcha_answer'])
                && $this->captcha->verify((string) $params['captcha_token'], (int) $params['captcha_answer'], "route:$id");

            if (!$verified) {
                $challenge = $this->captcha->issueChallenge("route:$id");
                throw new CaptchaRequiredException(count($affectedTours), $challenge['question'], $challenge['token']);
            }
        }

        $wasPublic = (bool) $existing['public'];

        $this->routes->delete($id);

        foreach ($affectedTours as $tour) {
            $this->tours->recalculateTotalLength((int) $tour['id']);
        }

        if ($affectedTours !== []) {
            $this->notifications->notifyRouteDeleted($affectedTours, $existing);
        }
        if ($wasPublic) {
            $this->notifications->notifyRouteAutoUnpublished($existing);
        }

        return $this->json($response, ['data' => ['id' => $id]]);
    }
}
