<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\GeocodingService;
use Ytan\Service\Weather\WeatherRegion;
use Ytan\Service\Weather\WeatherRegionResolver;
use Ytan\Tests\Fixtures\FakeJsonHttpClient;

final class WeatherRegionResolverTest extends TestCase
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/reverse';

    private string $cacheDir;
    private FakeJsonHttpClient $httpClient;
    private WeatherRegionResolver $resolver;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ytan-region-test-' . uniqid();
        mkdir($this->cacheDir);
        $this->httpClient = new FakeJsonHttpClient();
        $this->resolver = new WeatherRegionResolver(new GeocodingService($this->cacheDir, $this->httpClient, 'en'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
    }

    public function testASwedishCountryCodeResolvesToSmhi(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => 'se']]);

        $region = $this->resolver->resolve(59.33, 18.07);

        $this->assertSame(WeatherRegion::PROVIDER_SMHI, $region->provider);
        $this->assertSame('SE', $region->regionCode);
    }

    public function testAGermanCountryCodeResolvesToDwdViaOpenMeteo(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => 'de']]);

        $region = $this->resolver->resolve(54.3, 10.15);

        $this->assertSame(WeatherRegion::PROVIDER_OPEN_METEO, $region->provider);
        $this->assertSame('DE', $region->regionCode);
        $this->assertSame('icon_seamless', $region->openMeteoModel);
        $this->assertSame('DWD', $region->sourceLabel);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}> countryCode, regionCode, model, source
     */
    public static function mappedCountryProvider(): array
    {
        return [
            'Germany' => ['de', 'DE', 'icon_seamless', 'DWD'],
            'Denmark' => ['dk', 'DK', 'dmi_seamless', 'DMI'],
            'Norway' => ['no', 'NO', 'metno_seamless', 'MET Norway'],
            'France' => ['fr', 'FR', 'meteofrance_seamless', 'Météo-France'],
            'Netherlands' => ['nl', 'NL', 'knmi_seamless', 'KNMI'],
        ];
    }

    /**
     * @dataProvider mappedCountryProvider
     */
    public function testEachMappedCountryResolvesToItsOwnOpenMeteoModel(string $countryCode, string $regionCode, string $model, string $source): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => $countryCode]]);

        $region = $this->resolver->resolve(52.5, 8.0);

        $this->assertSame(WeatherRegion::PROVIDER_OPEN_METEO, $region->provider);
        $this->assertSame($regionCode, $region->regionCode);
        $this->assertSame($model, $region->openMeteoModel);
        $this->assertSame($source, $region->sourceLabel);
    }

    public function testAnUnmappedCountryCodeResolvesToOpenMeteosOwnDefault(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => 'pl']]);

        $region = $this->resolver->resolve(52.2, 21.0);

        $this->assertSame(WeatherRegion::PROVIDER_OPEN_METEO, $region->provider);
        $this->assertSame('default', $region->regionCode);
        $this->assertNull($region->openMeteoModel);
        $this->assertNull($region->sourceLabel);
    }

    public function testNoCountryWithinTheBalticBoundingBoxResolvesToSmhi(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['error' => 'Unable to geocode']);

        $region = $this->resolver->resolve(56.0, 15.0);

        $this->assertSame(WeatherRegion::PROVIDER_SMHI, $region->provider);
        $this->assertSame('BALTIC-INTL', $region->regionCode);
    }

    public function testNoCountryOutsideTheBalticBoundingBoxResolvesToOpenMeteo(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['error' => 'Unable to geocode']);

        $region = $this->resolver->resolve(30.0, -40.0);

        $this->assertSame(WeatherRegion::PROVIDER_OPEN_METEO, $region->provider);
        $this->assertSame('default', $region->regionCode);
    }

    public function testBalticBoundingBoxIsInclusiveAtItsEdges(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['error' => 'Unable to geocode']);

        $region = $this->resolver->resolve(53.0, 9.0);

        $this->assertSame(WeatherRegion::PROVIDER_SMHI, $region->provider);
    }
}
