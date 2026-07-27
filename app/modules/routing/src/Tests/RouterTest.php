<?php

declare(strict_types=1);

namespace Pagekit\Routing\Tests;

use Pagekit\Event\EventDispatcher;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Router;
use Pagekit\Routing\Routes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

class RouterTest extends TestCase
{
    protected Router $router;
    protected Routes $routes;
    protected EventDispatcher $events;
    protected RequestStack $stack;

    protected function setUp(): void
    {
        $this->routes = new Routes();
        $this->events = new EventDispatcher();
        $this->stack = new RequestStack();

        $loader = new RoutesLoader($this->events);
        $this->router = new Router($this->routes, $loader, $this->stack);
    }

    public function testRouteGeneration(): void
    {
        $this->routes->add([
            'name' => 'test_route',
            'path' => '/test/{id}',
            'defaults' => ['_controller' => 'TestController::testAction'],
            'requirements' => ['id' => '\d+'],
        ]);

        $url = $this->router->generate('test_route', ['id' => 123]);
        $this->assertEquals('/test/123', $url);
    }

    public function testRouteGenerationWithInvalidRoute(): void
    {
        $this->expectException(RouteNotFoundException::class);
        $this->router->generate('non_existent_route');
    }

    public function testRouteMatching(): void
    {
        $this->routes->add([
            'name' => 'test_match',
            'path' => '/match/{param}',
            'defaults' => ['_controller' => 'TestController::matchAction'],
        ]);

        $request = Request::create('/match/value');
        $this->stack->push($request);

        $params = $this->router->match('/match/value');

        $this->assertArrayHasKey('_controller', $params);
        $this->assertArrayHasKey('param', $params);
        $this->assertEquals('value', $params['param']);
        $this->assertEquals('test_match', $params['_route']);
    }

    public function testContextFromRequest(): void
    {
        $request = Request::create('https://example.com/test', 'GET');
        $this->stack->push($request);

        $context = $this->router->getContext();
        $context->fromRequest($request);

        $this->assertEquals('https', $context->getScheme());
        $this->assertEquals('example.com', $context->getHost());
    }

    public function testRouteWithDefaults(): void
    {
        $this->routes->add([
            'name' => 'default_route',
            'path' => '/default/{page}',
            'defaults' => [
                '_controller' => 'TestController::defaultAction',
                'page' => 1,
            ],
        ]);

        $url = $this->router->generate('default_route');
        $this->assertEquals('/default', $url);

        $url = $this->router->generate('default_route', ['page' => 2]);
        $this->assertEquals('/default/2', $url);
    }

    public function testRouteWithRequirements(): void
    {
        $this->routes->add([
            'name' => 'requirement_route',
            'path' => '/req/{id}',
            'defaults' => ['_controller' => 'TestController::reqAction'],
            'requirements' => ['id' => '\d+'],
        ]);

        $request = Request::create('/req/123');
        $this->stack->push($request);

        $params = $this->router->match('/req/123');
        $this->assertEquals('123', $params['id']);
    }

    public function testRedirectResponse(): void
    {
        $this->routes->add([
            'name' => 'redirect_target',
            'path' => '/target',
            'defaults' => ['_controller' => 'TestController::targetAction'],
        ]);

        $response = $this->router->redirect('redirect_target');
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/target', $response->getTargetUrl());

        // Test with external URL
        $response = $this->router->redirect('https://example.com');
        $this->assertEquals('https://example.com', $response->getTargetUrl());
    }

    public function testGetRoute(): void
    {
        $this->routes->add([
            'name' => 'get_route',
            'path' => '/get',
            'defaults' => ['_controller' => 'TestController::getAction'],
        ]);

        $route = $this->router->getRoute('get_route');
        $this->assertNotNull($route);
        $this->assertEquals('/get', $route->getPath());
    }

    public function testGetRouteCollection(): void
    {
        $this->routes->add([
            'name' => 'collection_route',
            'path' => '/collection',
            'defaults' => ['_controller' => 'TestController::collectionAction'],
        ]);

        $collection = $this->router->getRouteCollection();
        $this->assertCount(1, $collection);
        $this->assertNotNull($collection->get('collection_route'));
    }

