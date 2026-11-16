<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

use Ytan\Service\GeometryService;

/**
 * High and low water computed by YTAN itself from the harmonic constants of
 * the nearest tide gauge in TICON-4 (DGFI-TUM, CC BY 4.0, 50 constituents,
 * ~3,600 gauges worldwide after merging duplicates; resources/tide/
 * ticon4.json, built by bin/build-ticon.php) with HarmonicTide - no key, no
 * request limit, no interface that can break. The same constants are what
 * TidesAtlas predicts from for its calibrated gauges. Astronomical tide only
 * (no wind set-up), like every other source. Asked after the national
 * services (official, and they handle local peculiarities), before
 * Open-Meteo.
 */
final class TiconTideSource implements TideSource
{
    /** the predicted span the mean range is taken from: a full spring-neap cycle */
    private const RANGE_DAYS = 15;

    /** @var array{constituents: list<string>, stations: list<array{0: float, 1: float, 2: string, 3: string, 4: int, 5: list<int>, 6: list<int>}>}|null */
    private ?array $data = null;

    public function __construct(private readonly string $file)
    {
    }

    public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
    {
        $station = $this->nearest($lat, $lng, $maxStationMeters);
        if ($station === null) {
            return null;
        }
        [$gauge, $distance] = $station;
        $constants = [];
        foreach ($this->data['constituents'] as $i => $name) {
            if ($gauge[5][$i] > 0) {
                $constants[$name] = [$gauge[5][$i] / 1000, $gauge[6][$i] / 10];
            }
        }
        // one day before $from so the event before it is there, and at least a spring-neap cycle for the mean range
        $events = HarmonicTide::extremes($constants, $from - 86400, max($to + 86400, $from + self::RANGE_DAYS * 86400));
        if ($events === []) {
            return null;
        }

        return [
            'station' => $gauge[2],
            'source' => 'ticon',
            'attribution' => 'TICON-4 (DGFI-TUM)',
            'lat' => (float) $gauge[0],
            'lng' => (float) $gauge[1],
            'distance_m' => $distance,
            'mean_range_m' => GaugeTideSource::meanRange($events),
            'events' => BshTideSource::window($events, $from, $to),
        ];
    }

    /** @return array{0: array{0: float, 1: float, 2: string, 3: string, 4: int, 5: list<int>, 6: list<int>}, 1: int}|null */
    private function nearest(float $lat, float $lng, int $maxMeters): ?array
    {
        if ($this->data === null) {
            $json = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;
            $this->data = is_array($json) && isset($json['constituents'], $json['stations']) ? $json : ['constituents' => [], 'stations' => []];
        }
        // a box around the position first (1 degree latitude = ~111 km), the stations are sorted by latitude
        $dLat = $maxMeters / 111000 + 0.001;
        $dLng = $dLat / max(0.05, cos(deg2rad($lat)));
        $best = null;
        foreach ($this->data['stations'] as $station) {
            if ($station[0] < $lat - $dLat) {
                continue;
            }
            if ($station[0] > $lat + $dLat) {
                break;
            }
            if (abs($station[1] - $lng) > $dLng) {
                continue;
            }
            $distance = (int) round(GeometryService::distanceMeters($lat, $lng, (float) $station[0], (float) $station[1]));
            if ($distance <= $maxMeters && ($best === null || $distance < $best[1])) {
                $best = [$station, $distance];
            }
        }

        return $best;
    }
}
