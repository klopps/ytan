<?php

declare(strict_types=1);

namespace Ytan\Service\Campsite;

use Ytan\Service\ClubList\ClubOsmMapper;

/**
 * Turns one OpenStreetMap element (Overpass JSON, `out tags center`) of a
 * campsite (tourism=camp_site) into an entry of the staging table
 * campsite_import. Simple sites (camp_site=basic, backcountry=yes) become
 * category 'basic' (POI type 1 "Zeltplatz"), all others 'campsite' (POI type
 * 11 "Campingplatz"). A site without a name gets "Campingplatz/Zeltplatz
 * {town}, {street}" - town and street from its address, else the nearest
 * place and street (CampsiteSurroundings) - or just "Campsite" without
 * either. Only an element without a position is dropped (null).
 */
final class CampsiteOsmMapper
{
    public const CATEGORY_CAMPSITE = 'campsite';
    public const CATEGORY_BASIC = 'basic';

    private const CAMP_SITE_LABELS = [
        'basic' => 'einfach',
        'standard' => 'Standard',
        'serviced' => 'mit Service',
        'deluxe' => 'Luxus',
    ];

    /** Facility tags => word, listed under "Ausstattung" when the tag says yes. */
    private const FACILITIES = [
        'toilets' => 'Toiletten',
        'shower' => 'Duschen',
        'drinking_water' => 'Trinkwasser',
        'power_supply' => 'Strom',
        'internet_access' => 'Internet',
        'washing_machine' => 'Waschmaschine',
        'sanitary_dump_station' => 'Entsorgungsstation',
        'shop' => 'Laden',
        'swimming_pool' => 'Schwimmbad',
        'playground' => 'Spielplatz',
    ];

