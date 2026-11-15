<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Ytan\Exception\ApiException;
use Ytan\Service\Weather\FmiWeatherProvider;
use Ytan\Tests\Fixtures\FakeTideHttp;

final class FmiWeatherProviderTest extends TestCase
{
    private FakeTideHttp $http;
    private FmiWeatherProvider $provider;

    protected function setUp(): void
    {
        $this->http = new FakeTideHttp();
        $this->provider = new FmiWeatherProvider($this->http, fn () => new DateTimeImmutable('2026-11-15T15:10:00Z', new DateTimeZone('UTC')));
    }

    /**
     * Shape of the real timevaluepair answer (checked live 2026-11-15),
     * trimmed to what the provider reads.
     *
     * @param array<string, array<string, string>> $series parameter => [UTC time => value]
     */
    private static function xml(array $series): string
    {
        $members = '';
        foreach ($series as $parameter => $points) {
            $tvps = '';
            foreach ($points as $time => $value) {
                $tvps .= "<wml2:point><wml2:MeasurementTVP><wml2:time>{$time}</wml2:time>\n\t<wml2:value>{$value}</wml2:value> </wml2:MeasurementTVP></wml2:point>";
            }
            $members .= <<<XML
                <wfs:member><omso:PointTimeSeriesObservation gml:id="WFS-abc-{$parameter}">
                  <om:parameter><om:NamedValue><om:value>
                    <gml:TimeInstant gml:id="analysis-time-1-1-{$parameter}--"><gml:timePosition>2026-11-15T14:40:00Z</gml:timePosition></gml:TimeInstant>
                  </om:value></om:NamedValue></om:parameter>
                  <om:result><wml2:MeasurementTimeseries gml:id="mts-1-1-{$parameter}">{$tvps}</wml2:MeasurementTimeseries></om:result>
                </omso:PointTimeSeriesObservation></wfs:member>
                XML;
        }

        return '<?xml version="1.0" encoding="UTF-8"?><wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0"'
            . ' xmlns:om="http://www.opengis.net/om/2.0" xmlns:omso="http://inspire.ec.europa.eu/schemas/omso/3.0"'
            . ' xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:wml2="http://www.opengis.net/waterml/2.0">'
            . $members . '</wfs:FeatureCollection>';
    }

    public function testRequestsSevenPlusDaysOfTheEditedForecastWithoutAKey(): void
    {
        $url = FmiWeatherProvider::url(60.1699, 24.9384, new DateTimeImmutable('2026-11-15T15:10:00Z'));

        $this->assertStringStartsWith('https://opendata.fmi.fi/wfs?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('fmi::forecast::edited::weather::scandinavia::point::timevaluepair', $query['storedquery_id']);
        $this->assertSame('60.1699,24.9384', $query['latlon']);
        $this->assertSame('2026-11-23T00:00:00Z', $query['endtime']);
        $this->assertStringContainsString('HourlyMaximumGust', $query['parameters']);
        $this->assertArrayNotHasKey('apikey', $query);
    }

    public function testMapsTheSeriesIntoTheAppsHourlyShapeInHelsinkiTime(): void
    {
        $this->http->on('https://opendata.fmi.fi/wfs', self::xml([
            'Temperature' => ['2026-11-15T16:00:00Z' => '8.1', '2026-11-15T17:00:00Z' => '8.05'],
            'FeelsLike' => ['2026-11-15T16:00:00Z' => '3.9', '2026-11-15T17:00:00Z' => '4.0'],
            'WindSpeedMS' => ['2026-11-15T16:00:00Z' => '5.0', '2026-11-15T17:00:00Z' => '6.0'],
            'HourlyMaximumGust' => ['2026-11-15T16:00:00Z' => '10.0', '2026-11-15T17:00:00Z' => 'NaN'],
            'WindDirection' => ['2026-11-15T16:00:00Z' => '226.4', '2026-11-15T17:00:00Z' => '230'],
            'Precipitation1h' => ['2026-11-15T16:00:00Z' => '0.4', '2026-11-15T17:00:00Z' => '0'],
            'WeatherSymbol3' => ['2026-11-15T16:00:00Z' => '32', '2026-11-15T17:00:00Z' => '1'],
        ]));

        $result = $this->provider->fetchGeneralForecast(60.17, 24.94);

        $this->assertSame('FMI', $result->source);
        $this->assertSame('edited', $result->model);
        $this->assertSame('2026-11-15T14:40:00Z', $result->generatedAt);
        $this->assertSame([], $result->daily);
        $this->assertContains($result->utcOffsetSeconds, [7200, 10800]); // EET / EEST, depends on today
        // 16:00 UTC = 18:00 in Helsinki (EET, November)
        $this->assertSame(['2026-11-15T18:00', '2026-11-15T19:00'], array_keys($result->hourly));

        $first = $result->hourly['2026-11-15T18:00'];
        $this->assertSame(8.1, $first['temperature']);
        $this->assertSame(3.9, $first['feels_like']);
        $this->assertSame(18.0, $first['wind_speed']); // m/s -> km/h
        $this->assertSame(36.0, $first['wind_gusts']);
        $this->assertSame(226, $first['wind_direction']);
        $this->assertSame(0.4, $first['precipitation']);
        $this->assertSame(63, $first['weather_code']); // 32 rain -> WMO 63

        $second = $result->hourly['2026-11-15T19:00'];
        $this->assertNull($second['wind_gusts'], 'NaN is "no value", not 0');
        $this->assertSame(0, $second['weather_code']);
    }

    public function testFailsLikeEveryProviderWhenFmiDoesNotAnswer(): void
    {
        $this->http->on('https://opendata.fmi.fi/wfs', null);

        $this->expectException(ApiException::class);
        $this->provider->fetchGeneralForecast(60.17, 24.94);
    }

    public function testRefusesAnExceptionReportOrADoctype(): void
    {
        $this->http->on('https://opendata.fmi.fi/wfs', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "boom">]><ExceptionReport>&e;</ExceptionReport>');

        $this->expectException(ApiException::class);
        $this->provider->fetchGeneralForecast(60.17, 24.94);
    }
}
