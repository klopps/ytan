<?php

declare(strict_types=1);

namespace Ytan\Service\ShelterList;

/**
 * Sweden: the Naturvardsverket's open data "Leder och friluftsanordningar"
 * (WFS, CC0) - the facility types Vindskydd, Rastskydd, Raststuga and
 * Overnattningsstuga in protected areas and along state trails. The service
 * only speaks GML.
 */
final class NvvShelterSource implements ShelterSource
{
    public const WFS_URL = 'https://geodata.naturvardsverket.se/anordningar_friluftsliv/wfs';

    /** Facility type => kind of the staged entry. */
    private const TYPES = [
        'Vindskydd' => 'shelter',
        'Rastskydd' => 'shelter',
        'Raststuga' => 'hut',
        'Övernattningsstuga' => 'hut',
    ];

    /** @var callable(string):string */
    private $fetch;

    /** @param (callable(string):string)|null $fetch URL => response body, injectable for tests */
    public function __construct(?callable $fetch = null)
    {
        $this->fetch = $fetch ?? [ShelterHttp::class, 'get'];
    }

    public function code(): string
    {
        return 'nvv';
    }

    public function fetch(): array
    {
        return self::parse(($this->fetch)(self::requestUrl()));
    }

    public static function requestUrl(): string
    {
        $equals = '';
        foreach (array_keys(self::TYPES) as $type) {
            $equals .= '<fes:PropertyIsEqualTo><fes:ValueReference>Typ</fes:ValueReference><fes:Literal>' . $type . '</fes:Literal></fes:PropertyIsEqualTo>';
        }

        return self::WFS_URL . '?' . http_build_query([
            'service' => 'WFS',
            'version' => '2.0.0',
            'request' => 'GetFeature',
            'typeNames' => 'ANORDNINGAR',
            'srsName' => 'urn:ogc:def:crs:EPSG::4326',
            'filter' => '<fes:Filter xmlns:fes="http://www.opengis.net/fes/2.0"><fes:Or>' . $equals . '</fes:Or></fes:Filter>',
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function parse(string $gml): array
    {
        $entries = [];
        ShelterHttp::eachGmlFeature($gml, 'Naturvårdsverket', function (\SimpleXMLElement $f, float $lat, float $lng) use (&$entries): void {
            $type = ShelterHttp::text($f, 'Typ');
            $id = ShelterHttp::text($f, 'Anordning_ID');
            if (!isset(self::TYPES[$type]) || $id === '') {
                return;
            }

            $subtype = ShelterHttp::text($f, 'Undertyp');
            $lines = ['Art: ' . $type . ($subtype !== '' && $subtype !== $type ? ' (' . $subtype . ')' : '')];
            $description = ShelterHttp::text($f, 'Beskrivning');
            if ($description !== '') {
                $lines[] = $description;
            }
            $area = ShelterHttp::text($f, 'Skyddat_område');
            if ($area !== '') {
                $lines[] = 'Gebiet: ' . trim((string) preg_replace('/\s*\(\d+\)\s*$/', '', $area));
            }
            $name = self::name(ShelterHttp::text($f, 'Anordningsnamn'), $type, $area, ShelterHttp::text($f, 'Kommun'));

            $entries[$id] = [
                'source' => 'nvv',
                'source_ref' => $id,
                'name' => $name,
                'type_label' => $type,
                'kind' => self::TYPES[$type],
                'country' => 'SE',
                'description' => implode("\n", $lines),
                'website' => '',
                'latitude' => round($lat, 7),
                'longitude' => round($lng, 7),
            ];
        });

        return array_values($entries);
    }

    /**
     * The entry's own name - or, when it has none or only the facility type
     * ("Vindskydd"), the type plus the first protected area ("Vindskydd
     * Färnebofjärden"), else the type plus the municipality.
     */
    public static function name(string $own, string $type, string $areas, string $municipality): string
    {
        $own = trim($own);
        if ($own !== '' && mb_strtolower($own) !== mb_strtolower($type)) {
            return $own;
        }
        // "Långfjället (2002448), Långfjället-Städjan-Nipfjället (SE0620001)" -> "Långfjället"
        $area = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', explode(',', $areas)[0]));
        $place = $area !== '' ? $area : trim($municipality);

        return $place !== '' ? $type . ' ' . $place : $type;
    }
}