    /**
     * @param array<string,mixed> $element
     * @return array<string,mixed>|null
     */
    public function map(array $element, string $country, ?CampsiteSurroundings $surroundings = null): ?array
    {
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        $type = (string) ($element['type'] ?? '');
        $id = $element['id'] ?? null;
        $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
        $lng = $element['lon'] ?? ($element['center']['lon'] ?? null);
        if (!in_array($type, ['node', 'way', 'relation'], true) || !is_int($id) || !is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        $category = self::category($tags);
        $place = self::tag($tags, 'addr:city') ?: self::tag($tags, 'addr:place');
        $name = self::tag($tags, 'name');
        if ($name === '') {
            $place = $place ?: ($surroundings?->placeNear((float) $lat, (float) $lng) ?? '');
            $street = self::tag($tags, 'addr:street') ?: ($surroundings?->streetNear((float) $lat, (float) $lng) ?? '');
            $name = self::generatedName($category, $place, $street);
        }

        return [
            'source' => 'osm',
            'source_ref' => $type . '/' . $id,
            'name' => $name,
            'category' => $category,
            'country' => strtoupper($country),
            'place' => $place,
            'description' => self::description($tags),
            'website' => ClubOsmMapper::website($tags),
            'tents' => self::yesNo($tags['tents'] ?? null),
            'caravans' => self::caravans($tags),
            'cabins' => self::yesNo($tags['cabins'] ?? null) ?? self::yesNo($tags['static_caravans'] ?? null),
            'latitude' => round((float) $lat, 7),
            'longitude' => round((float) $lng, 7),
        ];
    }

    /**
     * Name of a site without one: "Campingplatz|Zeltplatz {town}, {street}",
     * either part alone if the other is unknown (or the street is named like
     * the town), "Campsite" without both.
     */
    public static function generatedName(string $category, string $place, string $street): string
    {
        if (mb_strtolower($street) === mb_strtolower($place)) {
            $street = '';
        }
        $where = implode(', ', array_filter([$place, $street], fn (string $part) => $part !== ''));
        if ($where === '') {
            return 'Campsite';
        }

        return mb_substr(($category === self::CATEGORY_BASIC ? 'Zeltplatz ' : 'Campingplatz ') . $where, 0, 255);
    }

    /** @param array<string,mixed> $tags */
    public static function category(array $tags): string
    {
        return strtolower(self::tag($tags, 'camp_site')) === 'basic' || strtolower(self::tag($tags, 'backcountry')) === 'yes'
            ? self::CATEGORY_BASIC
            : self::CATEGORY_CAMPSITE;
    }

    /** 1 = yes, 0 = no, null = not tagged or anything else. */
    private static function yesNo(mixed $value): ?int
    {
        return match (strtolower(trim((string) $value))) {
            'yes' => 1,
            'no' => 0,
            default => null,
        };
    }

    /** @param array<string,mixed> $tags caravans or motorhomes: yes if either is yes */
    private static function caravans(array $tags): ?int
    {
        $caravans = self::yesNo($tags['caravans'] ?? null);
        $motorhome = self::yesNo($tags['motorhome'] ?? null);
        if ($caravans === 1 || $motorhome === 1) {
            return 1;
        }

        return $caravans ?? $motorhome;
    }

    /** @param array<string,mixed> $tags */
    private static function tag(array $tags, string $key): string
    {
        return trim((string) ($tags[$key] ?? ''));
    }

    /**
     * Facts worth showing, one per line: category, stars, what is allowed,
     * facilities, pitches, fee, opening hours, contact, operator, address and
     * the mapper's own description.
     *
     * @param array<string,mixed> $tags
     */
    private static function description(array $tags): string
    {
        $lines = [];
        $campSite = strtolower(self::tag($tags, 'camp_site'));
        if (isset(self::CAMP_SITE_LABELS[$campSite])) {
            $lines[] = 'Kategorie: ' . self::CAMP_SITE_LABELS[$campSite];
        }
        if (preg_match('/^[1-5]S?$/', self::tag($tags, 'stars'))) {
            $lines[] = 'Sterne: ' . self::tag($tags, 'stars');
        }

        $allowed = [];
        foreach (['tents' => 'Zelte', 'caravans' => 'Wohnwagen', 'motorhome' => 'Wohnmobile', 'cabins' => 'Hütten'] as $key => $label) {
            $value = self::yesNo($tags[$key] ?? null);
            if ($value !== null) {
                $allowed[] = $label . ': ' . ($value === 1 ? 'ja' : 'nein');
            }
        }
        if ($allowed !== []) {
            $lines[] = implode(' · ', $allowed);
        }

        $facilities = [];
        foreach (self::FACILITIES as $key => $label) {
            $value = strtolower(self::tag($tags, $key));
            if ($value !== '' && $value !== 'no') {
                $facilities[] = $label;
            }
        }
        if ($facilities !== []) {
            $lines[] = 'Ausstattung: ' . implode(', ', $facilities);
        }

        if (ctype_digit(self::tag($tags, 'capacity'))) {
            $lines[] = 'Stellplätze: ' . self::tag($tags, 'capacity');
        }
        $fee = strtolower(self::tag($tags, 'fee'));
        if ($fee === 'no') {
            $lines[] = 'Kostenlos';
        } elseif ($fee === 'yes') {
            $lines[] = 'Gebührenpflichtig';
        }
        $openingHours = self::tag($tags, 'opening_hours');
        if ($openingHours !== '') {
            $lines[] = 'Öffnungszeiten: ' . mb_substr($openingHours, 0, 100);
        }
        $phone = self::tag($tags, 'phone') ?: self::tag($tags, 'contact:phone');
        if ($phone !== '') {
            $lines[] = 'Telefon: ' . mb_substr(trim(explode(';', $phone)[0]), 0, 40);
        }
        $email = self::tag($tags, 'email') ?: self::tag($tags, 'contact:email');
        if ($email !== '' && filter_var(trim(explode(';', $email)[0]), FILTER_VALIDATE_EMAIL)) {
            $lines[] = 'E-Mail: ' . trim(explode(';', $email)[0]);
        }
        $operator = self::tag($tags, 'operator');
        if ($operator !== '') {
            $lines[] = 'Betreiber: ' . mb_substr($operator, 0, 100);
        }
        $address = self::address($tags);
        if ($address !== '') {
            $lines[] = 'Adresse: ' . $address;
        }
        $text = self::tag($tags, 'description') ?: self::tag($tags, 'description:de') ?: self::tag($tags, 'description:en');
        if ($text !== '') {
            $lines[] = mb_substr($text, 0, 300);
        }

        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $tags "Street 1, 12345 Town" from the addr:* tags, '' without a town */
    private static function address(array $tags): string
    {
        $town = self::tag($tags, 'addr:city') ?: self::tag($tags, 'addr:place');
        if ($town === '') {
            return '';
        }
        $street = trim(self::tag($tags, 'addr:street') . ' ' . self::tag($tags, 'addr:housenumber'));
        $postTown = trim(self::tag($tags, 'addr:postcode') . ' ' . $town);

        return mb_substr($street === '' ? $postTown : $street . ', ' . $postTown, 0, 200);
    }
}
