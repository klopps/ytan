<?php

declare(strict_types=1);

namespace Ytan\Service\ShelterList;

/**
 * Norway: Kartverket's "Turrutebasen" (tur- og friluftsruter, WFS, open data),
 * feature type RuteInfoPunkt. `tilrettelegging` is a number of the SOSI code
 * list FriluftslivTilrettelegging (product specification 20171210): 45 =
 * Gapahuk, 44 = Hytte ubetjent, 43 = Hytte selvbetjent (42 = staffed cabins,
 * left out). The points carry no name; `opphav` (origin) often holds the
 * cabin's name.
 */
final class KartverketShelterSource implements ShelterSource
{
    public const WFS_URL = 'https://wfs.geonorge.no/skwms1/wfs.turogfriluftsruter';
    private const APP_NS = 'http://skjema.geonorge.no/SOSI/produktspesifikasjon/TurOgFriluftsruter/20171210';

    /** Code => [kind, label]. */
    private const TYPES = [
        '45' => ['shelter', 'Gapahuk'],
        '44' => ['hut', 'Hytte ubetjent'],
        '43' => ['hut', 'Hytte selvbetjent'],
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
        return 'kv';
    }

    public function fetch(): array
    {
        return self::parse(($this->fetch)(self::requestUrl()));
    }

    public static function requestUrl(): string
    {
        $equals = '';
        foreach (array_keys(self::TYPES) as $code) {
            $equals .= '<fes:PropertyIsEqualTo><fes:ValueReference>app:tilrettelegging</fes:ValueReference><fes:Literal>' . $code . '</fes:Literal></fes:PropertyIsEqualTo>';
        }

        return self::WFS_URL . '?' . http_build_query([
            'service' => 'WFS',
            'version' => '2.0.0',
            'request' => 'GetFeature',
            'typeNames' => 'app:RuteInfoPunkt',
            'srsName' => 'urn:ogc:def:crs:EPSG::4326',
            'filter' => '<fes:Filter xmlns:fes="http://www.opengis.net/fes/2.0" xmlns:app="' . self::APP_NS . '"><fes:Or>' . $equals . '</fes:Or></fes:Filter>',
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function parse(string $gml): array
    {
        $entries = [];
        ShelterHttp::eachGmlFeature($gml, 'Kartverket', function (\SimpleXMLElement $f, float $lat, float $lng) use (&$entries): void {
            $code = ShelterHttp::text($f, 'tilrettelegging');
            $id = ShelterHttp::text($f, 'lokalId');
            if (!isset(self::TYPES[$code]) || $id === '') {
                return;
            }
            [$kind, $label] = self::TYPES[$code];

            $lines = ['Art: ' . $label];
            $responsible = ShelterHttp::text($f, 'vedlikeholdsansvarlig');
            if ($responsible !== '' && $responsible !== 'Andre') {
                $lines[] = 'Zuständig: ' . $responsible;
            }

            $entries[$id] = [
                'source' => 'kv',
                'source_ref' => $id,
                'name' => ShelterHttp::text($f, 'opphav'),
                'type_label' => $code === '45' ? 'Gapahuk' : 'Hytte',
                'kind' => $kind,
                'country' => 'NO',
                'description' => implode("\n", $lines),
                'website' => '',
                'latitude' => round($lat, 7),
                'longitude' => round($lng, 7),
            ];
        });

        return array_values($entries);
    }
}
