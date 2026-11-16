<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * A national tide source with a list of gauges (DMI, Rijkswaterstaat, Marine
 * Institute): the nearest gauge within $maxStationMeters, its high and low
 * waters for a window of WINDOW_DAYS from the day before the request (so
 * the phase at the start is known), both cached in TideCache. The mean
 * tidal range is the mean of the events' high-low differences.
 *
 * A subclass names its coverage box (no gauge list is loaded for a position
 * far outside it), its source key and attribution, and fetches the gauge
 * list and one gauge's events.
 */
abstract class GaugeTideSource implements TideSource
{
    protected const WINDOW_DAYS = 35;
    private const MAX_GAUGES_TRIED = 3;

    public function __construct(protected readonly TideCache $cache, protected readonly TideHttp $http)
    {
    }

    /** 'dmi', 'rws', ... (also the cache file prefix) */
    abstract protected function key(): string;

    abstract protected function attribution(): string;

    /** @return array{0: float, 1: float, 2: float, 3: float} south, west, north, east */
    abstract protected function box(): array;

    /** @return list<array{id: string, name: string, lat: float, lng: float}>|null null = failed */
    abstract protected function fetchGauges(): ?array;

    /**
     * @param array{id: string, name: string, lat: float, lng: float} $gauge
     * @return list<array{time: int, type: 'HW'|'NW', height_m: ?float}>|null null = failed
     */
    abstract protected function fetchEvents(array $gauge, int $from, int $to): ?array;

    public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
    {
        [$south, $west, $north, $east] = $this->box();
        $marginLat = $maxStationMeters / 111320.0;
        $marginLng = $marginLat / max(0.1, cos(deg2rad($lat)));
        if ($lat < $south - $marginLat || $lat > $north + $marginLat || $lng < $west - $marginLng || $lng > $east + $marginLng) {
            return null;
        }
        $gauges = $this->gauges();
        if ($gauges === null) {
            return null;
        }
        $near = [];
        foreach ($gauges as $gauge) {
            $distance = self::haversine($lat, $lng, $gauge['lat'], $gauge['lng']);
            if ($distance <= $maxStationMeters) {
                $near[] = $gauge + ['distance' => $distance];
            }
        }
        usort($near, static fn ($a, $b) => $a['distance'] <=> $b['distance']);

        foreach (array_slice($near, 0, self::MAX_GAUGES_TRIED) as $gauge) {
            $events = $this->events($gauge, $from, $to);
            if ($events === null || $events === []) {
                continue;
            }

            return [
                'station' => $gauge['name'],
                'source' => $this->key(),
                'attribution' => $this->attribution(),
                'lat' => $gauge['lat'],
                'lng' => $gauge['lng'],
                'distance_m' => (int) round($gauge['distance']),
                'mean_range_m' => self::meanRange($events),
                'events' => BshTideSource::window($events, $from, $to),
            ];
        }

        return null;
    }

    /** @return list<array{id: string, name: string, lat: float, lng: float}>|null */
    private function gauges(): ?array
    {
        $file = $this->key() . '-gauges.json';
        $cached = $this->cache->read($file);
        if ($cached !== null) {
            return $cached['gauges'] ?? null;
        }
        $gauges = $this->fetchGauges();
        if ($gauges === null || $gauges === []) {
            $this->cache->failed($file, $this->key() . ' gauge list');

            return null;
        }

        return $this->cache->write($file, ['gauges' => $gauges])['gauges'];
    }

    /**
     * @param array{id: string, name: string, lat: float, lng: float} $gauge
     * @return list<array{time: int, type: 'HW'|'NW', height_m: ?float}>|null
     */
    private function events(array $gauge, int $from, int $to): ?array
    {
        $file = $this->key() . '-' . TideCache::safe($gauge['id']) . '.json';
        $cached = $this->cache->read($file);
        if ($cached !== null && isset($cached['failed'])) {
            return null;
        }
        if ($cached !== null && $cached['from'] <= $from - 3600 && $cached['to'] >= $to) {
            return $cached['events'];
        }
        $start = $from - 86400;
        $end = max($to, $from + static::WINDOW_DAYS * 86400);
        $events = $this->fetchEvents($gauge, $start, $end);
        if ($events === null) {
            $this->cache->failed($file, $this->key() . ' ' . $gauge['id']);

            return null;
        }
        usort($events, static fn ($a, $b) => $a['time'] <=> $b['time']);
        // remember how far the data really reaches, not how far was asked: a source
        // that serves only a week (Instituto Hidrográfico) is asked again for a later day
        $last = end($events);
        $covered = $last === false ? $end : min($end, $last['time']);

        return $this->cache->write($file, ['from' => $start, 'to' => $covered, 'events' => $events])['events'];
    }

    /**
     * Types for extremes that come without one (Rijkswaterstaat): higher than
     * the neighbour = HW, lower = NW.
     *
     * @param list<array{0: int, 1: float}> $extremes [time, metres], sorted
     * @return list<array{time: int, type: 'HW'|'NW', height_m: ?float}>
     */
    public static function typed(array $extremes): array
    {
        $events = [];
        $count = count($extremes);
        for ($i = 0; $i < $count; $i++) {
            $neighbour = $extremes[$i > 0 ? $i - 1 : $i + 1][1] ?? null;
            if ($neighbour === null || $neighbour == $extremes[$i][1]) {
                continue;
            }
            $events[] = ['time' => $extremes[$i][0], 'type' => $extremes[$i][1] > $neighbour ? 'HW' : 'NW', 'height_m' => round($extremes[$i][1], 2)];
        }

        return $events;
    }

    /** @param list<array{time: int, type: string, height_m: ?float}> $events */
    public static function meanRange(array $events): ?float
    {
        $differences = [];
        for ($i = 1, $n = count($events); $i < $n; $i++) {
            if ($events[$i]['height_m'] !== null && $events[$i - 1]['height_m'] !== null && $events[$i]['type'] !== $events[$i - 1]['type']) {
                $differences[] = abs($events[$i]['height_m'] - $events[$i - 1]['height_m']);
            }
        }

        return $differences === [] ? null : round(array_sum($differences) / count($differences), 2);
    }

    protected static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return \Ytan\Service\GeometryService::distanceMeters($lat1, $lng1, $lat2, $lng2);
    }
}
