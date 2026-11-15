<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use PDO;
use Ytan\Database\Connection;
use Ytan\Database\LazyPdo;
use Ytan\Tests\TestCase;

final class LazyPdoTest extends TestCase
{
    public function testConnectsOnlyOnFirstQueryAndOnlyOnce(): void
    {
        $connects = 0;
        $pdo = new LazyPdo(function () use (&$connects): PDO {
            $connects++;

            return Connection::fromEnv();
        });

        $this->assertFalse($pdo->isConnected());
        $this->assertFalse($pdo->inTransaction(), 'Asking for a transaction must not connect.');
        $this->assertSame(0, $connects);

        $this->assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
        $stmt = $pdo->prepare('SELECT ? + 1');
        $stmt->execute([41]);
        $this->assertSame(42, (int) $stmt->fetchColumn());

        $this->assertSame(1, $connects);
        $this->assertTrue($pdo->isConnected());
    }

    public function testFetchModeAndTransactionsAreDelegated(): void
    {
        $pdo = new LazyPdo(fn () => Connection::fromEnv());

        $this->assertSame([[1]], $pdo->query('SELECT 1', PDO::FETCH_NUM)->fetchAll());
        $this->assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));

        $this->assertTrue($pdo->beginTransaction());
        $this->assertTrue($pdo->inTransaction());
        $this->assertTrue($pdo->rollBack());
        $this->assertFalse($pdo->inTransaction());
    }
}
