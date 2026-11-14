<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * Tide predictions of the Danish Meteorological Institute (DMI open data,
 * CC BY 4.0, no key): the oceanObs API's collections tidewaterstation (220
 * gauges) and tidewater (predictions of this and next year: 10-minute values
 * plus predictionType maximum/minimum = high/low water, cm). Checked
 * 2026-11-14: a filter on predictionType takes one value only, so high and
 * low water are two requests.
 */
final class DmiTideSource extends GaugeTideSource
{
    public const BASE_URL = 'https://opendataapi.dmi.dk/v2/oceanObs/collections';

    protected function key(): string
    {
        return 'dmi';
    }

    protected function attribution(): string
    {
        return '©DMI';
    }

    protected function box(): array
    {
        return [54.4, 7.8, 58.0, 15.3];
    }

    protected function fetchGauges(): ?array
    {
        $body = $this->http->get(self::BASE_URL . '/tidewaterstation/items?limit=1000');
        $json = $body === null ? null : json_decode($body, true);
        if (!is_array($json['features'] ?? null)) {
            return null;
        }

        return self::parseGauges($json);
    }

    /**
     * @param array<string, mixed> $json GeoJSON of tidewaterstation
     * @return list<array{id: string, name: string, lat: float, lng: float}>
     */
    public static function parseGauges(array $json): array
    {
        $gauges = [];
        foreach ((array) ($json['features'] ?? []) as $feature) {
            $id = $feature['properties']['stationId'] ?? null;
            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (!is_scalar($id) || !is_array($coordinates) || count($coordinates) < 2) {
                continue;
            }
            $gauges[] = [
                'id' => (string) $id,
                'name' => (string) ($feature['properties']['name'] ?? $id),
                'lat' => (float) $coordinates[1],
                'lng' => (float) $coordinates[0],
            ];
        }

        return $gauges;
    }

    protected function fetchEvents(array $gauge, int $from, int $to): ?array
    {
        $events = [];
        foreach (['maximum' => 'HW', 'minimum' => 'NW'] as $type => $label) {
            $url = self::BASE_URL . '/tidewater/items?' . http_build_query([
                'stationId' => $gauge['id'],
                'datetime' => gmdate('Y-m-d\TH:i:s\Z', $from) . '/' . gmdate('Y-m-d\TH:i:s\Z', $to),
                'predictionType' => $type,
                'limit' => 10000,
            ]);
            $body = $this->http->get($url);
            $json = $body === null ? null : json_decode($body, true);
            if (!is_array($json['features'] ?? null)) {
                return null;
            }
            array_push($events, ...self::parseEvents($json, $label));
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $json GeoJSON of tidewater
     * @param 'HW'|'NW' $type
     * @return list<array{time: int, type: 'HW'|'NW', height_m: ?float}>
     */
    public static function parseEvents(array $json, string $type): array
    {
        $events = [];
        foreach ((array) ($json['features'] ?? []) as $feature) {
            $time = strtotime((string) ($feature['properties']['predictionTime'] ?? ''));
            $value = $feature['properties']['value'] ?? null;
            if ($time !== false) {
                $events[] = ['time' => $time, 'type' => $type, 'height_m' => is_numeric($value) ? round((float) $value / 100, 2) : null];
            }
        }

        return $events;
    }
}
