<?php

declare(strict_types=1);

namespace Ytan\Service\ShelterList;

use Ytan\Exception\ApiException;

/**
 * The plain HTTP GET the shelter sources use by default (tests inject their
 * own callable instead), plus the small text helpers they share.
 */
final class ShelterHttp
{
    public static function get(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_USERAGENT => 'YTAN shelter import (+https://ytan.pesr.org)',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $status !== 200) {
            throw new ApiException('The shelter source ' . parse_url($url, PHP_URL_HOST) . ' is not reachable (HTTP ' . $status . ').', 502, 'shelter_import.source_unreachable');
        }

        return $body;
    }

    /** An absolute http(s) URL with a dotted host, or '' (sources pad links with spaces or leave a bare "http://"). */
    public static function url(?string $value): string
    {
        $value = trim((string) $value);
        if (!preg_match('#^https?://#i', $value) || preg_match('/\s/', $value)) {
            return '';
        }
        $host = (string) parse_url($value, PHP_URL_HOST);

        return str_contains($host, '.') ? $value : '';
    }

    /**
     * Reads a GML feature collection (WFS 2.0) and calls $each with every
     * feature element and its first gml:pos as [lat, lng] (the services are
     * asked for urn:ogc:def:crs:EPSG::4326, which is "lat lon").
     *
     * @param callable(\SimpleXMLElement, float, float):void $each
     */
    public static function eachGmlFeature(string $gml, string $sourceName, callable $each): void
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($gml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($xml === false || $xml->getName() !== 'FeatureCollection') {
            throw new ApiException("The $sourceName service did not answer with a feature collection.", 502, 'shelter_import.source_bad_answer');
        }

        foreach ($xml->children('http://www.opengis.net/wfs/2.0') as $member) {
            foreach ($member->xpath('./*') ?: [] as $feature) {
                self::eachFeature($feature, $each);
            }
        }
    }

    /** @param callable(\SimpleXMLElement, float, float):void $each */
    private static function eachFeature(\SimpleXMLElement $feature, callable $each): void
    {
        foreach ($feature->xpath('.//*[local-name()="pos"]') ?: [] as $pos) {
            $parts = preg_split('/\s+/', trim((string) $pos));
            if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $each($feature, (float) $parts[0], (float) $parts[1]);
            }

            return;
        }
    }

    /** Text of the first descendant element with this local name ('' if none). */
    public static function text(\SimpleXMLElement $feature, string $localName): string
    {
        foreach ($feature->xpath('.//*[local-name()="' . $localName . '"]') ?: [] as $node) {
            return trim((string) $node);
        }

        return '';
    }
}
