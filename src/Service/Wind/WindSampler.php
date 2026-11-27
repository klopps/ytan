<?php

declare(strict_types=1);

namespace Ytan\Service\Wind;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Wind over an area and a time span for the etmal reach lines with wind and
 * current (WaterReach::reachTimed()), as a WindField.
 *
 * First choice: the wind job's own blocks in storage/wind (tools/wind,
 * format in tools/wind/ytanwind/blocks.py and windkarte.md) - read locally,
 * no request to anyone, ICON-D2/MEPS/AROME at 0.02 degrees where they reach,
 * else ICON-EU/global. They cover the configured regions and the job's
 * forecast horizon only; outside them (or with no wind data at all) the
 * weather forecast (WeatherService, Open-Meteo & co., 7 days) at 3 x 3 points
 * of the area is the fallback.
 */
final class WindSampler
{
    /** Grid points per side at most (the reach grid takes the nearest one). */
    private const MAX_POINTS = 40;
    /** Finer than the finest blocks makes no sense. */
    private const MIN_STEP_DEG = 0.02;
    private const BLOCK_CELLS = 128;
    private const BLOCK_POINTS = 129;
    private const NODATA = 255;

    /** @var array<string, ?string> raw (gunzipped) block bytes, by path */
    private array $blocks = [];

    /**
     * @param string $windDir storage/wind
     * @param (\Closure(float, float): ?array)|null $forecast WeatherService::getForecast(); null = no fallback
     */
    public function __construct(private readonly string $windDir, private readonly ?\Closure $forecast = null)
    {
    }

    /**
     * @return WindField|null null = no wind data at all for this area and time
     */
    public function field(float $south, float $west, float $north, float $east, int $from, int $to): ?WindField
    {
        return $this->fromBlocks($south, $west, $north, $east, $from, $to)
            ?? $this->fromForecasts($south, $west, $north, $east, $from, $to);
    }

    /**
     * The wind job's blocks. Null when there is no manifest, the time span
     * starts outside its hours, or a needed hour has no value anywhere in the
     * area (outside the regions).
     */
    public function fromBlocks(float $south, float $west, float $north, float $east, int $from, int $to): ?WindField
    {
        $manifest = $this->manifest();
        if ($manifest === null) {
            return null;
        }
        $times = [];
        foreach ((array) ($manifest['hours'] ?? []) as $iso) {
            $time = is_string($iso) ? strtotime($iso) : false;
            if ($time === false) {
                return null;
            }
            $times[] = $time;
        }
        if (count($times) < 2 || $from < $times[0] - 1800 || $from > end($times)) {
            return null;
        }
        $run = (string) ($manifest['run'] ?? '');
        $channels = (int) ($manifest['channels'] ?? 2);
        $levels = (array) ($manifest['levels'] ?? []);
        if (preg_match('/^\d{12}$/', $run) !== 1 || $channels < 2) {
            return null;
        }
        $sets = [];
        foreach (['L1', 'L2'] as $level) {
            $sets[$level] = array_flip(array_map('strval', (array) ($levels[$level]['blocks'] ?? [])));
        }

        [$rows, $cols, $dLat, $dLng] = self::grid($south, $west, $north, $east);
        $t0 = intdiv($from, 3600) * 3600;
        $hours = intdiv($to - $t0, 3600) + 2;
        $u = [];
        $v = [];
        $gust = $channels > 2 ? [] : null;
        for ($h = 0; $h < $hours; $h++) {
            // the manifest's hours: hourly near, every 3 hours later on - interpolated between
            $t = $t0 + $h * 3600;
            $k = 0;
            while ($k < count($times) - 2 && $times[$k + 1] <= $t) {
                $k++;
            }
            $span = $times[$k + 1] - $times[$k];
            $w = $span > 0 ? max(0.0, min(1.0, ($t - $times[$k]) / $span)) : 0.0;
            $hu = [];
            $hv = [];
            $hg = [];
            $found = 0;
            for ($r = 0; $r < $rows; $r++) {
                $lat = $south + $r * $dLat;
                for ($c = 0; $c < $cols; $c++) {
                    $lng = $west + $c * $dLng;
                    $a = $this->sampleAt($run, $levels, $sets, $channels, $k, $lat, $lng);
                    $b = $w > 0.0 ? $this->sampleAt($run, $levels, $sets, $channels, $k + 1, $lat, $lng) : $a;
                    if ($a === null || $b === null) {
                        $hu[] = null;
                        $hv[] = null;
                        $hg[] = null;
                        continue;
                    }
                    $hu[] = $a[0] + $w * ($b[0] - $a[0]);
                    $hv[] = $a[1] + $w * ($b[1] - $a[1]);
                    $hg[] = $a[2] + $w * ($b[2] - $a[2]);
                    $found++;
                }
            }
            if ($found === 0) {
                return null;
            }
            if ($found < count($hu)) {
                // points outside the regions take the mean of the others
                $mean = static fn (array $list): float => array_sum(array_filter($list, static fn ($x) => $x !== null)) / $found;
                [$mu, $mv, $mg] = [$mean($hu), $mean($hv), $mean($hg)];
                foreach ($hu as $i => $value) {
                    if ($value === null) {
                        $hu[$i] = $mu;
                        $hv[$i] = $mv;
                        $hg[$i] = $mg;
                    }
                }
            }
            $u[] = $hu;
            $v[] = $hv;
            if ($gust !== null) {
                $gust[] = $hg;
            }
        }
        $sources = (array) ($manifest['sources'] ?? []);
        $models = array_values(array_filter([
            !empty($sources['d2']) ? 'ICON-D2' : null,
            !empty($sources['meps']) ? 'MEPS' : null,
            !empty($sources['arome']) ? 'AROME' : null,
            !empty($sources['eu']) ? 'ICON-EU' : null,
            !empty($sources['gl']) ? 'ICON' : null,
        ]));

        return new WindField($south, $west, $dLat, $dLng, $rows, $cols, $t0, $u, $v, $gust, 'wind', $models === [] ? null : implode(', ', $models));
    }

