<?php

declare(strict_types=1);

namespace Ytan\Service\Log;

use Closure;
use Throwable;
use Ytan\Exception\ApiException;

/**
 * The log of one import tool (storage/logs/{tool}-import.log, the same file
 * the tool's CLI script writes): every action the admin page triggers -
 * loading a source, reading a transfer file, importing a selection - as one
 * line with who did it, the parameters, the result and the time it took, or
 * the error. Actions that fetch from outside (Overpass, NGA, the official
 * lists) also get a "started" line first, so a request that dies (PHP time
 * limit, server restart) still leaves a trace.
 *
 * The LogWriter is created on the first line only (no file access for the
 * list requests that never log), and writing never throws.
 */
final class ImportLog
{
    private ?LogWriter $writer = null;

    /** @param Closure(): LogWriter|null $factory */
    public function __construct(private readonly ?Closure $factory = null)
    {
    }

    public static function none(): self
    {
        return new self();
    }

    public static function file(string $rootDir, string $name): self
    {
        return new self(fn () => new LogWriter($rootDir . '/storage/logs/' . $name . '.log'));
    }

    /**
     * Runs $action and logs its outcome; the result is returned unchanged and
     * an exception re-thrown after it was logged.
     *
     * @template T
     * @param array<string,mixed>|null $authUser the JWT payload (username, sub)
     * @param array<string,mixed> $params shown as key=value
     * @param callable(): T $action
     * @param (callable(T): array<string,mixed>)|null $summary what of the result to log (default: all of it)
     * @return T
     */
    public function run(?array $authUser, string $what, array $params, callable $action, bool $announce = false, ?callable $summary = null): mixed
    {
        $head = self::who($authUser) . ': ' . $what . self::describe($params, ' ');
        if ($announce) {
            $this->write($head . ' - started');
        }
        $started = microtime(true);
        try {
            $result = $action();
        } catch (Throwable $e) {
            $status = $e instanceof ApiException ? ' (HTTP ' . $e->getStatusCode() . ')' : '';
            $this->write('ERROR ' . $head . ' - FAILED' . $status . ': ' . $e->getMessage() . self::took($started));
            throw $e;
        }
        $logged = $summary !== null ? $summary($result) : $result;
        $this->write($head . ' - ' . (is_array($logged) && $logged !== [] ? 'result' . self::describe($logged, ' ') : 'done') . self::took($started));

        return $result;
    }

    /** One free line (e.g. a CLI step). */
    public function write(string $line): void
    {
        if ($this->factory === null) {
            return;
        }
        try {
            $this->writer ??= ($this->factory)();
            $this->writer->line($line);
        } catch (Throwable) {
            // a log must never break the import itself
        }
    }

    /**
     * key=value pairs of the scalars; lists and objects as their size
     * ("warnings=3"), so a result with thousands of ids stays one short line.
     *
     * @param array<mixed> $values
     */
    public static function describe(array $values, string $prefix = ''): string
    {
        $parts = [];
        foreach ($values as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            } elseif (is_array($value)) {
                $value = count($value);
            } elseif ($value === null) {
                $value = '-';
            } elseif (!is_scalar($value)) {
                continue;
            }
            $value = (string) $value;
            if (mb_strlen($value) > 120) {
                $value = mb_substr($value, 0, 117) . '...';
            }
            $parts[] = $key . '=' . str_replace(["\r", "\n"], ' ', $value);
        }

        return $parts === [] ? '' : $prefix . implode(' ', $parts);
    }

    /** @param array<string,mixed>|null $authUser */
    private static function who(?array $authUser): string
    {
        if ($authUser === null) {
            return 'cli';
        }
        $name = (string) ($authUser['username'] ?? '');

        return $name !== '' ? $name : 'user #' . (int) ($authUser['sub'] ?? 0);
    }

    private static function took(float $started): string
    {
        return sprintf(' (%.1f s)', microtime(true) - $started);
    }
}
