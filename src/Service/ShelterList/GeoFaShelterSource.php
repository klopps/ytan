<?php

declare(strict_types=1);

namespace Ytan\Service\ShelterList;

use Ytan\Exception\ApiException;

/**
 * Denmark: GeoFA, the shared outdoor database of the Danish state and
 * municipalities behind udinaturen.dk (GeoDanmark), table
 * fkg.t_5800_fac_pkt via its public SQL API (GeoJSON answer; the API adds
 * `off_kode = 1` - "visible to everybody" - to every query itself). Facility
 * types: 3012 Shelter, 3022 canoe/kayak overnight place, 3091 Baalhytte; only
 * status 3 ("Gaeldende / Vedtaget"). bookinglink, else link, becomes the POI's
 * link - many shelters are booked one by one.
 */
final class GeoFaShelterSource implements ShelterSource
{
    public const API_URL = 'https://geofa.geodanmark.dk/api/v2/sql/fkg';

    /** Facility type code => [kind, label]. */
    private const TYPES = [
        3012 => ['shelter', 'Shelter'],
        3022 => ['shelter', 'Kano-/Kajak-Übernachtungsplatz'],
        3091 => ['shelter', 'Bålhytte (überdachte Feuerstelle)'],
    ];

    /** Facility type => the word used in a generated name ("Shelter Mols Bjerge"). */
    private const NAME_LABELS = [3012 => 'Shelter', 3022 => 'Kanu-/Kajak-Lagerplatz', 3091 => 'Bålhytte'];

    /** @var callable(string):string */
    private $fetch;

    /** @param (callable(string):string)|null $fetch URL => response body, injectable for tests */
    public function __construct(?callable $fetch = null)
    {
        $this->fetch = $fetch ?? [ShelterHttp::class, 'get'];
    }

    public function code(): string
    {
        return 'geofa';
    }

    public function fetch(): array
    {
        return self::parse(($this->fetch)(self::requestUrl()));
    }

    public static function requestUrl(): string
    {
        $point = 'ST_Transform(ST_PointOnSurface(geometri), 4326)';

        return self::API_URL . '?' . http_build_query(['q' =>
            'select objekt_id, facil_ty_k, navn, beskrivels, link, bookinglink, antal_pl, betaling, book, cvr_navn, '
            . "ST_Y($point) as lat, ST_X($point) as lng from fkg.t_5800_fac_pkt "
            . 'where facil_ty_k in (' . implode(',', array_keys(self::TYPES)) . ') and statuskode = 3',
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['success'] ?? false) !== true || !is_array($data['features'] ?? null)) {
            throw new ApiException('The GeoFA service did not answer as expected.', 502, 'shelter_import.source_bad_answer');
        }

        $entries = [];
        foreach ($data['features'] as $feature) {
            $p = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $id = trim((string) ($p['objekt_id'] ?? ''));
            $type = (int) ($p['facil_ty_k'] ?? 0);
            if ($id === '' || !isset(self::TYPES[$type]) || !is_numeric($p['lat'] ?? null) || !is_numeric($p['lng'] ?? null)) {
                continue;
            }
            [$kind, $label] = self::TYPES[$type];

            $lines = ['Art: ' . $label];
            if (is_numeric($p['antal_pl'] ?? null) && (int) $p['antal_pl'] > 0) {
                $lines[] = 'Plätze: ' . (int) $p['antal_pl'];
            }
            if (($p['book'] ?? '') === 'Ja') {
                $lines[] = 'Buchung erforderlich';
            }
            if (($p['betaling'] ?? '') === 'Ja') {
                $lines[] = 'Gebührenpflichtig';
            } elseif (($p['betaling'] ?? '') === 'Nej') {
                $lines[] = 'Kostenlos';
            }
            $text = trim((string) ($p['beskrivels'] ?? ''));
            if ($text !== '') {
                $lines[] = mb_substr($text, 0, 400);
            }
            $owner = trim((string) ($p['cvr_navn'] ?? ''));
            if ($owner !== '') {
                $lines[] = 'Träger: ' . $owner;
            }

            $entries[$id] = [
                'source' => 'geofa',
                'source_ref' => $id,
                'name' => trim((string) ($p['navn'] ?? '')),
                'type_label' => self::NAME_LABELS[$type] ?? 'Shelter',
                'kind' => $kind,
                'country' => 'DK',
                'description' => implode("\n", $lines),
                'website' => ShelterHttp::url($p['bookinglink'] ?? null) ?: ShelterHttp::url($p['link'] ?? null),
                'latitude' => round((float) $p['lat'], 7),
                'longitude' => round((float) $p['lng'], 7),
            ];
        }

        return array_values($entries);
    }
}
