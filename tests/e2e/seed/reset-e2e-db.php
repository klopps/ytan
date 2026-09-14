<?php

declare(strict_types=1);

/**
 * Playwright's globalSetup (tests/e2e/global-setup.js) runs this once
 * before the whole e2e run: (1) makes sure the e2e test user exists with
 * a known password and full rights (so every flow - POI/Route/Area
 * creation, context menus, Tours - is reachable without also having to
 * test the permission system itself, which is PHPUnit's job), and
 * (2) wipes anything that user created on a PREVIOUS run, so specs start
 * from a clean, deterministic slate every time.
 *
 * Deliberately hardcodes its own 'ytan_e2e' database name rather than
 * trusting .env's DB_DATABASE - this script's whole purpose is to never
 * be able to accidentally write into the real dev database, even if
 * invoked directly instead of via the e2e npm scripts.
 *
 * This is a SEPARATE database from PHPUnit's 'ytan_test' (composer
 * test-db), not a shared one - found the hard way: an earlier version of
 * this script pointed at ytan_test directly, and leftover e2e-created
 * rows (a POI landing on the same auto-increment id a PHPUnit fixture
 * expected, an extra `user` row skewing count()-based assertions) broke
 * several PHPUnit tests the next time `composer test` ran. `ytan_e2e` is
 * created automatically (cloned from ytan_test's structure, itself
 * already schema-current) the first time this script runs on a machine.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$rootDir = dirname(__DIR__, 3);
Dotenv\Dotenv::createImmutable($rootDir)->load();

const E2E_DB_NAME = 'ytan_e2e';
const E2E_USERNAME = 'e2e_test_user';
const E2E_PASSWORD = 'E2eTest!2026';
const E2E_EMAIL = 'e2e-test-user@example.invalid';

$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? '3306';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';

// --- 0. First run on this machine: ytan_e2e doesn't exist yet - clone it
// from ytan_test's structure (same technique as bin/setup-test-db.php,
// which is what put ytan_test there in the first place). Cloning from
// ytan_test rather than the real dev DB keeps this one hop removed from
// live data, and ytan_test is already guaranteed schema-current by the
// composer test-db / composer test workflow CLAUDE.md documents.
$server = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port), $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = (bool) $server->query('SHOW DATABASES LIKE ' . $server->quote(E2E_DB_NAME))->fetchColumn();
if (!$exists) {
    fwrite(STDERR, '[reset-e2e-db] first run on this machine - creating ' . E2E_DB_NAME . " from ytan_test's structure\n");
    $server->exec('CREATE DATABASE `' . E2E_DB_NAME . '` CHARACTER SET utf8mb4');
    $source = new PDO(sprintf('mysql:host=%s;port=%s;dbname=ytan_test;charset=utf8mb4', $host, $port), $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $target = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, E2E_DB_NAME), $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $source->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $target->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $create = $source->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $target->exec($create['Create Table']);
    }
    $target->exec('SET FOREIGN_KEY_CHECKS=1');
    fwrite(STDERR, '[reset-e2e-db] cloned ' . count($tables) . " tables\n");
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, E2E_DB_NAME),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// --- 1. Reference data: poitype is static lookup data (icon/name pairs),
// not user data - cloned as an empty table by the structure-only copy
// above, so a fresh ytan_e2e has zero rows here. Without it the
// POI-creation test's type dropdown would be empty. Copy once from the
// real dev DB (read-only, never written back) if missing.
$poitypeCount = (int) $pdo->query('SELECT COUNT(*) FROM poitype')->fetchColumn();
if ($poitypeCount === 0) {
    $sourcePdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'] ?? 'localhost', $_ENV['DB_PORT'] ?? '3306', $_ENV['DB_DATABASE'] ?? 'ytan'),
        $_ENV['DB_USERNAME'] ?? 'root',
        $_ENV['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $rows = $sourcePdo->query('SELECT id, name, icon FROM poitype')->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare('INSERT INTO poitype (id, name, icon) VALUES (:id, :name, :icon)');
    foreach ($rows as $row) {
        $insert->execute($row);
    }
    fwrite(STDERR, '[reset-e2e-db] seeded ' . count($rows) . " poitype rows from the dev DB\n");
}

// --- 2. The e2e test user: upsert so re-running is idempotent and the
// password/rights are always reset to the known, expected values.
$passwordHash = password_hash(E2E_PASSWORD, PASSWORD_DEFAULT);
$existingId = $pdo->prepare('SELECT id FROM user WHERE username = ?');
$existingId->execute([E2E_USERNAME]);
$userId = $existingId->fetchColumn();

if ($userId === false) {
    $insertUser = $pdo->prepare(
        'INSERT INTO user (username, email, password, firstname, lastname, is_admin, tour_create, tour_publish, tour_manage, tour_copy, route_view_recording)
         VALUES (:username, :email, :password, :firstname, :lastname, 1, 1, 1, 1, 1, 1)'
    );
    $insertUser->execute([
        'username' => E2E_USERNAME,
        'email' => E2E_EMAIL,
        'password' => $passwordHash,
        'firstname' => 'E2E',
        'lastname' => 'Test',
    ]);
    $userId = (int) $pdo->lastInsertId();
    fwrite(STDERR, "[reset-e2e-db] created e2e test user #$userId\n");
} else {
    $userId = (int) $userId;
    $updateUser = $pdo->prepare(
        'UPDATE user SET password = :password, is_admin = 1, tour_create = 1, tour_publish = 1, tour_manage = 1, tour_copy = 1, route_view_recording = 1 WHERE id = :id'
    );
    $updateUser->execute(['password' => $passwordHash, 'id' => $userId]);
    fwrite(STDERR, "[reset-e2e-db] reset existing e2e test user #$userId\n");
}

// --- 3. Wipe anything this user created on a previous run. Order matters
// for the RESTRICT foreign keys on poi/route/area/tour.user_id: tour
// first (cascades tour_route/tour_tag), then the independent poi/route/
// area rows.
$pdo->prepare('DELETE FROM tour WHERE user_id = ?')->execute([$userId]);
$pdo->prepare('DELETE FROM route WHERE user_id = ?')->execute([$userId]);
$pdo->prepare('DELETE FROM poi WHERE user_id = ?')->execute([$userId]);
$pdo->prepare('DELETE FROM area WHERE user_id = ?')->execute([$userId]);

fwrite(STDERR, "[reset-e2e-db] cleaned up previous e2e-created data for user #$userId\n");

// Machine-readable line for global-setup.js to parse (stdout only, so
// stderr's human-readable progress log above doesn't interfere).
echo json_encode(['userId' => $userId, 'username' => E2E_USERNAME, 'password' => E2E_PASSWORD]) . PHP_EOL;
