<?php

declare(strict_types=1);

namespace Ytan\Tests;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Ytan\Database\Connection;

/**
 * Base class for tests that touch the database (tests/Integration/*).
 * Every test method runs inside a transaction that's rolled back in
 * tearDown(), so tests never need to clean up after themselves and never
 * see another test's leftover rows - regardless of test order.
 *
 * tests/bootstrap.php has already pointed DB_DATABASE at the dedicated
 * `<name>_test` database before this class (or Connection::fromEnv())
 * is ever touched - see bin/setup-test-db.php for how that database gets
 * its structure.
 */
abstract class TestCase extends BaseTestCase
{
    private static ?PDO $sharedPdo = null;
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$sharedPdo === null) {
            self::$sharedPdo = Connection::fromEnv();
        }
        $this->pdo = self::$sharedPdo;
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        parent::tearDown();
    }

    /**
     * @return int the new user's id
     */
    protected function createUser(array $overrides = []): int
    {
        $data = array_merge([
            'username' => 'user_' . bin2hex(random_bytes(4)),
            'email' => bin2hex(random_bytes(4)) . '@example.test',
            'firstname' => 'Test',
            'lastname' => 'User',
            'is_admin' => 0,
            'tour_create' => 0,
            'tour_publish' => 0,
            'tour_manage' => 0,
            'tour_copy' => 0,
            'route_view_recording' => 0,
        ], $overrides);

        $stmt = $this->pdo->prepare(
            'INSERT INTO user (username, email, firstname, lastname, is_admin, tour_create, tour_publish, tour_manage, tour_copy, route_view_recording, password)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->execute([
            $data['username'],
            $data['email'],
            $data['firstname'],
            $data['lastname'],
            $data['is_admin'],
            $data['tour_create'],
            $data['tour_publish'],
            $data['tour_manage'],
            $data['tour_copy'],
            $data['route_view_recording'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return int the new route's id
     */
    protected function createRoute(int $userId, array $overrides = []): int
    {
        $data = array_merge([
            'name' => 'Test Route ' . bin2hex(random_bytes(3)),
            'description' => '',
            'length' => 1000,
            'points' => '[]',
            'public' => 0,
            'color' => '#BF409F',
            'recorded_at' => null,
            'recording_duration_seconds' => null,
        ], $overrides);

        $stmt = $this->pdo->prepare(
            'INSERT INTO route (user_id, name, description, length, points, public, color, recorded_at, recording_duration_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['name'],
            $data['description'],
            $data['length'],
            $data['points'],
            $data['public'],
            $data['color'],
            $data['recorded_at'],
            $data['recording_duration_seconds'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Builds a minimal decoded-JWT-shaped auth payload, as
     * BaseController::requireAuthUser()/etc. expect on the "auth" request
     * attribute - for tests that call a controller method directly rather
     * than going through AuthMiddleware.
     */
    protected function authPayload(int $userId, array $overrides = []): array
    {
        return array_merge([
            'sub' => $userId,
            'username' => 'user' . $userId,
            'email' => 'user' . $userId . '@example.test',
            'firstname' => 'Test',
            'lastname' => 'User',
            'is_admin' => false,
            'tour_create' => false,
            'tour_publish' => false,
            'tour_manage' => false,
            'tour_copy' => false,
            'route_view_recording' => false,
        ], $overrides);
    }
}
