<?php

declare(strict_types=1);

namespace Ytan\Service\Weather;

use Ytan\Exception\ApiException;
use Ytan\Service\JsonHttpClient;

/**
 * Open-Meteo (free, no API key - see open-meteo.com) as a
 * WeatherProviderInterface, plus three methods WeatherService calls
 * directly (not through the interface):
 * - fetchGeneralForecastForModel() - the SAME general-forecast request as
 *   fetchGeneralForecast() below, just with Open-Meteo's own `models=`
 *   parameter set. Used for the five countries WeatherRegionResolver maps
 *   onto a specific national model (DWD/DMI/MET Norway/Météo-France/KNMI) -
 *   not part of WeatherProviderInterface itself since no other provider
 *   (SmhiWeatherProvider) has an analogous "give me submodel X" concept.
 * - fetchMarineHourly() (wave/swell/tide - Open-Meteo is the only source
 *   this app has for that, so every region uses it, regardless of model)
 * - fetchDailySunTimes() (sunrise/sunset are astronomical, not weather-
 *   model-specific - Open-Meteo can compute them for any coordinate
 *   regardless of which general provider/model serves the actual weather)
 *
 * This is the exact fetchGeneralHourly()/fetchMarineHourly() code that used
 * to live directly in WeatherService, moved here unchanged when
 * WeatherService became a multi-provider orchestrator - see that class's
 * own doc comment.
 */
final class OpenMeteoWeatherProvider implements WeatherProviderInterface
{
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    private const MARINE_URL = 'https://marine-api.open-meteo.com/v1/marine';

    private const GENERAL_HOURLY_VARS = 'temperature_2m,apparent_temperature,wind_speed_10m,wind_gusts_10m,wind_direction_10m,precipitation,weather_code';
    private const GENERAL_DAILY_VARS = 'sunrise,sunset';
    private const MARINE_HOURLY_VARS = 'wave_height,wave_direction,wave_period,swell_wave_height,swell_wave_direction,swell_wave_period,wind_wave_height,wind_wave_direction,wind_wave_period,sea_surface_temperature,sea_level_height_msl';

    // Open-Meteo's marine model caps hourly forecasts at 7 days - matching
    // that for the general forecast too keeps both series the same length
    // (they're zipped together by timestamp) and is plenty for planning a
    // multi-day kayak tour.
    private const FORECAST_DAYS = 7;

    public function __construct(private readonly JsonHttpClient $httpClient)
    {
    }

    public function fetchGeneralForecast(float $lat, float $lng): WeatherProviderResult
    {
        return $this->fetchGeneral($lat, $lng, null, 'Open-Meteo');
    }

    /**
     * @param string $model an Open-Meteo `models=` value, e.g.
     *        "icon_seamless" - see WeatherRegionResolver::COUNTRY_MODELS
     * @param string $sourceLabel the national service that model
     *        represents (e.g. "DWD"), shown to the user instead of the
     *        generic "Open-Meteo" - it's still Open-Meteo's own API/HTTP
     *        client under the hood, just a specific model parameter
     */
    public function fetchGeneralForecastForModel(float $lat, float $lng, string $model, string $sourceLabel): WeatherProviderResult
    {
        return $this->fetchGeneral($lat, $lng, $model, $sourceLabel);
    }

    private function fetchGeneral(float $lat, float $lng, ?string $model, string $sourceLabel): WeatherProviderResult
    {
        $query = [
            'latitude' => $lat,
            'longitude' => $lng,
            'hourly' => self::GENERAL_HOURLY_VARS,
            'daily' => self::GENERAL_DAILY_VARS,
            'forecast_days' => self::FORECAST_DAYS,
            'timezone' => 'auto',
        ];
        if ($model !== null) {
            $query['models'] = $model;
        }

        $response = $this->httpClient->getJson(self::FORECAST_URL, $query);

        $hourly = $response['hourly'] ?? null;
        if (!is_array($hourly) || !isset($hourly['time']) || !is_array($hourly['time'])) {
            throw new ApiException('Weather data is currently unavailable.', 503, 'weather.unavailable');
        }

        $result = [];
        foreach ($hourly['time'] as $index => $time) {
            $result[$time] = [
                'temperature' => $hourly['temperature_2m'][$index] ?? null,
                'feels_like' => $hourly['apparent_temperature'][$index] ?? null,
                'wind_speed' => $hourly['wind_speed_10m'][$index] ?? null,
                'wind_gusts' => $hourly['wind_gusts_10m'][$index] ?? null,
                'wind_direction' => $hourly['wind_direction_10m'][$index] ?? null,
                'precipitation' => $hourly['precipitation'][$index] ?? null,
                'weather_code' => $hourly['weather_code'][$index] ?? null,
            ];
        }

        return new WeatherProviderResult(
            hourly: $result,
            utcOffsetSeconds: (int) ($response['utc_offset_seconds'] ?? 0),
            source: $sourceLabel,
            model: $model,
            generatedAt: null,
            daily: $this->extractDaily($response['daily'] ?? null),
        );
    }

