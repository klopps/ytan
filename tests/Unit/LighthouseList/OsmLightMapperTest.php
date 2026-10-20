<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit\LighthouseList;

use PHPUnit\Framework\TestCase;
use Ytan\Service\LighthouseList\OsmLightMapper;

/**
 * Maps OpenSeaMap seamark tags to staging entries. The fixture
 * (tests/testdata/overpass-bornholm.json) is a trimmed real Overpass answer
 * for a Bornholm bounding box: a major light, a single-sector light, three
 * and six numbered sector lights, one with fog signal, one without number
 * and ranges.
 */
final class OsmLightMapperTest extends TestCase
{
    /** @return array<int, array<string,mixed>> OSM node id => element */
    private function fixture(): array
    {
        $answer = json_decode((string) file_get_contents(__DIR__ . '/../../testdata/overpass-bornholm.json'), true);
        $byId = [];
        foreach ($answer['elements'] as $element) {
            $byId[$element['id']] = $element;
        }

        return $byId;
    }

    private function map(int $id): array
    {
        $entry = (new OsmLightMapper())->map($this->fixture()[$id], 'Bornholm');
        $this->assertNotNull($entry);

        return $entry;
    }

    public function testPlainLightWithItsNumberRangeAndHeight(): void
    {
        $e = $this->map(295875845); // Christiansø

        $this->assertSame('node/295875845', $e['source_key']);
        $this->assertSame('node/295875845', $e['source_ref']);
        $this->assertSame('C2556', $e['international_id'], '"C 2556" without the space');
        $this->assertSame('Christiansø', $e['name']);
        $this->assertSame('Bornholm', $e['region']);
        $this->assertEqualsWithDelta(55.3206224, $e['latitude'], 0.0000001);
        $this->assertEqualsWithDelta(15.1869873, $e['longitude'], 0.0000001);
        $this->assertSame('Fl W 5s', $e['characteristic']);
        $this->assertSame('29', $e['flame_height']);
        $this->assertSame('W 18', $e['light_range']);
        $this->assertNull($e['sector_characteristic']);
        $this->assertFalse($e['sector_missing_range']);
        $this->assertSame('Quelle laut OpenStreetMap: Dansk Fyrliste 2007', $e['remarks']);
        $this->assertSame('', $e['dk_no']);
    }

    public function testLighthouseMappedAsLandmarkWay(): void
    {
        // Real OSM way 316995864 (Heiligenhafen): seamark:type=landmark, numbered lights only.
        $tags = [
            'man_made' => 'lighthouse', 'seamark:type' => 'landmark', 'seamark:name' => 'Leuchtturm Heiligenhafen',
            'seamark:light:reference' => 'C 1328',
            'seamark:light:1:character' => 'Oc', 'seamark:light:1:colour' => 'white', 'seamark:light:1:group' => '2',
            'seamark:light:1:height' => '16', 'seamark:light:1:period' => '9', 'seamark:light:1:range' => '13',
            'seamark:light:1:sector_start' => '206', 'seamark:light:1:sector_end' => '212.2',
            'seamark:light:2:character' => 'Oc', 'seamark:light:2:colour' => 'red', 'seamark:light:2:group' => '2',
            'seamark:light:2:height' => '16', 'seamark:light:2:period' => '9', 'seamark:light:2:range' => '10',
            'seamark:light:2:sector_start' => '212.2', 'seamark:light:2:sector_end' => '250',
        ];
        $e = (new OsmLightMapper())->map(['type' => 'way', 'id' => 316995864, 'center' => ['lat' => 54.3673404, 'lon' => 11.0214305], 'tags' => $tags], 'Kieler Bucht');

        $this->assertNotNull($e);
        $this->assertSame('way/316995864', $e['source_key']);
        $this->assertSame('C1328', $e['international_id']);
        $this->assertEqualsWithDelta(54.3673404, $e['latitude'], 0.0000001);
        $this->assertNotNull($e['sector_characteristic']);
    }

    public function testNumberedLightsBecomeSectorsWithTheirOwnRanges(): void
    {
        $e = $this->map(1533568487); // Tejn Havn: three numbered lights, Oc, white/red/green

        $this->assertSame('Oc WRG 5s', $e['characteristic'], 'colours of all lights, period of the first');
        $this->assertSame('W 7; R 4; G 4', $e['light_range']);
        $this->assertSame("W 7M 160-204\nR 4M 204-238\nG 4M 238-160", $e['sector_characteristic'], 'a sector may wrap past north');
        $this->assertFalse($e['sector_missing_range']);
        $this->assertSame('C2516.5', $e['international_id']);
    }

