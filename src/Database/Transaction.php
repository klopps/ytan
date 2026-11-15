<?php

declare(strict_types=1);

namespace Ytan\Database;

use PDO;
use Throwable;

/**
 * Runs a callable inside one database transaction: commit on success,
 * rollback and rethrow on any exception. Safe to nest - inside an already
 * open transaction (a caller's, or a test's in TestCase) it just runs the
 * callable and leaves commit/rollback to the outer owner.
 */
final class Transaction
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function run(PDO $pdo, callable $work): mixed
    {
        if ($pdo->inTransaction()) {
            return $work();
        }

        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
