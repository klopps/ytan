<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Weather\WeatherForecastRepository;
use Ytan\Tests\TestCase;

final class WeatherForecastRepositoryTest extends TestCase
{
    private WeatherForecastRepository $forecasts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forecasts = new WeatherForecastRepository($this->pdo);
    }

    public function testFindFreshReturnsNullWhenNoRowExists(): void
    {
        $this->assertNull($this->forecasts->findFresh(54.3, 10.15, 1800));
    }

    public function testUpsertThenFindFreshReturnsTheStoredRow(): void
    {
        $this->forecasts->upsert(
            54.3,
            10.15,
            'default',
            'Open-Meteo',
            null,
            null,
            gmdate('Y-m-d H:i:s'),
            ['hourly' => [['time' => '2026-09-12T00:00', 'temperature' => 15.7]]],
        );

        $row = $this->forecasts->findFresh(54.3, 10.15, 1800);

        $this->assertNotNull($row);
        $this->assertSame('default', $row['regionCode']);
        $this->assertSame('Open-Meteo', $row['source']);
        $this->assertNull($row['model']);
        $this->assertSame(15.7, $row['payload']['hourly'][0]['temperature']);
    }

    public function testFindFreshReturnsNullWhenTheRowIsOlderThanTheTtl(): void
    {
        $this->forecasts->upsert(
            54.3,
            10.15,
            'default',
            'Open-Meteo',
            null,
            null,
            gmdate('Y-m-d H:i:s', time() - 3600),
            ['hourly' => []],
        );

        $this->assertNull($this->forecasts->findFresh(54.3, 10.15, 1800));
    }

    public function testASecondUpsertForTheSameCoordinateReplacesTheFirst(): void
    {
        $this->forecasts->upsert(54.3, 10.15, 'default', 'Open-Meteo', null, null, gmdate('Y-m-d H:i:s'), ['hourly' => ['old']]);
        // A plain SQL DATETIME string, as WeatherService::toMysqlDatetime()
        // hands the repository - the repository itself does no parsing/
        // reformatting of its own.
        $this->forecasts->upsert(54.3, 10.15, 'SE', 'SMHI', 'snow1g', '2026-09-18 20:45:00', gmdate('Y-m-d H:i:s'), ['hourly' => ['new']]);

        $row = $this->forecasts->findFresh(54.3, 10.15, 1800);

        $this->assertSame('SMHI', $row['source']);
        $this->assertSame('snow1g', $row['model']);
        $this->assertSame(['new'], $row['payload']['hourly']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM weather_forecast WHERE lat = 54.3 AND lng = 10.15')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testDifferentCoordinatesAreStoredAsSeparateRows(): void
    {
        $this->forecasts->upsert(54.3, 10.15, 'default', 'Open-Meteo', null, null, gmdate('Y-m-d H:i:s'), ['hourly' => []]);
        $this->forecasts->upsert(59.33, 18.07, 'SE', 'SMHI', 'snow1g', null, gmdate('Y-m-d H:i:s'), ['hourly' => []]);

        $this->assertNotNull($this->forecasts->findFresh(54.3, 10.15, 1800));
        $this->assertNotNull($this->forecasts->findFresh(59.33, 18.07, 1800));
    }

    public function testDeleteOlderThanRemovesOnlyRowsPastTheCutoff(): void
    {
        // 8 days old - past the cutoff.
        $this->forecasts->upsert(54.3, 10.15, 'default', 'Open-Meteo', null, null, gmdate('Y-m-d H:i:s', time() - 8 * 24 * 3600), ['hourly' => []]);
        // 6 days old - still within the cutoff, must survive.
        $this->forecasts->upsert(59.33, 18.07, 'SE', 'SMHI', 'snow1g', null, gmdate('Y-m-d H:i:s', time() - 6 * 24 * 3600), ['hourly' => []]);

        $deleted = $this->forecasts->deleteOlderThan(7);

        $this->assertSame(1, $deleted);
        $this->assertNull($this->forecasts->findFresh(54.3, 10.15, PHP_INT_MAX));
        $this->assertNotNull($this->forecasts->findFresh(59.33, 18.07, PHP_INT_MAX));
    }

    public function testDeleteOlderThanReturnsZeroWhenNothingIsOldEnough(): void
    {
        $this->forecasts->upsert(54.3, 10.15, 'default', 'Open-Meteo', null, null, gmdate('Y-m-d H:i:s'), ['hourly' => []]);

        $this->assertSame(0, $this->forecasts->deleteOlderThan(7));
        $this->assertNotNull($this->forecasts->findFresh(54.3, 10.15, PHP_INT_MAX));
    }

    public function testDeleteOlderThanIsExclusiveRightAtTheCutoff(): void
    {
        // A few seconds on either side of the exact 7-day cutoff, rather
        // than exactly "now" (which would race against the wall-clock
        // second ticking over between upsert()'s gmdate() and
        // deleteOlderThan()'s own time() a moment later).
        $this->forecasts->upsert(54.3, 10.15, 'default', 'Open-Meteo', null, null, gmdate('Y-m-d H:i:s', time() - (7 * 24 * 3600 - 5)), ['hourly' => []]);
        $this->forecasts->upsert(59.33, 18.07, 'SE', 'SMHI', 'snow1g', null, gmdate('Y-m-d H:i:s', time() - (7 * 24 * 3600 + 5)), ['hourly' => []]);

        $deleted = $this->forecasts->deleteOlderThan(7);

        $this->assertSame(1, $deleted);
        $this->assertNotNull($this->forecasts->findFresh(54.3, 10.15, PHP_INT_MAX));
        $this->assertNull($this->forecasts->findFresh(59.33, 18.07, PHP_INT_MAX));
    }
}
