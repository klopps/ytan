<?php

declare(strict_types=1);

namespace Ytan\Service\Weather;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use Ytan\Exception\ApiException;
use Ytan\Service\Tide\TideHttp;

/**
 * FMI (Finnish Meteorological Institute) point forecast for
 * WeatherRegionResolver's 'fmi' region (Finland incl. Åland). FMI's open
 * data needs no API key any more (the todo note "eigener API-Key" was from
 * before 2019) and is CC BY 4.0. Open-Meteo has no FMI model, hence a real
 * provider like SMHI's.
 *
 * Checked live 2026-11-15:
 *   GET https://opendata.fmi.fi/wfs?service=WFS&version=2.0.0&request=getFeature
 *       &storedquery_id=fmi::forecast::edited::weather::scandinavia::point::timevaluepair
 *       &latlon={lat},{lng}&timestep=60&endtime={UTC}&parameters=...
 * answers in ~0.3 s with one wml2:MeasurementTimeseries per parameter
 * (gml:id "mts-1-1-{Parameter}"), hourly from the next full hour for as long
 * as endtime asks (8 days checked), times in UTC. The analysis time of the
 * edited forecast is in an om:parameter TimeInstant whose gml:id starts with
 * "analysis-time-". Values that don't exist are the literal "NaN".
 *
 * Traps found while checking:
 * - `WindGust` is NaN for every hour of the edited forecast; the gusts are
 *   in `HourlyMaximumGust` (m/s).
 * - Wind speeds are m/s, the app works in km/h (* 3.6, as SMHI).
 * - Times are UTC, the app wants location-local naive strings
 *   (Open-Meteo's timezone=auto convention, see WeatherProviderResult) -
 *   converted against Europe/Helsinki (also Åland's zone).
 *
 * HTTP goes through TideHttp (a plain "GET a URL, text or null" seam with
 * FakeTideHttp in tests) since JsonHttpClient only decodes JSON.
 */
final class FmiWeatherProvider implements WeatherProviderInterface
{
    private const BASE_URL = 'https://opendata.fmi.fi/wfs';
    private const STORED_QUERY = 'fmi::forecast::edited::weather::scandinavia::point::timevaluepair';
    private const TIMEZONE = 'Europe/Helsinki';
    // a little more than WeatherService's 7-day window
    private const FORECAST_DAYS = 8;
    private const PARAMETERS = ['Temperature', 'FeelsLike', 'WindSpeedMS', 'HourlyMaximumGust', 'WindDirection', 'Precipitation1h', 'WeatherSymbol3'];

    // FMI WeatherSymbol3 -> the WMO 4677 buckets weatherConditionIcon()
    // (public/js/weather.js) distinguishes. Sleet goes to the snow-shower
    // buckets, as SmhiWeatherProvider does.
    private const SYMBOL3_TO_WMO = [
        1 => 0, 2 => 2, 3 => 3,
        21 => 80, 22 => 81, 23 => 82,
        31 => 61, 32 => 63, 33 => 65,
        41 => 85, 42 => 85, 43 => 86,
        51 => 71, 52 => 73, 53 => 75,
        61 => 95, 62 => 95, 63 => 95, 64 => 95,
        71 => 85, 72 => 85, 73 => 86,
        81 => 85, 82 => 85, 83 => 86,
        91 => 45, 92 => 45,
    ];

    /** @var \Closure(): DateTimeImmutable */
    private \Closure $now;

