<?php

declare(strict_types=1);

namespace Ytan\Tests\Fixtures;

use Ytan\Domain\Weather\WeatherForecastRepositoryInterface;

/**
 * In-memory test double for WeatherForecastRepository - keyed by "lat,lng"
 * like the real table's unique key, so upsert() naturally replaces a prior
 * entry the same way ON DUPLICATE KEY UPDATE does. Lets WeatherServiceTest
 * stay DB-free (mirrors FakeJsonHttpClient's role for JsonHttpClient); the
 * real repository's own SQL is covered separately by
 * tests/Integration/WeatherForecastRepositoryTest.php.
 */
final class FakeWeatherForecastRepository implements WeatherForecastRepositoryInterface
{
    /** @var array<string, array{regionCode: string, source: string, model: ?string, generatedAt: ?string, fetchedAt: string, payload: array<string, mixed>}> */
    private array $rows = [];

    private int $upsertCount = 0;

    public function findFresh(float $lat, float $lng, int $ttlSeconds): ?array
    {
        $row = $this->rows[$this->key($lat, $lng)] ?? null;
        if ($row === null) {
            return null;
        }

        if (time() - strtotime($row['fetchedAt'] . ' UTC') > $ttlSeconds) {
            return null;
        }

        return $row;
    }

    public function upsert(
        float $lat,
        float $lng,
        string $regionCode,
        string $source,
        ?string $model,
        ?string $generatedAt,
        string $fetchedAt,
        array $payload
    ): void {
        $this->upsertCount++;
        $this->rows[$this->key($lat, $lng)] = [
            'regionCode' => $regionCode,
            'source' => $source,
            'model' => $model,
            'generatedAt' => $generatedAt,
            'fetchedAt' => $fetchedAt,
            'payload' => $payload,
        ];
    }

    public function upsertCount(): int
    {
        return $this->upsertCount;
    }

    /**
     * Backdates a cached row's fetchedAt, for tests that need to simulate
     * an expired cache entry without waiting.
     */
    public function backdate(float $lat, float $lng, int $secondsAgo): void
    {
        $key = $this->key($lat, $lng);
        if (isset($this->rows[$key])) {
            $this->rows[$key]['fetchedAt'] = gmdate('Y-m-d H:i:s', time() - $secondsAgo);
        }
    }

    public function deleteOlderThan(int $days): int
    {
        $cutoff = time() - $days * 24 * 3600;
        $before = count($this->rows);

        $this->rows = array_filter(
            $this->rows,
            fn (array $row) => strtotime($row['fetchedAt'] . ' UTC') >= $cutoff
        );

        return $before - count($this->rows);
    }

    private function key(float $lat, float $lng): string
    {
        return $lat . ',' . $lng;
    }
}
