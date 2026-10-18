<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\ValidationException;
use Ytan\Service\RadarConfig;

final class RadarConfigTest extends TestCase
{
    private function valid(array $override = []): array
    {
        return $override + [
            'history_minutes' => 30, 'forecast_minutes' => 60, 'fetch_interval_minutes' => 10, 'workers' => 6,
            'max_mosaic_pixels' => 120_000_000, 'presets' => ['se', 'de'], 'custom' => [],
        ];
    }

    public function testNormalizeKeepsPresetOrderAndRoundsCustomRegions(): void
    {
        $config = RadarConfig::normalize($this->valid([
            'custom' => [['name' => '  Kieler Förde  ', 'south' => '54.3', 'west' => 10.1, 'north' => 54.6123456, 'east' => 10.4]],
        ]));

        $this->assertSame(30, $config['history_minutes']);
        $this->assertSame(60, $config['forecast_minutes']);
        $this->assertSame(10, $config['fetch_interval_minutes']);
        $this->assertSame(6, $config['workers']);
        $this->assertSame(['de', 'se'], $config['presets']);
        $this->assertSame(['name' => 'Kieler Förde', 'south' => 54.3, 'west' => 10.1, 'north' => 54.6123, 'east' => 10.4], $config['custom'][0]);
    }

    /**
     * @dataProvider invalidInputs
     */
    public function testInvalidInputIsRejected(array $input, string $code): void
    {
        try {
            RadarConfig::normalize($input);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    public static function invalidInputs(): array
    {
        $base = ['history_minutes' => 60, 'forecast_minutes' => 60, 'fetch_interval_minutes' => 5, 'workers' => 4,
            'max_mosaic_pixels' => 120_000_000, 'presets' => ['de'], 'custom' => []];
        $rect = ['name' => 'x', 'south' => 54.0, 'west' => 10.0, 'north' => 55.0, 'east' => 11.0];

        return [
            'forecast not a multiple of 5' => [['forecast_minutes' => 62] + $base, 'radar.settings_invalid_forecast'],
            'forecast too long' => [['forecast_minutes' => 125] + $base, 'radar.settings_invalid_forecast'],
            'forecast negative' => [['forecast_minutes' => -5] + $base, 'radar.settings_invalid_forecast'],
            'history zero' => [['history_minutes' => 0] + $base, 'radar.settings_invalid_history'],
            'history too long' => [['history_minutes' => 65] + $base, 'radar.settings_invalid_history'],
            'history not a multiple of 5' => [['history_minutes' => 12] + $base, 'radar.settings_invalid_history'],
            'interval 20' => [['fetch_interval_minutes' => 20] + $base, 'radar.settings_invalid_interval'],
            'interval 7' => [['fetch_interval_minutes' => 7] + $base, 'radar.settings_invalid_interval'],
            'workers 0' => [['workers' => 0] + $base, 'radar.settings_invalid_workers'],
            'workers 13' => [['workers' => 13] + $base, 'radar.settings_invalid_workers'],
            'limit below 50 M' => [['max_mosaic_pixels' => 49_999_999] + $base, 'radar.settings_invalid_max_mosaic'],
            'limit above 500 M' => [['max_mosaic_pixels' => 500_000_001] + $base, 'radar.settings_invalid_max_mosaic'],
            'limit missing' => [array_diff_key($base, ['max_mosaic_pixels' => 1]), 'radar.settings_invalid_max_mosaic'],
            'history missing' => [array_diff_key($base, ['history_minutes' => 1]), 'radar.settings_invalid_history'],
            'unknown preset' => [['presets' => ['xx']] + $base, 'radar.settings_unknown_region'],
            'no region at all' => [['presets' => []] + $base, 'radar.settings_no_region'],
            'south above north' => [['custom' => [['south' => 56.0] + $rect]] + $base, 'radar.settings_invalid_region'],
            'outside the coverage' => [['custom' => [['south' => 10.0, 'north' => 12.0] + $rect]] + $base, 'radar.settings_invalid_region'],
            'missing side' => [['custom' => [['name' => 'x', 'south' => 1, 'west' => 2, 'north' => 3]]] + $base, 'radar.settings_invalid_region'],
            'too many own regions' => [['custom' => array_fill(0, 11, $rect)] + $base, 'radar.settings_too_many_regions'],
            'all presets together too large' => [['presets' => array_keys(RadarConfig::PRESETS)] + $base, 'radar.settings_too_large'],
        ];
    }

    public function testEveryCountryCanBeChosenOnItsOwn(): void
    {
        $this->assertGreaterThanOrEqual(45, count(RadarConfig::PRESETS));
        foreach (array_keys(RadarConfig::PRESETS) as $code) {
            $this->assertSame([$code], RadarConfig::normalize($this->valid(['presets' => [$code]]))['presets'], $code);
        }
    }

    public function testTheConfiguredLimitDecidesWhatIsTooLarge(): void
    {
        // ES + DE + SE: about 65 M pixels
        $regions = ['presets' => ['es', 'de', 'se']];
        $box = RadarConfig::enclosing(RadarConfig::regions(['presets' => $regions['presets'], 'custom' => []]));
        $pixels = RadarConfig::mosaicPixels($box);
        $this->assertGreaterThan(60_000_000, $pixels);
        $this->assertLessThan(70_000_000, $pixels);
        try {
            RadarConfig::normalize($this->valid($regions + ['max_mosaic_pixels' => 60_000_000]));
            $this->fail('expected too large');
        } catch (ValidationException $e) {
            $this->assertSame('radar.settings_too_large', $e->getErrorCode());
        }
        $ok = RadarConfig::normalize($this->valid($regions + ['max_mosaic_pixels' => 70_000_000]));
        $this->assertSame(70_000_000, $ok['max_mosaic_pixels']);
    }

    public function testIsPaused(): void
    {
        $now = strtotime('2026-10-18T15:00:00Z');
        $this->assertTrue(RadarConfig::isPaused(['paused_until' => '2026-10-18T15:30:00Z', 'paused_forever' => false], $now));
        $this->assertFalse(RadarConfig::isPaused(['paused_until' => '2026-10-18T14:59:00Z', 'paused_forever' => false], $now));
        $this->assertTrue(RadarConfig::isPaused(['paused_until' => null, 'paused_forever' => true], $now));
        $this->assertFalse(RadarConfig::isPaused(['paused_until' => null, 'paused_forever' => false], $now));
    }

    public function testForecastZeroIsAllowed(): void
    {
        $this->assertSame(0, RadarConfig::normalize($this->valid(['forecast_minutes' => 0]))['forecast_minutes']);
    }

    public function testDefaultsAsRequested(): void
    {
        $d = RadarConfig::defaults();
        $this->assertSame([60, 60, 5, 4], [$d['history_minutes'], $d['forecast_minutes'], $d['fetch_interval_minutes'], $d['workers']]);
        $this->assertSame(120_000_000, $d['max_mosaic_pixels']); // the former fixed constant
        // the defaults are valid input (the pause is not part of the settings form)
        $this->assertSame(array_diff_key($d, ['paused_until' => 1, 'paused_forever' => 1]), RadarConfig::normalize($d));
        $this->assertFalse(RadarConfig::isPaused($d));
    }

    public function testDefaultsAreTheOriginalAreaAndFitTheLimit(): void
    {
        $regions = RadarConfig::regions(RadarConfig::defaults());
        $box = RadarConfig::enclosing($regions);
        $this->assertSame(['de', 'dk', 'se'], array_column($regions, 'name'));
        $this->assertEqualsWithDelta(47.2, $box['south'], 1e-9);
        $this->assertEqualsWithDelta(24.2, $box['east'], 1e-9);
        // the original fixed area 47-70 N / 5-25 E measured ~30 M pixels
        $this->assertGreaterThan(20_000_000, RadarConfig::mosaicPixels($box));
        $this->assertLessThan(RadarConfig::MAX_MOSAIC_PIXELS_MIN, RadarConfig::mosaicPixels($box));
    }

    public function testReadStatusReturnsTheJobReportOrNull(): void
    {
        $dir = sys_get_temp_dir() . '/ytan-radar-status-' . uniqid();
        mkdir($dir);
        try {
            $this->assertNull(RadarConfig::readStatus($dir));
            file_put_contents($dir . '/status.json', json_encode(['last_run' => ['outcome' => 'processed'], 'storage_bytes' => 123]));
            $this->assertSame(123, RadarConfig::readStatus($dir)['storage_bytes']);
            file_put_contents($dir . '/status.json', '{broken');
            $this->assertNull(RadarConfig::readStatus($dir));
        } finally {
            @unlink($dir . '/status.json');
            @rmdir($dir);
        }
    }

    public function testWriteJobConfigWritesRegionsAsRectangles(): void
    {
        $dir = sys_get_temp_dir() . '/ytan-radar-config-' . uniqid();
        try {
            RadarConfig::writeJobConfig($dir, RadarConfig::normalize($this->valid([
                'presets' => ['dk'],
                'custom' => [['name' => 'Ostsee', 'south' => 54.0, 'west' => 13.0, 'north' => 56.0, 'east' => 16.0]],
            ])));
            $json = json_decode((string) file_get_contents($dir . '/config.json'), true);
            $this->assertSame(60, $json['forecast_minutes']);
            $this->assertSame([30, 10, 6], [$json['history_minutes'], $json['fetch_interval_minutes'], $json['workers']]);
            $this->assertSame(['dk', 'Ostsee'], array_column($json['regions'], 'name'));
            $this->assertEquals(RadarConfig::PRESETS['dk']['north'], $json['regions'][0]['north']);
            $this->assertSame([], glob($dir . '/*.tmp'));
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }
}