    /**
     * @return list<array{date: string, sunrise: ?string, sunset: ?string}>
     */
    private function extractDaily(mixed $daily): array
    {
        $dailyResult = [];
        if (is_array($daily) && isset($daily['time']) && is_array($daily['time'])) {
            foreach ($daily['time'] as $index => $date) {
                $dailyResult[] = [
                    'date' => $date,
                    'sunrise' => $daily['sunrise'][$index] ?? null,
                    'sunset' => $daily['sunset'][$index] ?? null,
                ];
            }
        }

        return $dailyResult;
    }

    /**
     * @return array{daily: list<array{date: string, sunrise: ?string, sunset: ?string}>, utc_offset_seconds: int}
     *         Only asks Open-Meteo for the `daily` block (no `hourly` param
     *         at all) when called on behalf of a non-Open-Meteo general
     *         provider (SmhiWeatherProvider today) - still needs its own
     *         utc_offset_seconds since a provider that skipped this call
     *         entirely (Open-Meteo itself, via fetchGeneralForecast()
     *         above) has no reason to ask twice.
     */
    public function fetchDailySunTimes(float $lat, float $lng): array
    {
        $response = $this->httpClient->getJson(self::FORECAST_URL, [
            'latitude' => $lat,
            'longitude' => $lng,
            'daily' => self::GENERAL_DAILY_VARS,
            'forecast_days' => self::FORECAST_DAYS,
            'timezone' => 'auto',
        ]);

        return [
            'daily' => $this->extractDaily($response['daily'] ?? null),
            'utc_offset_seconds' => (int) ($response['utc_offset_seconds'] ?? 0),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>|null keyed by ISO time
     *         string, or null when the request itself failed (network
     *         error, non-200) - distinct from a successful response whose
     *         values are simply all null for an inland point, which
     *         returns a normal keyed array here (each entry's
     *         wave_height just happens to be null).
     */
    public function fetchMarineHourly(float $lat, float $lng): ?array
    {
        $response = $this->httpClient->getJson(self::MARINE_URL, [
            'latitude' => $lat,
            'longitude' => $lng,
            'hourly' => self::MARINE_HOURLY_VARS,
            'forecast_days' => self::FORECAST_DAYS,
            'timezone' => 'auto',
        ]);

        $hourly = $response['hourly'] ?? null;
        if (!is_array($hourly) || !isset($hourly['time']) || !is_array($hourly['time'])) {
            return null;
        }

        $result = [];
        foreach ($hourly['time'] as $index => $time) {
            $result[$time] = [
                'wave_height' => $hourly['wave_height'][$index] ?? null,
                'wave_direction' => $hourly['wave_direction'][$index] ?? null,
                'wave_period' => $hourly['wave_period'][$index] ?? null,
                'swell_wave_height' => $hourly['swell_wave_height'][$index] ?? null,
                'swell_wave_direction' => $hourly['swell_wave_direction'][$index] ?? null,
                'swell_wave_period' => $hourly['swell_wave_period'][$index] ?? null,
                'wind_wave_height' => $hourly['wind_wave_height'][$index] ?? null,
                'wind_wave_direction' => $hourly['wind_wave_direction'][$index] ?? null,
                'wind_wave_period' => $hourly['wind_wave_period'][$index] ?? null,
                'sea_surface_temperature' => $hourly['sea_surface_temperature'][$index] ?? null,
                // Astronomical/oceanographic sea level relative to mean sea
                // level - i.e. the actual tide, not wave chop. Confirmed
                // live: a real tidal North Sea point (Cuxhaven) returns a
                // clean ~12.4h semi-diurnal curve swinging +-2m; the Baltic
                // reference point this feature was built against shows only
                // a small, non-tidal-shaped sea-level wobble (the Baltic is
                // nearly tideless) - both are real, non-null data, just very
                // different amplitudes depending on the coast.
                'tide_height' => $hourly['sea_level_height_msl'][$index] ?? null,
            ];
        }

        return $result;
    }
}