    /**
     * @param (\Closure(): DateTimeImmutable)|null $now for tests (fixes endtime in the URL)
     */
    public function __construct(private readonly TideHttp $http, ?\Closure $now = null)
    {
        $this->now = $now ?? fn () => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function url(float $lat, float $lng, DateTimeImmutable $nowUtc): string
    {
        $end = $nowUtc->setTimezone(new DateTimeZone('UTC'))->modify('+' . self::FORECAST_DAYS . ' days')->format('Y-m-d\T00:00:00\Z');

        return self::BASE_URL . '?' . http_build_query([
            'service' => 'WFS',
            'version' => '2.0.0',
            'request' => 'getFeature',
            'storedquery_id' => self::STORED_QUERY,
            'latlon' => round($lat, 4) . ',' . round($lng, 4),
            'timestep' => 60,
            'endtime' => $end,
            'parameters' => implode(',', self::PARAMETERS),
        ]);
    }

    public function fetchGeneralForecast(float $lat, float $lng): WeatherProviderResult
    {
        $xml = $this->http->get(self::url($lat, $lng, ($this->now)()));
        $series = $xml !== null ? $this->parse($xml) : null;
        if ($series === null || ($series['values']['Temperature'] ?? []) === []) {
            throw new ApiException('Weather data is currently unavailable.', 503, 'weather.unavailable');
        }

        $tz = new DateTimeZone(self::TIMEZONE);
        $utc = new DateTimeZone('UTC');
        $values = $series['values'];

        $hourly = [];
        foreach (array_keys($values['Temperature']) as $time) {
            $windMs = $values['WindSpeedMS'][$time] ?? null;
            $gustMs = $values['HourlyMaximumGust'][$time] ?? null;
            $symbol = $values['WeatherSymbol3'][$time] ?? null;
            $direction = $values['WindDirection'][$time] ?? null;
            $localTime = (new DateTimeImmutable($time, $utc))->setTimezone($tz)->format('Y-m-d\TH:i');

            $hourly[$localTime] = [
                'temperature' => round($values['Temperature'][$time], 1),
                'feels_like' => isset($values['FeelsLike'][$time]) ? round($values['FeelsLike'][$time], 1) : null,
                'wind_speed' => $windMs !== null ? round($windMs * 3.6, 1) : null,
                'wind_gusts' => $gustMs !== null ? round($gustMs * 3.6, 1) : null,
                'wind_direction' => $direction !== null ? (int) round($direction) : null,
                'precipitation' => $values['Precipitation1h'][$time] ?? null,
                'weather_code' => $symbol !== null ? (self::SYMBOL3_TO_WMO[(int) $symbol] ?? null) : null,
            ];
        }

        return new WeatherProviderResult(
            hourly: $hourly,
            utcOffsetSeconds: $tz->getOffset(new DateTimeImmutable('now', $utc)),
            source: 'FMI',
            model: 'edited',
            generatedAt: $series['analysis_time'],
        );
    }

    /**
     * @return array{values: array<string, array<string, float>>, analysis_time: ?string}|null
     *         values[parameter][UTC time] (NaN left out), null for unreadable XML
     */
    private function parse(string $xml): ?array
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml, LIBXML_NONET)) {
            return null;
        }
        $xpath = new DOMXPath($doc);

        $values = [];
        foreach ($xpath->query('//*[local-name()="MeasurementTimeseries"]') as $series) {
            $id = (string) $series->attributes?->getNamedItemNS('http://www.opengis.net/gml/3.2', 'id')?->nodeValue;
            $parameter = substr($id, (int) strrpos($id, '-') + 1);
            if (!in_array($parameter, self::PARAMETERS, true)) {
                continue;
            }
            foreach ($xpath->query('.//*[local-name()="MeasurementTVP"]', $series) as $point) {
                $time = trim((string) $xpath->evaluate('string(*[local-name()="time"])', $point));
                $value = trim((string) $xpath->evaluate('string(*[local-name()="value"])', $point));
                if ($time === '' || $value === '' || !is_numeric($value)) {
                    continue; // "NaN"
                }
                $values[$parameter][$time] = (float) $value;
            }
        }

        $analysis = null;
        foreach ($xpath->query('//*[local-name()="TimeInstant"]') as $instant) {
            $id = (string) $instant->attributes?->getNamedItemNS('http://www.opengis.net/gml/3.2', 'id')?->nodeValue;
            if (str_starts_with($id, 'analysis-time-')) {
                $analysis = trim((string) $xpath->evaluate('string(*[local-name()="timePosition"])', $instant)) ?: null;
                break;
            }
        }

        return ['values' => $values, 'analysis_time' => $analysis];
    }
}
