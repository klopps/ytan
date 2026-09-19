<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\ApiException;
use Ytan\Service\GeocodingService;
use Ytan\Service\Weather\OpenMeteoWeatherProvider;
use Ytan\Service\Weather\SmhiWeatherProvider;
use Ytan\Service\Weather\WeatherRegionResolver;
use Ytan\Service\WeatherService;
use Ytan\Tests\Fixtures\FakeJsonHttpClient;
use Ytan\Tests\Fixtures\FakeWeatherForecastRepository;

/**
 * Stays DB-free (WeatherForecastRepository's own SQL is covered by
 * tests/Integration/WeatherForecastRepositoryTest.php) via
 * FakeWeatherForecastRepository, and region routing is exercised through a
 * REAL WeatherRegionResolver/GeocodingService wired to the SAME
 * FakeJsonHttpClient as the weather providers - a test coordinate's region
 * is controlled by which Nominatim response the test configures, exactly
 * like a real request would resolve it. setUp()'s default Nominatim
 * response (country_code "pl" - deliberately NOT one of
 * WeatherRegionResolver::COUNTRY_MODELS's five countries, and not "se"
 * either) keeps every pre-existing test on Open-Meteo's own plain
 * best-match default unless a test deliberately overrides it (see the SMHI
 * routing tests further down, and the country->model routing tests right
 * after them).
 */
final class WeatherServiceTest extends TestCase
{
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    private const MARINE_URL = 'https://marine-api.open-meteo.com/v1/marine';
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/reverse';

    private string $geocodingCacheDir;
    private FakeJsonHttpClient $httpClient;
    private FakeWeatherForecastRepository $forecasts;
    private WeatherService $service;

