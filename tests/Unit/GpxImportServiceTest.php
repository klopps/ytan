<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\ValidationException;
use Ytan\Service\GpxExportService;
use Ytan\Service\GpxImportService;

final class GpxImportServiceTest extends TestCase
{
    private GpxImportService $service;

    protected function setUp(): void
    {
        $this->service = new GpxImportService();
    }

    private function gpx(string $body, string $metadata = '<metadata><name>Schlei-Woche</name><desc>Fünf Etappen</desc><time>2026-07-01T06:00:00Z</time></metadata>'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">' . $metadata . $body . '</gpx>';
    }

    public function testParsesDocumentDataTracksAndRoutesInOrder(): void
    {
        $parsed = $this->service->parse($this->gpx(
            '<trk><name>Etappe 1</name><desc>Ab Kappeln</desc>'
            . '<trkseg><trkpt lat="54.66" lon="9.93"><time>2026-07-01T07:00:00Z</time></trkpt><trkpt lat="54.60" lon="9.80"><time>2026-07-01T08:00:00Z</time></trkpt></trkseg>'
            . '<trkseg><trkpt lat="54.55" lon="9.70"><time>2026-07-01T09:30:00Z</time></trkpt></trkseg></trk>'
            . '<rte><name>Geplant</name><rtept lat="54.5" lon="9.6"/><rtept lat="54.4" lon="9.5"/></rte>'
        ));

        $this->assertSame('Schlei-Woche', $parsed['name']);
        $this->assertSame('Fünf Etappen', $parsed['desc']);
        $this->assertSame('2026-07-01T06:00:00Z', $parsed['time']);
        $this->assertCount(2, $parsed['tracks']);

        [$trk, $rte] = $parsed['tracks'];
        $this->assertSame([0, 'trk', 'Etappe 1', 'Ab Kappeln'], [$trk['index'], $trk['kind'], $trk['name'], $trk['desc']]);
        $this->assertCount(3, $trk['points'], 'segments are joined');
        $this->assertSame('2026-07-01T07:00:00Z', $trk['time']);
        $this->assertSame('2026-07-01T09:30:00Z', $trk['ended_at']);

        $this->assertSame([1, 'rte', 'Geplant'], [$rte['index'], $rte['kind'], $rte['name']]);
        $this->assertCount(2, $rte['points']);
        $this->assertNull($rte['time']);
        // Several tracks: nothing is copied down from the document.
        $this->assertNull($rte['desc']);
    }

    public function testWaypointsWithNameOrDescAreListedAsPois(): void
    {
        $parsed = $this->service->parse($this->gpx(
            '<wpt lat="54.1" lon="10.1"><name>Schleuse</name><desc>Nur bis 18 Uhr</desc></wpt>'
            . '<wpt lat="54.2" lon="10.2"><desc>Flache Einsetzstelle hinter der Brücke links</desc></wpt>'
            . '<wpt lat="54.3" lon="10.3"><ele>2</ele><time>2026-07-01T07:00:00Z</time></wpt>'
            . '<wpt lat="999" lon="10.3"><name>Ungültig</name></wpt>'
        ));

        $this->assertCount(2, $parsed['waypoints']);
        $this->assertSame([
            'poitype_id' => 0,
            'name' => 'Schleuse',
            'description' => 'Nur bis 18 Uhr',
            'latitude' => 54.1,
            'longitude' => 10.1,
            'url' => '',
        ], $this->service->toPoi($parsed['waypoints'][0]));
        // No name: "IMPORT: " + the first 20 characters of the description.
        $this->assertSame('IMPORT: Flache Einsetzstelle', $this->service->toPoi($parsed['waypoints'][1])['name']);
        $this->assertSame(
            [['index' => 0, 'name' => 'Schleuse'], ['index' => 1, 'name' => 'IMPORT: Flache Einsetzstelle']],
            $this->service->summary($parsed)['waypoints']
        );
    }

    public function testWaypointWithoutDescriptionGetsEmptyStringsNotNull(): void
    {
        $parsed = $this->service->parse($this->gpx('<wpt lat="54.1" lon="10.1"><name>Nur Name</name></wpt>'));

        $poi = $this->service->toPoi($parsed['waypoints'][0]);

        $this->assertSame('', $poi['description']);
        $this->assertSame('', $poi['url']);
    }

    public function testRealWikilocFileWithWaypoints(): void
    {
        $parsed = $this->service->parse(file_get_contents(__DIR__ . '/../testdata/kanutour1.gpx'));

        $this->assertCount(1, $parsed['tracks']);
        $this->assertCount(1188, $parsed['tracks'][0]['points']);
        $this->assertCount(25, $parsed['waypoints']);
        $this->assertSame('1. Übernachtung: Campingplatz Rote Schleuse', $parsed['waypoints'][0]['name']);
    }

    public function testSingleTrackInheritsMissingDataFromDocument(): void
    {
        $parsed = $this->service->parse($this->gpx('<trk><trkseg><trkpt lat="54.1" lon="10.1"/><trkpt lat="54.2" lon="10.2"/></trkseg></trk>'));

        $track = $parsed['tracks'][0];
        $this->assertSame('Schlei-Woche', $track['name']);
        $this->assertSame('Fünf Etappen', $track['desc']);
        $this->assertSame('2026-07-01T06:00:00Z', $track['time']);
    }

