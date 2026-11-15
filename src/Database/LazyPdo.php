<?php

declare(strict_types=1);

namespace Ytan\Database;

use Closure;
use PDO;
use PDOStatement;

/**
 * A PDO that connects on its first use instead of in App::create().
 *
 * Every request used to open a database connection up front, also the ones
 * that never touch the database (radar tiles, wind blocks, /sw.js, static
 * template pages without a login). Repositories keep type-hinting PDO and get
 * this subclass; each method opens the real connection once and delegates
 * to it. The parent constructor is deliberately never called - only the
 * overridden methods below may be used.
 */
final class LazyPdo extends PDO
{
    private ?PDO $inner = null;

    /** @param Closure(): PDO $connect */
    public function __construct(private readonly Closure $connect)
    {
    }

    public function isConnected(): bool
    {
        return $this->inner !== null;
    }

    private function pdo(): PDO
    {
        return $this->inner ??= ($this->connect)();
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return $this->pdo()->prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $fetchMode === null
            ? $this->pdo()->query($query)
            : $this->pdo()->query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return $this->pdo()->exec($statement);
    }

    public function beginTransaction(): bool
    {
        return $this->pdo()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo()->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo()->rollBack();
    }

    public function inTransaction(): bool
    {
        // No connection yet = certainly no open transaction; don't connect for that.
        return $this->inner !== null && $this->inner->inTransaction();
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->pdo()->lastInsertId($name);
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return $this->pdo()->quote($string, $type);
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->pdo()->getAttribute($attribute);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->pdo()->setAttribute($attribute, $value);
    }

    public function errorCode(): ?string
    {
        return $this->pdo()->errorCode();
    }

    public function errorInfo(): array
    {
        return $this->pdo()->errorInfo();
    }
}
