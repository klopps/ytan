<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\ValidationException;
use Ytan\Service\WindConfig;

final class WindConfigTest extends TestCase
{
    private function valid(array $override = []): array
    {
        return $override + ['enabled' => true, 'horizon_hours' => 72, 'run_interval_hours' => 6, 'gusts' => false, 'fine' => true, 'world' => true, 'regions' => null];
    }

    public function testNormalizeAcceptsValidInput(): void
    {
        $this->assertSame($this->valid(), WindConfig::normalize($this->valid(['horizon_hours' => '72'])));
    }

    public function testDefaultsAreValid(): void
    {
        $this->assertSame(WindConfig::defaults(), WindConfig::normalize(WindConfig::defaults()));
    }

    /**
     * @dataProvider invalidInputs
     */
    public function testInvalidInputIsRejected(array $input, string $code): void
    {
        try {
            WindConfig::normalize($input);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    public static function invalidInputs(): array
    {
        $ok = ['enabled' => true, 'horizon_hours' => 48, 'run_interval_hours' => 3, 'gusts' => false, 'fine' => true, 'world' => true];

        return [
            'horizon not offered' => [['horizon_hours' => 36] + $ok, 'wind.settings_invalid_horizon'],
            'horizon missing' => [array_diff_key($ok, ['horizon_hours' => 1]), 'wind.settings_invalid_horizon'],
            'interval 4' => [['run_interval_hours' => 4] + $ok, 'wind.settings_invalid_interval'],
            'flag as string' => [['gusts' => 'yes'] + $ok, 'wind.settings_invalid_flag'],
            'flag missing' => [array_diff_key($ok, ['fine' => 1]), 'wind.settings_invalid_flag'],
            'world missing' => [array_diff_key($ok, ['world' => 1]), 'wind.settings_invalid_flag'],
            'regions empty' => [['regions' => []] + $ok, 'wind.settings_invalid_regions'],
            'regions not a list' => [['regions' => ['a' => self::rect()]] + $ok, 'wind.settings_invalid_regions'],
            'region south above north' => [['regions' => [['south' => 10, 'north' => 5] + self::rect()]] + $ok, 'wind.settings_invalid_regions'],
            'region west above east' => [['regions' => [['west' => 10, 'east' => 5] + self::rect()]] + $ok, 'wind.settings_invalid_regions'],
            'region beyond the pole' => [['regions' => [['north' => 91] + self::rect()]] + $ok, 'wind.settings_invalid_regions'],
            'region beyond the date line' => [['regions' => [['east' => 181] + self::rect()]] + $ok, 'wind.settings_invalid_regions'],
            'region side missing' => [['regions' => [['name' => 'x', 'south' => 1, 'west' => 2, 'north' => 3]]] + $ok, 'wind.settings_invalid_regions'],
            'eleven regions' => [['regions' => array_fill(0, 11, self::rect())] + $ok, 'wind.settings_invalid_regions'],
        ];
    }

    private static function rect(): array
    {
        return ['name' => 'au', 'south' => -35.0, 'west' => 150.0, 'north' => -33.0, 'east' => 152.0];
    }

    public function testRegionsAnywhereOnTheGlobeAreAccepted(): void
    {
        $in = $this->valid(['regions' => [['name' => ' Sydney ', 'south' => '-35', 'west' => 150, 'north' => -33, 'east' => 152.123456]]]);
        $this->assertSame(
            [['name' => 'Sydney', 'south' => -35.0, 'west' => 150.0, 'north' => -33.0, 'east' => 152.1235]],
            WindConfig::normalize($in)['regions']
        );
        $this->assertNull(WindConfig::normalize($this->valid(['regions' => null]))['regions']);
        $this->assertNull(WindConfig::normalize(array_diff_key($this->valid(), ['regions' => 1]))['regions']);
    }

    public function testStatusIsNullBeforeTheFirstRun(): void
    {
        $this->assertNull(WindConfig::readStatus(sys_get_temp_dir() . '/ytan-wind-none-' . uniqid()));
    }
}