    public function testSixSectorsAndTheSectorTextGoToTheRemarks(): void
    {
        $e = $this->map(1535758630); // Danneskjolds Batteri, no seamark:name but a plain name

        $this->assertSame('Danneskjolds Batteri', $e['name']);
        $this->assertSame(
            "G 4M 172-186\nW 7M 186-194\nR 4M 194-204\nG 4M 341-347\nW 7M 347-350\nR 4M 350-358",
            $e['sector_characteristic']
        );
        $this->assertStringContainsString('G. 172°', $e['remarks'], 'seamark:information is kept');
    }

    public function testASingleSectorWithoutRangeGetsThePlaceholder(): void
    {
        $e = $this->map(1215754604); // Arnager Badehavn: F, white, 276-315, no range

        $this->assertSame('F W', $e['characteristic']);
        $this->assertSame('W ?M 276-315', $e['sector_characteristic']);
        $this->assertTrue($e['sector_missing_range']);
        $this->assertSame('', $e['light_range']);
    }

    public function testSectorsWithoutAnyRangeAndAnIncompleteLastSector(): void
    {
        $e = $this->map(10738709334); // no name, no number, last sector has no end

        $this->assertSame('', $e['international_id']);
        $this->assertSame('', $e['name']);
        $this->assertSame("G ?M 104-106\nW ?M 106-108", $e['sector_characteristic'], 'the red sector has no end and is left out');
        $this->assertTrue($e['sector_missing_range']);
        $this->assertSame('', $e['flame_height']);
    }

    public function testGroupAndFogSignal(): void
    {
        $this->assertSame('Fl(2) W 10s', $this->map(1535758202)['characteristic'], 'group 2 -> Fl(2)');
        $this->assertSame('Nebelsignal: siren, group 1, 30s', $this->map(1535761636)['other_signals']);
    }

    public function testNodesThatAreNoNavigationLightsAreSkipped(): void
    {
        $mapper = new OsmLightMapper();
        $light = ['id' => 1, 'lat' => 55.0, 'lon' => 10.0, 'tags' => [
            'seamark:type' => 'light_minor', 'seamark:light:character' => 'F', 'seamark:light:colour' => 'red',
        ]];
        $this->assertNotNull($mapper->map($light, 'X'));

        $skipped = [
            'aero' => ['seamark:light:category' => 'aero'],
            'air obstruction, numbered' => ['seamark:light:1:category' => 'air_obstruction', 'seamark:light:1:colour' => 'red'],
            'no light data' => ['seamark:light:character' => null],
            'not a light' => ['seamark:type' => 'beacon_cardinal'],
        ];
        foreach ($skipped as $label => $override) {
            $node = $light;
            foreach ($override as $key => $value) {
                if ($value === null) {
                    unset($node['tags']['seamark:light:character'], $node['tags']['seamark:light:colour']);
                } else {
                    $node['tags'][$key] = $value;
                }
            }
            if ($label === 'air obstruction, numbered') {
                unset($node['tags']['seamark:light:character'], $node['tags']['seamark:light:colour']);
            }
            $this->assertNull($mapper->map($node, 'X'), $label);
        }

        $this->assertNull($mapper->map(['id' => 2, 'tags' => $light['tags']], 'X'), 'a node without position');
    }

    public function testListNumbersAreAcceptedFromTheUsualTagsOnly(): void
    {
        $mapper = new OsmLightMapper();
        $base = ['id' => 1, 'lat' => 55.0, 'lon' => 10.0, 'tags' => ['seamark:type' => 'light_minor', 'seamark:light:character' => 'F']];

        $withTag = fn (string $key, string $value) => $mapper->map(array_merge_recursive($base, ['tags' => [$key => $value]]), 'X')['international_id'];

        $this->assertSame('B1188', $withTag('seamark:reference', 'B 1188'));
        $this->assertSame('C0969', $withTag('ref', 'C0969'));
        $this->assertSame('C2503.1', $withTag('seamark:light:reference', 'C 2503.1'));
        $this->assertSame('', $withTag('ref', 'Leuchtturm 7'), 'something that is no list number');
    }

    public function testColoursAndTheLongNameOfTheArea(): void
    {
        $mapper = new OsmLightMapper();
        $node = ['id' => 5, 'lat' => 55.0, 'lon' => 10.0, 'tags' => [
            'seamark:type' => 'light_major', 'seamark:light:1:character' => 'Fl', 'seamark:light:1:colour' => 'white;red',
            'seamark:light:2:character' => 'Fl', 'seamark:light:2:colour' => 'yellow', 'seamark:light:period' => '6',
        ]];

        $entry = $mapper->map($node, str_repeat('x', 300));

        $this->assertSame('Fl WRY 6s', $entry['characteristic'], 'colour lists and amber/yellow, fixed order');
        $this->assertSame(100, mb_strlen($entry['region']));
        $this->assertNull($entry['sector_characteristic'], 'mixed colours and Y are no overlay sectors');
    }
}
