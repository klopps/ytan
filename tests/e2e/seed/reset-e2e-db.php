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
// The user names/passwords come from .env (2026-11-19): E2E_TEST_USERNAME/
// E2E_TEST_PASSWORD = the main test user, every right EXCEPT admin (the same
// user exists on the dev database for manual tests), ADMIN_TEST_USERNAME/
// ADMIN_TEST_PASSWORD = an admin, used only by the specs that need admin
// endpoints (admin-logs, the Routencheck switch). The constants below are
// the fallback for a machine without those lines. Until 2026-11-19 there was
// only one hard-coded user, an admin - LEGACY_USERNAME's leftovers are
// still cleaned up.
const FALLBACK_USERNAME = 'e2e_test_user';
const FALLBACK_PASSWORD = 'E2eTest!2026';
const FALLBACK_ADMIN_USERNAME = 'e2e_admin';
const FALLBACK_ADMIN_PASSWORD = 'E2eAdmin!2026';
const LEGACY_USERNAME = 'e2e_test_user';

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

// A later migration (picked up by ytan_test via `composer test-db`) would
// otherwise never reach an already-existing ytan_e2e - it then failed with
// "Unknown column" errors. Any difference in the column layout between the
// two schemas means: throw ytan_e2e away and clone it again.
if ($exists) {
    $layout = $server->prepare(
        "SELECT GROUP_CONCAT(CONCAT(TABLE_NAME, '.', COLUMN_NAME, ':', COLUMN_TYPE) ORDER BY TABLE_NAME, COLUMN_NAME SEPARATOR ',')
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?"
    );
    $server->exec('SET SESSION group_concat_max_len = 1000000');
    $layout->execute(['ytan_test']);
    $testLayout = $layout->fetchColumn();
    $layout->execute([E2E_DB_NAME]);
    if ($layout->fetchColumn() !== $testLayout) {
        fwrite(STDERR, '[reset-e2e-db] schema differs from ytan_test - recreating ' . E2E_DB_NAME . "\n");
        $server->exec('DROP DATABASE `' . E2E_DB_NAME . '`');
        $exists = false;
    }
}

if (!$exists) {
    fwrite(STDERR, '[reset-e2e-db] creating ' . E2E_DB_NAME . " from ytan_test's structure\n");
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

// app_settings is a single-row table seeded by a migration (009), which the
// structure-only clone doesn't carry - without the row, SettingsRepository
// prints PHP warnings above the page, pushing the map down and making every
// pixel-offset click in the specs miss. All columns have defaults.
$pdo->exec('INSERT IGNORE INTO app_settings (id) VALUES (1)');

// --- 2. The e2e test users: upsert so re-running is idempotent and the
// password/rights are always reset to the known, expected values.
function envOr(string $key, string $fallback): string
{
    $value = trim((string) ($_ENV[$key] ?? ''));

    return $value === '' ? $fallback : $value;
}

/**
 * First name, last name and e-mail of the same user on the dev database
 * (read-only) - the user guide's screenshots (profile chapter) show them, so
 * they should be PaulPaddler's real profile, not "E2E Test". The e-mail is
 * only taken when YTAN_E2E_REAL_EMAIL=1 (set by
 * playwright.screenshots.config.js): a normal e2e run sends mails (tour
 * notifications), which must not reach a real address. Missing row/dev DB =
 * placeholders.
 *
 * @return array{firstname: string, lastname: string, email: string}
 */
function devProfile(string $name, bool $admin): array
{
    $profile = [
        'firstname' => 'E2E',
        'lastname' => $admin ? 'Admin' : 'Test',
        'email' => 'e2e-' . strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name)) . '@example.invalid',
    ];
    try {
        $dev = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'] ?? 'localhost', $_ENV['DB_PORT'] ?? '3306', $_ENV['DB_DATABASE'] ?? 'ytan'),
            $_ENV['DB_USERNAME'] ?? 'root',
            $_ENV['DB_PASSWORD'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $row = $dev->prepare('SELECT firstname, lastname, email FROM user WHERE username = ?');
        $row->execute([$name]);
        $found = $row->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        $found = false;
    }
    if (is_array($found)) {
        if (trim((string) $found['firstname']) !== '' || trim((string) $found['lastname']) !== '') {
            $profile['firstname'] = (string) $found['firstname'];
            $profile['lastname'] = (string) $found['lastname'];
        }
        if (getenv('YTAN_E2E_REAL_EMAIL') === '1' && trim((string) $found['email']) !== '') {
            $profile['email'] = (string) $found['email'];
        }
    }

    return $profile;
}

