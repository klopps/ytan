<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Service\RequestThrottle;
use Ytan\Service\Tide\WeatherTideOverlay;
use Ytan\Service\WeatherService;

/**
 * Backs the map's on-demand weather timeline (right-click/long-press a map
 * location -> "Weather data for this location" -> public/js/weather.js) -
 * public, ungated (like public POIs/routes/areas, weather data is useful
 * without login).
 *
 * The tide curve comes from a national tide gauge within the Routencheck's
 * tidal_station_km when there is one (WeatherTideOverlay, applied after the
 * forecast cache and never stored in it), else from Open-Meteo.
 */
final class WeatherController extends BaseController
{
    public function __construct(
        private readonly WeatherService $weather,
        private readonly RequestThrottle $throttle,
        private readonly ?WeatherTideOverlay $tides = null,
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        [$lat, $lng] = $this->requireCoordinates($request, 'weather.lat_lng_required');
        $this->throttle->hit(RequestThrottle::GEO, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));

        $data = $this->weather->getForecast($lat, $lng);
        if ($this->tides !== null && $this->settings !== null) {
            $rules = $this->settings->briefingRules();
            try {
                $data = $this->tides->apply($data, $lat, $lng, (int) round((float) $rules['tidal_station_km'] * 1000), (float) $rules['tidal_min_range_m']);
            } catch (\Throwable $e) {
                error_log('YTAN: tide overlay for ' . $lat . ',' . $lng . ' failed: ' . $e->getMessage());
            }
        }

        return $this->json($response, ['data' => $data]);
    }
}
