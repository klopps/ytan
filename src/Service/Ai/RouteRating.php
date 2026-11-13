<?php

declare(strict_types=1);

namespace Ytan\Service\Ai;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The Routencheck's traffic light, computed without any AI (routencheck.md,
 * "Entscheidungsmodell Ampel"): every section of the route is rated for the
 * hour(s) it is paddled in - 0 green, 1 yellow, 2 red, null = no data (grey) -
 * from mean wind, gusts, waves, thunderstorms, darkness and water temperature,
 * tightened or relaxed by where the wind comes from (open water upwind =
 * exposed, land close upwind = lee, land upwind + open water downwind =
 * offshore wind, wind from the side + waves). The route gets the worst rating
 * of its sections; windows() repeats that for every start hour of the day.
 *
 * Every rating carries its reasons ({criterion, value, threshold, level}), so
 * the page and the AI text can say WHY - the AI never decides a colour.
 */
final class RouteRating
{
    private const GRAVITY = 9.81;
    private const THUNDER_CODES = [95, 96, 99];

    /** @var array<string, ?int> open water per section + direction class */
    private array $waterCache = [];

    /**
     * @param list<array{from: float, to: float, mid: array{0: float, 1: float}, bearing: float, length: float, weather: int}> $sections
     * @param list<array{hourly: array<string, array<string, mixed>>, sun: array<string, array{sunrise: ?DateTimeImmutable, sunset: ?DateTimeImmutable}>}> $forecasts
     * @param \Closure(array{0: float, 1: float}, float): ?int $openWater metres of open water from a position in a direction (null = unknown)
     * @param array<string, mixed> $rules BriefingRules::normalize()d
     */
    public function __construct(
        private readonly array $sections,
        private readonly array $forecasts,
        private readonly \Closure $openWater,
        private readonly array $rules,
        private readonly float $levelFactor,
        private readonly DateTimeZone $timezone,
    ) {
    }

    /**
     * Rating of the whole route when starting at $start with $speedKmh.
     *
     * @return array{rating: ?int, partial: bool, reasons: list<array<string, mixed>>, end: DateTimeImmutable, sections: list<array<string, mixed>>}
     */
    public function assess(DateTimeImmutable $start, float $speedKmh): array
    {
        $time = $start;
        $sections = [];
        $ratings = [];
        $partial = false;
        foreach ($this->sections as $index => $section) {
            $entry = $time;
            $hour = $this->hourData($section['weather'], $entry);
            $speed = $this->effectiveSpeed($speedKmh, $section['bearing'], $hour);
            $seconds = (int) round($section['length'] / 1000 / $speed * 3600);
            $exit = $entry->modify('+' . $seconds . ' seconds');
            $time = $exit;

            $hours = [];
            $level = null;
            $reasons = [];
            $values = null;
            $missing = false;
            for ($slot = $this->floorHour($entry); $slot <= $exit; $slot = $slot->modify('+1 hour')) {
                $data = $this->hourData($section['weather'], $slot);
                $instant = max($entry, $slot);
                $result = $this->evaluate($index, $section, $data, [$instant, min($exit, $slot->modify('+59 minutes'))]);
                $hours[] = $slot->format('H');
                if ($result['level'] === null) {
                    $missing = true;
                    continue;
                }
                $values ??= $result['values'];
                $level = max($level ?? 0, $result['level']);
                $reasons = self::mergeReasons($reasons, $result['reasons']);
            }
            if ($missing) {
                $partial = true;
            }
            $ratings[] = $level;
            $sections[] = [
                'index' => $index,
                'from_m' => (int) round($section['from']),
                'to_m' => (int) round($section['to']),
                'entry' => $entry->format('H:i'),
                'exit' => $exit->format('H:i'),
                'hours' => array_values(array_unique($hours)),
                'rating' => $level,
                'partial' => $missing,
                'reasons' => $reasons,
                'values' => $values,
            ];
        }
        $known = array_filter($ratings, static fn ($r) => $r !== null);
        $routeReasons = [];
        foreach ($sections as $section) {
            $routeReasons = self::mergeReasons($routeReasons, $section['reasons']);
        }

        return [
            'rating' => $known === [] ? null : max($known),
            'partial' => $partial || count($known) < count($ratings),
            'reasons' => $routeReasons,
            'end' => $time,
            'sections' => $sections,
        ];
    }

    /**
     * The route's rating for every start hour of the day between the rules'
     * window_from and window_to.
     *
     * @return list<array{hour: int, rating: ?int, reason: ?string, end: string}>
     */
    public function windows(string $date, float $speedKmh): array
    {
        $windows = [];
        for ($hour = (int) $this->rules['window_from']; $hour <= (int) $this->rules['window_to']; $hour++) {
            $start = new DateTimeImmutable(sprintf('%s %02d:00:00', $date, $hour), $this->timezone);
            $result = $this->assess($start, $speedKmh);
            $windows[] = [
                'hour' => $hour,
                'rating' => $result['rating'],
                'reason' => $result['reasons'][0]['criterion'] ?? null,
                'end' => $result['end']->format('H:i'),
            ];
        }

        return $windows;
    }

