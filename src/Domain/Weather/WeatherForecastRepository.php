<?php

declare(strict_types=1);

namespace Ytan\Domain\Weather;

use PDO;

/**
 * DB-backed replacement for WeatherService's old per-coordinate flat-file
 * cache (see WeatherService's own doc comment for why: this table also
 * needs to hold which provider/model served a forecast, and be queryable
 * by age for a later scheduled cleanup - neither fits a directory of JSON
 * files well). Coordinates are rounded by the caller (WeatherService::
 * COORD_PRECISION, unchanged at 2 decimals) before reaching here, matching
 * the old cache's ~1.1km grid.
 *
 * The UNIQUE KEY on (lat, lng) in the migration is what makes upsert()
 * self-cleaning: a fresh fetch for a coordinate always replaces that
 * coordinate's previous row outright, satisfying the todo item's "delete
 * old data once new data exists for a location" requirement without any
 * extra cleanup logic. deleteOlderThan() below handles the other half of
 * that same todo item - rows nobody has re-fetched in a while (a location
 * that was looked up once and never again) - via bin/cleanup-weather-data.php,
 * the first scheduled task in this project (see that script's own doc
 * comment, and CLAUDE.md's Commands section, for how it's meant to run).
 */
final class WeatherForecastRepository implements WeatherForecastRepositoryInterface
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{regionCode: string, source: string, model: ?string, generatedAt: ?string, fetchedAt: string, payload: array<string, mixed>}|null
     *         null on a cache miss - no row for this coordinate, or the row
     *         is older than $ttlSeconds
     */
    public function findFresh(float $lat, float $lng, int $ttlSeconds): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT region_code, source, model, generated_at, fetched_at, payload
             FROM weather_forecast
             WHERE lat = ? AND lng = ? AND fetched_at >= ?'
        );
        $stmt->execute([$lat, $lng, gmdate('Y-m-d H:i:s', time() - $ttlSeconds)]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'regionCode' => $row['region_code'],
            'source' => $row['source'],
            'model' => $row['model'],
            'generatedAt' => $row['generated_at'],
            'fetchedAt' => $row['fetched_at'],
            'payload' => json_decode((string) $row['payload'], true),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
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
        $stmt = $this->db->prepare(
            'INSERT INTO weather_forecast (lat, lng, region_code, source, model, generated_at, fetched_at, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                region_code = VALUES(region_code),
                source = VALUES(source),
                model = VALUES(model),
                generated_at = VALUES(generated_at),
                fetched_at = VALUES(fetched_at),
                payload = VALUES(payload)'
        );
        $stmt->execute([
            $lat,
            $lng,
            $regionCode,
            $source,
            $model,
            $generatedAt,
            $fetchedAt,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function deleteOlderThan(int $days): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 24 * 3600);

        $stmt = $this->db->prepare('DELETE FROM weather_forecast WHERE fetched_at < ?');
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }
}
