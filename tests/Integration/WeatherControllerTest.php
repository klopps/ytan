<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Exception\ApiException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\WeatherController;
use Ytan\Service\WeatherService;
use Ytan\Tests\Fixtures\FakeJsonHttpClient;

/**
 * Covers WeatherController's validation and response shape. WeatherService
 * is wired to a FakeJsonHttpClient throughout - no test here ever makes
 * a real network call. No DB fixtures needed (WeatherService never touches
 * $this->pdo), but ControllerTestCase remains the right base for its
 * request()/response()/decode() helpers, per this app's existing
 * convention for controller-level tests.
 */
final class WeatherControllerTest extends ControllerTestCase
{
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    private const MARINE_URL = 'https://marine-api.open-meteo.com/v1/marine';

    private string $cacheDir;
    private FakeJsonHttpClient $httpClient;
    private WeatherController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir() . '/ytan-weather-controller-test-' . uniqid();
        mkdir($this->cacheDir);
        $this->httpClient = new FakeJsonHttpClient();
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

        $this->controller = new WeatherController(new WeatherService($this->cacheDir, $this->httpClient));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
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
