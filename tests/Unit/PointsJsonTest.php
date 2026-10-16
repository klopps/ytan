<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\PointsJson;

final class PointsJsonTest extends TestCase
{
    public function testRoundsLatLngToSixDecimals(): void
    {
        $json = '[{"lat":54.99077682394697,"lng":10.333843231201172},{"lat":54.99131400775246,"lng":10.323356296867132}]';

        $this->assertSame(
            '[{"lat":54.990777,"lng":10.333843},{"lat":54.991314,"lng":10.323356}]',
            PointsJson::round($json)
        );
    }

    public function testKeepsOtherKeysAndNestedArrays(): void
    {
        $json = '[[{"lat":1.123456789,"lng":-2.987654321,"time":"x"}]]';

        $this->assertSame('[[{"lat":1.123457,"lng":-2.987654,"time":"x"}]]', PointsJson::round($json));
    }

    public function testLeavesShortCoordinatesAndNonArraysUnchanged(): void
    {
        $this->assertSame('[{"lat":54.5,"lng":10}]', PointsJson::round('[{"lat":54.5,"lng":10}]'));
        $this->assertSame('[]', PointsJson::round('[]'));
        $this->assertNull(PointsJson::round(null));
        $this->assertSame('', PointsJson::round(''));
        $this->assertSame('not json', PointsJson::round('not json'));
        $this->assertSame('"text"', PointsJson::round('"text"'));
    }
}
