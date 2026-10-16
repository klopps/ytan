#!/usr/bin/env php
<?php

declare(strict_types=1);

// Tiny migration runner: applies database/migrations/*.sql and *.php in
// filename order, tracking what already ran in a `schema_migrations` table.
// A .php migration (for data changes plain SQL can't express) returns a
// function(PDO $pdo): void.

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

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

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = array_merge(glob($root . '/database/migrations/*.sql'), glob($root . '/database/migrations/*.php'));
sort($files);

$ranAny = false;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    echo "Applying $name ...\n";
    if (str_ends_with($name, '.php')) {
        (require $file)($pdo);
    } else {
        $pdo->exec(file_get_contents($file));
    }

    $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (?, NOW())');
    $stmt->execute([$name]);
    $ranAny = true;
}

echo $ranAny ? "Done.\n" : "Nothing to do, schema is up to date.\n";
