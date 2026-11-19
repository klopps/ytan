<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Tide\IfremerMarsTideSource;
use Ytan\Service\Tide\TideCache;
use Ytan\Tests\Fixtures\FakeTideHttp;

final class IfremerMarsTideSourceTest extends TestCase
{
    private const EPOCH_1900 = 2208988800;
    private string $dir;
    private FakeTideHttp $http;
    private int $now;
    /** first hour of the fake series (unix) */
    private int $start;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-ifremer-' . uniqid();
        $this->http = new FakeTideHttp();
        $this->start = gmmktime(0, 0, 0, 11, 18, 2026);
        $this->now = $this->start + 30 * 3600;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function source(): IfremerMarsTideSource
    {
        return new IfremerMarsTideSource(new TideCache($this->dir), $this->http, fn () => $this->now);
    }

    /**
     * A 5x5 block, 48 hours of a 12.42 h tide of +-2 m; $dry = [j, i] cells that are land.
     *
     * @param list<array{0: int, 1: int}> $dry
     */
    private function ascii(array $dry): string
    {
        $hours = 48;
        $lines = ['Dataset {', '} MARC;', '---------------------------------------------', 'XE.XE[48][5][5]'];
        for ($t = 0; $t < $hours; $t++) {
            $raw = (int) round(2.0 * cos(2 * M_PI * ($t * 3600) / (12.42 * 3600)) / 0.0015259255);
            for ($j = 0; $j < 5; $j++) {
                $row = [];
                for ($i = 0; $i < 5; $i++) {
                    $row[] = in_array([$j, $i], $dry, true) ? -32768 : $raw;
                }
                $lines[] = "[$t][$j], " . implode(', ', $row);
            }
        }
        $lines[] = '';
        $lines[] = 'XE.time[48]';
        $lines[] = implode(', ', array_map(fn ($t) => sprintf('%.7E', $this->start + $t * 3600 + self::EPOCH_1900), range(0, $hours - 1)));

        return implode("\n", $lines);
    }

    private function serve(array $dry = []): void
    {
        $this->http->on(IfremerMarsTideSource::DATASET_URL . '.dds', "Dataset {\n    Float64 time[time = 183123];\n}");
        $this->http->on(IfremerMarsTideSource::DATASET_URL . '.ascii', $this->ascii($dry));
    }

    public function testCellOfAPositionAndOutsideTheGrid(): void
    {
        $this->assertSame(['j' => 809, 'i' => 135], IfremerMarsTideSource::cell(48.38, -4.49));
        $this->assertNull(IfremerMarsTideSource::cell(54.3, 10.1), 'Baltic');
        $this->assertNull(IfremerMarsTideSource::cell(43.0, -3.0), 'south of the grid');
    }

    public function testHighAndLowWaterFromTheNearestWetCell(): void
    {
        // centre cell and its row dry: the nearest wet one is one row away
        $this->serve([[2, 0], [2, 1], [2, 2], [2, 3], [2, 4]]);

        $tide = $this->source()->tide(48.38, -4.49, null, $this->start, $this->start + 48 * 3600, 30000);

        $this->assertSame('ifremer', $tide['source']);
        $this->assertSame(IfremerMarsTideSource::ATTRIBUTION, $tide['attribution']);
        $this->assertEqualsWithDelta(48.38 + 0.0063, $tide['lat'], 0.008);
        $this->assertGreaterThan(300, $tide['distance_m']);
        $this->assertEqualsWithDelta(4.0, $tide['mean_range_m'], 0.1);
        $this->assertSame('NW', $tide['events'][0]['type']);
        // low water half a period after the start
        $this->assertEqualsWithDelta($this->start + 6.21 * 3600, $tide['events'][0]['time'], 15 * 60);
        $url = $this->http->requests[1]['url'];
        $this->assertStringContainsString(rawurlencode('XE[182972:1:183122][807:1:811][133:1:137]'), $url);
    }

    public function testLandOnlyOrTooFarGivesNothing(): void
    {
        $all = [];
        for ($j = 0; $j < 5; $j++) {
            for ($i = 0; $i < 5; $i++) {
                $all[] = [$j, $i];
            }
        }
        $this->serve($all);
        $this->assertNull($this->source()->tide(48.38, -4.49, null, $this->start, $this->start + 86400, 30000));

        $this->serve();
        $this->assertNull($this->source()->tide(48.10, -4.49, null, $this->start, $this->start + 86400, 100), 'nearest wet cell beyond the station distance');
    }

    public function testTheSeriesIsCachedAndAFailureRemembered(): void
    {
        $this->serve();
        $this->source()->tide(48.38, -4.49, null, $this->start, $this->start + 86400, 30000);
        $this->source()->tide(48.38, -4.49, null, $this->start, $this->start + 86400, 30000);
        $this->assertCount(2, $this->http->requests, 'one DDS + one data request');

        $this->http->on(IfremerMarsTideSource::DATASET_URL . '.ascii', null);
        $this->assertNull(@$this->source()->tide(48.70, -4.00, null, $this->start, $this->start + 86400, 30000));
        $this->assertNull($this->source()->tide(48.70, -4.00, null, $this->start, $this->start + 86400, 30000));
        $this->assertCount(3, $this->http->requests, 'the failed cell is not asked again at once');
    }

    public function testParseAsciiRejectsAnIncompleteAnswer(): void
    {
        $this->assertNull(IfremerMarsTideSource::parseAscii("Error {\n code = 400;\n}"));
        $this->assertNull(IfremerMarsTideSource::parseAscii("[0][0], 1, 2\n[1][0], 3, 4\n\nXE.time[3]\n1.0, 2.0, 3.0"));
    }
}
