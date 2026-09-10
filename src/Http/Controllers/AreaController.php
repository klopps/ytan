<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Area\AreaRepository;

final class AreaController extends BaseController
{
    public function __construct(private readonly AreaRepository $areas)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $scope = $request->getQueryParams()['scope'] ?? ($auth !== null ? 'mine_public' : 'public');
        ['limit' => $limit, 'offset' => $offset] = $this->parsePagination($request);
        $userId = in_array($scope, ['mine', 'mine_public'], true) ? (int) $this->requireAuthUser($request)['sub'] : null;

        $data = match ($scope) {
            'mine' => $this->areas->findByUser($userId, $limit, $offset),
            'mine_public' => $this->areas->findByUserWithPublic($userId, $limit, $offset),
            default => $this->areas->findPublic($limit, $offset),
        };

        $body = ['data' => $data];
        if ($limit !== null) {
            $body['meta'] = [
                'total' => match ($scope) {
                    'mine' => $this->areas->countByUser($userId),
                    'mine_public' => $this->areas->countByUserWithPublic($userId),
                    default => $this->areas->countPublic(),
                },
                'limit' => $limit,
                'offset' => $offset,
            ];
        }

        return $this->json($response, $body);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['data' => $this->areas->findById((int) $args['id'])]);
    }

    public function create(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $area = $this->areas->create((int) $auth['sub'], $this->jsonBody($request));

        return $this->json($response, ['data' => $area], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->areas->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);

        return $this->json($response, ['data' => $this->areas->update($id, $this->jsonBody($request))]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->areas->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);
        $this->areas->delete($id);

        return $this->json($response, ['data' => ['id' => $id]]);
    }
}
