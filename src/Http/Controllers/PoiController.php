<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Exception\ForbiddenException;

final class PoiController extends BaseController
{
    public function __construct(private readonly PoiRepository $pois)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $scope = $request->getQueryParams()['scope'] ?? ($auth !== null ? 'mine_public' : 'public');

        $data = match ($scope) {
            'mine' => $this->pois->findByUser($this->requireAuthUser($request)['sub']),
            'mine_public' => $this->pois->findByUserWithPublic($this->requireAuthUser($request)['sub']),
            'all' => $this->requireAdmin($request) ? $this->pois->findAll() : [],
            default => $this->pois->findPublic(),
        };

        return $this->json($response, ['data' => $data]);
    }

    public function bounds(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => $this->pois->bounds()]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['data' => $this->pois->findById((int) $args['id'])]);
    }

    public function create(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $poi = $this->pois->create((int) $auth['sub'], $this->jsonBody($request));

        return $this->json($response, ['data' => $poi], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->pois->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);

        return $this->json($response, ['data' => $this->pois->update($id, $this->jsonBody($request))]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->pois->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);
        $this->pois->delete($id);

        return $this->json($response, ['data' => ['id' => $id]]);
    }

    private function requireAdmin(Request $request): bool
    {
        $auth = $this->requireAuthUser($request);
        if (!($auth['is_admin'] ?? false)) {
            throw new ForbiddenException('Admin access required.');
        }

        return true;
    }
}
