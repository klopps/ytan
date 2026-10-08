<?php

declare(strict_types=1);

namespace Ytan\Service;

use XMLWriter;

/**
 * Turns a route row into a GPX 1.1 document (RouteExportController::gpx()).
 *
 * The points go out as one track (<trk><trkseg><trkpt>), not as a GPX
 * route (<rte><rtept>): Garmin Connect, Komoot, OsmAnd etc. all import a
 * track as a course/route to follow, while <rte> with hundreds of points is
 * read as a list of turn points by some of them. A route has neither
 * timestamps nor elevations per point, so <trkpt> only carries lat/lon.
 */
final class GpxExportService
{
    public static function build(array $route, string $creator): string
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('gpx');
        $xml->writeAttribute('version', '1.1');
        $xml->writeAttribute('creator', $creator);
        $xml->writeAttribute('xmlns', 'http://www.topografix.com/GPX/1/1');
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeAttribute('xsi:schemaLocation', 'http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd');

        $name = (string) $route['name'];
        $description = trim((string) ($route['description'] ?? ''));

        $xml->startElement('metadata');
        $xml->writeElement('name', $name);
        $xml->writeElement('time', gmdate('Y-m-d\TH:i:s\Z'));
        $xml->endElement();

        $xml->startElement('trk');
        $xml->writeElement('name', $name);
        if ($description !== '') {
            $xml->writeElement('desc', $description);
        }
        $xml->startElement('trkseg');
        foreach (json_decode((string) $route['points'], true) ?: [] as $point) {
            if (!isset($point['lat'], $point['lng'])) {
                continue;
            }
            $xml->startElement('trkpt');
            $xml->writeAttribute('lat', self::coordinate((float) $point['lat']));
            $xml->writeAttribute('lon', self::coordinate((float) $point['lng']));
            $xml->endElement();
        }
        $xml->endElement(); // trkseg
        $xml->endElement(); // trk

        $xml->endElement(); // gpx
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * A download filename from the route's name: letters/digits (incl.
     * umlauts) kept, everything else collapsed to "-", "route-{id}" if
     * nothing is left.
     */
    public static function filename(array $route): string
    {
        $base = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', (string) $route['name']), '-');
        if ($base === '') {
            $base = 'route-' . $route['id'];
        }

        return mb_substr($base, 0, 80) . '.gpx';
    }

    /** 7 decimals (~1 cm), no exponent notation, locale-independent. */
    private static function coordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 7, '.', ''), '0'), '.');
    }
}