    /**
     * One section in one hour.
     *
     * @param array<string, mixed>|null $hour
     * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $instants entry/exit within this hour
     * @return array{level: ?int, reasons: list<array<string, mixed>>, values: ?array<string, mixed>}
     */
    private function evaluate(int $index, array $section, ?array $hour, array $instants): array
    {
        $wind = self::kmhToMs($hour['wind_speed'] ?? null);
        if ($hour === null || $wind === null) {
            return ['level' => null, 'reasons' => [], 'values' => null];
        }
        $gust = self::kmhToMs($hour['wind_gusts'] ?? null);
        $direction = is_numeric($hour['wind_direction'] ?? null) ? (float) $hour['wind_direction'] : null;

        $upwind = $direction !== null ? $this->water($index, $section['mid'], $direction) : null;
        $downwind = $direction !== null ? $this->water($index, $section['mid'], fmod($direction + 180.0, 360.0)) : null;
        $lee = $upwind !== null && $upwind < $this->rules['lee_fetch_m'];
        $exposed = $upwind !== null && $upwind >= $this->rules['exposed_fetch_km'] * 1000;
        $offshore = $upwind !== null && $downwind !== null
            && $upwind < $this->rules['offshore_land_km'] * 1000
            && $downwind >= $this->rules['offshore_open_km'] * 1000;

        $factor = $this->levelFactor * ($lee ? $this->rules['lee_factor'] : 1.0) * ($exposed ? $this->rules['exposed_factor'] : 1.0);
        $waveFactor = $this->levelFactor * ($exposed ? $this->rules['exposed_factor'] : 1.0);

        $relative = $direction !== null ? RouteGeometry::angleBetween($direction, $section['bearing']) : null;
        $wave = $this->wave($hour, $wind, $upwind);

        $reasons = [];
        $level = 0;
        $windLevel = self::threshold($wind, $this->rules['wind'], $factor);
        if ($windLevel > 0) {
            $reasons[] = self::reason('wind', $wind, $this->rules['wind'], $factor, $windLevel);
        }
        $level = max($level, $windLevel);
        if ($gust !== null) {
            $gustLevel = self::threshold($gust, $this->rules['gust'], $factor);
            if ($gustLevel > 0) {
                $reasons[] = self::reason('gust', $gust, $this->rules['gust'], $factor, $gustLevel);
            }
            $level = max($level, $gustLevel);
        }
        $waveLevel = 0;
        if ($wave !== null) {
            $waveLevel = self::threshold($wave, $this->rules['wave'], $waveFactor);
            if ($waveLevel > 0) {
                $reasons[] = self::reason('wave', $wave, $this->rules['wave'], $waveFactor, $waveLevel);
            }
            $level = max($level, $waveLevel);
        }
        if (in_array((int) ($hour['weather_code'] ?? -1), self::THUNDER_CODES, true)) {
            $reasons[] = ['criterion' => 'thunder', 'value' => (int) $hour['weather_code'], 'threshold' => null, 'level' => 2];
            $level = 2;
        }
        $dark = $this->darkness($section['weather'], $instants);
        if ($dark !== null) {
            $reasons[] = $dark;
            $level = max($level, $dark['level']);
        }
        $sea = is_numeric($hour['sea_surface_temperature'] ?? null) ? (float) $hour['sea_surface_temperature'] : null;
        if ($sea !== null && $sea < $this->rules['cold_water_c']) {
            $reasons[] = ['criterion' => 'cold_water', 'value' => round($sea, 1), 'threshold' => $this->rules['cold_water_c'], 'level' => 1];
            $level = max($level, 1);
        }
        if ($offshore) {
            $offshoreLevel = $windLevel >= 1 ? 2 : 1;
            $reasons[] = ['criterion' => 'offshore', 'value' => round($downwind / 1000, 1), 'threshold' => $this->rules['offshore_open_km'], 'level' => $offshoreLevel];
            $level = max($level, $offshoreLevel);
        }
        if ($relative !== null && $relative >= $this->rules['beam_min_deg'] && $relative <= $this->rules['beam_max_deg'] && $waveLevel >= 1) {
            $beamLevel = min(2, $waveLevel + 1);
            $reasons[] = ['criterion' => 'beam_sea', 'value' => (int) round($relative), 'threshold' => null, 'level' => $beamLevel];
            $level = max($level, $beamLevel);
        }
        usort($reasons, static fn ($a, $b) => $b['level'] <=> $a['level']);

        return [
            'level' => $level,
            'reasons' => $reasons,
            'values' => [
                'wind' => round($wind, 1),
                'gust' => $gust !== null ? round($gust, 1) : null,
                'direction' => $direction !== null ? (int) round($direction) : null,
                'relative' => $relative === null ? null : ($relative < $this->rules['beam_min_deg'] ? 'head' : ($relative > $this->rules['beam_max_deg'] ? 'tail' : 'beam')),
                'wave' => $wave !== null ? round($wave, 1) : null,
                'fetch_m' => $upwind,
                'lee' => $lee,
                'exposed' => $exposed,
                'offshore' => $offshore,
            ],
        ];
    }

