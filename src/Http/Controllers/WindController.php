<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Service\WindDataService;

/**
 * Wind overlay (public/js/wind.js): the manifest and the data blocks the
 * Python job in tools/wind/ produces. Public like the weather data.
 *
 * Both stay under /api/: templates/sw.php caches other static-looking
 * responses cache-first, which would freeze the wind data.
 */
final class WindController extends BaseController
{
    public function __construct(private readonly WindDataService $wind)
    {
    }

    /** `data: null` before the job's first run (the client then says "no wind data"). */
    public function manifest(Request $request, Response $response): Response
    {
        // the job checks every 15 minutes but publishes at most every 3 hours; a minute is fine
        return $this->json($response, ['data' => $this->wind->manifest()])
            ->withHeader('Cache-Control', 'public, max-age=60');
    }

    public function block(Request $request, Response $response, array $args): Response
    {
        $bytes = $this->wind->block(
            (string) $args['run'],
            (string) $args['level'],
            (int) $args['hour'],
            isset($args['block']) ? (string) $args['block'] : null,
        );
        $response->getBody()->write($bytes);

        // a run's files never change; the run is replaced, not edited
        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Encoding', 'gzip')
            ->withHeader('Cache-Control', 'public, max-age=86400, immutable');
    }
}
