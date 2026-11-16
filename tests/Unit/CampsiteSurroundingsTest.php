<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Campsite\CampsiteSurroundings;

/**
 * Nearest place (10 km) and named street (200 m) for naming unnamed
 * campsites, through a 0.1 degree grid - also across cell borders.
 */
final class CampsiteSurroundingsTest extends TestCase
{
    private function place(string $name, float $lat, float $lng, string $place = 'village'): array
    {
        return ['type' => 'node', 'lat' => $lat, 'lon' => $lng, 'tags' => ['place' => $place, 'name' => $name]];
    }

    private function street(string $name, array $points): array
    {
        return [
            'type' => 'way',
            'tags' => ['highway' => 'residential', 'name' => $name],
            'geometry' => array_map(fn (array $p) => ['lat' => $p[0], 'lon' => $p[1]], $points),
        ];
    }

    public function testCampsitesAreNotKeptOtherElementsAre(): void
    {
        $s = new CampsiteSurroundings();

        $this->assertFalse($s->add(['type' => 'node', 'tags' => ['tourism' => 'camp_site', 'name' => 'X']]));
        $this->assertTrue($s->add(['type' => 'node', 'tags' => []]));
        $this->assertTrue($s->add($this->place('Laboe', 54.40, 10.22)));
    }

    public function testNearestPlaceWithinTenKilometresAcrossACellBorder(): void
    {
        $s = new CampsiteSurroundings();
        $s->add($this->place('Far', 54.30, 10.22));        // ~11 km south
        $s->add($this->place('Near', 54.401, 10.199));     // other cell (lng < 10.2), ~150 m
        $s->add($this->place('Middle', 54.42, 10.22, 'town'));

        $this->assertSame('Near', $s->placeNear(54.400, 10.201));
        $this->assertSame('', $s->placeNear(55.5, 12.0), 'nothing within 10 km');
    }

    public function testPlacesOfOtherTypesAndWithoutNameAreIgnored(): void
    {
        $s = new CampsiteSurroundings();
        $s->add($this->place('Farm', 54.40, 10.22, 'isolated_dwelling'));
        $s->add(['type' => 'node', 'lat' => 54.40, 'lon' => 10.22, 'tags' => ['place' => 'village']]);

        $this->assertSame('', $s->placeNear(54.40, 10.22));
    }

    public function testNearestStreetIsMeasuredToTheSegmentNotItsEnds(): void
    {
        $s = new CampsiteSurroundings();
        // a long east-west street; the campsite is ~55 m north of its middle, far from both ends
        $s->add($this->street('Strandstraße', [[54.4000, 10.2100], [54.4000, 10.2140]]));
        $s->add($this->street('Dorfweg', [[54.4030, 10.2120], [54.4040, 10.2120]]));

        $this->assertSame('Strandstraße', $s->streetNear(54.4005, 10.2120));
        $this->assertSame('', $s->streetNear(54.4100, 10.2120), 'more than 200 m away');
    }

    public function testFarNorthUsesTwoRingsOfCells(): void
    {
        $s = new CampsiteSurroundings();
        // at 69 N a 0.1 degree cell is only ~4 km wide; the place is ~8 km east, two cells away
        $s->add($this->place('Tromsø', 69.65, 19.15));

        $this->assertSame('Tromsø', $s->placeNear(69.65, 18.95));
    }
}
