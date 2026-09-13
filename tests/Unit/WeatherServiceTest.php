<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\ApiException;
use Ytan\Service\WeatherService;
use Ytan\Tests\Fixtures\FakeJsonHttpClient;

final class WeatherServiceTest extends TestCase
{
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    private const MARINE_URL = 'https://marine-api.open-meteo.com/v1/marine';

    private string $cacheDir;
    private FakeJsonHttpClient $httpClient;
    private WeatherService $service;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ytan-weather-test-' . uniqid();
        mkdir($this->cacheDir);
        $this->httpClient = new FakeJsonHttpClient();
        $this->service = new WeatherService($this->cacheDir, $this->httpClient);

        $this->httpClient->setResponseFor(self::FORECAST_URL, [
            'hourly' => [
                'time' => ['2026-09-12T00:00', '2026-09-12T01:00'],
                'temperature_2m' => [15.7, 15.2],
                'wind_speed_10m' => [10.0, 9.5],
                'wind_direction_10m' => [193, 190],
                'precipitation' => [0.0, 0.0],
                'weather_code' => [3, 2],
            ],
        ]);
        $this->httpClient->setResponseFor(self::MARINE_URL, [
            'hourly' => [
                'time' => ['2026-09-12T00:00', '2026-09-12T01:00'],
                'wave_height' => [0.28, 0.30],
                'wave_direction' => [234, 235],
                'wave_period' => [2.05, 2.10],
                'swell_wave_height' => [0.08, 0.09],
                'swell_wave_direction' => [284, 285],
                'swell_wave_period' => [2.10, 2.15],
                'wind_wave_height' => [0.26, 0.27],
                'wind_wave_direction' => [225, 226],
                'wind_wave_period' => [1.85, 1.90],
                'sea_surface_temperature' => [17.5, 17.4],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
    }

    public function testCombinesGeneralAndMarineIntoAnHourlyTimeline(): void
    {
        $result = $this->service->getForecast(54.30, 10.15);

        $this->assertSame(['lat' => 54.3, 'lng' => 10.15], $result['coordinates']);
        $this->assertTrue($result['has_marine_data']);
        $this->assertCount(2, $result['hourly']);

        $first = $result['hourly'][0];
        $this->assertSame('2026-09-12T00:00', $first['time']);
        $this->assertSame(15.7, $first['temperature']);
        $this->assertSame(10.0, $first['wind_speed']);
        $this->assertSame(193, $first['wind_direction']);
        $this->assertSame(0.28, $first['wave_height']);
        $this->assertSame(17.5, $first['sea_surface_temperature']);
    }

    public function testHasMarineDataIsFalseWhenEveryHourIsNullInland(): void
    {
        $this->httpClient->setResponseFor(self::MARINE_URL, [
            'hourly' => [
                'time' => ['2026-09-12T00:00', '2026-09-12T01:00'],
                'wave_height' => [null, null], 'wave_direction' => [null, null], 'wave_period' => [null, null],
                'swell_wave_height' => [null, null], 'swell_wave_direction' => [null, null], 'swell_wave_period' => [null, null],
                'wind_wave_height' => [null, null], 'wind_wave_direction' => [null, null], 'wind_wave_period' => [null, null],
                'sea_surface_temperature' => [null, null],
            ],
        ]);

        $result = $this->service->getForecast(48.135, 11.582);

        $this->assertFalse($result['has_marine_data']);
        $this->assertNull($result['hourly'][0]['wave_height']);
        $this->assertSame(15.7, $result['hourly'][0]['temperature']);
    }

    public function testHasMarineDataIsFalseWhenTheMarineRequestFailsButGeneralStillReturns(): void
    {
        $this->httpClient->setResponseFor(self::MARINE_URL, null);

        $result = $this->service->getForecast(54.30, 10.15);

        $this->assertFalse($result['has_marine_data']);
        $this->assertNull($result['hourly'][0]['wave_height']);
        $this->assertSame(15.7, $result['hourly'][0]['temperature']);
    }

    public function testThrowsWhenTheGeneralForecastRequestFails(): void
    {
        $this->httpClient->setResponseFor(self::FORECAST_URL, null);

        try {
            $this->service->getForecast(54.30, 10.15);
            $this->fail('Expected ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('weather.unavailable', $e->getErrorCode());
        }
    }

    public function testASecondCallForTheSameCoordinatesWithinTheTtlDoesNotRefetch(): void
    {
        $this->service->getForecast(54.30, 10.15);
        $callsAfterFirst = $this->httpClient->callCount();

        $this->service->getForecast(54.30, 10.15);

        $this->assertSame($callsAfterFirst, $this->httpClient->callCount());
    }

    public function testAnExpiredCacheEntryIsRefetched(): void
    {
        $this->service->getForecast(54.30, 10.15);
        $callsAfterFirst = $this->httpClient->callCount();

        $file = $this->cacheDir . '/weather_54.3_10.15.json';
        $this->assertFileExists($file);
        $decoded = json_decode((string) file_get_contents($file), true);
        $decoded['fetched_at'] = time() - 3600; // older than the 1800s TTL
        file_put_contents($file, json_encode($decoded));

        $this->service->getForecast(54.30, 10.15);

        $this->assertGreaterThan($callsAfterFirst, $this->httpClient->callCount());
    }

    public function testCoordinatesRoundToTheSameCacheEntryAtTwoDecimalPrecision(): void
    {
        $this->service->getForecast(54.301, 10.149);
        $callsAfterFirst = $this->httpClient->callCount();

        $this->service->getForecast(54.302, 10.151);

        $this->assertSame($callsAfterFirst, $this->httpClient->callCount());
    }

    public function testCoordinatesFurtherApartResultInASeparateFetch(): void
    {
        $this->service->getForecast(54.30, 10.15);
        $callsAfterFirst = $this->httpClient->callCount();

        $this->service->getForecast(54.32, 10.15);

        $this->assertGreaterThan($callsAfterFirst, $this->httpClient->callCount());
    }
}
