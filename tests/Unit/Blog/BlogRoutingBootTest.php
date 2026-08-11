<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Pagekit\Application;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Module\Module;
use Pagekit\Routing\Event\AliasListener;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Router;
use Pagekit\Routing\Routes;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Covers what the blog's boot leaves behind for routing: the router is told how
 * to build the blog's URL resolver, and the route listener is subscribed with
 * the module it reads the permalink from.
 *
 * Both are collaborator wiring, and both are only used once a page asks for a
 * post URL - handing the resolver its cache pool, module and repository in the
 * wrong order, or handing the listener something other than the blog module,
 * costs the page it is built on rather than the boot. So the test asks for a
 * post URL: that answer can only come from a resolver built with the services
 * it needs, using the permalink the listener published, on the alias path the
 * listener registered.
 *
 * Boot lives in the package's index.php, a module definition rather than an
 * autoloaded class, so it is required in the same scope as the container it
 * wires.
 */
final class BlogRoutingBootTest extends TestCase
{
    /**
     * The post whose URL is asked for. The resolver answers permalink tokens
     * out of the cache it is built with, so nothing here reaches a database.
     */
    private const CACHED_POST = ['id' => 1, 'slug' => 'hello', 'year' => '2024', 'month' => '05', 'day' => '06'];

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testABootedBlogBuildsPostUrlsFromItsConfiguredPermalink(): void
    {
        $router = $this->bootedBlog('{slug}');

        $this->assertSame('/blog/hello', $router->generate('@blog/id', ['id' => 1]));
    }

    /**
     * With numeric post URLs there is no permalink to alias, so the post route
     * stays as it is - but the resolver is still what turns the post id into
     * the parameters the URL is built from, so it is still built, and a router
     * that cannot build it takes this URL down as well.
     */
    public function testABootedBlogBuildsNumericPostUrlsThroughTheSameResolver(): void
    {
        $router = $this->bootedBlog('');

        $this->assertSame('/blog/1', $router->generate('@blog/id', ['id' => 1]));
    }

    /**
     * A router serving a blog that has booted: the post route is declared, the
     * routing module's own alias listener is subscribed - it is what carries an
     * alias the blog registers into the collection the router builds - and the
     * package's boot has run against a container holding the services it
     * reaches for.
     */
    private function bootedBlog(string $permalink): Router
    {
        $routes = new Routes();
        $routes->add(['name' => '@blog/id', 'path' => '/blog/{id}']);

        $app = new Application();
        $app->get('events')->subscribe(new AliasListener($routes));

        $router = new Router($routes, new RoutesLoader($app->get('events')), new RequestStack());

        $this->boot($this->wire($app, $router, $routes, $permalink));

        return $router;
    }

    /**
     * Runs the boot closure of the blog package the way the module manager
     * does: against the container handed in, which the definition's own
     * closures capture.
     */
    private function boot(Application $app): void
    {
        /** @var array{events: array{boot: callable}} $module */
        $module = require dirname(__DIR__, 3) . '/packages/pagekit/blog/index.php';

        $module['events']['boot'](null, $app);
    }

    /**
     * The services the blog's boot reaches for: the router and routes it wires
     * itself into, the cache the resolver reads post metadata from, the blog
     * module carrying the permalink setting, and the entity manager the post
     * repository is built on (never queried here).
     */
    private function wire(Application $app, Router $router, Routes $routes, string $permalink): Application
    {
        $app->set('router', $router);
        $app->set('routes', $routes);
        $app->set('cache', $this->cachePool());
        $app->set('db.em', $this->entityManager());
        $app->set('module', $this->modules($this->blogModule($permalink)));

        return $app;
    }

    /**
     * A pool holding the metadata of the one post whose URL is asked for.
     */
    private function cachePool(): CacheItemPoolInterface
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn([self::CACHED_POST['id'] => self::CACHED_POST]);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        return $pool;
    }

    private function entityManager(): EntityManager
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getMetadata')->willReturn($this->createMock(Metadata::class));

        return $em;
    }

    /**
     * The module manager as the blog's boot uses it: it asks for one module by
     * name, and only that name may answer.
     */
    private function modules(Module $blog): object
    {
        return new class ($blog) {
            public function __construct(private readonly Module $blog)
            {
            }

            public function get(string $name): ?Module
            {
                return $name === 'blog' ? $this->blog : null;
            }
        };
    }

    private function blogModule(string $permalink): Module
    {
        return new Module([
            'name' => 'blog',
            'path' => '',
            'config' => ['permalink' => ['type' => $permalink, 'custom' => '{slug}']],
        ]);
    }
}