    /**
     * Weather forecasts at 3 x 3 points of the area (corners, edge middles,
     * centre). Null when none of them answered.
     */
    public function fromForecasts(float $south, float $west, float $north, float $east, int $from, int $to): ?WindField
    {
        if ($this->forecast === null) {
            return null;
        }
        $t0 = intdiv($from, 3600) * 3600;
        $hours = intdiv($to - $t0, 3600) + 2;
        $dLat = ($north - $south) / 2;
        $dLng = ($east - $west) / 2;
        $points = [];
        $sources = [];
        for ($r = 0; $r < 3; $r++) {
            for ($c = 0; $c < 3; $c++) {
                $points[] = $this->forecastPoint($south + $r * $dLat, $west + $c * $dLng, $t0, $hours, $sources);
            }
        }
        $known = array_values(array_filter($points));
        if ($known === []) {
            return null;
        }
        $u = [];
        $v = [];
        $gust = [];
        for ($h = 0; $h < $hours; $h++) {
            $hu = [];
            $hv = [];
            $hg = [];
            foreach ($points as $point) {
                // a point without an answer takes the first one that has one
                $point ??= $known[0];
                [$hu[], $hv[], $hg[]] = $point[$h];
            }
            $u[] = $hu;
            $v[] = $hv;
            $gust[] = $hg;
        }

        return new WindField($south, $west, $dLat, $dLng, 3, 3, $t0, $u, $v, $gust, 'forecast', $sources === [] ? null : implode(', ', array_keys($sources)));
    }

