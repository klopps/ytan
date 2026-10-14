<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\ValidationException;
use Ytan\Service\LighthousePolygon;

/**
 * The area drawn on the map of the lighthouse import: parameter parsing with
 * its limits, bounding box and the point-in-polygon test.
 */
final class LighthousePolygonTest extends TestCase
{
    public function testParseReadsLatLngPairs(): void
    {
        $this->assertSame(
            [[55.0, 14.0], [55.5, 14.5], [54.5, -14.25]],
            LighthousePolygon::parse(' 55,14; 55.5 ,14.5;54.5,-14.25 ')
        );
    }

    /** @dataProvider invalidPolygons */
    public function testParseRejectsInvalidInput(string $text): void
    {
        $this->expectException(ValidationException::class);
        LighthousePolygon::parse($text);
    }

    /** @return array<string, array{0:string}> */
    public static function invalidPolygons(): array
    {
        $tooMany = implode(';', array_map(fn ($i) => (50 + $i / 1000) . ',10', range(0, LighthousePolygon::MAX_VERTICES)));

        return [
            'empty' => [''],
            'two vertices' => ['55,14;56,15'],
            'not a number' => ['55,14;abc,15;56,16'],
            'one coordinate' => ['55,14;56;57,15'],
            'three coordinates' => ['55,14,1;56,15;57,16'],
            'latitude out of range' => ['91,14;56,15;57,16'],
            'longitude out of range' => ['55,181;56,15;57,16'],
            'too many vertices' => [$tooMany],
        ];
    }

    public function testBoundingBox(): void
    {
        $this->assertSame(
            ['south' => 54.0, 'west' => 9.0, 'north' => 56.0, 'east' => 12.0],
            LighthousePolygon::boundingBox([[54.0, 10.0], [56.0, 9.0], [55.0, 12.0]])
        );
    }

    public function testContainsSquare(): void
    {
        $square = [[54.0, 10.0], [54.0, 12.0], [56.0, 12.0], [56.0, 10.0]];

        $this->assertTrue(LighthousePolygon::contains($square, 55.0, 11.0));
        $this->assertFalse(LighthousePolygon::contains($square, 53.9, 11.0));
        $this->assertFalse(LighthousePolygon::contains($square, 55.0, 12.1));
        $this->assertFalse(LighthousePolygon::contains($square, 55.0, 9.9));
    }

    public function testContainsConcavePolygon(): void
    {
        // A "C" opening to the east: the notch (lat 54.8-55.2, lng > 10.5) is outside.
        $c = [[54.0, 10.0], [54.0, 12.0], [54.8, 12.0], [54.8, 10.5], [55.2, 10.5], [55.2, 12.0], [56.0, 12.0], [56.0, 10.0]];

        $this->assertTrue(LighthousePolygon::contains($c, 54.4, 11.0), 'lower arm');
        $this->assertTrue(LighthousePolygon::contains($c, 55.6, 11.0), 'upper arm');
        $this->assertTrue(LighthousePolygon::contains($c, 55.0, 10.2), 'spine');
        $this->assertFalse(LighthousePolygon::contains($c, 55.0, 11.5), 'inside the notch');
    }
}
