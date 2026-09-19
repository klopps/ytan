<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Weather\WeatherForecastRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\WeatherController;
use Ytan\Service\GeocodingService;
use Ytan\Service\Weather\OpenMeteoWeatherProvider;
use Ytan\Service\Weather\SmhiWeatherProvider;
use Ytan\Service\Weather\WeatherRegionResolver;
use Ytan\Service\WeatherService;
use Ytan\Tests\Fixtures\FakeJsonHttpClient;

/**
 * Covers WeatherController's validation and response shape. The two
 * general-forecast providers and region-resolving GeocodingService are
 * wired to a FakeJsonHttpClient throughout - no test here ever makes a
 * real network call. WeatherForecastRepository IS backed by the real test
 * DB (this app's usual DB-touching-service convention, via
 * ControllerTestCase's inherited transaction-per-test TestCase base) -
 * unlike WeatherServiceTest's DB-free FakeWeatherForecastRepository, since
 * this suite is already an Integration test.
 */
final class WeatherControllerTest extends ControllerTestCase
{
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    private const MARINE_URL = 'https://marine-api.open-meteo.com/v1/marine';
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/reverse';

    private string $geocodingCacheDir;
    private FakeJsonHttpClient $httpClient;
    private WeatherController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geocodingCacheDir = sys_get_temp_dir() . '/ytan-weather-controller-test-' . uniqid();
        mkdir($this->geocodingCacheDir);
        $this->httpClient = new FakeJsonHttpClient();
        // Every coordinate this suite uses (54.30/10.15, 48.135/11.582) is
        // in Germany - still resolves through OpenMeteoWeatherProvider (now
        // with source "DWD"/model "icon_seamless" rather than plain
        // "Open-Meteo", see WeatherRegionResolver::COUNTRY_MODELS - not
        // asserted on by name here, this suite only cares about the
        // response shape/values, which this routing change doesn't affect).
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => 'de']]);
        $this->httpClient->setResponseFor(self::FORECAST_URL, [
            'hourly' => [
                'time' => ['2026-09-12T00:00'],
                'temperature_2m' => [15.7], 'wind_speed_10m' => [10.0], 'wind_direction_10m' => [193],
                'precipitation' => [0.0], 'weather_code' => [3],
            ],
        ]);
        $this->httpClient->setResponseFor(self::MARINE_URL, [
            'hourly' => [
                'time' => ['2026-09-12T00:00'],
                'wave_height' => [0.28], 'wave_direction' => [234], 'wave_period' => [2.05],
                'swell_wave_height' => [0.08], 'swell_wave_direction' => [284], 'swell_wave_period' => [2.10],
                'wind_wave_height' => [0.26], 'wind_wave_direction' => [225], 'wind_wave_period' => [1.85],
                'sea_surface_temperature' => [17.5],
            ],
        ]);

        $geocoding = new GeocodingService($this->geocodingCacheDir, $this->httpClient, 'en');
        $this->controller = new WeatherController(new WeatherService(
            new WeatherForecastRepository($this->pdo),
            new WeatherRegionResolver($geocoding),
            new OpenMeteoWeatherProvider($this->httpClient),
            new SmhiWeatherProvider($this->httpClient),
        ));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->geocodingCacheDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->geocodingCacheDir);
        parent::tearDown();
    }

    public function testShowReturnsAnHourlyTimelineForValidCoordinates(): void
    {
        $response = $this->controller->show(
            $this->request('GET', '/api/v1/weather', null, null, ['lat' => '54.30', 'lng' => '10.15']),
            $this->response()
        );

        $decoded = $this->decode($response);
        $this->assertSame(200, $decoded['status']);
        $this->assertTrue($decoded['data']['has_marine_data']);
        $this->assertSame(15.7, $decoded['data']['hourly'][0]['temperature']);
        $this->assertSame(0.28, $decoded['data']['hourly'][0]['wave_height']);
    }

    public function testShowRejectsAMissingLat(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->show(
            $this->request('GET', '/api/v1/weather', null, null, ['lng' => '10.15']),
            $this->response()
        );
    }

    public function testShowRejectsAMissingLng(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->show(
            $this->request('GET', '/api/v1/weather', null, null, ['lat' => '54.30']),
            $this->response()
        );
    }

    public function testShowRejectsANonNumericLat(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->show(
            $this->request('GET', '/api/v1/weather', null, null, ['lat' => 'abc', 'lng' => '10.15']),
            $this->response()
        );
    }

    public function testShowReturnsNoMarineDataForAnInlandStyleResponse(): void
    {
        $this->httpClient->setResponseFor(self::MARINE_URL, [
            'hourly' => [
                'time' => ['2026-09-12T00:00'],
                'wave_height' => [null], 'wave_direction' => [null], 'wave_period' => [null],
                'swell_wave_height' => [null], 'swell_wave_direction' => [null], 'swell_wave_period' => [null],
                'wind_wave_height' => [null], 'wind_wave_direction' => [null], 'wind_wave_period' => [null],
                'sea_surface_temperature' => [null],
            ],
        ]);

        $response = $this->controller->show(
            $this->request('GET', '/api/v1/weather', null, null, ['lat' => '48.135', 'lng' => '11.582']),
            $this->response()
        );

        $decoded = $this->decode($response);
        $this->assertSame(200, $decoded['status']);
        $this->assertFalse($decoded['data']['has_marine_data']);
        $this->assertSame(15.7, $decoded['data']['hourly'][0]['temperature']);
    }

    public function testShowPropagatesAnApiExceptionWhenTheGeneralForecastFails(): void
    {
        $this->httpClient->setResponseFor(self::FORECAST_URL, null);

        $this->expectException(ApiException::class);

        $this->controller->show(
            $this->request('GET', '/api/v1/weather', null, null, ['lat' => '54.30', 'lng' => '10.15']),
            $this->response()
        );
    }
}
