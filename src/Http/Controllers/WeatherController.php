<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Service\RequestThrottle;
use Ytan\Service\WeatherService;

/**
 * Backs the map's on-demand weather timeline (right-click/long-press a map
 * location -> "Weather data for this location" -> public/js/weather.js) -
 * public, ungated (like public POIs/routes/areas, weather data is useful
 * without login).
 */
final class WeatherController extends BaseController
{
    public function __construct(
        private readonly WeatherService $weather,
        private readonly RequestThrottle $throttle,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        [$lat, $lng] = $this->requireCoordinates($request, 'weather.lat_lng_required');
        $this->throttle->hit(RequestThrottle::GEO, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));

        $data = $this->weather->getForecast($lat, $lng);

        return $this->json($response, ['data' => $data]);
    }
}
