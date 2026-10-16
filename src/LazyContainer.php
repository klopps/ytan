<?php

declare(strict_types=1);

namespace Ytan;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Minimal PSR-11 container for App::create(): every repository, service and
 * controller is registered as a factory and only built (and its classes
 * autoloaded) the first time something asks for it - a request then builds
 * just what its route needs instead of all ~40 objects. Each entry is built
 * once per request (shared).
 *
 * Slim resolves a route callable `[SomeController::class, 'method']` through
 * this container (`AppFactory::setContainer()`), so a controller is created
 * only when its route matched.
 */
final class LazyContainer implements ContainerInterface
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @param Closure(self): mixed $factory */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new class ("No container entry '$id'.") extends RuntimeException implements NotFoundExceptionInterface {
            };
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    /** Whether the entry has been built already (for tests). */
    public function isBuilt(string $id): bool
    {
        return array_key_exists($id, $this->instances);
    }
}
