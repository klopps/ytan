<?php

declare(strict_types=1);

namespace Ytan\Service\Weather;

/**
 * A WeatherProviderInterface::fetchGeneralForecast() result - hourly rows
 * already in the app's own field shape (temperature/feels_like/wind_speed/
 * wind_gusts/wind_direction/precipitation/weather_code, keyed by ISO time
 * string, WeatherService zips these against marine data the same way it
 * always has) plus the metadata todo.md asks to start storing (source,
 * model, generated_at - the last one nullable, "falls vorhanden").
 */
final class WeatherProviderResult
{
    /**
     * @param array<string, array<string, mixed>> $hourly keyed by ISO time
     *        string (location-local, naive - see WeatherService's own doc
     *        comment on why: Open-Meteo's timezone=auto convention, which
     *        every provider must match so the frontend's "now" line logic
     *        keeps working unchanged)
     */
    /**
     * @param list<array{date: string, sunrise: ?string, sunset: ?string}> $daily
     *        Sunrise/sunset per calendar day. Only OpenMeteoWeatherProvider
     *        populates this (its request already asks for Open-Meteo's own
     *        `daily` block alongside `hourly`, so returning it here avoids
     *        a second network call when Open-Meteo IS the chosen general
     *        provider); SmhiWeatherProvider always returns an empty array
     *        here - WeatherService calls OpenMeteoWeatherProvider::
     *        fetchDailySunTimes() separately for a non-Open-Meteo region,
     *        since sun times are astronomical, not weather-model-specific.
     */
    public function __construct(
        public readonly array $hourly,
        public readonly int $utcOffsetSeconds,
        public readonly string $source,
        public readonly ?string $model,
        public readonly ?string $generatedAt,
        public readonly array $daily = [],
    ) {
    }
}
