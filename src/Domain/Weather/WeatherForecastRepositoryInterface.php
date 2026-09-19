<?php

declare(strict_types=1);

namespace Ytan\Domain\Weather;

/**
 * Injection seam for WeatherService's DB-backed cache - same reasoning as
 * JsonHttpClient (src/Service/JsonHttpClient.php): WeatherForecastRepository
 * exists solely as a swappable collaborator for WeatherService, not a
 * general-purpose domain repository consumed from many places the way
 * PoiRepository/RouteRepository etc. are (those stay plain concrete
 * classes, no interface, per this codebase's usual convention) - so
 * tests/Fixtures/FakeWeatherForecastRepository.php can stand in without a
 * real database, exactly like FakeJsonHttpClient does for JsonHttpClient.
 */
interface WeatherForecastRepositoryInterface
{
    /**
     * @return array{regionCode: string, source: string, model: ?string, generatedAt: ?string, fetchedAt: string, payload: array<string, mixed>}|null
     */
    public function findFresh(float $lat, float $lng, int $ttlSeconds): ?array;

    /**
     * @param ?string $generatedAt plain SQL `Y-m-d H:i:s` (UTC), NOT an
     *        ISO 8601 string - the real weather_forecast.generated_at
     *        column is a DATETIME and rejects a trailing "Z" outright.
     *        WeatherService::toMysqlDatetime() does this conversion from
     *        whatever ISO string a provider handed back; callers of this
     *        method are expected to have already done the same.
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
    ): void;

    /**
     * Deletes every row whose fetched_at is older than $days days, for the
     * todo item's "delete anything older than 7 days, at least daily"
     * requirement (bin/cleanup-weather-data.php). Distinct from upsert()'s
     * own self-cleaning behavior (which only ever touches the ONE row for
     * a coordinate being re-fetched) - this catches rows nobody has
     * re-fetched at all, which would otherwise sit forever.
     *
     * @return int number of rows deleted
     */
    public function deleteOlderThan(int $days): int;
}
