<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Wind\WindSampler;
use Ytan\Tests\RemovesDirectories;

/**
 * WindSampler: the wind job's blocks (storage/wind, written here as a tiny
 * fixture in the job's format) and the weather forecast as the fallback.
 */
final class WindSamplerTest extends TestCase
{
    use RemovesDirectories;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-wind-sampler-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    /** One byte of the job's u/v scale (-40..40 m/s on 0..254). */
    private static function uv(float $ms): string
    {
        return chr((int) round(($ms + 40.0) / 80.0 * 254.0));
    }

    /**
     * A run with two hours and one L2 block over 55 N / 10 E (block 74_56 at
     * 0.02 degrees = 2.56 degree blocks): hour 0 wind 4 m/s from the west,
     * hour 1 wind 8 m/s from the south.
     */
    private function writeRun(int $firstHour): void
    {
        $run = '202611270600';
        foreach ([[4.0, 0.0], [0.0, 8.0]] as $hour => [$u, $v]) {
            $path = sprintf('%s/runs/%s/L2/%03d', $this->dir, $run, $hour);
            mkdir($path, 0777, true);
            file_put_contents($path . '/74_56.bin.gz', gzencode(str_repeat(self::uv($u) . self::uv($v), 129 * 129)));
        }
        file_put_contents($this->dir . '/manifest.json', json_encode([
            'run' => $run,
            'hours' => [gmdate('Y-m-d\TH:i:s\Z', $firstHour), gmdate('Y-m-d\TH:i:s\Z', $firstHour + 3600)],
            'channels' => 2,
            'sources' => ['d2' => $run, 'eu' => $run],
            'levels' => ['L0' => [], 'L1' => ['res' => 0.0625, 'size' => 129, 'blocks' => []], 'L2' => ['res' => 0.02, 'size' => 129, 'blocks' => ['74_56']]],
        ]));
    }

    public function testTheJobsBlocksGiveTheWindPerHourInterpolatedBetweenHours(): void
    {
        $t = 1_800_000_000 - 1_800_000_000 % 3600;
        $this->writeRun($t);
        $field = (new WindSampler($this->dir))->field(54.9, 9.9, 55.1, 10.1, $t, $t + 3600);

        $this->assertNotNull($field);
        $this->assertSame('wind', $field->source);
        $this->assertSame('ICON-D2, ICON-EU', $field->model);
        $this->assertSame($t, $field->t0);
        $i = $field->nearestIndex(55.0, 10.0);
        $this->assertEqualsWithDelta(4.0, $field->u[0][$i], 0.2);
        $this->assertEqualsWithDelta(0.0, $field->v[0][$i], 0.2);
        $this->assertEqualsWithDelta(8.0, $field->v[1][$i], 0.2);
        // beyond the run's last hour the last one stands
        $this->assertEqualsWithDelta(8.0, $field->v[2][$i], 0.2);
        $this->assertSame(270, $field->summary(55.0, 10.0, $t, $t)['direction']);
    }

    public function testNoBlocksForTheTimeOrPlaceMeansTheForecast(): void
    {
        $t = 1_800_000_000 - 1_800_000_000 % 3600;
        $this->writeRun($t);
        $calls = 0;
        $forecast = function (float $lat, float $lng) use ($t, &$calls): array {
            $calls++;
            $hourly = [];
            for ($h = -2; $h < 30; $h++) {
                // 18 km/h = 5 m/s from the north, gusts 36 km/h
                $hourly[] = ['time' => gmdate('Y-m-d\TH:i', $t + $h * 3600), 'wind_speed' => 18.0, 'wind_direction' => 0.0, 'wind_gusts' => 36.0];
            }

            return ['utc_offset_seconds' => 0, 'source' => 'DWD', 'hourly' => $hourly];
        };
        $sampler = new WindSampler($this->dir, $forecast);

        // far away from the block (Biscay): the forecast at 3 x 3 points
        $field = $sampler->field(44.9, -4.1, 45.1, -3.9, $t, $t + 7200);
        $this->assertNotNull($field);
        $this->assertSame('forecast', $field->source);
        $this->assertSame('DWD', $field->model);
        $this->assertSame(9, $calls);
        $this->assertEqualsWithDelta(-5.0, $field->v[1][4], 0.01, 'from the north = towards south');
        $this->assertEqualsWithDelta(0.0, $field->u[1][4], 0.01);
        $this->assertSame(['direction' => 0, 'speed_ms' => 5.0, 'gust_ms' => 10.0], $field->summary(45.0, -4.0, $t, $t + 7200));

        // a day after the run: also the forecast
        $this->assertSame('forecast', $sampler->field(54.9, 9.9, 55.1, 10.1, $t + 86400, $t + 90000)->source);
    }

    public function testWithoutAnyWindDataThereIsNoField(): void
    {
        $this->assertNull((new WindSampler($this->dir))->field(54.9, 9.9, 55.1, 10.1, 1_800_000_000, 1_800_003_600));
        $this->assertNull((new WindSampler($this->dir, fn () => null))->field(54.9, 9.9, 55.1, 10.1, 1_800_000_000, 1_800_003_600));
    }
}
