<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\NationalLightsException;
use Ytan\Service\NationalLights\GmlFeatures;

final class GmlFeaturesTest extends TestCase
{
    private function collection(string $members, string $attributes = ''): string
    {
        return '<?xml version="1.0" encoding="utf-8" ?><wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0" '
            . 'xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:x="urn:x" ' . $attributes . '>' . $members . '</wfs:FeatureCollection>';
    }

    private function member(int $n, string $name): string
    {
        return '<wfs:member><x:Lys gml:id="Lys.' . $n . '"><x:objectid>' . $n . '</x:objectid><x:navn>' . $name . '</x:navn><x:sted/>'
            . '<x:shape><gml:Point gml:id="p' . $n . '" srsName="urn:ogc:def:crs:EPSG::4326"><gml:pos>59.5 ' . $n . '.25</gml:pos></gml:Point></x:shape></x:Lys></wfs:member>';
    }

    public function testEveryMemberBecomesARowWithAttributesAndThePointPosition(): void
    {
        $rows = GmlFeatures::parse($this->collection($this->member(1, 'Skagen') . $this->member(2, 'Grønn &amp; Rød') . $this->member(3, 'C')));

        $this->assertCount(3, $rows, 'no member may be skipped (reading after next() skipped every second one)');
        $this->assertSame(['objectid' => '1', 'navn' => 'Skagen', 'sted' => '', 'shape_pos' => '59.5 1.25'], $rows[0]);
        $this->assertSame('Grønn & Rød', $rows[1]['navn']);
        $this->assertSame('3', $rows[2]['objectid']);
    }

    public function testAnEmptyCollectionIsNoError(): void
    {
        $this->assertSame([], GmlFeatures::parse($this->collection('', 'numberMatched="0"')));
    }

    public function testADocumentTypeDeclarationIsRefused(): void
    {
        $this->expectException(NationalLightsException::class);
        GmlFeatures::parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0"/>');
    }

    public function testSomethingThatIsNoFeatureCollectionIsRefused(): void
    {
        $this->expectException(NationalLightsException::class);
        GmlFeatures::parse('<html><body>404 Not Found</body></html>');
    }

    public function testBrokenXmlIsRefused(): void
    {
        $this->expectException(NationalLightsException::class);
        GmlFeatures::parse('not xml at all');
    }
}
