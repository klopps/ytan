<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Exception\ValidationException;
use Ytan\Service\LighthouseDuplicateService;

/**
 * Backend of the admin tool "Lighthouse duplicates"
 * (templates/admin-lighthouse-duplicates.php): lists groups of lighthouses
 * that lie close together (paginated by group) and merges the ones the admin
 * picks. Admin only.
 */
final class LighthouseDuplicateController extends BaseController
{
    private const DEFAULT_DISTANCE_M = 50.0;
    private const MAX_DISTANCE_M = 5000.0;

    public function __construct(private readonly LighthouseDuplicateService $service)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $params = $request->getQueryParams();
        $distance = isset($params['distance']) ? (float) $params['distance'] : self::DEFAULT_DISTANCE_M;
        if ($distance <= 0 || $distance > self::MAX_DISTANCE_M) {
            throw new ValidationException('distance must be between 0 and ' . (int) self::MAX_DISTANCE_M . ' metres.');
        }
        ['limit' => $limit, 'offset' => $offset] = $this->parsePagination($request);

        $hideDifferentIds = filter_var($params['hide_different_ids'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $onlyWithManual = filter_var($params['only_with_manual'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $groups = $this->service->findGroups($distance, $hideDifferentIds, $onlyWithManual);

        return $this->json($response, [
            'data' => $limit === null ? $groups : array_slice($groups, $offset, $limit),
            'meta' => ['total' => count($groups), 'limit' => $limit, 'offset' => $offset, 'distance' => $distance, 'hide_different_ids' => $hideDifferentIds, 'only_with_manual' => $onlyWithManual],
        ]);
    }

    public function merge(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $merges = $this->jsonBody($request)['merges'] ?? null;
        if (!is_array($merges) || $merges === []) {
            throw new ValidationException('merges must be a non-empty list.');
        }

        return $this->json($response, ['data' => $this->service->merge(array_values($merges))]);
    }
}
