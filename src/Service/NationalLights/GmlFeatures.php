<?php

declare(strict_types=1);

namespace Ytan\Service\NationalLights;

use Ytan\Exception\NationalLightsException;

/**
 * Reads the members of a WFS 2.0 GML answer (the ArcGIS server of Kystverket) into flat rows: the local name of
 * each attribute element => its text, and for a geometry element the text of its first `gml:pos` (a point) as
 * `{name}_pos` ("lat lon" for srsName urn:ogc:def:crs:EPSG::4326 - latitude first). Streams the document, so a
 * 12 MB answer does not become a 12 MB DOM. A DOCTYPE is refused (no entity expansion), nothing is fetched.
 */
final class GmlFeatures
{
    /** @return list<array<string,string>> */
    public static function parse(string $xml): array
    {
        if (preg_match('/<!DOCTYPE/i', substr($xml, 0, 4096)) === 1) {
            throw new NationalLightsException('The service answered with a document type declaration - refused.');
        }

        $reader = new \XMLReader();
        $previous = libxml_use_internal_errors(true);
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            libxml_use_internal_errors($previous);
            throw new NationalLightsException('The service did not answer with GML.');
        }

        $rows = [];
        $sawRoot = false;
        $more = @$reader->read();
        while ($more) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                if (!$sawRoot) {
                    $sawRoot = true;
                    if ($reader->localName !== 'FeatureCollection') {
                        break;
                    }
                }
                if ($reader->localName === 'member') {
                    $feature = $reader->expand();
                    if ($feature instanceof \DOMElement) {
                        $rows[] = self::row($feature);
                    }
                    // next() already stands on the following node: reading again would skip every second member
                    $more = @$reader->next();
                    continue;
                }
            }
            $more = @$reader->read();
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$sawRoot || ($rows === [] && !str_contains($xml, 'numberMatched="0"'))) {
            throw new NationalLightsException('The service did not answer with a list of features.');
        }

        return $rows;
    }

    /** @return array<string,string> */
    private static function row(\DOMElement $member): array
    {
        $row = [];
        foreach ($member->getElementsByTagName('*') as $first) {
            // the member's first element child is the feature; its children are the attributes
            $feature = $first;
            break;
        }
        if (!isset($feature)) {
            return $row;
        }
        foreach ($feature->childNodes as $attribute) {
            if (!$attribute instanceof \DOMElement) {
                continue;
            }
            $pos = $attribute->getElementsByTagNameNS('*', 'pos')->item(0);
            if ($pos !== null) {
                $row[$attribute->localName . '_pos'] = trim($pos->textContent);
            } elseif ($attribute->getElementsByTagName('*')->length === 0) {
                $row[$attribute->localName] = trim($attribute->textContent);
            }
        }

        return $row;
    }
}