    /**
     * u, v, gust (m/s) per hour from $t0 at one position, null = no forecast.
     *
     * @param array<string, true> $sources collects the forecasts' source names
     * @return list<array{0: float, 1: float, 2: float}>|null
     */
    private function forecastPoint(float $lat, float $lng, int $t0, int $hours, array &$sources): ?array
    {
        try {
            $payload = ($this->forecast)($lat, $lng);
        } catch (Throwable $e) {
            error_log('YTAN etmal: weather for ' . $lat . ',' . $lng . ' failed: ' . $e->getMessage());

            return null;
        }
        if (!is_array($payload) || !is_numeric($payload['utc_offset_seconds'] ?? null)) {
            return null;
        }
        $seconds = (int) $payload['utc_offset_seconds'];
        $zone = new DateTimeZone(sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60)));
        $rows = [];
        foreach ((array) ($payload['hourly'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['time']) || !is_numeric($row['wind_speed'] ?? null) || !is_numeric($row['wind_direction'] ?? null)) {
                continue;
            }
            $time = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr((string) $row['time'], 0, 13) . ':00', $zone);
            if ($time !== false) {
                $rows[$time->getTimestamp()] = $row;
            }
        }
        if ($rows === []) {
            return null;
        }
        $source = is_string($payload['source'] ?? null) ? trim($payload['source']) : '';
        if ($source !== '') {
            $sources[$source] = true;
        }
        ksort($rows);
        $keys = array_keys($rows);
        $result = [];
        for ($h = 0; $h < $hours; $h++) {
            $t = $t0 + $h * 3600;
            // the last forecast hour at or before $t (the first one before the forecast starts)
            $best = $keys[0];
            foreach ($keys as $key) {
                if ($key > $t) {
                    break;
                }
                $best = $key;
            }
            $row = $rows[$best];
            $speed = (float) $row['wind_speed'] / 3.6;
            $from = deg2rad((float) $row['wind_direction']);
            $gust = is_numeric($row['wind_gusts'] ?? null) ? (float) $row['wind_gusts'] / 3.6 : $speed;
            $result[] = [-$speed * sin($from), -$speed * cos($from), $gust];
        }

        return $result;
    }

    /** @return array{0: int, 1: int, 2: float, 3: float} rows, cols, dLat, dLng */
    private static function grid(float $south, float $west, float $north, float $east): array
    {
        $rows = max(2, min(self::MAX_POINTS, (int) ceil(($north - $south) / self::MIN_STEP_DEG) + 1));
        $cols = max(2, min(self::MAX_POINTS, (int) ceil(($east - $west) / self::MIN_STEP_DEG) + 1));

        return [$rows, $cols, ($north - $south) / ($rows - 1), ($east - $west) / ($cols - 1)];
    }

    /** @return array<string, mixed>|null */
    private function manifest(): ?array
    {
        $json = @file_get_contents($this->windDir . '/manifest.json');
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * u, v, gust (m/s) at a position for a manifest hour: L2 where a block has
     * data, else L1, else the L0 overview. Null = no value.
     *
     * @param array<string, mixed> $levels the manifest's levels
     * @param array<string, array<string, int>> $sets block names per level
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function sampleAt(string $run, array $levels, array $sets, int $channels, int $hour, float $lat, float $lng): ?array
    {
        foreach (['L2', 'L1'] as $level) {
            $res = (float) ($levels[$level]['res'] ?? 0);
            if ($res <= 0) {
                continue;
            }
            $bx = (int) floor(($lng + 180) / (self::BLOCK_CELLS * $res));
            $by = (int) floor(($lat + 90) / (self::BLOCK_CELLS * $res));
            $name = $bx . '_' . $by;
            if (!isset($sets[$level][$name])) {
                continue;
            }
            $data = $this->block(sprintf('%s/runs/%s/%s/%03d/%s.bin.gz', $this->windDir, $run, $level, $hour, $name));
            if ($data === null) {
                continue;
            }
            $value = self::bilinear($data, $channels, self::BLOCK_POINTS, self::BLOCK_POINTS,
                ($lng + 180) / $res - $bx * self::BLOCK_CELLS, ($lat + 90) / $res - $by * self::BLOCK_CELLS);
            if ($value !== null) {
                return $value;
            }
        }
        $l0 = (array) ($levels['L0'] ?? []);
        if (empty($l0['rows']) || empty($l0['res'])) {
            return null;
        }
        $data = $this->block(sprintf('%s/runs/%s/L0/%03d.bin.gz', $this->windDir, $run, $hour));

        return $data === null ? null : self::bilinear($data, $channels, (int) $l0['cols'], (int) $l0['rows'],
            ($lng - (float) $l0['west']) / (float) $l0['res'], ($lat - (float) $l0['south']) / (float) $l0['res']);
    }

    private function block(string $path): ?string
    {
        if (!array_key_exists($path, $this->blocks)) {
            $gz = is_file($path) ? @file_get_contents($path) : false;
            $raw = $gz === false ? false : @gzdecode($gz);
            $this->blocks[$path] = $raw === false ? null : $raw;
        }

        return $this->blocks[$path];
    }

    /**
     * Bilinear u/v/gust from interleaved bytes - the same rules as wind.js's
     * windBilinear(): corners without data are left out and the weights
     * renormalised, less than half the weight left = no value.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function bilinear(string $data, int $channels, int $cols, int $rows, float $fi, float $fj): ?array
    {
        if ($fi < 0 || $fj < 0 || $fi > $cols - 1 || $fj > $rows - 1) {
            return null;
        }
        $i0 = min((int) floor($fi), $cols - 2);
        $j0 = min((int) floor($fj), $rows - 2);
        $fx = $fi - $i0;
        $fy = $fj - $j0;
        $wsum = 0.0;
        $su = 0.0;
        $sv = 0.0;
        $sg = 0.0;
        for ($dj = 0; $dj < 2; $dj++) {
            for ($di = 0; $di < 2; $di++) {
                $idx = (($j0 + $dj) * $cols + ($i0 + $di)) * $channels;
                if ($idx + 1 >= strlen($data)) {
                    return null;
                }
                $bu = ord($data[$idx]);
                $bv = ord($data[$idx + 1]);
                if ($bu === self::NODATA || $bv === self::NODATA) {
                    continue;
                }
                $w = ($di ? $fx : 1 - $fx) * ($dj ? $fy : 1 - $fy);
                $wsum += $w;
                $su += $w * $bu;
                $sv += $w * $bv;
                $bg = $channels > 2 ? ord($data[$idx + 2]) : self::NODATA;
                // without a gust value the mean wind stands in for it
                $sg += $w * ($bg !== self::NODATA ? $bg / 254 * 60 : hypot($bu / 254 * 80 - 40, $bv / 254 * 80 - 40));
            }
        }
        if ($wsum < 0.5) {
            return null;
        }

        return [($su / $wsum) / 254 * 80 - 40, ($sv / $wsum) / 254 * 80 - 40, $sg / $wsum];
    }
}