/** Creates or resets one user; every right on, is_admin as given, profile from devProfile(). Returns its id. */
function upsertE2eUser(PDO $pdo, string $name, string $password, bool $admin): int
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $profile = devProfile($name, $admin);
    $existing = $pdo->prepare('SELECT id FROM user WHERE username = ?');
    $existing->execute([$name]);
    $id = $existing->fetchColumn();
    if ($id === false) {
        $pdo->prepare(
            'INSERT INTO user (username, email, password, firstname, lastname, is_admin, tour_create, tour_publish, tour_manage, tour_copy, route_view_recording, export_routes_own, export_routes_public, ai_use)
             VALUES (:username, :email, :password, :firstname, :lastname, :admin, 1, 1, 1, 1, 1, 1, 1, 1)'
        )->execute(['username' => $name, 'password' => $hash, 'admin' => (int) $admin] + $profile);
        $id = (int) $pdo->lastInsertId();
        fwrite(STDERR, "[reset-e2e-db] created e2e user #$id" . ($admin ? ' (admin)' : '') . "\n");
    } else {
        $id = (int) $id;
        $pdo->prepare(
            'UPDATE user SET password = :password, email = :email, firstname = :firstname, lastname = :lastname, is_admin = :admin, tour_create = 1, tour_publish = 1, tour_manage = 1, tour_copy = 1, route_view_recording = 1, export_routes_own = 1, export_routes_public = 1, ai_use = 1 WHERE id = :id'
        )->execute(['password' => $hash, 'admin' => (int) $admin, 'id' => $id] + $profile);
        fwrite(STDERR, "[reset-e2e-db] reset existing e2e user #$id" . ($admin ? ' (admin)' : '') . "\n");
    }

    return $id;
}

$userName = envOr('E2E_TEST_USERNAME', FALLBACK_USERNAME);
$userPassword = envOr('E2E_TEST_PASSWORD', FALLBACK_PASSWORD);
$adminName = envOr('ADMIN_TEST_USERNAME', FALLBACK_ADMIN_USERNAME);
$adminPassword = envOr('ADMIN_TEST_PASSWORD', FALLBACK_ADMIN_PASSWORD);
if (strcasecmp($userName, $adminName) === 0) {
    fwrite(STDERR, "[reset-e2e-db] E2E_TEST_USERNAME and ADMIN_TEST_USERNAME must differ\n");
    exit(1);
}

$userId = upsertE2eUser($pdo, $userName, $userPassword, false);
$adminId = upsertE2eUser($pdo, $adminName, $adminPassword, true);

// --- 3. Wipe anything these users created on a previous run (plus the
// pre-2026-11-19 single user's). Order matters for the RESTRICT foreign
// keys on poi/route/area/tour.user_id: tour first (cascades tour_route/
// tour_tag), then the independent poi/route/area rows.
$legacy = $pdo->prepare('SELECT id FROM user WHERE username = ?');
$legacy->execute([LEGACY_USERNAME]);
$cleanIds = array_unique(array_filter([$userId, $adminId, (int) $legacy->fetchColumn()]));
foreach ($cleanIds as $id) {
    foreach (['tour', 'route', 'poi', 'area'] as $table) {
        $pdo->prepare("DELETE FROM `$table` WHERE user_id = ?")->execute([$id]);
    }
    fwrite(STDERR, "[reset-e2e-db] cleaned up previous e2e-created data for user #$id\n");
}

// Machine-readable line for global-setup.js to parse (stdout only, so
// stderr's human-readable progress log above doesn't interfere).
echo json_encode([
    'userId' => $userId, 'username' => $userName, 'password' => $userPassword,
    'admin' => ['userId' => $adminId, 'username' => $adminName, 'password' => $adminPassword],
]) . PHP_EOL;
