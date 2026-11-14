<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * Tide predictions of Kartverket (Norwegian Mapping Authority, CC BY 4.0, no
 * key): vannstand.kartverket.no/tideapi.php computes high and low water for
 * ANY point on the Norwegian coast and in the fjords (from the nearest
 * reference gauge with time delay and height factor) - so there is no gauge
 * list, the position itself (rounded to 0.01 degrees, ~1 km) is the key.
 * Outside Norway it answers <nodata/>, remembered like data (30 days) so a
 * Swedish river does not ask again. Answer: XML, <waterlevel value (cm)
 * time flag="high|low"/> (checked 2026-11-14).
 */
final class KartverketTideSource implements TideSource
{
    public const BASE_URL = 'https://vannstand.kartverket.no/tideapi.php';
    private const WINDOW_DAYS = 35;
    /** Norway incl. Svalbard: south, west, north, east */
    private const BOX = [57.5, 3.0, 81.0, 32.0];

    public function __construct(private readonly TideCache $cache, private readonly TideHttp $http)
    {
    }

    public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
    {
        if ($lat < self::BOX[0] || $lat > self::BOX[2] || $lng < self::BOX[1] || $lng > self::BOX[3]) {
            return null;
        }
        $qLat = round($lat, 2);
        $qLng = round($lng, 2);
        $file = sprintf('kv-%.2f_%.2f.json', $qLat, $qLng);
        $cached = $this->cache->read($file);
        if ($cached !== null && (isset($cached['failed']) || isset($cached['nodata']))) {
            return null;
        }
        if ($cached === null || $cached['from'] > $from - 3600 || $cached['to'] < $to) {
            $start = $from - 86400;
            $end = max($to, $from + self::WINDOW_DAYS * 86400);
            $body = $this->http->get(self::BASE_URL . '?' . http_build_query([
                'tide_request' => 'locationdata', 'lat' => $qLat, 'lon' => $qLng, 'datatype' => 'tab', 'refcode' => 'cd',
                'fromtime' => gmdate('Y-m-d\TH:i', $start), 'totime' => gmdate('Y-m-d\TH:i', $end),
                'tzone' => 0, 'lang' => 'en', 'dst' => 0,
            ]));
            $parsed = $body === null ? null : self::parse($body);
            if ($parsed === null) {
                $this->cache->failed($file, 'Kartverket ' . $qLat . ',' . $qLng);

                return null;
            }
            $cached = $this->cache->write($file, $parsed + ['from' => $start, 'to' => $end]);
            if (isset($cached['nodata'])) {
                return null;
            }
        }
        if ($cached['events'] === []) {
            return null;
        }

        return [
            'station' => (string) $cached['name'],
            'source' => 'kartverket',
            'attribution' => '©Kartverket',
            'lat' => $qLat,
            'lng' => $qLng,
            'distance_m' => 0,
            'mean_range_m' => GaugeTideSource::meanRange($cached['events']),
            'events' => BshTideSource::window($cached['events'], $from, $to),
        ];
    }

    /**
     * @return array{name: string, events: list<array{time: int, type: 'HW'|'NW', height_m: ?float}>}|array{nodata: true}|null null = not the expected answer
     */
    public static function parse(string $xml): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($doc === false || !isset($doc->locationdata)) {
            return null;
        }
        $data = $doc->locationdata;
        if (isset($data->nodata)) {
            return ['nodata' => true];
        }
        $events = [];
        foreach ($data->data ?? [] as $series) {
            foreach ($series->waterlevel ?? [] as $level) {
                $flag = (string) $level['flag'];
                $time = strtotime((string) $level['time']);
                if ($time === false || !in_array($flag, ['high', 'low'], true)) {
                    continue;
                }
                $value = (string) $level['value'];
                $events[] = ['time' => $time, 'type' => $flag === 'high' ? 'HW' : 'NW', 'height_m' => is_numeric($value) ? round((float) $value / 100, 2) : null];
            }
        }
        usort($events, static fn ($a, $b) => $a['time'] <=> $b['time']);

        return ['name' => (string) ($data->location['name'] ?? 'Kartverket'), 'events' => $events];
    }
}
