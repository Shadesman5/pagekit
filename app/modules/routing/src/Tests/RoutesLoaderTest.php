<?php

namespace Pagekit\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Loader\AnnotationLoader;
use Pagekit\Routing\Route;
use Pagekit\Event\EventDispatcher;

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
            (new Route('/route3'))->setName('route3')
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
            'param' => 'default_value'
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
}