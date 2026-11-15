<?php

declare(strict_types=1);

namespace Ytan\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\StreamFactory;

/**
 * ETag + 304 for the big list endpoints (GET /pois ~945 KB raw, /routes,
 * /areas, /tours), which every page load fetches in full.
 *
 * The tag is a hash of the response body itself, not of MAX(updated_at) or
 * similar: the lists join other tables (lighthouse, water distances, data
 * sources, user names) that change without touching the main row, so only
 * the body is a tag that can never be stale. That still costs the query and
 * the JSON encoding, but saves the gzip run and - what matters on a phone -
 * the transfer.
 *
 * `Cache-Control: private, no-cache` makes the browser keep the copy but
 * revalidate on every request (the answer depends on the signed-in user),
 * `Vary: Authorization` keeps one user's copy from being offered for
 * another's request. Weak tag, since CompressionMiddleware (which runs
 * around this one) changes the encoding but not the content; a `-gzip`
 * suffix Apache's mod_deflate might append is ignored when comparing.
 */
final class ETagMiddleware implements MiddlewareInterface
{
    private const PATHS = '#/api/v1/(pois|routes|areas|tours)$#';

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || $response->getStatusCode() !== 200
            || $response->hasHeader('ETag')
            || $response->hasHeader('Cache-Control')
            || preg_match(self::PATHS, $request->getUri()->getPath()) !== 1) {
            return $response;
        }

        $etag = 'W/"' . hash('xxh128', (string) $response->getBody()) . '"';
        $response = $response
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'private, no-cache')
            ->withAddedHeader('Vary', 'Authorization');

        if (self::matches($request->getHeaderLine('If-None-Match'), $etag)) {
            return $response
                ->withStatus(304)
                ->withBody((new StreamFactory())->createStream(''))
                ->withoutHeader('Content-Type')
                ->withoutHeader('Content-Length');
        }

        return $response;
    }

    public static function matches(string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === '') {
            return false;
        }
        $wanted = self::opaque($etag);
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || self::opaque($candidate) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /** The tag without W/ prefix, quotes and a mod_deflate "-gzip" suffix. */
    private static function opaque(string $tag): string
    {
        $tag = trim(preg_replace('#^W/#', '', $tag), '"');

        return preg_replace('#-gzip$#', '', $tag);
    }
}
