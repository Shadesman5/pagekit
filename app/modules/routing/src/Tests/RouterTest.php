<?php

declare(strict_types=1);

namespace Pagekit\Routing\Tests;

use Pagekit\Event\EventDispatcher;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Routing\Generator\CompiledUrlGenerator;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\ParamsResolverInterface;
use Pagekit\Routing\Route;
use Pagekit\Routing\Router;
use Pagekit\Routing\Routes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RouteCollection;

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
     * exception, surfacing as HTTP 500 on /api/site/node and /api/site/menu. The router
     * must fall back to the non-cached matcher/generator instead.
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

            // Simulate a half-written dump: valid PHP that holds no route data.
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

    /**
     * The dumped matcher must reach disk through the filesystem service, which puts
     * the file in place in a single step. The router requires the file straight back
     * and matches on the data it returns, so a reader that catches the dump
     * half-written gets a truncated file instead of routes - and rapid route changes
     * (page drag & drop) make writing while others read the normal case, not the
     * rare one.
     */
    public function testDumpedCacheIsWrittenThroughTheFilesystem(): void
    {
        $files = new class () extends Filesystem {
            /** @var list<array{file: string, content: string}> */
            public array $writes = [];

            public function dumpAtomic(string $file, string $content, ?int $mode = null): void
            {
                $this->writes[] = ['file' => $file, 'content' => $content];

                parent::dumpAtomic($file, $content, $mode);
            }
        };

        $dir = $this->createCacheDir();

        try {
            $this->routes->add([
                'name' => 'cached_route',
                'path' => '/cached/{id}',
                'defaults' => ['_controller' => 'TestController::cachedAction'],
            ]);

            $router = new Router($this->routes, new RoutesLoader($this->events), $this->stack, ['cache' => $dir], $files);

            $cache = (new \ReflectionMethod($router, 'getCache'))->invoke($router, '%s/%s.matcher.cache');

            $request = Request::create('/cached/7');
            $this->stack->push($request);

            $params = $router->match('/cached/7');
            $this->assertSame('7', $params['id']);

            $this->assertCount(1, $files->writes);
            $this->assertSame($cache['file'], $files->writes[0]['file']);
            $this->assertStringContainsString('return [', $files->writes[0]['content']);
            $this->assertFileExists($cache['file']);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * A cache the process cannot write costs the request its cache, never its
     * response: matcher and generator are built from the route collection instead.
     * The failed write must also leave the directory untouched - anything dropped
     * there would be picked up as a cache by the next request.
     */
    public function testUnwritableCacheDegradesToTheUncachedRouter(): void
    {
        $files = new class () extends Filesystem {
            public int $attempts = 0;

            public function dumpAtomic(string $file, string $content, ?int $mode = null): void
            {
                ++$this->attempts;

                throw new \RuntimeException("Failed to write file ($file).");
            }
        };

        $dir = $this->createCacheDir();

        try {
            $this->routes->add([
                'name' => 'unwritable_route',
                'path' => '/unwritable/{id}',
                'defaults' => ['_controller' => 'TestController::unwritableAction'],
            ]);

            $router = new Router($this->routes, new RoutesLoader($this->events), $this->stack, ['cache' => $dir], $files);

            $request = Request::create('/unwritable/5');
            $this->stack->push($request);

            $params = $router->match('/unwritable/5');
            $this->assertSame('5', $params['id']);

            // Matching adopted the request context, so the generated URL carries its
            // base URL in front of the route path.
            $url = $router->generate('unwritable_route', ['id' => 5]);
            $this->assertStringContainsString('/unwritable/5', $url);

            // Both dumps were attempted, both were refused, and neither wrote anything.
            $this->assertSame(2, $files->attempts);
            $this->assertSame([], glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * Naming the filesystem is optional, and the router the routing module builds
     * does not name one. Such a router still has to cache, otherwise every request
     * would redump the routes.
     */
    public function testCacheIsWrittenWithoutAnInjectedFilesystem(): void
    {
        $dir = $this->createCacheDir();

        try {
            $this->routes->add([
                'name' => 'plain_route',
                'path' => '/plain/{id}',
                'defaults' => ['_controller' => 'TestController::plainAction'],
            ]);

            $router = new Router($this->routes, new RoutesLoader($this->events), $this->stack, ['cache' => $dir]);

            $cache = (new \ReflectionMethod($router, 'getCache'))->invoke($router, '%s/%s.generator.cache');

            $this->assertSame('/plain/9', $router->generate('plain_route', ['id' => 9]));

            $this->assertFileExists($cache['file']);

            $dump = file_get_contents($cache['file']);
            $this->assertIsString($dump);
            $this->assertStringContainsString("'plain_route' =>", $dump);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * A request is matched on the dumped route data, not on the route
     * collection: a dump holding the route under another path takes the request
     * there, which is an answer the collection cannot give.
     */
    public function testTheDumpedRoutesAreWhatARequestIsMatchedOn(): void
    {
        $dir = $this->createCacheDir();
        $files = new RouterTestWriteCountingFilesystem();

        try {
            $router = new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir], $files);

            $dumped = $this->dumpedRoutes('cached_pair', '/dumped/{id}', ['_controller' => 'TestController::pairAction']);

            $dump = $this->cacheFile($router, '%s/%s.matcher.cache');
            file_put_contents($dump, (new CompiledUrlMatcherDumper($dumped))->dump());

            $this->stack->push(Request::create('/dumped/7'));

            $params = $router->match('/dumped/7');

            // The route and everything declared with it come out of the file.
            $this->assertSame('cached_pair', $params['_route']);
            $this->assertSame('7', $params['id']);
            $this->assertSame('TestController::pairAction', $params['_controller']);

            // A dump that is there is a dump that is used: nothing was written
            // over it, and nothing was put next to it.
            $this->assertSame(0, $files->writes);
            $this->assertSame([$dump], glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * The same for URL generation: the URL comes out of the dumped values, so a
     * dump holding the route under another path generates that path.
     */
    public function testTheDumpedRoutesAreWhatUrlsAreGeneratedFrom(): void
    {
        $dir = $this->createCacheDir();
        $files = new RouterTestWriteCountingFilesystem();

        try {
            $router = new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir], $files);

            $dumped = $this->dumpedRoutes('cached_pair', '/dumped/{id}', ['_controller' => 'TestController::pairAction']);

            $dump = $this->cacheFile($router, '%s/%s.generator.cache');
            file_put_contents($dump, CompiledUrlGenerator::dump($dumped));

            $this->assertSame('/dumped/7', $router->generate('cached_pair', ['id' => 7]));

            $this->assertSame(0, $files->writes);
            $this->assertSame([$dump], glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * The dumps a request writes are the ones the next request finds: a dump is
     * named after the routes it holds, so routes that did not change are not
     * dumped a second time.
     */
    public function testTheDumpsARequestWritesAreFoundByTheNextRequest(): void
    {
        $dir = $this->createCacheDir();

        try {
            // Matching and generating each dump their own file.
            (new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir]))->match('/pair/7');
            (new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir]))->generate('cached_pair', ['id' => 7]);

            $dumps = glob($dir.'/*') ?: [];
            $this->assertCount(2, $dumps);

            $files = new RouterTestWriteCountingFilesystem();
            $matching = new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir], $files);
            $generating = new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir], $files);

            $this->stack->push(Request::create('/pair/7'));

            $this->assertSame('7', $matching->match('/pair/7')['id']);
            $this->assertSame('/pair/7', $generating->generate('cached_pair', ['id' => 7]));

            // The same two dumps: neither was written again, and no third one
            // appeared under a name only this request would look up.
            $this->assertSame(0, $files->writes);
            $this->assertSame($dumps, glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * A dump is current because it exists, not because of its age: its name is
     * derived from the routes it was written for. Dating it instead would hand
     * that decision to file times - a deployment that resets them, or a
     * production opcache that does not revalidate them, would leave a dump that
     * is stale but looks current, or force a rewrite on every request.
     */
    public function testTheDumpIsReadRegardlessOfHowItsAgeComparesToTheRoutes(): void
    {
        $dir = $this->createCacheDir();
        $files = new RouterTestWriteCountingFilesystem();

        try {
            // Routes whose modified marker sits in the far future against a dump
            // dated before everything - the pair an age comparison rejects.
            $router = new Router($this->cachedRoutes(new RouterTestPinnedRoutes()), new RoutesLoader($this->events), $this->stack, ['cache' => $dir], $files);

            $dumped = $this->dumpedRoutes('cached_pair', '/dumped/{id}', ['_controller' => 'TestController::pairAction']);

            $dump = $this->cacheFile($router, '%s/%s.generator.cache');
            file_put_contents($dump, CompiledUrlGenerator::dump($dumped));
            touch($dump, 1);

            $this->assertSame('/dumped/7', $router->generate('cached_pair', ['id' => 7]));
            $this->assertSame(0, $files->writes);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * Declaring a route changes which dump the router reads, so the new route is
     * served straight away instead of being shadowed by the previous dump. The
     * superseded file stays behind for the cache clear to sweep.
     */
    public function testChangedRoutesAreServedFromANewDump(): void
    {
        $dir = $this->createCacheDir();

        try {
            $router = new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir]);

            $this->assertSame('/pair/7', $router->generate('cached_pair', ['id' => 7]));

            $routes = $this->cachedRoutes();
            $routes->add([
                'name' => 'added_pair',
                'path' => '/added/{id}',
                'defaults' => ['_controller' => 'TestController::addedAction'],
            ]);

            $files = new RouterTestWriteCountingFilesystem();
            $next = new Router($routes, new RoutesLoader($this->events), $this->stack, ['cache' => $dir], $files);

            $this->assertSame('/added/3', $next->generate('added_pair', ['id' => 3]));

            $this->assertSame(1, $files->writes);
            $this->assertCount(2, glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * A dump can be caught half-written, with the routes in it stopping mid
     * array. Reading such a file back raises a parse error, which must cost the
     * request its cache and nothing else - it is served from the route
     * collection instead.
     */
    public function testTruncatedDumpFallsBackInsteadOfFatal(): void
    {
        $dir = $this->createCacheDir();

        try {
            // Two requests fill the pair of dumps, one file each.
            (new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir]))->match('/pair/7');
            (new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir]))->generate('cached_pair', ['id' => 7]);

            $dumps = glob($dir.'/*') ?: [];
            $this->assertCount(2, $dumps);

            foreach ($dumps as $dump) {
                file_put_contents($dump, '<?php return [');
            }

            $this->stack->push(Request::create('/pair/7'));

            $matching = new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir]);
            $generating = new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir]);

            $this->assertSame('7', $matching->match('/pair/7')['id']);
            $this->assertSame('/pair/7', $generating->generate('cached_pair', ['id' => 7]));

            // Reading the ruined files through did not leave anything else behind.
            $this->assertCount(2, glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * A dump the router was told it had written but that is not on disk - it was
     * swept between writing and reading - must not take the request with it:
     * reading a file that is not there would be fatal, so the route collection
     * answers instead.
     */
    public function testDumpThatNeverReachedDiskFallsBackInsteadOfFatal(): void
    {
        $dir = $this->createCacheDir();

        try {
            $router = new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir], new RouterTestSilentFilesystem());

            $this->stack->push(Request::create('/pair/7'));

            $this->assertSame('7', $router->match('/pair/7')['id']);
            $this->assertStringContainsString('/pair/7', $router->generate('cached_pair', ['id' => 7]));
            $this->assertSame([], glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * The dump is named after the routes and the options that shape them, and
     * reading either of them can fail: an option is whatever the module that
     * set it put there, and a closure or a database connection has no
     * serialized form. Naming the dump is the router's problem, routing is the
     * request's - so an identity that cannot be taken costs the request its
     * cache and nothing else.
     */
    public function testAnUnreadableCacheKeyDegradesToTheUncachedRouter(): void
    {
        $dir = $this->createCacheDir();
        $files = new RouterTestWriteCountingFilesystem();

        try {
            $router = new Router($this->cachedRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir], $files);

            $router->setOption('test.route_option', fn (): string => 'nothing serializes this');

            $this->stack->push(Request::create('/pair/7'));

            $this->assertSame('7', $router->match('/pair/7')['id']);

            // Matching adopted the request context, so the generated URL carries
            // its base URL in front of the route path.
            $this->assertStringContainsString('/pair/7', $router->generate('cached_pair', ['id' => 7]));

            // Without a key there is nothing to tell a dump apart from a stale
            // one, so neither is written nor read. Writing under a key that
            // stands for less than the routes it holds would be worse than not
            // caching: the next request would find it and believe it.
            $this->assertSame(0, $files->writes);
            $this->assertSame([], glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * A route's params resolver keeps its say once the routes are dumped - the
     * seam an extension uses to turn a post id into the parameters its
     * permalink is built from, and to read them back off a matched request. The
     * resolver is named by the route's defaults, so it only runs at all if the
     * dumped values were read back with the route it belongs to.
     */
    public function testParamsResolverKeepsTransformingParametersOnDumpedRoutes(): void
    {
        $dir = $this->createCacheDir();
        $files = new RouterTestWriteCountingFilesystem();

        $dumped = $this->dumpedRoutes('resolved_post', '/dumped-post/{id}', [
            '_controller' => 'TestController::postAction',
            '_resolver' => RouterTestUrlResolver::class,
        ]);

        try {
            $generating = new Router($this->resolverRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir], $files);

            $generatorDump = $this->cacheFile($generating, '%s/%s.generator.cache');
            file_put_contents($generatorDump, CompiledUrlGenerator::dump($dumped));

            // The resolver rewrote the id, the dumped route built the URL from it.
            $this->assertSame('/dumped-post/42', $generating->generate('resolved_post', ['id' => 1]));

            $matching = new Router($this->resolverRoutes(), new RoutesLoader($this->events), $this->stack, ['cache' => $dir], $files);

            $matcherDump = $this->cacheFile($matching, '%s/%s.matcher.cache');
            file_put_contents($matcherDump, (new CompiledUrlMatcherDumper($dumped))->dump());

            $this->stack->push(Request::create('/dumped-post/42'));

            $params = $matching->match('/dumped-post/42');

            $this->assertSame('42', $params['id']);
            $this->assertSame('resolved', $params['slug']);

            $this->assertSame(0, $files->writes);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * A route names its resolver by class, because that name is what survives
     * being dumped - but a resolver that needs collaborators cannot be built
     * from a name. The module that owns it says how to build it instead, and
     * the router takes that way when it meets the class the route names.
     */
    public function testARegisteredFactoryBuildsTheResolverARouteNames(): void
    {
        $router = new Router($this->injectedResolverRoutes(), new RoutesLoader($this->events), $this->stack);

        $router->addResolver(RouterTestInjectedResolver::class, fn () => new RouterTestInjectedResolver('injected'));

        // Building this resolver from its name alone is an ArgumentCountError,
        // so an answer at all is the factory having been used.
        $this->assertSame('/post/injected', $router->generate('injected_post', ['id' => 1]));
    }

    /**
     * A resolver that needs nothing keeps being built from the name the route
     * carries, so registering a factory is what a resolver with dependencies
     * does, not what every resolver has to do.
     */
    public function testAResolverWithoutAFactoryIsBuiltFromTheNameTheRouteCarries(): void
    {
        $router = new Router($this->resolverRoutes(), new RoutesLoader($this->events), $this->stack);

        $this->assertSame('/post/42', $router->generate('resolved_post', ['id' => 1]));
    }

    /**
     * The resolver of a request is built once and then reused. Building it is
     * what an extension does its own setup in - the blog resolver reads its
     * post metadata cache there - so a page full of post links must not pay for
     * that per link.
     */
    public function testTheResolverIsBuiltOncePerRouter(): void
    {
        $built = 0;

        $router = new Router($this->injectedResolverRoutes(), new RoutesLoader($this->events), $this->stack);

        $router->addResolver(RouterTestInjectedResolver::class, function () use (&$built) {
            ++$built;

            return new RouterTestInjectedResolver('injected');
        });

        $router->generate('injected_post', ['id' => 1]);
        $router->generate('injected_post', ['id' => 2]);

        $this->stack->push(Request::create('/post/injected'));
        $this->assertSame('injected', $router->match('/post/injected')['slug']);

        $this->assertSame(1, $built, 'the resolver of a request is built once, for matching and generating alike');
    }

    /**
     * The routes of a single request. Dumping compiles the routes it writes and
     * a compiled route carries that state, so a router standing in for the next
     * request gets its own set - the way a request builds its routes from
     * scratch.
     */
    private function cachedRoutes(Routes $routes = new Routes()): Routes
    {
        $routes->add([
            'name' => 'cached_pair',
            'path' => '/pair/{id}',
            'defaults' => ['_controller' => 'TestController::pairAction'],
            'requirements' => ['id' => '\d+'],
        ]);

        return $routes;
    }

    private function resolverRoutes(): Routes
    {
        $routes = new Routes();
        $routes->add([
            'name' => 'resolved_post',
            'path' => '/post/{id}',
            'defaults' => [
                '_controller' => 'TestController::postAction',
                '_resolver' => RouterTestUrlResolver::class,
            ],
        ]);

        return $routes;
    }

    /**
     * The same route, resolved by a resolver that has to be handed its
     * collaborators - the shape of an extension's resolver.
     */
    private function injectedResolverRoutes(): Routes
    {
        $routes = new Routes();
        $routes->add([
            'name' => 'injected_post',
            'path' => '/post/{id}',
            'defaults' => [
                '_controller' => 'TestController::postAction',
                '_resolver' => RouterTestInjectedResolver::class,
            ],
        ]);

        return $routes;
    }

    /**
     * The routes of a dump lying in the cache directory: the route the router
     * was given, under a path its own route collection has never heard of. An
     * answer carrying that path can only have been read back out of the file.
     *
     * @param array<string, mixed> $defaults
     */
    private function dumpedRoutes(string $name, string $path, array $defaults): RouteCollection
    {
        $routes = new RouteCollection();
        $routes->add($name, new Route($path, $defaults, ['id' => '\d+']));

        return $routes;
    }

    /**
     * The path a router reads one of its two dumps from. A dump is named after
     * the routes it holds, so the router is asked where one has to lie to be
     * found instead of the name being spelled out here.
     */
    private function cacheFile(Router $router, string $file): string
    {
        $path = (new \ReflectionMethod($router, 'getCache'))->invoke($router, $file)['file'];

        $this->assertIsString($path);

        return $path;
    }

    private function createCacheDir(): string
    {
        $dir = sys_get_temp_dir().'/pk-route-cache-'.uniqid();
        mkdir($dir);

        return $dir;
    }

    private function removeCacheDir(string $dir): void
    {
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }
}

/**
 * Fixture: a filesystem that counts what the router puts on disk, so a test can
 * tell a dump that was reused from one that was written again.
 */
final class RouterTestWriteCountingFilesystem extends Filesystem
{
    public int $writes = 0;

    public function dumpAtomic(string $file, string $content, ?int $mode = null): void
    {
        ++$this->writes;

        parent::dumpAtomic($file, $content, $mode);
    }
}

/**
 * Fixture: a filesystem that reports a write it never performed, leaving the
 * state a dump swept between writing and reading leaves behind.
 */
final class RouterTestSilentFilesystem extends Filesystem
{
    public function dumpAtomic(string $file, string $content, ?int $mode = null): void
    {
    }
}

/**
 * Fixture: routes whose modified marker sits far ahead of any file, so a dump
 * written for them is always the older of the two.
 */
final class RouterTestPinnedRoutes extends Routes
{
    public function getModified(): int
    {
        // 2100-01-01, later than anything a file on disk can be dated.
        return 4102444800;
    }
}

/**
 * Fixture: a params resolver in the shape an extension registers - it rewrites
 * the parameters a URL is generated from and enriches the ones a match returns.
 */
final class RouterTestUrlResolver implements ParamsResolverInterface
{
    /**
     * @param  array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function match(array $parameters = []): array
    {
        $parameters['slug'] = 'resolved';

        return $parameters;
    }

    /**
     * @param  array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function generate(array $parameters = []): array
    {
        $parameters['id'] = 42;

        return $parameters;
    }
}

/**
 * Fixture: a params resolver in the shape of one that reaches for services of
 * its own - the blog resolver takes a cache pool, its module and a repository.
 * Its class name is all a route holds, and that is not enough to build it.
 */
final class RouterTestInjectedResolver implements ParamsResolverInterface
{
    public function __construct(private readonly string $slug)
    {
    }

    /**
     * @param  array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function match(array $parameters = []): array
    {
        $parameters['slug'] = $this->slug;

        return $parameters;
    }

    /**
     * @param  array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function generate(array $parameters = []): array
    {
        $parameters['id'] = $this->slug;

        return $parameters;
    }
}
