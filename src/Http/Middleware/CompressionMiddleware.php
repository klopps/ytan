<?php

declare(strict_types=1);

namespace Ytan\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\StreamFactory;

/**
 * gzip for the app's own text responses (performance review 2026-10-16:
 * production sent everything uncompressed - GET /api/v1/pois alone was
 * 945 KB instead of 95 KB). Done here rather than relying on Apache's
 * mod_deflate, which the host may not have; if it does, it leaves a
 * response that already carries a Content-Encoding alone. Static files are
 * compressed by mod_deflate (public/.htaccess) where available.
 *
 * Only when the client accepts gzip, for text types, from 1 KB on (below
 * that the header overhead eats the gain). Not for the Garmin watch's
 * endpoint: whether Connect IQ's makeWebRequest decodes gzip is unknown,
 * and its payload is small anyway.
 */
final class CompressionMiddleware implements MiddlewareInterface
{
    private const MIN_BYTES = 1024;
    private const LEVEL = 5;
    private const COMPRESSIBLE = '#^(text/|application/(json|javascript|xml|gpx\+xml|manifest\+json)|image/svg\+xml)#';

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        if (!str_contains(strtolower($request->getHeaderLine('Accept-Encoding')), 'gzip')
            || $response->hasHeader('Content-Encoding')
            || in_array($response->getStatusCode(), [204, 304], true)
            || preg_match(self::COMPRESSIBLE, $response->getHeaderLine('Content-Type')) !== 1
            || str_ends_with($request->getUri()->getPath(), '/watch/device/route')) {
            return $response;
        }

        $body = (string) $response->getBody();
        if (strlen($body) < self::MIN_BYTES) {
            return $response;
        }

        $compressed = gzencode($body, self::LEVEL);
        if ($compressed === false) {
            return $response;
        }

        return $response
            ->withBody((new StreamFactory())->createStream($compressed))
            ->withHeader('Content-Encoding', 'gzip')
            ->withAddedHeader('Vary', 'Accept-Encoding')
            ->withoutHeader('Content-Length');
    }
}
