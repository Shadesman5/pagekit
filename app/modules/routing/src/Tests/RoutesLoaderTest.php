<?php

declare(strict_types=1);

namespace Pagekit\Routing\Tests;

use Pagekit\Application;
use Pagekit\Event\EventDispatcher;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Route;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RoutesLoaderTest extends TestCase
{
    protected RoutesLoader $loader;
    protected EventDispatcher $events;

    protected function setUp(): void
    {
        $this->events = new EventDispatcher();
        $this->loader = new RoutesLoader($this->events);
    }

    public function testLoadSimpleRoute(): void
    {
        $route = new Route('/test');
        $route->setName('test_route');
        $route->setDefaults(['_controller' => 'TestController::testAction']);

        $collection = $this->loader->load([$route]);

        $this->assertCount(1, $collection);
        $this->assertNotNull($collection->get('test_route'));
        $this->assertEquals('/test', $collection->get('test_route')->getPath());
    }

    public function testLoadMultipleRoutes(): void
    {
        $routes = [
            (new Route('/route1'))->setName('route1'),
            (new Route('/route2'))->setName('route2'),
            (new Route('/route3'))->setName('route3'),
        ];

        $collection = $this->loader->load($routes);

        $this->assertCount(3, $collection);
        $this->assertNotNull($collection->get('route1'));
        $this->assertNotNull($collection->get('route2'));
        $this->assertNotNull($collection->get('route3'));
    }

    public function testLoadRouteWithController(): void
    {
        // Test with a mock controller class
        $route = new Route('/controller');
        $route->setName('controller_route');
        $route->setOption('controller', 'NonExistentController');

        $collection = $this->loader->load([$route]);

        // Should still add the route even if controller doesn't exist
        $this->assertCount(1, $collection);
        $this->assertNotNull($collection->get('controller_route'));
    }

    public function testRouteConfigureEvent(): void
    {
        $eventFired = false;
        $routeName = null;

        $this->events->on('route.configure', function ($event, $route, $collection) use (&$eventFired, &$routeName) {
            $eventFired = true;
            $routeName = $route->getName();
        });

        $route = new Route('/event');
        $route->setName('event_route');

        $this->loader->load([$route]);

        $this->assertTrue($eventFired);
        $this->assertEquals('event_route', $routeName);
    }

    public function testLoadRouteWithDefaults(): void
    {
        $route = new Route('/defaults/{param}');
        $route->setName('defaults_route');
        $route->setDefaults([
            '_controller' => 'TestController::defaultsAction',
            'param' => 'default_value',
        ]);

        $collection = $this->loader->load([$route]);
        $loadedRoute = $collection->get('defaults_route');

        $this->assertNotNull($loadedRoute);
        $this->assertEquals('default_value', $loadedRoute->getDefault('param'));
        $this->assertEquals('TestController::defaultsAction', $loadedRoute->getDefault('_controller'));
    }

    public function testLoadRouteWithRequirements(): void
    {
        $route = new Route('/requirements/{id}');
        $route->setName('requirements_route');
        $route->setRequirements(['id' => '\d+']);

        $collection = $this->loader->load([$route]);
        $loadedRoute = $collection->get('requirements_route');

        $this->assertNotNull($loadedRoute);
        $this->assertEquals('\d+', $loadedRoute->getRequirement('id'));
    }

    public function testLoadRouteWithMethods(): void
    {
        $route = new Route('/methods');
        $route->setName('methods_route');
        $route->setMethods(['GET', 'POST']);

        $collection = $this->loader->load([$route]);
        $loadedRoute = $collection->get('methods_route');

        $this->assertNotNull($loadedRoute);
        $this->assertEquals(['GET', 'POST'], $loadedRoute->getMethods());
    }

    public function testLoadRouteWithHost(): void
    {
        $route = new Route('/host');
        $route->setName('host_route');
        $route->setHost('example.com');

        $collection = $this->loader->load([$route]);
        $loadedRoute = $collection->get('host_route');

        $this->assertNotNull($loadedRoute);
        $this->assertEquals('example.com', $loadedRoute->getHost());
    }

    public function testLoadRouteWithSchemes(): void
    {
        $route = new Route('/schemes');
        $route->setName('schemes_route');
        $route->setSchemes(['https']);

        $collection = $this->loader->load([$route]);
        $loadedRoute = $collection->get('schemes_route');

        $this->assertNotNull($loadedRoute);
        $this->assertEquals(['https'], $loadedRoute->getSchemes());
    }

    public function testAddControllerRethrowsInDebugMode(): void
    {
        $app = $this->createMock(Application::class);
        $app->method('has')->willReturnMap([
            ['debug', true],
            ['log', false],
        ]);
        $app->method('get')->willReturnMap([
            ['debug', true],
        ]);

        $loader = new RoutesLoader($this->events, null, $app);

        $route = new Route('/abstract');
        $route->setName('abstract_route');
        $route->setOption('controller', RoutesLoaderTestAbstractController::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/abstract/i');

        $loader->load([$route]);
    }

    public function testAddControllerLogsViaLoggerInProduction(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains(RoutesLoaderTestAbstractController::class));

        $app = $this->createMock(Application::class);
        $app->method('has')->willReturnMap([
            ['debug', true],
            ['log', true],
        ]);
        $app->method('get')->willReturnMap([
            ['debug', false],
            ['log', $logger],
        ]);

        $loader = new RoutesLoader($this->events, null, $app);

        $route = new Route('/abstract');
        $route->setName('abstract_route');
        $route->setOption('controller', RoutesLoaderTestAbstractController::class);

        $loader->load([$route]);
    }

    public function testAddControllerFallsBackToErrorLogWhenNoLogService(): void
    {
        $app = $this->createMock(Application::class);
        $app->method('has')->willReturnMap([
            ['debug', true],
            ['log', false],
        ]);
        $app->method('get')->willReturnMap([
            ['debug', false],
        ]);

        $loader = new RoutesLoader($this->events, null, $app);

        $route = new Route('/abstract');
        $route->setName('abstract_route');
        $route->setOption('controller', RoutesLoaderTestAbstractController::class);

        $this->assertErrorLogContainsControllerName(
            fn () => $loader->load([$route]),
            RoutesLoaderTestAbstractController::class
        );
    }

    public function testAddControllerFallsBackToErrorLogWhenNoApplicationInjected(): void
    {
        $loader = new RoutesLoader($this->events);

        $route = new Route('/abstract');
        $route->setName('abstract_route');
        $route->setOption('controller', RoutesLoaderTestAbstractController::class);

        $this->assertErrorLogContainsControllerName(
            fn () => $loader->load([$route]),
            RoutesLoaderTestAbstractController::class
        );
    }

    /**
     * Captures error_log() output to a temp file and asserts it contains the given controller name.
     */
    private function assertErrorLogContainsControllerName(callable $action, string $controllerName): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pk-routes-loader-test-');
        $previousErrorLog = ini_get('error_log');
        $previousLogErrors = ini_get('log_errors');

        ini_set('error_log', $tmp);
        ini_set('log_errors', '1');

        try {
            $action();

            $contents = file_get_contents($tmp);
            $this->assertNotFalse($contents);
            $this->assertStringContainsString($controllerName, $contents);
        } finally {
            ini_set('error_log', $previousErrorLog);
            ini_set('log_errors', $previousLogErrors);

            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
    }
}

/**
 * Fixture: an abstract controller whose loading triggers
 * \InvalidArgumentException inside AttributeLoader::load(),
 * exercising the catch block in RoutesLoader::addController().
 */
abstract class RoutesLoaderTestAbstractController
{
    public function indexAction(): void
    {
    }
}
