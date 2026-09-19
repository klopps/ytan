<?php

declare(strict_types=1);

namespace Ytan\Service\Weather;

use DateTimeImmutable;
use DateTimeZone;
use Ytan\Exception\ApiException;
use Ytan\Service\JsonHttpClient;

/**
 * SMHI (Swedish Meteorological and Hydrological Institute) point forecast,
 * for WeatherRegionResolver's 'smhi' region (Sweden + international Baltic
 * waters - the only two the todo item calls out as SMHI's territory).
 * No API key needed.
 *
 * Confirmed live 2026-09-18 (SMHI migrated point-forecast APIs from pmp3g
 * to this snow1g endpoint around March 2026 - official docs are a JS app,
 * not fetchable, so this was verified with a real request rather than
 * assumed from documentation):
 *   GET https://opendata-download-metfcst.smhi.se/api/category/snow1g/version/1/geotype/point/lon/{lng}/lat/{lat}/data.json
 * Response: {"referenceTime": "<model run, ISO UTC>", "timeSeries": [{"time": "<ISO UTC>", "data": {...}}, ...]}
 * - hourly for the near term, same as Open-Meteo's own cadence for the
 * forecast_days=7 window this app uses.
 *
 * Two conversions this class exists to get right (both silently wrong in
 * ways that wouldn't throw, only mislead the user, if skipped):
 * - SMHI's `time` is a real UTC instant ("...Z"); the rest of this app's
 *   pipeline (and weather.js's "now" line math) expects a LOCATION-local,
 *   naive time string, matching Open-Meteo's `timezone=auto` convention -
 *   see WeatherProviderResult's own doc comment. Converted via PHP's tz
 *   database against 'Europe/Stockholm' (correct for both Sweden and the
 *   Baltic, which share this zone), so DST is handled automatically.
 * - SMHI's `wind_speed`/`wind_speed_of_gust` are m/s; every other provider
 *   in this app (Open-Meteo, unitless-default = km/h) and the frontend
 *   that renders them assume km/h. Converted (* 3.6) so a Swedish forecast
 *   doesn't silently read ~3.6x too calm.
 */
final class SmhiWeatherProvider implements WeatherProviderInterface
{
    private const BASE_URL = 'https://opendata-download-metfcst.smhi.se/api/category/snow1g/version/1/geotype/point';
    private const TIMEZONE = 'Europe/Stockholm';

    // SMHI's Wsymb2 symbol_code (1-27, stable across their API versions)
    // mapped onto the WMO code table 4677 buckets weatherConditionIcon()
    // (public/js/weather.js) actually distinguishes - not a 1:1 semantic
    // match (WMO has no clean "sleet" bucket in the reduced icon set this
    // app draws), sleet is mapped onto the closest visual/practical
    // read (wintry mix -> snow icon) rather than rain, since that's more
    // useful to a paddler than precision here.
    private const SYMBOL_CODE_TO_WMO = [
        1 => 0, 2 => 1, 3 => 2, 4 => 2, 5 => 3, 6 => 3, 7 => 45,
        8 => 80, 9 => 81, 10 => 82, 11 => 95,
        12 => 85, 13 => 85, 14 => 86, 15 => 85, 16 => 85, 17 => 86,
        18 => 61, 19 => 63, 20 => 65, 21 => 95,
        22 => 71, 23 => 73, 24 => 75, 25 => 71, 26 => 73, 27 => 75,
    ];

    public function __construct(private readonly JsonHttpClient $httpClient)
    {
    }

    public function fetchGeneralForecast(float $lat, float $lng): WeatherProviderResult
    {
        $url = self::BASE_URL . '/lon/' . $lng . '/lat/' . $lat . '/data.json';
        $response = $this->httpClient->getJson($url, []);

        $timeSeries = $response['timeSeries'] ?? null;
        if (!is_array($timeSeries) || $timeSeries === []) {
            throw new ApiException('Weather data is currently unavailable.', 503, 'weather.unavailable');
        }

        $tz = new DateTimeZone(self::TIMEZONE);
        $utc = new DateTimeZone('UTC');

        $hourly = [];
        foreach ($timeSeries as $entry) {
            $time = $entry['time'] ?? null;
            $data = $entry['data'] ?? null;
            if (!is_string($time) || !is_array($data)) {
                continue;
            }

            $localTime = (new DateTimeImmutable($time, $utc))->setTimezone($tz)->format('Y-m-d\TH:i');
            $windSpeedMs = $data['wind_speed'] ?? null;
            $windGustsMs = $data['wind_speed_of_gust'] ?? null;
            $symbolCode = $data['symbol_code'] ?? null;

            $hourly[$localTime] = [
                'temperature' => $data['air_temperature'] ?? null,
                'feels_like' => null, // SMHI's point forecast has no apparent-temperature field.
                'wind_speed' => $windSpeedMs !== null ? $windSpeedMs * 3.6 : null,
                'wind_gusts' => $windGustsMs !== null ? $windGustsMs * 3.6 : null,
                'wind_direction' => $data['wind_from_direction'] ?? null,
                'precipitation' => $data['precipitation_amount_mean'] ?? null,
                'weather_code' => is_int($symbolCode) ? (self::SYMBOL_CODE_TO_WMO[$symbolCode] ?? null) : null,
            ];
        }

        $referenceTime = $response['referenceTime'] ?? null;

        return new WeatherProviderResult(
            hourly: $hourly,
            utcOffsetSeconds: $tz->getOffset(new DateTimeImmutable('now', $utc)),
            source: 'SMHI',
            model: 'snow1g',
            generatedAt: is_string($referenceTime) ? $referenceTime : null,
        );
    }
}
