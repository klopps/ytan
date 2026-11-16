<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * Tide predictions of the UK Hydrographic Office (ADMIRALTY UK Tidal API,
 * free "Discovery" tier, key from developer.admiralty.co.uk in .env's
 * UKHO_API_KEY, header Ocp-Apim-Subscription-Key): 608 stations in England,
 * Scotland, Wales and Northern Ireland, high/low water for today and the
 * next six days (checked 2026-11-14; times are UTC).
 *
 * The Discovery tier does NOT allow caching (UKHO FAQ), so nothing is
 * written to TideCache: the station list is kept only for the lifetime of
 * the process, the events are fetched per Routencheck (TideService
 * remembers them for that one request). A date beyond the seven days simply
 * gets no events from it - then the next source (Open-Meteo) is asked.
 */
final class UkhoTideSource implements TideSource
{
    public const BASE_URL = 'https://admiraltyapi.azure-api.net/uktidalapi/api/V1/Stations';
    private const DURATION_DAYS = 7;
    /** Great Britain and Northern Ireland: south, west, north, east */
    private const BOX = [49.8, -8.7, 61.0, 2.0];

    /** @var list<array{id: string, name: string, lat: float, lng: float}>|null */
    private ?array $stations = null;

    public function __construct(private readonly string $apiKey, private readonly TideHttp $http)
    {
    }

    public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
    {
        if ($this->apiKey === '') {
            return null;
        }
        $marginLat = $maxStationMeters / 111320.0;
        $marginLng = $marginLat / max(0.1, cos(deg2rad($lat)));
        if ($lat < self::BOX[0] - $marginLat || $lat > self::BOX[2] + $marginLat || $lng < self::BOX[1] - $marginLng || $lng > self::BOX[3] + $marginLng) {
            return null;
        }
        $stations = $this->stations();
        if ($stations === null) {
            return null;
        }
        $near = [];
        foreach ($stations as $station) {
            $distance = self::haversine($lat, $lng, $station['lat'], $station['lng']);
            if ($distance <= $maxStationMeters) {
                $near[] = $station + ['distance' => $distance];
            }
        }
        usort($near, static fn ($a, $b) => $a['distance'] <=> $b['distance']);

        foreach (array_slice($near, 0, 2) as $station) {
            $body = $this->http->get(self::BASE_URL . '/' . rawurlencode($station['id']) . '/TidalEvents?duration=' . self::DURATION_DAYS, $this->headers());
            $json = $body === null ? null : json_decode($body, true);
            if (!is_array($json)) {
                error_log('YTAN: UKHO tidal events for ' . $station['id'] . ' could not be loaded');
                continue;
            }
            $events = self::parseEvents($json);
            if (!self::alternates($events)) {
                // secondary ports like Chepstow list only high waters - no tidal phase without the lows
                continue;
            }

            return [
                'station' => $station['name'],
                'source' => 'ukho',
                'attribution' => '©Crown copyright, UKHO',
                'lat' => $station['lat'],
                'lng' => $station['lng'],
                'distance_m' => (int) round($station['distance']),
                'mean_range_m' => GaugeTideSource::meanRange($events),
                'events' => BshTideSource::window($events, $from, $to),
            ];
        }

        return null;
    }

    /**
     * Whether high and low waters take turns (found 2026-11-14: Chepstow
     * answers high waters only, the phase model needs both).
     *
     * @param list<array{time: int, type: string, height_m: ?float}> $events
     */
    public static function alternates(array $events): bool
    {
        if (count($events) < 2) {
            return false;
        }
        for ($i = 1, $n = count($events); $i < $n; $i++) {
            if ($events[$i]['type'] === $events[$i - 1]['type']) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{id: string, name: string, lat: float, lng: float}>|null */
    private function stations(): ?array
    {
        if ($this->stations !== null) {
            return $this->stations;
        }
        $body = $this->http->get(self::BASE_URL, $this->headers());
        $json = $body === null ? null : json_decode($body, true);
        if (!is_array($json['features'] ?? null)) {
            error_log('YTAN: UKHO station list could not be loaded');

            return null;
        }

        return $this->stations = self::parseStations($json);
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['Ocp-Apim-Subscription-Key: ' . $this->apiKey, 'Accept: application/json'];
    }

    /**
     * @param array<string, mixed> $json GeoJSON of /Stations
     * @return list<array{id: string, name: string, lat: float, lng: float}>
     */
    public static function parseStations(array $json): array
    {
        $stations = [];
        foreach ((array) ($json['features'] ?? []) as $feature) {
            $id = $feature['properties']['Id'] ?? null;
            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (!is_string($id) || !is_array($coordinates) || count($coordinates) < 2) {
                continue;
            }
            $stations[] = [
                'id' => $id,
                'name' => (string) ($feature['properties']['Name'] ?? $id),
                'lat' => (float) $coordinates[1],
                'lng' => (float) $coordinates[0],
            ];
        }

        return $stations;
    }

    /**
     * @param array<int, mixed> $json /TidalEvents answer
     * @return list<array{time: int, type: 'HW'|'NW', height_m: ?float}>
     */
    public static function parseEvents(array $json): array
    {
        $events = [];
        foreach ($json as $event) {
            $type = $event['EventType'] ?? null;
            if (!is_array($event) || !in_array($type, ['HighWater', 'LowWater'], true)) {
                continue;
            }
            // "2026-11-14T04:02:00", no zone: UTC
            $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', substr((string) ($event['DateTime'] ?? ''), 0, 19), new \DateTimeZone('UTC'));
            if ($time === false) {
                continue;
            }
            $height = $event['Height'] ?? null;
            $events[] = ['time' => $time->getTimestamp(), 'type' => $type === 'HighWater' ? 'HW' : 'NW', 'height_m' => is_numeric($height) ? round((float) $height, 2) : null];
        }
        usort($events, static fn ($a, $b) => $a['time'] <=> $b['time']);

        return $events;
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return \Ytan\Service\GeometryService::distanceMeters($lat1, $lng1, $lat2, $lng2);
    }
}
