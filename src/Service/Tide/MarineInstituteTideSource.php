<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * Tide predictions of the Marine Institute (Ireland, CC BY 4.0, no key):
 * the ERDDAP dataset IMI_TidePrediction_HighLow - 38 gauges, high/low water
 * (tide_time_category HIGH/LOW, metres above Ordnance Datum Malin) up to the
 * end of 2028 (checked 2026-11-14). ERDDAP wants < and > percent-encoded in
 * the query, plain ones are refused with HTTP 400.
 */
final class MarineInstituteTideSource extends GaugeTideSource
{
    public const BASE_URL = 'https://erddap.marine.ie/erddap/tabledap/IMI_TidePrediction_HighLow.json';

    protected function key(): string
    {
        return 'imi';
    }

    protected function attribution(): string
    {
        return '©Marine Institute';
    }

    protected function box(): array
    {
        return [51.2, -11.0, 55.5, -5.3];
    }

    protected function fetchGauges(): ?array
    {
        $body = $this->http->get(self::BASE_URL . '?stationID,longitude,latitude&distinct()');
        $json = $body === null ? null : json_decode($body, true);
        if (!is_array($json['table']['rows'] ?? null)) {
            return null;
        }
        $gauges = [];
        foreach (self::rows($json) as $row) {
            if (isset($row['stationID'], $row['latitude'], $row['longitude'])) {
                $gauges[] = [
                    'id' => (string) $row['stationID'],
                    'name' => str_replace('_', ' ', (string) $row['stationID']),
                    'lat' => (float) $row['latitude'],
                    'lng' => (float) $row['longitude'],
                ];
            }
        }

        return $gauges;
    }

    protected function fetchEvents(array $gauge, int $from, int $to): ?array
    {
        $url = self::BASE_URL . '?time,tide_time_category,Water_Level_ODMalin'
            . '&stationID=' . rawurlencode('"' . $gauge['id'] . '"')
            . '&time%3E=' . gmdate('Y-m-d\TH:i:s\Z', $from)
            . '&time%3C=' . gmdate('Y-m-d\TH:i:s\Z', $to);
        $body = $this->http->get($url);
        $json = $body === null ? null : json_decode($body, true);
        if (!is_array($json['table']['rows'] ?? null)) {
            return null;
        }

        return self::parseEvents($json);
    }

    /**
     * @param array<string, mixed> $json ERDDAP .json table
     * @return list<array{time: int, type: 'HW'|'NW', height_m: ?float}>
     */
    public static function parseEvents(array $json): array
    {
        $events = [];
        foreach (self::rows($json) as $row) {
            $time = strtotime((string) ($row['time'] ?? ''));
            $category = strtoupper((string) ($row['tide_time_category'] ?? ''));
            if ($time === false || !in_array($category, ['HIGH', 'LOW'], true)) {
                continue;
            }
            $value = $row['Water_Level_ODMalin'] ?? null;
            $events[] = ['time' => $time, 'type' => $category === 'HIGH' ? 'HW' : 'NW', 'height_m' => is_numeric($value) ? round((float) $value, 2) : null];
        }

        return $events;
    }

    /**
     * ERDDAP's {table: {columnNames, rows}} as associative rows.
     *
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>
     */
    private static function rows(array $json): array
    {
        $names = (array) ($json['table']['columnNames'] ?? []);
        $rows = [];
        foreach ((array) ($json['table']['rows'] ?? []) as $row) {
            if (is_array($row) && count($row) === count($names)) {
                $rows[] = array_combine($names, $row);
            }
        }

        return $rows;
    }
}
