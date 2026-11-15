<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * Tide predictions of the Instituto Hidrográfico (Portuguese Navy) through
 * the internal API behind hidrografico.pt ("hmapi", found via the unofficial
 * Home Assistant integration hass-hidrograficopt, checked 2026-11-15). Not
 * documented and without a stated licence - used on the user's explicit
 * decision (2026-11-15); it may change or disappear without notice, then the
 * Routencheck falls back to Open-Meteo.
 *
 * GET /hmapi/tides/ = GeoJSON of 43 stations (mainland, Azores, Madeira and
 * former territories; CODP = id, PORTO = name); GET /hmapi/tidestation/
 * ?portID=CODP&period=7 = events from yesterday for at most seven days
 * (a larger period gives the same), tide PM (preia-mar = HW) / BM
 * (baixa-mar = LW), date "Y-m-d H:i:s" in UTC ("Fuso 0"), height in metres;
 * moon phase rows have tide null and are skipped.
 */
final class IhTideSource extends GaugeTideSource
{
    public const BASE_URL = 'https://www.hidrografico.pt/hmapi';
    protected const WINDOW_DAYS = 7;

    protected function key(): string
    {
        return 'ih';
    }

    protected function attribution(): string
    {
        return 'Instituto Hidrográfico';
    }

    protected function box(): array
    {
        // mainland Portugal, Madeira and the Azores
        return [30.0, -31.5, 42.2, -6.1];
    }

    protected function fetchGauges(): ?array
    {
        $body = $this->http->get(self::BASE_URL . '/tides/');
        $json = $body === null ? null : json_decode($body, true);
        if (!is_array($json['features'] ?? null)) {
            return null;
        }

        return self::parseGauges($json);
    }

    /**
     * @param array<string, mixed> $json GeoJSON of /tides/
     * @return list<array{id: string, name: string, lat: float, lng: float}>
     */
    public static function parseGauges(array $json): array
    {
        $gauges = [];
        foreach ((array) ($json['features'] ?? []) as $feature) {
            $id = $feature['properties']['CODP'] ?? null;
            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (!is_int($id) || !is_array($coordinates) || count($coordinates) < 2) {
                continue;
            }
            $gauges[] = [
                'id' => (string) $id,
                'name' => (string) ($feature['properties']['PORTO'] ?? $id),
                'lat' => (float) $coordinates[1],
                'lng' => (float) $coordinates[0],
            ];
        }

        return $gauges;
    }

    protected function fetchEvents(array $gauge, int $from, int $to): ?array
    {
        $body = $this->http->get(self::BASE_URL . '/tidestation/?' . http_build_query(['portID' => $gauge['id'], 'period' => self::WINDOW_DAYS]));
        $json = $body === null ? null : json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }

        return self::parseEvents($json);
    }

    /**
     * @param array<int, mixed> $json /tidestation/ answer
     * @return list<array{time: int, type: 'HW'|'NW', height_m: ?float}>
     */
    public static function parseEvents(array $json): array
    {
        $events = [];
        foreach ($json as $row) {
            $tide = is_array($row) ? strtoupper((string) ($row['tide'] ?? '')) : '';
            if ($tide !== 'PM' && $tide !== 'BM') {
                continue;
            }
            $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr((string) ($row['date'] ?? ''), 0, 19), new \DateTimeZone('UTC'));
            if ($time === false) {
                continue;
            }
            $height = $row['height'] ?? null;
            $events[] = ['time' => $time->getTimestamp(), 'type' => $tide === 'PM' ? 'HW' : 'NW', 'height_m' => is_numeric($height) ? round((float) $height, 2) : null];
        }
        usort($events, static fn ($a, $b) => $a['time'] <=> $b['time']);

        return $events;
    }
}
