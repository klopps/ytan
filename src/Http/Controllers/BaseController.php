<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\UnauthorizedException;

abstract class BaseController
{
    protected function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * @return array{sub:int,username:string,is_admin:bool}
     */
    protected function requireAuthUser(Request $request): array
    {
        $auth = $request->getAttribute('auth');
        if ($auth === null) {
            throw new UnauthorizedException();
        }

        return $auth;
    }

    protected function assertOwnerOrAdmin(array $authUser, int $ownerId): void
    {
        if ((int) $authUser['sub'] !== $ownerId && !($authUser['is_admin'] ?? false)) {
            throw new ForbiddenException();
        }
    }

    /**
     * 403s unless the caller is an admin or has the named boolean right on
     * their JWT payload (e.g. "tour_create") - mirrors requireAdmin()'s
     * "admin overrides everything" shape for the Touren granular rights.
     */
    protected function assertTourRight(array $authUser, string $right): void
    {
        if (!($authUser['is_admin'] ?? false) && !($authUser[$right] ?? false)) {
            throw new ForbiddenException();
        }
    }

    /**
     * @return array{sub:int,username:string,is_admin:bool}
     */
    protected function requireAdmin(Request $request): array
    {
        $auth = $this->requireAuthUser($request);
        if (!($auth['is_admin'] ?? false)) {
            throw new ForbiddenException('Admin access required.');
        }

        return $auth;
    }

    /**
     * @return array<string,mixed> decoded JSON body
     */
    protected function jsonBody(Request $request): array
    {
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }

    private const MAX_PAGINATION_LIMIT = 500;

    /**
     * Reads the optional `limit`/`offset` query params shared by every list
     * endpoint (Poi/Route/Area/User/Tour). `limit` is null when the caller
     * didn't ask to paginate - repositories then apply no LIMIT at all,
     * preserving the existing "return everything" behavior for callers that
     * don't opt in (e.g. the main map bootstrap). When given, it's clamped
     * to [1, MAX_PAGINATION_LIMIT] so a stray huge value can't defeat the
     * point of pagination.
     *
     * @return array{limit: ?int, offset: int}
     */
    protected function parsePagination(Request $request): array
    {
        $params = $request->getQueryParams();

        $limit = isset($params['limit']) ? (int) $params['limit'] : null;
        if ($limit !== null) {
            $limit = max(1, min($limit, self::MAX_PAGINATION_LIMIT));
        }

        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        return ['limit' => $limit, 'offset' => $offset];
    }
}
