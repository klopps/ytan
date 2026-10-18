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
        return $override + ['enabled' => true, 'horizon_hours' => 72, 'run_interval_hours' => 6, 'gusts' => false, 'fine' => true];
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
        $ok = ['enabled' => true, 'horizon_hours' => 48, 'run_interval_hours' => 3, 'gusts' => false, 'fine' => true];

        return [
            'horizon not offered' => [['horizon_hours' => 36] + $ok, 'wind.settings_invalid_horizon'],
            'horizon missing' => [array_diff_key($ok, ['horizon_hours' => 1]), 'wind.settings_invalid_horizon'],
            'interval 4' => [['run_interval_hours' => 4] + $ok, 'wind.settings_invalid_interval'],
            'flag as string' => [['gusts' => 'yes'] + $ok, 'wind.settings_invalid_flag'],
            'flag missing' => [array_diff_key($ok, ['fine' => 1]), 'wind.settings_invalid_flag'],
        ];
    }

    public function testStatusIsNullBeforeTheFirstRun(): void
    {
        $this->assertNull(WindConfig::readStatus(sys_get_temp_dir() . '/ytan-wind-none-' . uniqid()));
    }
}
