#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Writes database/schema.sql - the complete current structure of the
 * database .env points at (plus reference rows and the list of applied
 * migrations), which bin/migrate.php loads into an empty database. See
 * Ytan\Database\SchemaDump. Only reads from the database.
 *
 * Usage: php bin/dump-schema.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Ytan\Database\SchemaDump;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_PORT'] ?? '3306',
    $_ENV['DB_DATABASE'] ?? 'ytan',
    $_ENV['DB_CHARSET'] ?? 'utf8mb4'
);
$pdo = new PDO($dsn, $_ENV['DB_USERNAME'] ?? 'root', $_ENV['DB_PASSWORD'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$file = $root . '/database/schema.sql';
file_put_contents($file, SchemaDump::generate($pdo, gmdate('Y-m-d')));
echo "Wrote $file\n";
