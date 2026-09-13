<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\GeocodingService;
use Ytan\Tests\Fixtures\FakeJsonHttpClient;

final class GeocodingServiceTest extends TestCase
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/reverse';

    private string $cacheDir;
    private FakeJsonHttpClient $httpClient;
    private GeocodingService $service;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ytan-geocoding-test-' . uniqid();
        mkdir($this->cacheDir);
        $this->httpClient = new FakeJsonHttpClient();
        $this->service = new GeocodingService($this->cacheDir, $this->httpClient, 'de');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
    }

    public function testReturnsTheNameFieldFromANominatimResponse(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, [
            'name' => 'Lyø By',
            'display_name' => 'Lyø By, Faaborg-Midtfyn Kommune, Region Süddänemark, 5601, Dänemark',
        ]);

        $this->assertSame('Lyø By', $this->service->reverseGeocode(55.0430497, 10.1515072));
    }

    public function testFallsBackToDisplayNameWhenNameIsMissing(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, [
            'display_name' => 'Dänemark',
        ]);

        $this->assertSame('Dänemark', $this->service->reverseGeocode(56.05, 10.55));
    }

    public function testReturnsNullWhenNominatimReportsAnError(): void
    {
        // Confirmed live: Nominatim returns HTTP 200 with an "error" field
        // for a coordinate it can't resolve at all, not a non-200 status.
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, [
            'error' => 'Unable to geocode',
        ]);

        $this->assertNull($this->service->reverseGeocode(0.0, 0.0));
    }

    public function testReturnsNullWhenTheRequestFails(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, null);

        $this->assertNull($this->service->reverseGeocode(56.05, 10.55));
    }

    public function testASecondCallForTheSameCoordinatesWithinTheTtlDoesNotRefetch(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['name' => 'Lyø By']);

        $this->service->reverseGeocode(55.043, 10.152);
        $this->service->reverseGeocode(55.043, 10.152);

        $this->assertSame(1, $this->httpClient->callCount());
    }

    public function testANullResultIsAlsoCachedAndDoesNotRefetch(): void
    {
        // A cached "no place name here" (open ocean) must be a cache HIT,
        // not indistinguishable from "no cache file at all" - otherwise
        // every repeat lookup of an at-sea point would hit Nominatim again.
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['error' => 'Unable to geocode']);

        $first = $this->service->reverseGeocode(0.0, 0.0);
        $second = $this->service->reverseGeocode(0.0, 0.0);

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $this->httpClient->callCount());
    }

    public function testAnExpiredCacheEntryIsRefetched(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['name' => 'Lyø By']);
        $this->service->reverseGeocode(55.043, 10.152);

        $files = glob($this->cacheDir . '/*');
        $this->assertCount(1, $files);
        $decoded = json_decode((string) file_get_contents($files[0]), true);
        $decoded['fetched_at'] = time() - (31 * 24 * 3600);
        file_put_contents($files[0], json_encode($decoded));

        $this->service->reverseGeocode(55.043, 10.152);

        $this->assertSame(2, $this->httpClient->callCount());
    }

    public function testCoordinatesRoundToTheSameCacheEntryAtThreeDecimalPrecision(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['name' => 'Lyø By']);

        $this->service->reverseGeocode(55.0431, 10.1522);
        $this->service->reverseGeocode(55.0432, 10.1519);

        $this->assertSame(1, $this->httpClient->callCount());
    }

    public function testCoordinatesFurtherApartResultInASeparateFetch(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['name' => 'Lyø By']);

        $this->service->reverseGeocode(55.043, 10.152);
        $this->service->reverseGeocode(55.100, 10.152);

        $this->assertSame(2, $this->httpClient->callCount());
    }

    public function testPassesTheConstructorLocaleAsAcceptLanguage(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['name' => 'Lyø By']);

        $this->service->reverseGeocode(55.043, 10.152);

        $this->assertSame('de', $this->httpClient->requestedQueries[0]['accept-language']);
    }
}
