<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\ApiException;
use Ytan\Service\Weather\SmhiWeatherProvider;
use Ytan\Tests\Fixtures\FakeJsonHttpClient;

final class SmhiWeatherProviderTest extends TestCase
{
    private const URL = 'https://opendata-download-metfcst.smhi.se/api/category/snow1g/version/1/geotype/point/lon/18.07/lat/59.33/data.json';

    private FakeJsonHttpClient $httpClient;
    private SmhiWeatherProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new FakeJsonHttpClient();
        $this->provider = new SmhiWeatherProvider($this->httpClient);
    }

    public function testMapsAnHourlyEntryIntoTheAppsOwnFieldShape(): void
    {
        $this->httpClient->setResponseFor(self::URL, [
            'referenceTime' => '2026-09-18T20:45:00Z',
            'timeSeries' => [
                [
                    'time' => '2026-09-18T21:00:00Z',
                    'data' => [
                        'air_temperature' => 13.0,
                        'wind_from_direction' => 226,
                        'wind_speed' => 5.0,
                        'wind_speed_of_gust' => 10.2,
                        'precipitation_amount_mean' => 0.4,
                        'symbol_code' => 1,
                    ],
                ],
            ],
        ]);

        $result = $this->provider->fetchGeneralForecast(59.33, 18.07);

        $this->assertSame('SMHI', $result->source);
        $this->assertSame('snow1g', $result->model);
        $this->assertSame('2026-09-18T20:45:00Z', $result->generatedAt);
        $this->assertSame([], $result->daily);

        $entry = array_values($result->hourly)[0];
        $this->assertSame(13.0, $entry['temperature']);
        $this->assertNull($entry['feels_like']);
        $this->assertSame(226, $entry['wind_direction']);
        $this->assertSame(0.4, $entry['precipitation']);
        $this->assertSame(0, $entry['weather_code']); // symbol_code 1 (Clear sky) -> WMO 0
    }

    public function testConvertsWindSpeedsFromMetersPerSecondToKilometersPerHour(): void
    {
        $this->httpClient->setResponseFor(self::URL, [
            'referenceTime' => null,
            'timeSeries' => [
                ['time' => '2026-09-18T21:00:00Z', 'data' => ['wind_speed' => 10.0, 'wind_speed_of_gust' => 20.0]],
            ],
        ]);

        $result = $this->provider->fetchGeneralForecast(59.33, 18.07);

        $entry = array_values($result->hourly)[0];
        $this->assertSame(36.0, $entry['wind_speed']);
        $this->assertSame(72.0, $entry['wind_gusts']);
    }

    public function testConvertsUtcTimeToLocationLocalNaiveTimeString(): void
    {
        // 21:00 UTC in September is 23:00 in Europe/Stockholm (CEST, +2).
        $this->httpClient->setResponseFor(self::URL, [
            'referenceTime' => null,
            'timeSeries' => [
                ['time' => '2026-09-18T21:00:00Z', 'data' => []],
            ],
        ]);

        $result = $this->provider->fetchGeneralForecast(59.33, 18.07);

        $this->assertSame(['2026-09-18T23:00'], array_keys($result->hourly));
    }

    public function testGeneratedAtIsNullWhenTheResponseOmitsReferenceTime(): void
    {
        $this->httpClient->setResponseFor(self::URL, [
            'timeSeries' => [['time' => '2026-09-18T21:00:00Z', 'data' => []]],
        ]);

        $result = $this->provider->fetchGeneralForecast(59.33, 18.07);

        $this->assertNull($result->generatedAt);
    }

    public function testAnUnmappedSymbolCodeResultsInANullWeatherCode(): void
    {
        $this->httpClient->setResponseFor(self::URL, [
            'timeSeries' => [['time' => '2026-09-18T21:00:00Z', 'data' => ['symbol_code' => 999]]],
        ]);

        $result = $this->provider->fetchGeneralForecast(59.33, 18.07);

        $this->assertNull(array_values($result->hourly)[0]['weather_code']);
    }

    public function testThrowsWhenTheRequestFails(): void
    {
        $this->httpClient->setResponseFor(self::URL, null);

        try {
            $this->provider->fetchGeneralForecast(59.33, 18.07);
            $this->fail('Expected ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('weather.unavailable', $e->getErrorCode());
        }
    }

    public function testThrowsWhenTheResponseHasNoTimeSeries(): void
    {
        $this->httpClient->setResponseFor(self::URL, ['referenceTime' => '2026-09-18T20:45:00Z', 'timeSeries' => []]);

        $this->expectException(ApiException::class);
        $this->provider->fetchGeneralForecast(59.33, 18.07);
    }
}
