<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\GeometryService;
use Ytan\Service\WatchRoutePayload;

final class WatchRoutePayloadTest extends TestCase
{
    private function route(array $points, array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'name' => 'Schlei',
            'updated_at' => '2026-10-06 10:00:00',
            'points' => json_encode($points),
        ], $overrides);
    }

    public function testNoRouteYieldsEmptyPointListWithUnit(): void
    {
        $payload = WatchRoutePayload::build(null, 'nautical', '2026-10-06 10:00:00');

        $this->assertSame(['v' => 'none', 'n' => '', 'u' => 'n', 'p' => []], $payload);
    }

    public function testPointsAreFlattenedAsIntegersInHundredThousandthsOfADegree(): void
    {
        $payload = WatchRoutePayload::build($this->route([
            ['lat' => 54.123456, 'lng' => 10.654321],
            ['lat' => 54.2, 'lng' => 10.7],
        ]), 'metric', 'x');

        $this->assertSame([5412346, 1065432, 5420000, 1070000], $payload['p']);
        $this->assertSame('m', $payload['u']);
        $this->assertSame('Schlei', $payload['n']);
    }

    public function testVersionChangesWithRouteUpdateAndSelection(): void
    {
        $points = [['lat' => 54.0, 'lng' => 10.0], ['lat' => 54.1, 'lng' => 10.1]];
        $a = WatchRoutePayload::build($this->route($points), 'metric', 's1')['v'];
        $b = WatchRoutePayload::build($this->route($points, ['updated_at' => '2026-10-07 10:00:00']), 'metric', 's1')['v'];
        $c = WatchRoutePayload::build($this->route($points), 'metric', 's2')['v'];

        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function testLongRecordingIsSimplifiedBelowTheCap(): void
    {
        // ~3000 points zig-zagging by ~30 m - a dense GPS recording.
        $points = [];
        for ($i = 0; $i < 3000; $i++) {
            $points[] = ['lat' => 54.0 + $i * 0.0002, 'lng' => 10.0 + (($i % 2) ? 0.0004 : 0.0)];
        }

        $payload = WatchRoutePayload::build($this->route($points), 'metric', 'x');

        $this->assertLessThanOrEqual(WatchRoutePayload::MAX_POINTS * 2, count($payload['p']));
        // first and last vertex always survive
        $this->assertSame([5400000, 1000000], array_slice($payload['p'], 0, 2));
        $this->assertSame((int) round((54.0 + 2999 * 0.0002) * 100000), $payload['p'][count($payload['p']) - 2]);
    }

    public function testSimplifyKeepsCornersAndDropsCollinearPoints(): void
    {
        $line = [
            ['lat' => 54.0, 'lng' => 10.0],
            ['lat' => 54.0, 'lng' => 10.001],
            ['lat' => 54.0, 'lng' => 10.002],
            ['lat' => 54.01, 'lng' => 10.002],
        ];

        $this->assertSame([$line[0], $line[2], $line[3]], GeometryService::simplifyPolyline($line, 5.0));
    }
}
