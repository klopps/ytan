<?php

declare(strict_types=1);

namespace Ytan\Service\ShelterList;

use Ytan\Service\ClubList\ClubOsmMapper;

/**
 * Turns one OpenStreetMap element (Overpass JSON, `out tags center`) of a
 * shelter or hut into an entry of the staging table shelter_import. Elements
 * without a position are dropped (null); a missing name is fine - most
 * shelters have none.
 */
final class ShelterOsmMapper
{
    public const KIND_SHELTER = 'shelter';
    public const KIND_HUT = 'hut';

    /** shelter_type values that are not a place to stay in the outdoors. */
    public const EXCLUDED_SHELTER_TYPES = 'public_transport|bicycle_parking|bus_stop|field_shelter|animal_shelter|picnic_shelter';

    /**
     * @param array<string,mixed> $element
     * @return array<string,mixed>|null
     */
    public function map(array $element, string $country): ?array
    {
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        $type = (string) ($element['type'] ?? '');
        $id = $element['id'] ?? null;
        $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
        $lng = $element['lon'] ?? ($element['center']['lon'] ?? null);
        if (!in_array($type, ['node', 'way', 'relation'], true) || !is_int($id) || !is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        return [
            'source' => 'osm',
            'source_ref' => $type . '/' . $id,
            'name' => trim((string) ($tags['name'] ?? '')),
            'type_label' => self::typeLabel($tags),
            'kind' => in_array($tags['tourism'] ?? '', ['wilderness_hut', 'alpine_hut'], true) ? self::KIND_HUT : self::KIND_SHELTER,
            'country' => strtoupper($country),
            'description' => self::description($tags),
            'website' => ClubOsmMapper::website($tags),
            'latitude' => round((float) $lat, 7),
            'longitude' => round((float) $lng, 7),
        ];
    }

    /**
     * The word for the facility in a generated name ("Shelter Mols Bjerge").
     *
     * @param array<string,mixed> $tags
     */
    public static function typeLabel(array $tags): string
    {
        return match (true) {
            ($tags['tourism'] ?? '') === 'alpine_hut' => 'Berghütte',
            ($tags['tourism'] ?? '') === 'wilderness_hut', ($tags['shelter_type'] ?? '') === 'basic_hut' => 'Hütte',
            ($tags['shelter_type'] ?? '') === 'weather_shelter' => 'Wetterschutz',
            ($tags['shelter_type'] ?? '') === 'rock_shelter' => 'Felsunterstand',
            default => 'Shelter',
        };
    }

    /**
     * Facts worth showing, one per line: the shelter type, capacity, fee,
     * access and the mapper's own description.
     *
     * @param array<string,mixed> $tags
     */
    private static function description(array $tags): string
    {
        $lines = [];
        $shelterType = trim((string) ($tags['shelter_type'] ?? ''));
        if ($shelterType !== '') {
            $lines[] = 'Art: ' . str_replace('_', ' ', $shelterType);
        }
        $capacity = trim((string) ($tags['capacity'] ?? $tags['beds'] ?? ''));
        if ($capacity !== '') {
            $lines[] = 'Plätze: ' . $capacity;
        }
        $fee = strtolower(trim((string) ($tags['fee'] ?? '')));
        if ($fee === 'no') {
            $lines[] = 'Kostenlos';
        } elseif ($fee === 'yes') {
            $lines[] = 'Gebührenpflichtig';
        }
        if (strtolower(trim((string) ($tags['reservation'] ?? ''))) === 'required') {
            $lines[] = 'Buchung erforderlich';
        }
        $access = strtolower(trim((string) ($tags['access'] ?? '')));
        if ($access !== '' && $access !== 'yes') {
            $lines[] = 'Zugang: ' . $access;
        }
        $text = trim((string) ($tags['description'] ?? $tags['description:de'] ?? $tags['description:en'] ?? ''));
        if ($text !== '') {
            $lines[] = mb_substr($text, 0, 300);
        }

        return implode("\n", $lines);
    }
}