    public function testReadsOwnExportBack(): void
    {
        $route = ['id' => 1, 'name' => 'Rund um Fehmarn', 'description' => 'Uhrzeigersinn', 'points' => json_encode([['lat' => 54.4, 'lng' => 11.1], ['lat' => 54.5, 'lng' => 11.2]])];

        $parsed = $this->service->parse(GpxExportService::build($route, 'YTAN'));

        $this->assertSame('Rund um Fehmarn', $parsed['tracks'][0]['name']);
        $this->assertSame('Uhrzeigersinn', $parsed['tracks'][0]['desc']);
        $this->assertSame(54.5, $parsed['tracks'][0]['points'][1]['lat']);
    }

    public function testSummaryHasCountsAndLengthButNoPoints(): void
    {
        $summary = $this->service->summary($this->service->parse($this->gpx(
            '<trk><name>A</name><trkseg><trkpt lat="54.0" lon="10.0"/><trkpt lat="54.01" lon="10.0"/></trkseg></trk>'
        )));

        $track = $summary['tracks'][0];
        $this->assertArrayNotHasKey('points', $track);
        $this->assertSame(2, $track['point_count']);
        $this->assertSame(2, $track['route_point_count']);
        $this->assertEqualsWithDelta(1112, $track['length'], 2);
    }

    public function testRejectsDoctypeAndNonGpx(): void
    {
        foreach ([
            '<?xml version="1.0"?><!DOCTYPE gpx [<!ENTITY x SYSTEM "file:///etc/passwd">]><gpx>&x;</gpx>',
            '<kml xmlns="http://www.opengis.net/kml/2.2"></kml>',
            'not xml at all',
            '',
        ] as $input) {
            try {
                $this->service->parse($input);
                $this->fail('Accepted: ' . $input);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInvalidPointsAreSkipped(): void
    {
        $parsed = $this->service->parse($this->gpx('<trk><trkseg><trkpt lat="abc" lon="10"/><trkpt lat="95" lon="10"/><trkpt lat="54" lon="10"/></trkseg></trk>'));

        $this->assertCount(1, $parsed['tracks'][0]['points']);
    }

    public function testToRouteJoinsTracksAndTakesRecordingWindow(): void
    {
        $parsed = $this->service->parse($this->gpx(
            '<trk><trkseg><trkpt lat="54.0" lon="10.0"><time>2026-07-01T07:00:00Z</time></trkpt><trkpt lat="54.01" lon="10.0"><time>2026-07-01T07:30:00Z</time></trkpt></trkseg></trk>'
            . '<trk><trkseg><trkpt lat="54.02" lon="10.0"><time>2026-07-01T08:00:00Z</time></trkpt><trkpt lat="54.03" lon="10.0"><time>2026-07-01T09:00:00Z</time></trkpt></trkseg></trk>'
        ));

        $route = $this->service->toRoute($parsed['tracks'], 'Zusammen', 'Beides');

        $this->assertSame('Zusammen', $route['name']);
        $this->assertCount(4, json_decode($route['points'], true));
        $this->assertSame('2026-07-01 07:00:00', $route['recorded_at']);
        $this->assertSame(7200, $route['recording_duration_seconds']);
        $this->assertEqualsWithDelta(3336, $route['length'], 5);
    }

    public function testToRouteWithoutTimesHasNoRecording(): void
    {
        $parsed = $this->service->parse($this->gpx('<rte><rtept lat="54" lon="10"/><rtept lat="54.1" lon="10"/></rte>'));

        $route = $this->service->toRoute($parsed['tracks'], 'R', '');

        $this->assertNull($route['recorded_at']);
        $this->assertNull($route['recording_duration_seconds']);
    }

    public function testRouteColorsAreDistinctAndCycle(): void
    {
        $count = count(GpxImportService::ROUTE_COLORS);
        $this->assertCount($count, array_unique(array_map('strtoupper', GpxImportService::ROUTE_COLORS)));
        $this->assertSame('#BF409F', GpxImportService::routeColor(0));
        $this->assertSame(GpxImportService::routeColor(1), GpxImportService::routeColor($count + 1));
        foreach (GpxImportService::ROUTE_COLORS as $color) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $color);
        }
    }

    public function testLongTracksAreSimplifiedBelowTheCap(): void
    {
        $points = '';
        for ($i = 0; $i < 5000; $i++) {
            // A zigzag, so simplification can't just collapse it to two points.
            $points .= sprintf('<trkpt lat="%.6f" lon="%.6f"/>', 54 + $i * 0.0001, 10 + (($i % 2) * 0.0002));
        }
        $parsed = $this->service->parse($this->gpx('<trk><trkseg>' . $points . '</trkseg></trk>'));

        $route = $this->service->toRoute($parsed['tracks'], 'Lang', '');

        $this->assertLessThanOrEqual(GpxImportService::MAX_POINTS, count(json_decode($route['points'], true)));
        $this->assertLessThan(65535, strlen($route['points']));

        $summary = $this->service->summary($parsed)['tracks'][0];
        $this->assertSame(5000, $summary['point_count']);
        $this->assertSame(count(json_decode($route['points'], true)), $summary['route_point_count']);
    }
}
