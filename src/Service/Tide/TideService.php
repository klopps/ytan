<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * Tide data for a position from the first source that has some (national
 * sources first, Open-Meteo last - decision 2026-11-14), and the tidal current
 * model of the Routencheck (phase()).
 *
 * Results are kept per position for the lifetime of the object (one
 * Routencheck request asks for the same few positions per start hour).
 */
final class TideService
{
    /** below this share of the maximum the current counts as slack water */
    public const SLACK_STRENGTH = 0.3;

    /** @var array<string, ?array<string, mixed>> */
    private array $memo = [];

    /** @param list<TideSource> $sources in order of preference */
    public function __construct(private readonly array $sources)
    {
    }

    /**
     * @param array{lat: float, lng: float}|null $coast
     * @return array<string, mixed>|null see TideSource::tide()
     */
    public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
    {
        $key = sprintf('%.4f,%.4f,%d,%d,%d', $lat, $lng, $from, $to, $maxStationMeters);
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        foreach ($this->sources as $source) {
            try {
                $tide = $source->tide($lat, $lng, $coast, $from, $to, $maxStationMeters);
            } catch (\Throwable $e) {
                error_log('YTAN: tide source ' . $source::class . ' failed: ' . $e->getMessage());
                $tide = null;
            }
            if ($tide !== null) {
                return $this->memo[$key] = $tide;
            }
        }

        return $this->memo[$key] = null;
    }

    /**
     * The tidal current at a moment: flood (water runs up the river) from low
     * to high water, ebb (down the river) from high to low water - each event
     * time shifted by $lagSeconds, because the current turns a while after
     * high/low water at the gauge (an admin rule, decision 2026-11-14).
     * 'strength' (0..1) follows a half sine over the phase: 0 at the turn
     * (slack water), 1 halfway between.
     *
     * @param list<array{time: int, type: string}> $events sorted
     * @return array{phase: 'flood'|'ebb', strength: float, slack: bool}|null null = the moment is outside the events
     */
    public static function phase(array $events, int $time, int $lagSeconds = 0): ?array
    {
        $count = count($events);
        for ($i = 0; $i < $count - 1; $i++) {
            $start = $events[$i]['time'] + $lagSeconds;
            $end = $events[$i + 1]['time'] + $lagSeconds;
            if ($time < $start || $time > $end || $end <= $start) {
                continue;
            }
            $strength = sin(M_PI * ($time - $start) / ($end - $start));

            return [
                'phase' => $events[$i]['type'] === 'NW' ? 'flood' : 'ebb',
                'strength' => round($strength, 3),
                'slack' => $strength < self::SLACK_STRENGTH,
            ];
        }

        return null;
    }
}
