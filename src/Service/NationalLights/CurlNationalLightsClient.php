<?php

declare(strict_types=1);

namespace Ytan\Service\NationalLights;

use Ytan\Exception\NationalLightsException;
use Ytan\Service\Http\HttpFetch;

/**
 * Real NationalLightsClient - plain curl GETs of two WFS 2.0 services (checked 2026-11-22):
 *
 *  - Finland, Väylävirasto (CC BY 4.0, no key): GeoServer at avoinapi.vaylapilvi.fi/vaylatiedot/ows, JSON with
 *    srsName=EPSG:4326 (coordinates [lon, lat]). Layers vesivaylatiedot:turvalaitteet_vaylavirasto (the aids, names;
 *    asked with a CQL filter for the lighthouse types and only the columns used - the whole layer is 11 MB),
 *    :loistot_uusi (every light, 3 MB) and :valosektorit_uusi (every sector, 2 MB).
 *  - Norway, Kystverket (NLOD 2.0): ArcGIS at maps.kystverket.cloudgis.no (new URL since 2026-02-03), GML.
 *    GeoJSON is useless there: with `propertyName` the geometry comes out as "Point",} - broken JSON. Layer Lys is
 *    asked with an OGC filter for the classes FS and FL and only the columns used (the whole layer is 33 MB), layer
 *    Sektorflate with no geometry (the polygons are most of its 33 MB). srsName urn:ogc:def:crs:EPSG::4326 gives
 *    `<gml:pos>lat lon</gml:pos>` (latitude first; plain EPSG:4326 would too, but the URN is unambiguous).
 */
final class CurlNationalLightsClient implements NationalLightsClient
{
    public const FINLAND = 'https://avoinapi.vaylapilvi.fi/vaylatiedot/ows';
    public const NORWAY = 'https://maps.kystverket.cloudgis.no/enterprise/services/PROD/nfs_sistop_ekstern_prod/MapServer/WFSServer';

    /** Types of Väylävirasto's navigation aids that are lighthouses of some kind (Merimajakka = sea lighthouse, ...). */
    public const FINNISH_TYPES = ['Merimajakka', 'Tunnusmajakka', 'Sektoriloisto', 'Apuloisto'];
    /** Kystverket's classes: FS = lighthouse station (Fyrstasjon), FL = lighthouse lantern (Fyrlykt, the fairway sector lights). */
    public const NORWEGIAN_CLASSES = ['FS - Fyrstasjon', 'FL - Fyrlykt'];

    public function __construct(
        private readonly string $finland = self::FINLAND,
        private readonly string $norway = self::NORWAY,
        private readonly int $timeoutSeconds = 180,
    ) {
    }

    public function fetchFinland(): array
    {
        $list = fn (string $layer, array $extra = []): array => $this->finnishFeatures(array_merge([
            'service' => 'WFS', 'version' => '2.0.0', 'request' => 'GetFeature',
            'typeNames' => 'vesivaylatiedot:' . $layer, 'outputFormat' => 'application/json', 'srsName' => 'EPSG:4326',
        ], $extra));

        $types = implode(',', array_map(fn ($type) => "'" . $type . "'", self::FINNISH_TYPES));

        return [
            'aids' => $list('turvalaitteet_vaylavirasto', [
                'propertyName' => 'turvalaitenumero,nimifi,nimisv,sijaintifi,turvalaitetyyppifi,toimintatilakoodi',
                'CQL_FILTER' => "turvalaitetyyppifi IN ($types)",
            ]),
            'lights' => $list('loistot_uusi'),
            'sectors' => $list('valosektorit_uusi'),
        ];
    }

    public function fetchNorway(): array
    {
        $get = fn (array $params): array => GmlFeatures::parse($this->get($this->norway, array_merge([
            'service' => 'WFS', 'version' => '2.0.0', 'request' => 'GetFeature', 'srsName' => 'urn:ogc:def:crs:EPSG::4326',
        ], $params)));

        $classes = '';
        foreach (self::NORWEGIAN_CLASSES as $class) {
            $classes .= '<fes:PropertyIsEqualTo><fes:ValueReference>klasse</fes:ValueReference><fes:Literal>'
                . htmlspecialchars($class, ENT_XML1) . '</fes:Literal></fes:PropertyIsEqualTo>';
        }

        $lights = [];
        foreach ($get([
            'typeNames' => 'nfs_sistop_ekstern_prod:Lys',
            'propertyName' => 'objectid,globalid,sjomerke_nr,navn,sted,beliggenhet,klasse,karakter,beskrivelse,lys_hoyde,'
                . 'rekkevidde_hvit,rekkevidde_rod,rekkevidde_gronn,rekkevidde_gul,nfs_status,shape',
            'filter' => '<fes:Filter xmlns:fes="http://www.opengis.net/fes/2.0"><fes:Or>' . $classes . '</fes:Or></fes:Filter>',
        ]) as $row) {
            $position = preg_split('/\s+/', $row['shape_pos'] ?? '');
            if (count($position) < 2 || !is_numeric($position[0]) || !is_numeric($position[1])) {
                continue;
            }
            unset($row['shape_pos']);
            $lights[] = $row + ['lat' => (float) $position[0], 'lon' => (float) $position[1]];
        }

        $sectors = $get([
            'typeNames' => 'nfs_sistop_ekstern_prod:Sektorflate',
            'propertyName' => 'fknavinst,lys_farge,retning_sektorlinje_1,retning_sektorlinje_2,sektor_nr',
        ]);

        return ['lights' => $lights, 'sectors' => $sectors];
    }

    /**
     * @param array<string,string> $params
     * @return list<array<string,mixed>> the features' attributes, a point geometry as lat/lon
     */
    private function finnishFeatures(array $params): array
    {
        $decoded = json_decode($this->get($this->finland, $params), true);
        if (!is_array($decoded) || !is_array($decoded['features'] ?? null)) {
            throw new NationalLightsException('Väylävirasto answered with something that is not a list of features.');
        }

        $rows = [];
        foreach ($decoded['features'] as $feature) {
            $row = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (is_array($coordinates) && count($coordinates) >= 2 && is_numeric($coordinates[0]) && is_numeric($coordinates[1])) {
                $row['lon'] = (float) $coordinates[0];
                $row['lat'] = (float) $coordinates[1];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string,string> $params */
    private function get(string $endpoint, array $params): string
    {
        ['status' => $status, 'body' => $body, 'error' => $error] = HttpFetch::request(
            $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            [],
            15,
            $this->timeoutSeconds
        );

        if ($body === null || $status !== 200) {
            throw new NationalLightsException('The lights service is not available (' . ($status > 0 ? "HTTP $status" : ($error !== '' ? $error : 'no answer')) . ').');
        }

        return $body;
    }
}
