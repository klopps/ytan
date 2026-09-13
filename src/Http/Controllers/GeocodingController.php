<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Exception\ValidationException;
use Ytan\Service\GeocodingService;

/**
 * Backs the weather timeline's place-name title (public/js/weather.js) -
 * public, ungated, same reasoning as WeatherController (useful without
 * login, not tied to any user's own data).
 */
final class GeocodingController extends BaseController
{
    public function __construct(private readonly GeocodingService $geocoding)
    {
    }

    public function reverse(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $lat = $params['lat'] ?? null;
        $lng = $params['lng'] ?? null;

        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
            throw new ValidationException('lat and lng are required numeric query parameters.', 'geocoding.lat_lng_required');
        }

        $placeName = $this->geocoding->reverseGeocode((float) $lat, (float) $lng);

        return $this->json($response, ['data' => ['place_name' => $placeName]]);
    }
}
