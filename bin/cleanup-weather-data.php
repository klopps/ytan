#!/usr/bin/env php
<?php

declare(strict_types=1);

// Deletes weather_forecast rows older than 7 days (todo.md's "Wetterdaten"
// item: "Alle Wetterdaten, die älter als 7 Tage sind, sollen regelmäßig
// (mindestens einmal täglich) aus der Datenbank entfernt werden"). The
// other half of that requirement - deleting a location's OLD forecast the
// moment a fresh one is fetched - is already handled automatically by
// WeatherForecastRepository::upsert()'s ON DUPLICATE KEY UPDATE, so this
// script only needs to catch rows nobody has re-fetched at all (a location
// looked up once and never again, which would otherwise sit forever).
//
// This is the first scheduled/recurring task in this project - nothing
// invokes it automatically. Add a cron entry (or systemd timer) on each
// deployed host yourself, e.g. once a day at a quiet hour:
//   0 3 * * * /usr/bin/php /path/to/ytan/bin/cleanup-weather-data.php >> /path/to/ytan/storage/logs/weather-cleanup.log 2>&1
// See CLAUDE.md's Commands section.

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Ytan\Database\Connection;
use Ytan\Domain\Weather\WeatherForecastRepository;

const MAX_AGE_DAYS = 7;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$forecasts = new WeatherForecastRepository(Connection::fromEnv());
$deleted = $forecasts->deleteOlderThan(MAX_AGE_DAYS);

echo "Deleted $deleted weather_forecast row(s) older than " . MAX_AGE_DAYS . " days.\n";
