<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\App;
use Ytan\LazyContainer;
use Ytan\Tests\TestCase;

/**
 * App::create() registers its objects in a LazyContainer and the routes as
 * [SomeController::class, 'method'] - a typo there would only show up when
 * that route is requested, so this resolves every route once.
 */
final class AppWiringTest extends TestCase
{
    public function testCreatesNoControllerUpFrontAndEveryRouteResolves(): void
    {
        $app = App::create(dirname(__DIR__, 2));
        $container = $app->getContainer();
        $this->assertInstanceOf(LazyContainer::class, $container);

        $controllerRoutes = 0;
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $callable = $route->getCallable();
            if (!is_array($callable)) {
                continue; // a closure (HTML pages, health, csp-report)
            }
            [$class, $method] = $callable;
            $this->assertFalse($container->isBuilt($class), "$class built before its route ran");
            $this->assertTrue($container->has($class), "no container entry for $class ({$route->getPattern()})");
            $controllerRoutes++;
        }
        $this->assertGreaterThan(90, $controllerRoutes);

        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $callable = $route->getCallable();
            if (is_array($callable)) {
                [$class, $method] = $callable;
                $this->assertTrue(method_exists($container->get($class), $method), "$class::$method() missing ({$route->getPattern()})");
            }
        }
    }

    public function testContainerBuildsAnEntryOnceAndOnlyWhenAsked(): void
    {
        $container = new LazyContainer();
        $builds = 0;
        $container->set('thing', function () use (&$builds) {
            $builds++;
            return new \stdClass();
        });

        $this->assertSame(0, $builds);
        $this->assertSame($container->get('thing'), $container->get('thing'));
        $this->assertSame(1, $builds);
        $this->assertFalse($container->has('other'));

        $this->expectException(\Psr\Container\NotFoundExceptionInterface::class);
        $container->get('other');
    }
}
