<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\GeometryService;

final class GeometryServiceTest extends TestCase
{
    /**
     * A simple 10x10 square, traced A(0,0) -> B(0,10) -> C(10,10) -> D(10,0)
     * -> back to A.
     *
     * @return list<array{lat:float,lng:float}>
     */
    private function square(): array
    {
        return [
            ['lat' => 0.0, 'lng' => 0.0],
            ['lat' => 0.0, 'lng' => 10.0],
            ['lat' => 10.0, 'lng' => 10.0],
            ['lat' => 10.0, 'lng' => 0.0],
        ];
    }

    public function testPointStrictlyInsideThePolygonIsInside(): void
    {
        $this->assertTrue(GeometryService::isPointInPolygon(['lat' => 5.0, 'lng' => 5.0], $this->square()));
    }

    public function testPointStrictlyOutsideThePolygonIsOutside(): void
    {
        $this->assertFalse(GeometryService::isPointInPolygon(['lat' => 5.0, 'lng' => 20.0], $this->square()));
    }

    public function testPointExactlyOnAVertexIsTreatedAsInside(): void
    {
        $this->assertTrue(GeometryService::isPointInPolygon(['lat' => 0.0, 'lng' => 0.0], $this->square()));
    }

    public function testPointExactlyOnAnEdgeIsTreatedAsInside(): void
    {
        $this->assertTrue(GeometryService::isPointInPolygon(['lat' => 0.0, 'lng' => 5.0], $this->square()));
    }

    public function testCrossingSegmentsIntersect(): void
    {
        $this->assertTrue(GeometryService::segmentsIntersect(
            ['lat' => 0.0, 'lng' => 0.0],
            ['lat' => 10.0, 'lng' => 10.0],
            ['lat' => 10.0, 'lng' => 0.0],
            ['lat' => 0.0, 'lng' => 10.0],
        ));
    }

    public function testDisjointSegmentsDoNotIntersect(): void
    {
        $this->assertFalse(GeometryService::segmentsIntersect(
            ['lat' => 0.0, 'lng' => 0.0],
            ['lat' => 0.0, 'lng' => 1.0],
            ['lat' => 5.0, 'lng' => 5.0],
            ['lat' => 5.0, 'lng' => 6.0],
        ));
    }

    public function testParallelNonOverlappingSegmentsDoNotIntersect(): void
    {
        $this->assertFalse(GeometryService::segmentsIntersect(
            ['lat' => 0.0, 'lng' => 0.0],
            ['lat' => 0.0, 'lng' => 10.0],
            ['lat' => 1.0, 'lng' => 0.0],
            ['lat' => 1.0, 'lng' => 10.0],
        ));
    }

    public function testCollinearOverlappingSegmentsIntersect(): void
    {
        $this->assertTrue(GeometryService::segmentsIntersect(
            ['lat' => 0.0, 'lng' => 0.0],
            ['lat' => 0.0, 'lng' => 10.0],
            ['lat' => 0.0, 'lng' => 5.0],
            ['lat' => 0.0, 'lng' => 15.0],
        ));
    }

    public function testSegmentsTouchingAtASharedEndpointIntersect(): void
    {
        $this->assertTrue(GeometryService::segmentsIntersect(
            ['lat' => 0.0, 'lng' => 0.0],
            ['lat' => 0.0, 'lng' => 10.0],
            ['lat' => 0.0, 'lng' => 10.0],
            ['lat' => 10.0, 'lng' => 10.0],
        ));
    }

    public function testPolylineFullyInsideThePolygonIntersectsViaVertexCheck(): void
    {
        $polyline = [
            ['lat' => 3.0, 'lng' => 3.0],
            ['lat' => 6.0, 'lng' => 6.0],
        ];

        $this->assertTrue(GeometryService::doesPolylineIntersectPolygon($polyline, $this->square()));
    }

    public function testPolylineMissingThePolygonDoesNotIntersect(): void
    {
        $polyline = [
            ['lat' => 20.0, 'lng' => 20.0],
            ['lat' => 30.0, 'lng' => 30.0],
        ];

        $this->assertFalse(GeometryService::doesPolylineIntersectPolygon($polyline, $this->square()));
    }

    public function testPolylineCrossingTheBoundaryOnceIntersects(): void
    {
        $polyline = [
            ['lat' => -5.0, 'lng' => 5.0],
            ['lat' => 5.0, 'lng' => 5.0],
        ];

        $this->assertTrue(GeometryService::doesPolylineIntersectPolygon($polyline, $this->square()));
    }

    public function testHaversineDistanceBetweenIdenticalPointsIsZero(): void
    {
        $point = ['lat' => 54.3, 'lng' => 10.15];

        $this->assertSame(0.0, GeometryService::haversineDistanceMeters($point, $point));
    }

    public function testPointWithinThresholdOfAPolylineIsNear(): void
    {
        // ~111m per 0.001 degree of latitude - well within 200m.
        $point = ['lat' => 54.001, 'lng' => 10.0];
        $polyline = [
            ['lat' => 54.0, 'lng' => 10.0],
            ['lat' => 54.0, 'lng' => 10.01],
        ];

        $this->assertTrue(GeometryService::isPointNearPolyline($point, $polyline, 200.0));
    }

    public function testPointFarBeyondThresholdOfAPolylineIsNotNear(): void
    {
        $point = ['lat' => 55.0, 'lng' => 10.0];
        $polyline = [
            ['lat' => 54.0, 'lng' => 10.0],
            ['lat' => 54.0, 'lng' => 10.01],
        ];

        $this->assertFalse(GeometryService::isPointNearPolyline($point, $polyline, 200.0));
    }
}
