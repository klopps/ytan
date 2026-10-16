<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Service\GeocodingService;
use Ytan\Service\RequestThrottle;

/**
 * Backs the weather timeline's place-name title (public/js/weather.js) -
 * public, ungated, same reasoning as WeatherController (useful without
 * login, not tied to any user's own data).
 */
final class GeocodingController extends BaseController
{
    public function __construct(
        private readonly GeocodingService $geocoding,
        private readonly RequestThrottle $throttle,
    ) {
    }

    public function reverse(Request $request, Response $response): Response
    {
        [$lat, $lng] = $this->requireCoordinates($request, 'geocoding.lat_lng_required');
        $this->throttle->hit(RequestThrottle::GEO, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));

        $placeName = $this->geocoding->reverseGeocode($lat, $lng);

        return $this->json($response, ['data' => ['place_name' => $placeName]]);
    }
}