    /**
     * Wave height: the model's (marine forecast, ~5 km grid) capped by what the
     * wind can build over the open water upwind (fetch-limited JONSWAP growth,
     * at most a fully developed sea); without a model value the estimate alone.
     */
    private function wave(array $hour, float $wind, ?int $fetch): ?float
    {
        $model = is_numeric($hour['wave_height'] ?? null) ? (float) $hour['wave_height'] : null;
        $estimate = $fetch !== null && $fetch > 0 ? self::fetchLimitedWave($wind, $fetch) : null;
        if ($model !== null && $estimate !== null) {
            return min($model, $estimate);
        }

        return $model ?? $estimate;
    }

    /** Significant wave height (m) for a 10 m wind (m/s) over a fetch (m). */
    public static function fetchLimitedWave(float $wind, int $fetch): float
    {
        if ($wind <= 0) {
            return 0.0;
        }
        $limited = 0.0016 * $wind * sqrt($fetch / self::GRAVITY);
        $developed = 0.243 * $wind * $wind / self::GRAVITY;

        return min($limited, $developed);
    }

    /** @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $instants */
    private function darkness(int $weather, array $instants): ?array
    {
        $worst = null;
        foreach ($instants as $instant) {
            $sun = $this->forecasts[$weather]['sun'][$instant->format('Y-m-d')] ?? null;
            if ($sun === null || $sun['sunrise'] === null || $sun['sunset'] === null) {
                continue;
            }
            $dusk = (int) $this->rules['dusk_minutes'];
            $level = 0;
            if ($instant < $sun['sunrise']->modify("-$dusk minutes") || $instant > $sun['sunset']->modify("+$dusk minutes")) {
                $level = 2;
            } elseif ($instant < $sun['sunrise'] || $instant > $sun['sunset']) {
                $level = 1;
            }
            if ($level > 0 && ($worst === null || $level > $worst['level'])) {
                $worst = [
                    'criterion' => $level === 2 ? 'dark' : 'dusk',
                    'value' => $instant->format('H:i'),
                    'threshold' => ($instant < $sun['sunrise'] ? $sun['sunrise'] : $sun['sunset'])->format('H:i'),
                    'level' => $level,
                ];
            }
        }

        return $worst;
    }

    private function effectiveSpeed(float $speedKmh, float $bearing, ?array $hour): float
    {
        $wind = self::kmhToMs($hour['wind_speed'] ?? null);
        $direction = is_numeric($hour['wind_direction'] ?? null) ? (float) $hour['wind_direction'] : null;
        if ($wind === null || $direction === null) {
            return $speedKmh;
        }
        $headwind = $wind * cos(deg2rad(RouteGeometry::angleBetween($direction, $bearing)));

        return max($speedKmh * 0.3, $speedKmh - $this->rules['headwind_slowdown'] * max(0.0, $headwind));
    }

    private function water(int $index, array $position, float $bearing): ?int
    {
        $class = ((int) round($bearing / 10)) % 36;
        $key = $index . ':' . $class;
        if (!array_key_exists($key, $this->waterCache)) {
            $this->waterCache[$key] = ($this->openWater)($position, $class * 10.0);
        }

        return $this->waterCache[$key];
    }

    private function hourData(int $weather, DateTimeImmutable $time): ?array
    {
        return $this->forecasts[$weather]['hourly'][$time->format('Y-m-d\TH') . ':00'] ?? null;
    }

    private function floorHour(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTime((int) $time->format('H'), 0);
    }

    private static function threshold(float $value, array $pair, float $factor): int
    {
        if ($value >= $pair['red'] * $factor) {
            return 2;
        }

        return $value >= $pair['yellow'] * $factor ? 1 : 0;
    }

    private static function reason(string $criterion, float $value, array $pair, float $factor, int $level): array
    {
        return [
            'criterion' => $criterion,
            'value' => round($value, 1),
            'threshold' => round(($level === 2 ? $pair['red'] : $pair['yellow']) * $factor, 1),
            'level' => $level,
        ];
    }

    /** Worst reason per criterion, worst first. */
    private static function mergeReasons(array $reasons, array $more): array
    {
        $byCriterion = [];
        foreach (array_merge($reasons, $more) as $reason) {
            $current = $byCriterion[$reason['criterion']] ?? null;
            if ($current === null || $reason['level'] > $current['level']
                || ($reason['level'] === $current['level'] && is_float($reason['value']) && is_float($current['value']) && $reason['value'] > $current['value'])) {
                $byCriterion[$reason['criterion']] = $reason;
            }
        }
        $merged = array_values($byCriterion);
        usort($merged, static fn ($a, $b) => $b['level'] <=> $a['level']);

        return $merged;
    }

    private static function kmhToMs(mixed $kmh): ?float
    {
        return is_numeric($kmh) ? (float) $kmh / 3.6 : null;
    }
}
