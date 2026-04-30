<?php

declare(strict_types=1);

namespace Pagekit\Routing\Tests;

use Pagekit\Routing\Route;
use PHPUnit\Framework\TestCase;

class RouteTest extends TestCase
{
    public function testRouteName(): void
    {
        $route = new Route('/test');
        $route->setName('test_route');

        $this->assertEquals('test_route', $route->getName());
    }

    public function testRouteNameTrimming(): void
    {
        $route = new Route('/test');
        $route->setName('/trimmed/route/');

        $this->assertEquals('trimmed/route', $route->getName());
    }

    public function testGetController(): void
    {
        $route = new Route('/test');
        $route->setDefault('_controller', 'TestController::testAction');

        $controller = $route->getController();

        $this->assertIsArray($controller);
        $this->assertCount(2, $controller);
        $this->assertEquals('TestController', $controller[0]);
        $this->assertEquals('testAction', $controller[1]);
    }

    public function testGetControllerWithCallable(): void
    {
        $callable = function () {
            return 'test';
        };
        $route = new Route('/test');
        $route->setDefault('_controller', $callable);

        $controller = $route->getController();

        $this->assertEquals($callable, $controller);
    }

    public function testGetControllerClass(): void
    {
        $route = new Route('/test');
        $route->setDefault('_controller', 'stdClass::method');

        $reflection = $route->getControllerClass();

        $this->assertInstanceOf(\ReflectionClass::class, $reflection);
        $this->assertEquals('stdClass', $reflection->getName());
    }

    public function testGetControllerClassReturnsNull(): void
    {
        $route = new Route('/test');
        $route->setDefault('_controller', function () {
        });

        $reflection = $route->getControllerClass();

        $this->assertNull($reflection);
    }

    public function testGetControllerMethod(): void
    {
        $route = new Route('/test');
        // Using DateTime as it has public methods we can test
        $route->setDefault('_controller', 'DateTime::createFromFormat');

        $reflection = $route->getControllerMethod();

        $this->assertInstanceOf(\ReflectionMethod::class, $reflection);
        $this->assertEquals('createFromFormat', $reflection->getName());
    }

    public function testGetControllerMethodReturnsNull(): void
    {
        $route = new Route('/test');
        $route->setDefault('_controller', function () {
        });

        $reflection = $route->getControllerMethod();

        $this->assertNull($reflection);
    }

    public function testRouteInheritsFromSymfonyRoute(): void
    {
        $route = new Route('/test');

        $this->assertInstanceOf(\Symfony\Component\Routing\Route::class, $route);
    }

    public function testRouteDefaults(): void
    {
        $route = new Route('/test');
        $route->setDefaults([
            '_controller' => 'TestController::testAction',
            'param1' => 'value1',
            'param2' => 'value2',
        ]);

        $this->assertEquals('TestController::testAction', $route->getDefault('_controller'));
        $this->assertEquals('value1', $route->getDefault('param1'));
        $this->assertEquals('value2', $route->getDefault('param2'));
    }

    public function testRouteRequirements(): void
    {
        $route = new Route('/test/{id}');
        $route->setRequirements([
            'id' => '\d+',
            '_method' => 'GET|POST',
        ]);

        $this->assertEquals('\d+', $route->getRequirement('id'));
        $this->assertEquals('GET|POST', $route->getRequirement('_method'));
    }

    public function testRouteMethods(): void
    {
        $route = new Route('/test');
        $route->setMethods(['GET', 'POST']);

        $this->assertEquals(['GET', 'POST'], $route->getMethods());
    }

    public function testRouteHost(): void
    {
        $route = new Route('/test');
        $route->setHost('example.com');

        $this->assertEquals('example.com', $route->getHost());
    }

    public function testRouteSchemes(): void
    {
        $route = new Route('/test');
        $route->setSchemes(['https', 'http']);

        $this->assertEquals(['https', 'http'], $route->getSchemes());
    }

    public function testRouteCondition(): void
    {
        $route = new Route('/test');
        $route->setCondition("context.getMethod() in ['GET', 'POST']");

        $this->assertEquals("context.getMethod() in ['GET', 'POST']", $route->getCondition());
    }
}
