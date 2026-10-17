<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Service\RadarTileService;

/**
 * Rain radar overlay (public/js/radar.js): the frame list and the tiles
 * the Python job in tools/radar/ produces. Public like the weather data.
 *
 * Both stay under /api/: templates/sw.php caches every other .png
 * cache-first forever, which would freeze the radar images.
 */
final class RadarController extends BaseController
{
    public function __construct(private readonly RadarTileService $tiles)
    {
    }

    public function manifest(Request $request, Response $response): Response
    {
        $data = $this->tiles->manifest();

        // the job runs every 5 minutes; a minute of staleness is fine
        return $this->json($response, ['data' => $data])
            ->withHeader('Cache-Control', 'public, max-age=60');
    }

    public function tile(Request $request, Response $response, array $args): Response
    {
        $png = $this->tiles->tile((string) $args['frame'], (int) $args['z'], (int) $args['x'], (int) $args['y']);
        $response->getBody()->write($png);

        // a frame never changes once published (job.py), and lives ~3 hours at most
        return $response
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Cache-Control', 'public, max-age=86400, immutable');
    }
}
