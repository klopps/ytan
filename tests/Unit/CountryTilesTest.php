<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\ValidationException;
use Ytan\Service\Overpass\CountryTiles;

/**
 * CountryTiles: a country's box (RadarConfig::PRESETS) split evenly into
 * tiles of at most the given size, null for a country without a box, and the
 * Overpass bounding-box filter.
 */
final class CountryTilesTest extends TestCase
{
    public function testSplitsTheBoxEvenlyIntoTilesOfAtMostTheSize(): void
    {
        $tiles = CountryTiles::tiles('FR', 3.0);
        $this->assertCount(20, $tiles, 'France 41.3-51.2 N, 5.3 W-9.7 E: 4 rows x 5 columns');
        $this->assertSame(['south' => 41.3, 'west' => -5.3, 'north' => 43.775, 'east' => -2.3], $tiles[0]);
        $this->assertSame(['south' => 48.725, 'west' => 6.7, 'north' => 51.2, 'east' => 9.7], $tiles[19]);
        foreach ($tiles as $tile) {
            $this->assertLessThanOrEqual(3.0, $tile['north'] - $tile['south']);
            $this->assertLessThanOrEqual(3.0, $tile['east'] - $tile['west']);
        }
        $this->assertCount(4, CountryTiles::tiles('fr', 8.0), 'case-insensitive; 2 x 2 at 8 degrees');
        $this->assertCount(1, CountryTiles::tiles('LU', 3.0), 'small country: one tile');
        $this->assertSame([null], CountryTiles::tiles('FO', 3.0), 'no box: the whole area');
    }

    public function testPickAndBbox(): void
    {
        $tiles = CountryTiles::tiles('FR', 3.0);
        $this->assertSame('(41.3,-5.3,43.775,-2.3)', CountryTiles::bbox(CountryTiles::pick($tiles, 0)));
        $this->assertSame('', CountryTiles::bbox(CountryTiles::pick([null], 0)));

        $this->expectException(ValidationException::class);
        CountryTiles::pick($tiles, 20);
    }
}
