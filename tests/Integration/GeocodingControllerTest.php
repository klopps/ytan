<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\GeocodingController;
use Ytan\Service\GeocodingService;
use Ytan\Tests\Fixtures\FakeJsonHttpClient;

/**
 * Covers GeocodingController's validation and response shape.
 * GeocodingService is wired to a FakeJsonHttpClient throughout - no test
 * here ever makes a real network call to Nominatim.
 */
final class GeocodingControllerTest extends ControllerTestCase
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/reverse';

    private string $cacheDir;
    private FakeJsonHttpClient $httpClient;
    private GeocodingController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir() . '/ytan-geocoding-controller-test-' . uniqid();
        mkdir($this->cacheDir);
        $this->httpClient = new FakeJsonHttpClient();

        $this->controller = new GeocodingController(new GeocodingService($this->cacheDir, $this->httpClient, 'en'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
        parent::tearDown();
    }

    public function testReverseReturnsThePlaceNameForValidCoordinates(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['name' => 'Lyø By']);

        $response = $this->controller->reverse(
            $this->request('GET', '/api/v1/geocode/reverse', null, null, ['lat' => '55.043', 'lng' => '10.152']),
            $this->response()
        );

        $decoded = $this->decode($response);
        $this->assertSame(200, $decoded['status']);
        $this->assertSame('Lyø By', $decoded['data']['place_name']);
    }

    public function testReverseReturnsANullPlaceNameWhenNominatimHasNoResult(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['error' => 'Unable to geocode']);

        $response = $this->controller->reverse(
            $this->request('GET', '/api/v1/geocode/reverse', null, null, ['lat' => '0', 'lng' => '0']),
            $this->response()
        );

        $decoded = $this->decode($response);
        $this->assertSame(200, $decoded['status']);
        $this->assertNull($decoded['data']['place_name']);
    }

    public function testReverseRejectsAMissingLat(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->reverse(
            $this->request('GET', '/api/v1/geocode/reverse', null, null, ['lng' => '10.15']),
            $this->response()
        );
    }

    public function testReverseRejectsAMissingLng(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->reverse(
            $this->request('GET', '/api/v1/geocode/reverse', null, null, ['lat' => '54.30']),
            $this->response()
        );
    }

    public function testReverseRejectsANonNumericLat(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->reverse(
            $this->request('GET', '/api/v1/geocode/reverse', null, null, ['lat' => 'abc', 'lng' => '10.15']),
            $this->response()
        );
    }
}
