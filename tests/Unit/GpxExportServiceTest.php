<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\GpxExportService;

final class GpxExportServiceTest extends TestCase
{
    private function route(array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'name' => 'Schlei & Ostsee <Runde>',
            'description' => 'Ab Kappeln',
            'points' => json_encode([
                ['lat' => 54.6612345678, 'lng' => 9.9312],
                ['lat' => -0.00001, 'lng' => 10.0],
                ['foo' => 'ignored'],
            ]),
        ], $overrides);
    }

    public function testBuildsValidGpx11TrackWithEscapedNameAndAllPoints(): void
    {
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML(GpxExportService::build($this->route(), 'YTAN')));

        $gpx = $doc->documentElement;
        $this->assertSame('gpx', $gpx->localName);
        $this->assertSame('http://www.topografix.com/GPX/1/1', $gpx->namespaceURI);
        $this->assertSame('1.1', $gpx->getAttribute('version'));
        $this->assertSame('YTAN', $gpx->getAttribute('creator'));

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('g', 'http://www.topografix.com/GPX/1/1');
        $this->assertSame('Schlei & Ostsee <Runde>', $xpath->evaluate('string(/g:gpx/g:trk/g:name)'));
        $this->assertSame('Ab Kappeln', $xpath->evaluate('string(/g:gpx/g:trk/g:desc)'));

        $points = $xpath->query('/g:gpx/g:trk/g:trkseg/g:trkpt');
        $this->assertSame(2, $points->length);
        $this->assertSame('54.6612346', $points->item(0)->getAttribute('lat'));
        $this->assertSame('9.9312', $points->item(0)->getAttribute('lon'));
        $this->assertSame('-0.00001', $points->item(1)->getAttribute('lat'));
        $this->assertSame('10', $points->item(1)->getAttribute('lon'));
    }

    public function testEmptyDescriptionIsOmitted(): void
    {
        $this->assertStringNotContainsString('<desc>', GpxExportService::build($this->route(['description' => '  ']), 'YTAN'));
    }

    public function testFilenameKeepsLettersAndFallsBackToId(): void
    {
        $this->assertSame('Große-Schlei-Runde.gpx', GpxExportService::filename(' Große Schlei / Runde! ', 'route', 7));
        $this->assertSame('route-7.gpx', GpxExportService::filename('***', 'route', 7));
        $this->assertSame('tour-3.gpx', GpxExportService::filename('', 'tour', 3));
    }

    public function testTourBecomesOneTrackPerRouteInOrderWithTourMetadata(): void
    {
        $gpx = GpxExportService::buildTracks('Schlei-Woche', 'Fünf Etappen', [
            $this->route(['name' => 'Etappe 1']),
            $this->route(['name' => 'Etappe 2', 'points' => '[]', 'description' => '']),
        ], 'YTAN');

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($gpx));
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('g', 'http://www.topografix.com/GPX/1/1');

        $this->assertSame('Schlei-Woche', $xpath->evaluate('string(/g:gpx/g:metadata/g:name)'));
        $this->assertSame('Fünf Etappen', $xpath->evaluate('string(/g:gpx/g:metadata/g:desc)'));
        $tracks = $xpath->query('/g:gpx/g:trk');
        $this->assertSame(2, $tracks->length);
        $this->assertSame('Etappe 1', $xpath->evaluate('string(g:name)', $tracks->item(0)));
        $this->assertSame('Etappe 2', $xpath->evaluate('string(g:name)', $tracks->item(1)));
        // A route without points keeps its (empty) segment.
        $this->assertSame(1, $xpath->query('g:trkseg', $tracks->item(1))->length);
        $this->assertSame(0, $xpath->query('g:trkseg/g:trkpt', $tracks->item(1))->length);
    }
}