    public function testOptionsHandling(): void
    {
        $options = ['cache' => '/tmp/cache', 'debug' => true];
        $router = new Router($this->routes, new RoutesLoader($this->events), $this->stack, $options);

        $this->assertEquals($options['cache'], $router->getOptions()['cache']);

        $router->setOption('custom', 'value');
        $this->assertEquals('value', $router->getOptions()['custom']);

        $router->setOptions(['new' => 'options']);
        $this->assertEquals('options', $router->getOptions()['new']);
    }

    public function testGenerateWithFragment(): void
    {
        $this->routes->add([
            'name' => 'fragment_route',
            'path' => '/fragment',
            'defaults' => ['_controller' => 'TestController::fragmentAction'],
        ]);

        $url = $this->router->generate('fragment_route#section');
        $this->assertEquals('/fragment#section', $url);
    }

    public function testGenerateWithQuery(): void
    {
        $this->routes->add([
            'name' => 'query_route',
            'path' => '/query',
            'defaults' => ['_controller' => 'TestController::queryAction'],
        ]);

        $url = $this->router->generate('query_route?foo=bar', ['baz' => 'qux']);
        $this->assertStringContainsString('foo=bar', $url);
        $this->assertStringContainsString('baz=qux', $url);
    }

    /**
     * Regression test: route-affecting options must participate in the cache key.
     *
     * Modules can change the generated route collection based on router options
     * (e.g. an option that adds alias routes during route.configure). If such an
     * option is excluded from the cache key, the dumped matcher/generator become
     * stale after the option changes, so the router keeps serving routes built for
     * the previous value and URL generation fails. Any change to a route-affecting
     * option must therefore produce a different cache key. This is generic core
     * behaviour, so the test uses a neutral option name rather than an extension's.
     */
    public function testCacheKeyReflectsRouteAffectingOptions(): void
    {
        $router = new Router($this->routes, new RoutesLoader($this->events), $this->stack, ['cache' => sys_get_temp_dir()]);

        $getCache = new \ReflectionMethod($router, 'getCache');

        $router->setOption('test.route_option', 'variant-a');
        $variantACache = $getCache->invoke($router, '%s/%s.generator.cache')['file'];

        $router->setOption('test.route_option', 'variant-b');
        $variantBCache = $getCache->invoke($router, '%s/%s.generator.cache')['file'];

        $this->assertNotSame(
            $variantACache,
            $variantBCache,
            'Changing a route-affecting option must change the router cache key.'
        );
    }

    /**
     * Regression test: a corrupted/partial route cache file must never crash the request.
     *
     * Rapid page reordering (drag & drop) fires many requests that regenerate the routing
     * dump concurrently. A reader seeing a half-written cache file used to hit an uncaught
     * exception (LogicException from instantiate*()), surfacing as HTTP 500 on
     * /api/site/node and /api/site/menu. The router must fall back to the non-cached
     * matcher/generator instead.
     */
    public function testCorruptCacheFileFallsBackInsteadOfFatal(): void
    {
        $dir = sys_get_temp_dir().'/pk-route-cache-'.uniqid();
        mkdir($dir);

        try {
            $this->routes->add([
                'name' => 'corrupt_route',
                'path' => '/corrupt/{id}',
                'defaults' => ['_controller' => 'TestController::corruptAction'],
            ]);

            $router = new Router($this->routes, new RoutesLoader($this->events), $this->stack, ['cache' => $dir]);

            $getCache = new \ReflectionMethod($router, 'getCache');

            // Simulate a half-written dump: valid PHP, but the expected class is missing.
            $matcherFile = $getCache->invoke($router, '%s/%s.matcher.cache')['file'];
            $generatorFile = $getCache->invoke($router, '%s/%s.generator.cache')['file'];
            file_put_contents($matcherFile, '<?php /* partial cache write, class missing */');
            file_put_contents($generatorFile, '<?php /* partial cache write, class missing */');

            $request = Request::create('/corrupt/42');
            $this->stack->push($request);

            // Neither matching nor generation may throw - both fall back gracefully.
            $params = $router->match('/corrupt/42');
            $this->assertSame('42', $params['id']);

            $url = $router->generate('corrupt_route', ['id' => 42]);
            $this->assertStringContainsString('/corrupt/42', $url);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }
}
