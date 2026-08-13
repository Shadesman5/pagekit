<?php

declare(strict_types=1);

namespace Pagekit\Debug\Tests;

use Pagekit\Debug\DataCollector\RoutesDataCollector;
use Pagekit\Event\EventDispatcher;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Router;
use Pagekit\Routing\Routes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Covers RoutesDataCollector: the debug bar's route list is built once per set
 * of routes and read back from its file afterwards, so the panel costs a
 * file_exists() on the requests in between.
 */
class RoutesDataCollectorTest extends TestCase
{
    public function testCollectsNamePathMethodsAndControllerOfEveryRoute(): void
    {
        $dir = $this->createCacheDir();

        try {
            $collector = new RoutesDataCollector($this->router($this->siteRoutes()), new EventDispatcher(), $dir);

            $data = $collector->collect();

            $this->assertSame('routes', $collector->getName());
            $this->assertNull($data['route']);
            $this->assertSame([
                ['name' => '@blog/id', 'path' => '/blog/{id}', 'methods' => [], 'controller' => 'BlogController::postAction'],
                ['name' => '@feed', 'path' => '/feed', 'methods' => ['GET'], 'controller' => 'Closure'],
            ], $data['routes']);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    public function testCollectedRoutesAreReadBackFromTheirFile(): void
    {
        $dir = $this->createCacheDir();

        try {
            $collector = new RoutesDataCollector($this->router($this->siteRoutes()), new EventDispatcher(), $dir);
            $collector->collect();

            $files = glob($dir.'/*') ?: [];
            $this->assertCount(1, $files);

            $marker = [['name' => '@marker', 'path' => '/marker', 'methods' => [], 'controller' => 'MarkerController::indexAction']];
            file_put_contents($files[0], '<?php return '.var_export($marker, true).';');

            $data = $collector->collect();

            $this->assertSame($marker, $data['routes']);
            $this->assertCount(1, glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * The routes are what makes the list stale, so declaring one puts it in the
     * panel instead of leaving the previously collected list in front of it.
     */
    public function testDeclaringARouteCollectsANewList(): void
    {
        $dir = $this->createCacheDir();

        try {
            (new RoutesDataCollector($this->router($this->siteRoutes()), new EventDispatcher(), $dir))->collect();

            $routes = $this->siteRoutes();
            $routes->add([
                'name' => '@blog/comments',
                'path' => '/blog/{id}/comments',
                'defaults' => ['_controller' => 'BlogController::commentsAction'],
            ]);

            $data = (new RoutesDataCollector($this->router($routes), new EventDispatcher(), $dir))->collect();

            $this->assertSame(['@blog/id', '@feed', '@blog/comments'], array_column($data['routes'], 'name'));
            $this->assertCount(2, glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * A route can be declared with its controller as a closure - route defaults
     * are passed through as they are given. Reading the routes as a whole to
     * name the collected list would throw on such a route, and a debug panel is
     * not something a request may be lost to: the list is named after the
     * fields it shows instead, and a closure has one of those like any other
     * route.
     */
    public function testARouteCarryingAClosureIsCollectedRatherThanThrownOn(): void
    {
        $dir = $this->createCacheDir();

        try {
            $routes = $this->siteRoutes();
            $routes->add([
                'name' => '@callback',
                'path' => '/callback',
                'defaults' => ['_controller' => fn (): string => ''],
            ]);

            $data = (new RoutesDataCollector($this->router($routes), new EventDispatcher(), $dir))->collect();

            $this->assertSame(['@blog/id', '@feed', '@callback'], array_column($data['routes'], 'name'));
            $this->assertSame(
                ['name' => '@callback', 'path' => '/callback', 'methods' => [], 'controller' => 'Closure'],
                $data['routes'][2],
            );
            $this->assertCount(1, glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    /**
     * Reading the routes by field rather than as a whole is what keeps the
     * closure out of the way, and the fields are the ones that make the list
     * stale: a route declared next to the closure is collected instead of being
     * shadowed by the list the previous set left behind.
     */
    public function testTheCollectedListStillTracksRoutesDeclaredAroundAClosure(): void
    {
        $dir = $this->createCacheDir();

        try {
            $callback = ['name' => '@callback', 'path' => '/callback', 'defaults' => ['_controller' => fn (): string => '']];

            $routes = $this->siteRoutes();
            $routes->add($callback);

            (new RoutesDataCollector($this->router($routes), new EventDispatcher(), $dir))->collect();

            $changed = $this->siteRoutes();
            $changed->add($callback);
            $changed->add([
                'name' => '@blog/comments',
                'path' => '/blog/{id}/comments',
                'defaults' => ['_controller' => 'BlogController::commentsAction'],
            ]);

            $data = (new RoutesDataCollector($this->router($changed), new EventDispatcher(), $dir))->collect();

            $this->assertSame(['@blog/id', '@feed', '@callback', '@blog/comments'], array_column($data['routes'], 'name'));
            $this->assertCount(2, glob($dir.'/*') ?: []);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    public function testReportsTheRouteTheRequestWasMatchedTo(): void
    {
        $dir = $this->createCacheDir();

        try {
            $events = new EventDispatcher();
            $collector = new RoutesDataCollector($this->router($this->siteRoutes()), $events, $dir);

            $request = Request::create('/blog/5');
            $request->attributes->set('_route', '@blog/id');

            $events->trigger('request', [$request]);

            $this->assertSame('@blog/id', $collector->collect()['route']);
        } finally {
            $this->removeCacheDir($dir);
        }
    }

    private function siteRoutes(): Routes
    {
        $routes = new Routes();

        $routes->add([
            'name' => '@blog/id',
            'path' => '/blog/{id}',
            'defaults' => ['_controller' => 'BlogController::postAction'],
        ]);
        $routes->get('/feed', fn () => '');

        return $routes;
    }

    private function router(Routes $routes): Router
    {
        return new Router($routes, new RoutesLoader(new EventDispatcher()), new RequestStack());
    }

    private function createCacheDir(): string
    {
        $dir = sys_get_temp_dir().'/pk-routes-collector-'.uniqid();
        mkdir($dir);

        return $dir;
    }

    private function removeCacheDir(string $dir): void
    {
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }
}
