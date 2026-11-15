<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Database\Transaction;
use Ytan\Tests\TestCase;

final class TransactionTest extends TestCase
{
    public function testInsideAnOpenTransactionItOnlyRunsTheWork(): void
    {
        // TestCase already holds a transaction around every test
        $this->assertTrue($this->pdo->inTransaction());

        $result = Transaction::run($this->pdo, fn () => 42);

        $this->assertSame(42, $result);
        $this->assertTrue($this->pdo->inTransaction(), 'the outer transaction is left alone');
    }

    public function testAFailureInsideAnOpenTransactionIsRethrownWithoutRollingBackTheOuterOne(): void
    {
        try {
            Transaction::run($this->pdo, fn () => throw new \RuntimeException('boom'));
            $this->fail('expected the exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertTrue($this->pdo->inTransaction());
    }

    public function testWithoutAnOpenTransactionItCommitsOrRollsBack(): void
    {
        // leave TestCase's transaction for this test, re-opened at the end so its tearDown can roll back
        $this->pdo->rollBack();
        try {
            $this->pdo->exec('CREATE TEMPORARY TABLE ytan_tx_test (v INT)');
            Transaction::run($this->pdo, fn () => $this->pdo->exec('INSERT INTO ytan_tx_test VALUES (1)'));
            try {
                Transaction::run($this->pdo, function () {
                    $this->pdo->exec('INSERT INTO ytan_tx_test VALUES (2)');
                    throw new \RuntimeException('undo');
                });
            } catch (\RuntimeException) {
            }

            $this->assertFalse($this->pdo->inTransaction());
            $this->assertSame([1], array_map('intval', $this->pdo->query('SELECT v FROM ytan_tx_test')->fetchAll(\PDO::FETCH_COLUMN)));
        } finally {
            $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS ytan_tx_test');
            $this->pdo->beginTransaction();
        }
    }
}
