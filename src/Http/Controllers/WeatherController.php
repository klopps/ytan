<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Exception\ValidationException;
use Ytan\Service\WeatherService;

/**
 * Backs the map's on-demand weather timeline (right-click/long-press a map
 * location -> "Weather data for this location" -> public/js/weather.js) -
 * public, ungated (like public POIs/routes/areas, weather data is useful
 * without login).
 */
final class WeatherController extends BaseController
{
    public function __construct(private readonly WeatherService $weather)
    {
    }

    public function show(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $lat = $params['lat'] ?? null;
        $lng = $params['lng'] ?? null;

        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
            throw new ValidationException('lat and lng are required numeric query parameters.', 'weather.lat_lng_required');
        }

        $data = $this->weather->getForecast((float) $lat, (float) $lng);

        return $this->json($response, ['data' => $data]);
    }
}