    protected function setUp(): void
    {
        $this->geocodingCacheDir = sys_get_temp_dir() . '/ytan-geocoding-test-' . uniqid();
        mkdir($this->geocodingCacheDir);
        $this->httpClient = new FakeJsonHttpClient();
        $this->forecasts = new FakeWeatherForecastRepository();

        $geocoding = new GeocodingService($this->geocodingCacheDir, $this->httpClient, 'en');
        $this->service = new WeatherService(
            $this->forecasts,
            new WeatherRegionResolver($geocoding),
            new OpenMeteoWeatherProvider($this->httpClient),
            new SmhiWeatherProvider($this->httpClient),
        );

        // Every default test coordinate below is off the German Baltic
        // coast or inland Germany, but the default Nominatim fixture
        // deliberately reports an unrelated, unmapped country ("pl") rather
        // than "de" - these coordinates predate country->model routing and
        // exist purely to exercise the general merge/cache logic, not
        // routing itself (see the dedicated routing tests further down for
        // that, including a real "de" -> DWD case). Real-world mismatch
        // between the coordinate and the fake country doesn't matter here.
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => 'pl']]);

        $this->httpClient->setResponseFor(self::FORECAST_URL, [
            'utc_offset_seconds' => 7200,
            'hourly' => [
                'time' => ['2026-09-12T00:00', '2026-09-12T01:00'],
                'temperature_2m' => [15.7, 15.2],
                'apparent_temperature' => [14.9, 14.3],
                'wind_speed_10m' => [10.0, 9.5],
                'wind_gusts_10m' => [18.0, 16.5],
                'wind_direction_10m' => [193, 190],
                'precipitation' => [0.0, 0.0],
                'weather_code' => [3, 2],
            ],
            'daily' => [
                'time' => ['2026-09-12'],
                'sunrise' => ['2026-09-12T06:52'],
                'sunset' => ['2026-09-12T19:48'],
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
                'sea_level_height_msl' => [-0.21, -0.23],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->geocodingCacheDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->geocodingCacheDir);
    }

    public function testCombinesGeneralAndMarineIntoAnHourlyTimeline(): void
    {
        $result = $this->service->getForecast(54.30, 10.15);

        $this->assertSame(['lat' => 54.3, 'lng' => 10.15], $result['coordinates']);
        $this->assertSame('Open-Meteo', $result['source']);
        $this->assertNull($result['model']);
        $this->assertNull($result['generated_at']);
        $this->assertTrue($result['has_marine_data']);
        $this->assertSame(7200, $result['utc_offset_seconds']);
        $this->assertCount(2, $result['hourly']);

        $first = $result['hourly'][0];
        $this->assertSame('2026-09-12T00:00', $first['time']);
        $this->assertSame(15.7, $first['temperature']);
        $this->assertSame(14.9, $first['feels_like']);
        $this->assertSame(10.0, $first['wind_speed']);
        $this->assertSame(18.0, $first['wind_gusts']);
        $this->assertSame(193, $first['wind_direction']);
        $this->assertSame(0.28, $first['wave_height']);
        $this->assertSame(17.5, $first['sea_surface_temperature']);
        $this->assertSame(-0.21, $first['tide_height']);

        $this->assertCount(1, $result['daily']);
        $this->assertSame('2026-09-12', $result['daily'][0]['date']);
        $this->assertSame('2026-09-12T06:52', $result['daily'][0]['sunrise']);
        $this->assertSame('2026-09-12T19:48', $result['daily'][0]['sunset']);
    }

    public function testUtcOffsetSecondsDefaultsToZeroWhenTheResponseOmitsIt(): void
    {
        $this->httpClient->setResponseFor(self::FORECAST_URL, [
            'hourly' => [
                'time' => ['2026-09-12T00:00'],
                'temperature_2m' => [15.7], 'apparent_temperature' => [14.9],
                'wind_speed_10m' => [10.0], 'wind_gusts_10m' => [18.0], 'wind_direction_10m' => [193],
                'precipitation' => [0.0], 'weather_code' => [3],
            ],
        ]);

        $result = $this->service->getForecast(54.30, 10.15);

        $this->assertSame(0, $result['utc_offset_seconds']);
    }

    public function testDailyIsAnEmptyListWhenTheResponseHasNoDailyBlock(): void
    {
        $this->httpClient->setResponseFor(self::FORECAST_URL, [
            'hourly' => [
                'time' => ['2026-09-12T00:00'],
                'temperature_2m' => [15.7], 'apparent_temperature' => [14.9],
                'wind_speed_10m' => [10.0], 'wind_gusts_10m' => [18.0], 'wind_direction_10m' => [193],
                'precipitation' => [0.0], 'weather_code' => [3],
            ],
        ]);

        $result = $this->service->getForecast(54.30, 10.15);

        $this->assertSame([], $result['daily']);
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
        $this->assertNull($result['hourly'][0]['tide_height']);
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

        $this->forecasts->backdate(54.3, 10.15, 3600); // older than the 1800s TTL

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

    public function testASwedishCoordinateRoutesToSmhi(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => 'se']]);
        // fillHourlyGaps() (see WeatherService) always fetches Open-Meteo's
        // own forecast as the reference hour grid/filler for a non-Open-
        // Meteo region, even when the primary provider has no gaps to fill -
        // its key set is what WeatherService iterates to build the merged
        // result. Overridden here (setUp()'s own default fixture uses
        // unrelated 2026-09-12 dates) so it contains the SAME hour SMHI's
        // fixture below reports - proving SMHI's own value wins over
        // Open-Meteo's when both cover the same hour.
        $this->httpClient->setResponseFor(self::FORECAST_URL, [
            'utc_offset_seconds' => 7200,
            'hourly' => [
                'time' => ['2026-09-18T23:00'],
                'temperature_2m' => [11.0], 'apparent_temperature' => [10.0],
                'wind_speed_10m' => [15.0], 'wind_gusts_10m' => [25.0], 'wind_direction_10m' => [200],
                'precipitation' => [0.0], 'weather_code' => [3],
            ],
        ]);
        // Marine data still always comes from Open-Meteo (see WeatherService's
        // own doc comment) - keyed to match SMHI's one hourly entry above
        // (21:00 UTC -> 23:00 Europe/Stockholm, CEST) so the merge in
        // fetchAndCombine() actually finds it, proving that wiring still works
        // when a non-Open-Meteo provider serves the general forecast.
        $this->httpClient->setResponseFor(self::MARINE_URL, [
            'hourly' => [
                'time' => ['2026-09-18T23:00'],
                'wave_height' => [0.35], 'wave_direction' => [240], 'wave_period' => [2.2],
                'swell_wave_height' => [0.1], 'swell_wave_direction' => [280], 'swell_wave_period' => [2.3],
                'wind_wave_height' => [0.3], 'wind_wave_direction' => [230], 'wind_wave_period' => [1.9],
                'sea_surface_temperature' => [16.0], 'sea_level_height_msl' => [0.05],
            ],
        ]);
        $this->httpClient->setResponseFor(
            'https://opendata-download-metfcst.smhi.se/api/category/snow1g/version/1/geotype/point/lon/18.07/lat/59.33/data.json',
            [
                'referenceTime' => '2026-09-18T20:45:00Z',
                'timeSeries' => [
                    [
                        'time' => '2026-09-18T21:00:00Z',
                        'data' => [
                            'air_temperature' => 13.0,
                            'wind_from_direction' => 226,
                            'wind_speed' => 5.0, // m/s -> 18.0 km/h expected
                            'wind_speed_of_gust' => 10.0, // m/s -> 36.0 km/h expected
                            'precipitation_amount_mean' => 0.2,
                            'symbol_code' => 1, // Clear sky -> WMO 0
                        ],
                    ],
                ],
            ]
        );

        $result = $this->service->getForecast(59.33, 18.07);

        $this->assertSame('SMHI', $result['source']);
        $this->assertSame('snow1g', $result['model']);
        $this->assertSame('2026-09-18T20:45:00Z', $result['generated_at']);
        // fillHourlyGaps() (see WeatherService) synthesizes a full 168-hour
        // grid starting at this day's local midnight, so SMHI's one real
        // hour is no longer necessarily at index 0 - find it by its own
        // time key instead of assuming a position.
        $hour = $this->findHourlyEntry($result['hourly'], '2026-09-18T23:00');
        $this->assertNotNull($hour);
        $this->assertSame(13.0, $hour['temperature']);
        $this->assertSame(18.0, $hour['wind_speed']);
        $this->assertSame(36.0, $hour['wind_gusts']);
        $this->assertSame(0, $hour['weather_code']);
        $this->assertSame(0.35, $hour['wave_height']);
    }

    /**
     * @param list<array<string, mixed>> $hourly
     * @return array<string, mixed>|null
     */
    private function findHourlyEntry(array $hourly, string $time): ?array
    {
        foreach ($hourly as $entry) {
            if ($entry['time'] === $time) {
                return $entry;
            }
        }

        return null;
    }

    public function testAShortGapInSmhisOwnDataIsInterpolated(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => 'se']]);
        $this->httpClient->setResponseFor(self::MARINE_URL, null);
        $this->httpClient->setResponseFor(
            'https://opendata-download-metfcst.smhi.se/api/category/snow1g/version/1/geotype/point/lon/18.07/lat/59.33/data.json',
            [
                'referenceTime' => null,
                'timeSeries' => [
                    // 10:00/12:00 UTC -> 12:00/14:00 Europe/Stockholm (CEST) - a
                    // 2h gap, so the missing 13:00 must be interpolated from
                    // these two SMHI readings, not filled from Open-Meteo.
                    ['time' => '2026-09-18T10:00:00Z', 'data' => [
                        'air_temperature' => 10.0, 'wind_from_direction' => 350,
                        'wind_speed' => 5.0, 'wind_speed_of_gust' => 5.0,
                        'precipitation_amount_mean' => 0.0, 'symbol_code' => 1, // WMO 0
                    ]],
                    ['time' => '2026-09-18T12:00:00Z', 'data' => [
                        'air_temperature' => 14.0, 'wind_from_direction' => 10,
                        'wind_speed' => 5.0, 'wind_speed_of_gust' => 5.0,
                        'precipitation_amount_mean' => 2.0, 'symbol_code' => 6, // WMO 3
                    ]],
                ],
            ]
        );
        // Filler is still fetched unconditionally (see fillHourlyGaps()) but
        // must NOT be the source of the interpolated hour below - left with
        // an obviously-wrong sentinel temperature to prove that.
        $this->httpClient->setResponseFor(self::FORECAST_URL, [
            'utc_offset_seconds' => 7200,
            'hourly' => [
                'time' => ['2026-09-18T13:00'],
                'temperature_2m' => [999.0], 'apparent_temperature' => [999.0],
                'wind_speed_10m' => [999.0], 'wind_gusts_10m' => [999.0], 'wind_direction_10m' => [999],
                'precipitation' => [999.0], 'weather_code' => [99],
            ],
        ]);

        $result = $this->service->getForecast(59.33, 18.07);
        $hour = $this->findHourlyEntry($result['hourly'], '2026-09-18T13:00');

        $this->assertNotNull($hour);
        $this->assertSame(12.0, $hour['temperature']); // midpoint of 10.0 and 14.0
        $this->assertSame(1.0, $hour['precipitation']); // midpoint of 0.0 and 2.0
        $this->assertSame(0.0, $hour['wind_direction']); // shortest arc from 350 to 10, not through 180
        $this->assertSame(3, $hour['weather_code']); // exactly at the midpoint -> nearest-neighbor picks the later one
    }

    public function testAGapTooWideToInterpolateFallsBackToOpenMeteo(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => 'se']]);
        $this->httpClient->setResponseFor(self::MARINE_URL, null);
        $this->httpClient->setResponseFor(
            'https://opendata-download-metfcst.smhi.se/api/category/snow1g/version/1/geotype/point/lon/18.07/lat/59.33/data.json',
            [
                'referenceTime' => null,
                'timeSeries' => [
                    // 10:00/16:00 UTC -> 12:00/18:00 Europe/Stockholm - a 6h
                    // gap, past the 4h interpolation cap.
                    ['time' => '2026-09-18T10:00:00Z', 'data' => ['air_temperature' => 10.0, 'symbol_code' => 1]],
                    ['time' => '2026-09-18T16:00:00Z', 'data' => ['air_temperature' => 18.0, 'symbol_code' => 1]],
                ],
            ]
        );
        $this->httpClient->setResponseFor(self::FORECAST_URL, [
            'utc_offset_seconds' => 7200,
            'hourly' => [
                'time' => ['2026-09-18T15:00'],
                'temperature_2m' => [77.7], 'apparent_temperature' => [77.7],
                'wind_speed_10m' => [10.0], 'wind_gusts_10m' => [15.0], 'wind_direction_10m' => [90],
                'precipitation' => [0.5], 'weather_code' => [2],
            ],
        ]);

        $result = $this->service->getForecast(59.33, 18.07);
        $hour = $this->findHourlyEntry($result['hourly'], '2026-09-18T15:00');

        $this->assertNotNull($hour);
        // Open-Meteo's own value for that exact hour, NOT an interpolation
        // between SMHI's 12:00 (10.0) and 18:00 (18.0) readings (which would
        // have given 14.0) - the 6h gap is past the interpolation cap.
        $this->assertSame(77.7, $hour['temperature']);
    }

    public function testAGapNeitherProviderCanFillEndsUpAsNoData(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => 'se']]);
        $this->httpClient->setResponseFor(self::MARINE_URL, null);
        $this->httpClient->setResponseFor(
            'https://opendata-download-metfcst.smhi.se/api/category/snow1g/version/1/geotype/point/lon/18.07/lat/59.33/data.json',
            [
                'referenceTime' => null,
                'timeSeries' => [
                    ['time' => '2026-09-18T10:00:00Z', 'data' => ['air_temperature' => 10.0, 'symbol_code' => 1]],
                    ['time' => '2026-09-18T16:00:00Z', 'data' => ['air_temperature' => 18.0, 'symbol_code' => 1]],
                ],
            ]
        );
        // Open-Meteo (the filler) fails outright - nothing left to fill the
        // 6h gap's hours with.
        $this->httpClient->setResponseFor(self::FORECAST_URL, null);

        $result = $this->service->getForecast(59.33, 18.07);
        $hour = $this->findHourlyEntry($result['hourly'], '2026-09-18T15:00');

        $this->assertNotNull($hour);
        $this->assertNull($hour['temperature']);
        $this->assertNull($hour['wind_speed']);
        $this->assertNull($hour['weather_code']);
    }

    public function testAnOpenBalticWaterCoordinateWithNoResolvableCountryRoutesToSmhi(): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['error' => 'Unable to geocode']);
        $this->httpClient->setResponseFor(
            'https://opendata-download-metfcst.smhi.se/api/category/snow1g/version/1/geotype/point/lon/15/lat/56/data.json',
            [
                'referenceTime' => '2026-09-18T20:45:00Z',
                'timeSeries' => [
                    [
                        'time' => '2026-09-18T21:00:00Z',
                        'data' => [
                            'air_temperature' => 14.0,
                            'wind_from_direction' => 200,
                            'wind_speed' => 4.0,
                            'wind_speed_of_gust' => 8.0,
                            'precipitation_amount_mean' => 0.0,
                            'symbol_code' => 3,
                        ],
                    ],
                ],
            ]
        );

        $result = $this->service->getForecast(56.0, 15.0);

        $this->assertSame('SMHI', $result['source']);
    }

    public function testACoordinateOutsideTheBalticWithNoResolvableCountryStaysOnOpenMeteo(): void
    {
        // Mid-Atlantic - no country, and well outside the Baltic bounding box.
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['error' => 'Unable to geocode']);

        $result = $this->service->getForecast(30.0, -40.0);

        $this->assertSame('Open-Meteo', $result['source']);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> countryCode, source, openMeteoModel
     */
    public static function countryModelProvider(): array
    {
        return [
            'Germany -> DWD' => ['de', 'DWD', 'icon_seamless'],
            'Denmark -> DMI' => ['dk', 'DMI', 'dmi_seamless'],
            'Norway -> MET Norway' => ['no', 'MET Norway', 'metno_seamless'],
            'France -> Météo-France' => ['fr', 'Météo-France', 'meteofrance_seamless'],
            'Netherlands -> KNMI' => ['nl', 'KNMI', 'knmi_seamless'],
        ];
    }

    /**
     * @dataProvider countryModelProvider
     */
    public function testACountryWithAMappedOpenMeteoModelUsesItsOwnSourceAndModel(string $countryCode, string $expectedSource, string $expectedModel): void
    {
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => $countryCode]]);

        $result = $this->service->getForecast(52.5, 8.0);

        $this->assertSame($expectedSource, $result['source']);
        $this->assertSame($expectedModel, $result['model']);
        // Still genuinely Open-Meteo's own endpoint under the hood, just
        // with its `models=` query parameter set - not a second HTTP client.
        // requestedUrls[0] is the geocoding lookup (resolve() runs first),
        // so find the actual FORECAST_URL call rather than assuming a fixed
        // index.
        $forecastCallIndex = array_search(self::FORECAST_URL, $this->httpClient->requestedUrls, true);
        $this->assertNotFalse($forecastCallIndex);
        $this->assertSame($expectedModel, $this->httpClient->requestedQueries[$forecastCallIndex]['models'] ?? null);
    }

    public function testAMappedCountryDoesNotTriggerHourlyGapFilling(): void
    {
        // fillHourlyGaps() only ever runs for the SMHI provider (see its own
        // doc comment - Open-Meteo's own "_seamless" models are already
        // gap-free) - confirmed here by counting FORECAST_URL calls
        // specifically (not total callCount(), which also includes the
        // always-separate geocoding and marine requests): exactly one, not
        // the two a filler fetch would add.
        $this->httpClient->setResponseFor(self::NOMINATIM_URL, ['address' => ['country_code' => 'de']]);

        $this->service->getForecast(52.5, 8.0);

        $forecastCalls = array_filter($this->httpClient->requestedUrls, fn (string $url) => $url === self::FORECAST_URL);
        $this->assertCount(1, $forecastCalls);
    }
}
