<?php

declare(strict_types=1);

namespace Ytan\Service\Weather;

use Ytan\Exception\ApiException;

/**
 * A source of the general (temperature/wind/precipitation/weather-code)
 * hourly forecast - NOT marine/wave/tide data (that stays Open-Meteo's
 * marine API always, regardless of which general provider is chosen - see
 * WeatherService's own doc comment) and NOT sunrise/sunset (astronomical,
 * also always Open-Meteo - see OpenMeteoWeatherProvider::fetchDailySunTimes()).
 * WeatherRegionResolver picks which implementation WeatherService calls
 * for a given coordinate.
 */
interface WeatherProviderInterface
{
    /**
     * @throws ApiException when the forecast is unavailable - every
     *         coordinate on Earth has ordinary weather, so unlike the
     *         marine/geocoding side-lookups, a failure here is fatal to the
     *         whole request (mirrors WeatherService's pre-existing
     *         behavior for its general Open-Meteo fetch).
     */
    public function fetchGeneralForecast(float $lat, float $lng): WeatherProviderResult;
}
