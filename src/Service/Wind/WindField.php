<?php

declare(strict_types=1);

namespace Ytan\Service\Wind;

/**
 * Wind over an area, hour by hour: a small regular lat/lng grid (rows south ->
 * north, columns west -> east) with u (towards east) and v (towards north) in
 * m/s for each full hour from $t0 on, plus the gust where the source has it.
 * Built by WindSampler (from the wind job's blocks or from weather forecasts),
 * read by WaterReach::reachTimed().
 */
final class WindField
{
    /**
     * @param list<list<float>> $u [hour][row * cols + col] m/s towards east
     * @param list<list<float>> $v [hour][row * cols + col] m/s towards north
     * @param list<list<float>>|null $gust [hour][row * cols + col] m/s, null = unknown
     * @param string $source 'wind' (the wind job's blocks, storage/wind) or 'forecast' (WeatherService)
     */
    public function __construct(
        public readonly float $south,
        public readonly float $west,
        public readonly float $dLat,
        public readonly float $dLng,
        public readonly int $rows,
        public readonly int $cols,
        public readonly int $t0,
        public readonly array $u,
        public readonly array $v,
        public readonly ?array $gust,
        public readonly string $source,
        public readonly ?string $model = null,
    ) {
    }

    /** The same wind everywhere and at every hour (tests). */
    public static function uniform(float $u, float $v, int $t0, int $hours, string $source = 'forecast'): self
    {
        return new self(0.0, 0.0, 1.0, 1.0, 1, 1, $t0, array_fill(0, $hours, [$u]), array_fill(0, $hours, [$v]), null, $source);
    }

    public function hours(): int
    {
        return count($this->u);
    }

    /** The hour index of a moment (clamped to the field's hours). */
    public function hourAt(int $time): int
    {
        return max(0, min($this->hours() - 1, intdiv($time - $this->t0, 3600)));
    }

    /** The grid point nearest to a position (clamped to the grid). */
    public function nearestIndex(float $lat, float $lng): int
    {
        $row = $this->rows === 1 ? 0 : max(0, min($this->rows - 1, (int) round(($lat - $this->south) / $this->dLat)));
        $col = $this->cols === 1 ? 0 : max(0, min($this->cols - 1, (int) round(($lng - $this->west) / $this->dLng)));

        return $row * $this->cols + $col;
    }

    /** The strongest mean wind (m/s) anywhere in the field between two moments. */
    public function maxSpeed(int $from, int $to): float
    {
        $max = 0.0;
        for ($h = $this->hourAt($from); $h <= $this->hourAt($to); $h++) {
            foreach ($this->u[$h] as $i => $u) {
                $max = max($max, hypot($u, $this->v[$h][$i]));
            }
        }

        return $max;
    }

    /**
     * The wind at a position over a time span, for the summary line: mean
     * direction it comes FROM (degrees, null when calm), mean speed and the
     * highest gust (m/s, null when the source has none).
     *
     * @return array{direction: ?int, speed_ms: float, gust_ms: ?float}
     */
    public function summary(float $lat, float $lng, int $from, int $to): array
    {
        $index = $this->nearestIndex($lat, $lng);
        $u = 0.0;
        $v = 0.0;
        $speed = 0.0;
        $gust = null;
        $count = 0;
        for ($h = $this->hourAt($from); $h <= $this->hourAt($to); $h++) {
            $u += $this->u[$h][$index];
            $v += $this->v[$h][$index];
            $speed += hypot($this->u[$h][$index], $this->v[$h][$index]);
            if ($this->gust !== null) {
                $gust = max($gust ?? 0.0, $this->gust[$h][$index]);
            }
            $count++;
        }
        $u /= $count;
        $v /= $count;
        $direction = hypot($u, $v) < 0.2 ? null : (int) round(fmod(rad2deg(atan2(-$u, -$v)) + 360.0, 360.0));

        return ['direction' => $direction, 'speed_ms' => round($speed / $count, 1), 'gust_ms' => $gust === null ? null : round($gust, 1)];
    }
}
