<?php

declare(strict_types=1);

namespace Ytan\Service;

use XMLWriter;

/**
 * Turns routes into a GPX 1.1 document (GpxExportController): a single
 * route, or a tour's routes in tour order.
 *
 * Each route goes out as its own track (<trk><trkseg><trkpt>), not as a
 * GPX route (<rte><rtept>): Garmin Connect, Komoot, OsmAnd etc. all import
 * a track as a course/route to follow, while <rte> with hundreds of points
 * is read as a list of turn points by some of them. A tour becomes one
 * <trk> per route (named after it), so apps show its stages separately. A
 * route has neither timestamps nor elevations per point, so <trkpt> only
 * carries lat/lon.
 */
final class GpxExportService
{
    public static function build(array $route, string $creator): string
    {
        return self::buildTracks((string) $route['name'], '', [$route], $creator);
    }

    /**
     * @param string $name        document name (<metadata>) - the tour's
     * @param string $description document description, '' for none
     * @param array<int, array>   $routes route rows, one <trk> each, in this order
     */
    public static function buildTracks(string $name, string $description, array $routes, string $creator): string
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

        $xml->startElement('metadata');
        $xml->writeElement('name', $name);
        if (trim($description) !== '') {
            $xml->writeElement('desc', trim($description));
        }
        $xml->writeElement('time', gmdate('Y-m-d\TH:i:s\Z'));
        $xml->endElement();

        foreach ($routes as $route) {
            self::writeTrack($xml, $route);
        }

        $xml->endElement(); // gpx
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * A download filename from a name: letters/digits (incl. umlauts) kept,
     * everything else collapsed to "-", "{$fallbackPrefix}-{id}" if nothing
     * is left.
     */
    public static function filename(string $name, string $fallbackPrefix, int $id): string
    {
        $base = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $name), '-');
        if ($base === '') {
            $base = $fallbackPrefix . '-' . $id;
        }

        return mb_substr($base, 0, 80) . '.gpx';
    }

    /**
     * A route without points still gets its <trk> (with an empty <trkseg>,
     * valid GPX) so a tour's stage order stays recognizable.
     */
    private static function writeTrack(XMLWriter $xml, array $route): void
    {
        $description = trim((string) ($route['description'] ?? ''));

        $xml->startElement('trk');
        $xml->writeElement('name', (string) $route['name']);
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
    }

    /** 7 decimals (~1 cm), no exponent notation, locale-independent. */
    private static function coordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 7, '.', ''), '0'), '.');
    }
}
