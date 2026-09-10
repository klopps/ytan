<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Poi\PoiRepository;

final class PoiController extends BaseController
{
    public function __construct(private readonly PoiRepository $pois)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $scope = $request->getQueryParams()['scope'] ?? ($auth !== null ? 'mine_public' : 'public');
        ['limit' => $limit, 'offset' => $offset] = $this->parsePagination($request);

        if ($scope === 'all') {
            $this->requireAdmin($request);
        }
        $userId = in_array($scope, ['mine', 'mine_public'], true) ? (int) $this->requireAuthUser($request)['sub'] : null;

        $data = match ($scope) {
            'mine' => $this->pois->findByUser($userId, $limit, $offset),
            'mine_public' => $this->pois->findByUserWithPublic($userId, $limit, $offset),
            'all' => $this->pois->findAll($limit, $offset),
            default => $this->pois->findPublic($limit, $offset),
        };

        $body = ['data' => $data];
        if ($limit !== null) {
            $body['meta'] = [
                'total' => match ($scope) {
                    'mine' => $this->pois->countByUser($userId),
                    'mine_public' => $this->pois->countByUserWithPublic($userId),
                    'all' => $this->pois->countAll(),
                    default => $this->pois->countPublic(),
                },
                'limit' => $limit,
                'offset' => $offset,
            ];
        }

        return $this->json($response, $body);
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
}
