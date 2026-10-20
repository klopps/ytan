<?php

declare(strict_types=1);

namespace Ytan\Service\ClubList;

/**
 * Turns one OpenStreetMap element (Overpass JSON, `out tags center`) of a
 * canoe/kayak or rowing club into an entry of the staging table club_import.
 * Elements without a name or without a position are dropped (null).
 */
final class ClubOsmMapper
{
    public const KIND_CANOE = 'canoe';
    public const KIND_ROWING = 'rowing';
    public const KIND_BOTH = 'both';

    /** Regex (Overpass QL) for the sport tag; `sport` may hold a ";" separated list. */
    public const SPORT_PATTERN = '(^|;)(canoe|kayak|rowing|canoe_slalom|canoe_polo)(;|$)';

    /** Tags that carry the club's website, best first. */
    private const WEBSITE_TAGS = ['website', 'contact:website', 'url', 'operator:website'];

    /**
     * @param array<string,mixed> $element
     * @return array<string,mixed>|null
     */
    public function map(array $element, string $country): ?array
    {
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        $type = (string) ($element['type'] ?? '');
        $id = $element['id'] ?? null;
        $name = trim((string) ($tags['name'] ?? ''));
        $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
        $lng = $element['lon'] ?? ($element['center']['lon'] ?? null);
        if ($name === '' || !in_array($type, ['node', 'way', 'relation'], true) || !is_int($id)
            || !is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        return [
            'osm_ref' => $type . '/' . $id,
            'name' => $name,
            'kind' => self::kind((string) ($tags['sport'] ?? '')),
            'country' => strtoupper($country),
            'city' => trim((string) ($tags['addr:city'] ?? $tags['addr:town'] ?? $tags['addr:village'] ?? '')),
            'address' => self::address($tags),
            'website' => self::website($tags),
            'latitude' => round((float) $lat, 7),
            'longitude' => round((float) $lng, 7),
        ];
    }

    /** "canoe", "rowing" or "both" from a sport tag value. */
    public static function kind(string $sport): string
    {
        $values = array_map('trim', explode(';', strtolower($sport)));
        $rowing = in_array('rowing', $values, true);
        $canoe = array_intersect($values, ['canoe', 'kayak', 'canoe_slalom', 'canoe_polo']) !== [];
        if ($rowing && $canoe) {
            return self::KIND_BOTH;
        }

        return $rowing ? self::KIND_ROWING : self::KIND_CANOE;
    }

    /**
     * The first usable website tag as an absolute http(s) URL ("" when
     * there is none); a bare "example.org" gets "https://".
     *
     * @param array<string,mixed> $tags
     */
    public static function website(array $tags): string
    {
        foreach (self::WEBSITE_TAGS as $key) {
            $value = trim((string) ($tags[$key] ?? ''));
            // A tag can hold several values separated by ";" - the first one counts.
            $value = trim(explode(';', $value)[0]);
            if ($value === '' || preg_match('/\s/', $value)) {
                continue;
            }
            if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
                if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
                    continue; // mailto:, tel:, ... - not a website
                }
                $value = 'https://' . $value;
            }
            $parts = parse_url($value);
            if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || ($parts['host'] ?? '') === '' || !str_contains($parts['host'], '.')) {
                continue;
            }

            return $value;
        }

        return '';
    }

    /** @param array<string,mixed> $tags */
    private static function address(array $tags): string
    {
        $street = trim(trim((string) ($tags['addr:street'] ?? '')) . ' ' . trim((string) ($tags['addr:housenumber'] ?? '')));
        $city = trim(trim((string) ($tags['addr:postcode'] ?? '')) . ' '
            . trim((string) ($tags['addr:city'] ?? $tags['addr:town'] ?? $tags['addr:village'] ?? '')));

        return implode(', ', array_filter([$street, $city], fn ($part) => $part !== ''));
    }
}
