<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * Tide predictions of Rijkswaterstaat (Netherlands, open data, no key): the
 * "DD-API 2.0" waterwebservices (the old waterwebservices.rijkswaterstaat.nl
 * redirects to a notice since the switch - checked 2026-11-14). The gauges
 * are the catalogue's locations with water height (WATHTE, surface water OW)
 * grouped "Getijextreem berekend" (GETETBRKD2, astronomical high/low water,
 * cm above NAP) - 101 of them, also up the Scheldt to Antwerp and the
 * Rotterdam rivers; the extremes come without a type (GaugeTideSource::typed()).
 */
final class RwsTideSource extends GaugeTideSource
{
    public const CATALOGUE_URL = 'https://ddapi20-waterwebservices.rijkswaterstaat.nl/METADATASERVICES/OphalenCatalogus';
    public const OBSERVATIONS_URL = 'https://ddapi20-waterwebservices.rijkswaterstaat.nl/ONLINEWAARNEMINGENSERVICES/OphalenWaarnemingen';
    private const GROUPING = 'GETETBRKD2';

    protected function key(): string
    {
        return 'rws';
    }

    protected function attribution(): string
    {
        return 'Rijkswaterstaat';
    }

    protected function box(): array
    {
        return [50.7, 2.5, 53.8, 7.3];
    }

    protected function fetchGauges(): ?array
    {
        $body = $this->http->postJson(self::CATALOGUE_URL, ['CatalogusFilter' => ['Grootheden' => true, 'Groeperingen' => true, 'Compartimenten' => true]]);
        $json = $body === null ? null : json_decode($body, true);
        if (!is_array($json['LocatieLijst'] ?? null)) {
            return null;
        }

        return self::parseGauges($json);
    }

    /**
     * @param array<string, mixed> $catalogue OphalenCatalogus answer
     * @return list<array{id: string, name: string, lat: float, lng: float}>
     */
    public static function parseGauges(array $catalogue): array
    {
        $metadata = [];
        foreach ((array) ($catalogue['AquoMetadataLijst'] ?? []) as $entry) {
            if (($entry['Grootheid']['Code'] ?? null) === 'WATHTE' && ($entry['Groepering']['Code'] ?? null) === self::GROUPING
                && in_array($entry['Compartiment']['Code'] ?? 'OW', ['OW', ''], true)) {
                $metadata[(int) $entry['AquoMetadata_MessageID']] = true;
            }
        }
        $locationIds = [];
        foreach ((array) ($catalogue['AquoMetadataLocatieLijst'] ?? []) as $link) {
            if (isset($metadata[(int) ($link['AquoMetaData_MessageID'] ?? $link['AquoMetadata_MessageID'] ?? 0)])) {
                $locationIds[(int) $link['Locatie_MessageID']] = true;
            }
        }
        $gauges = [];
        foreach ((array) ($catalogue['LocatieLijst'] ?? []) as $location) {
            if (isset($locationIds[(int) ($location['Locatie_MessageID'] ?? 0)]) && isset($location['Code'], $location['Lat'], $location['Lon'])) {
                $gauges[] = [
                    'id' => (string) $location['Code'],
                    'name' => (string) ($location['Naam'] ?? $location['Code']),
                    'lat' => (float) $location['Lat'],
                    'lng' => (float) $location['Lon'],
                ];
            }
        }

        return $gauges;
    }

    protected function fetchEvents(array $gauge, int $from, int $to): ?array
    {
        $body = $this->http->postJson(self::OBSERVATIONS_URL, [
            'Locatie' => ['Code' => $gauge['id']],
            'AquoPlusWaarnemingMetadata' => ['AquoMetadata' => [
                'Compartiment' => ['Code' => 'OW'],
                'Grootheid' => ['Code' => 'WATHTE'],
                'Groepering' => ['Code' => self::GROUPING],
            ]],
            'Periode' => [
                'Begindatumtijd' => gmdate('Y-m-d\TH:i:s.000+00:00', $from),
                'Einddatumtijd' => gmdate('Y-m-d\TH:i:s.000+00:00', $to),
            ],
        ]);
        $json = $body === null ? null : json_decode($body, true);
        if (!is_array($json) || !($json['Succesvol'] ?? false)) {
            return null;
        }

        return self::parseEvents($json);
    }

    /**
     * @param array<string, mixed> $json OphalenWaarnemingen answer
     * @return list<array{time: int, type: 'HW'|'NW', height_m: ?float}>
     */
    public static function parseEvents(array $json): array
    {
        $extremes = [];
        foreach ((array) ($json['WaarnemingenLijst'] ?? []) as $series) {
            foreach ((array) ($series['MetingenLijst'] ?? []) as $measurement) {
                $time = strtotime((string) ($measurement['Tijdstip'] ?? ''));
                $value = $measurement['Meetwaarde']['Waarde_Numeriek'] ?? null;
                // 999999999 = no value
                if ($time !== false && is_numeric($value) && abs((float) $value) < 10000) {
                    $extremes[] = [$time, (float) $value / 100];
                }
            }
        }
        usort($extremes, static fn ($a, $b) => $a[0] <=> $b[0]);

        return self::typed($extremes);
    }
}
