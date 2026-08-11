<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Blog;

use Pagekit\Blog\Event\RouteListener;
use Pagekit\Blog\UrlResolver;
use Pagekit\Event\EventDispatcher;
use Pagekit\Event\EventInterface;
use Pagekit\Module\Module;
use Pagekit\Routing\Event\RouterListener;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Route;
use Pagekit\Routing\Router;
use Pagekit\Routing\Routes;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * Covers RouteListener, which puts the blog's permalink pattern where the
 * router can see it: as a router option on every request, and as the alias
 * route that makes a permalink path lead to the post route.
 *
 * The listener reads the pattern off the blog module it is constructed with.
 * Deriving it through the resolver instead would mean building one - and a
 * resolver reads the post metadata cache when it is built - for a pattern that
 * is plain configuration, so the tests below pin the module as the source.
 *
 * The blog classes are runtime-loaded (not in composer's autoload map);
 * bootstrap.php requires them in dependency order.
 */
class RouteListenerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    // -----------------------------------------------------------------------
    // request: the permalink as a router option.
    // -----------------------------------------------------------------------

    /**
     * The permalink decides which routes the router ends up with, so the router
     * has to know it: it goes into the options the router names its dumped
     * routes after. Without it, the routes dumped for one permalink would be
     * served under the next one, and every post URL on the site would be built
     * from a pattern the blog no longer uses.
     */
    public function testTheConfiguredPermalinkReachesTheRouterAsAnOption(): void
    {
        $router = $this->router();

        $this->listener($router, module: $this->blogModule('{year}/{month}/{slug}'))->onAppRequest();

        $this->assertSame('{year}/{month}/{slug}', $router->getOptions()['blog.permalink']);
    }

    /**
     * Reading the pattern must not cost a resolver: building one reads the
     * post metadata cache, and this runs on every request, including the ones
     * that never generate a post URL.
     */
    public function testThePermalinkIsReadWithoutBuildingAResolver(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->never())->method('getItem');

        $router = $this->router();

        $this->listener($router, cache: $cache, module: $this->blogModule('{slug}'))->onAppRequest();

        $this->assertSame('{slug}', $router->getOptions()['blog.permalink']);
    }

    /**
     * The option has to be there before the request is matched: matching is
     * what makes the router build its routes, and it is the same event both
     * listeners answer, so the blog has to be the earlier of the two.
     */
    public function testThePermalinkIsPublishedBeforeTheRequestIsMatched(): void
    {
        [$handler, $publishing] = $this->listener()->subscribe()['request'];
        [, $matching] = (new RouterListener($this->createMock(UrlMatcherInterface::class)))->subscribe()['request'];

        $this->assertSame('onAppRequest', $handler);
        $this->assertGreaterThan(
            $matching,
            $publishing,
            'the permalink option must reach the router before the router matches the request on its routes'
        );
    }

    // -----------------------------------------------------------------------
    // route.configure: the resolver and the permalink alias on the post route.
    // -----------------------------------------------------------------------

    /**
     * A custom permalink is a second path the post route answers under, so it
     * is registered as an alias next to the route - carrying the resolver,
     * which is what turns the slug in the path back into a post id.
     */
    public function testACustomPermalinkBecomesAnAliasPathForThePostRoute(): void
    {
        $routes = new Routes();

        $this->listener(routes: $routes, module: $this->blogModule('{slug}'))
            ->onConfigureRoute($this->createMock(EventInterface::class), $this->postRoute());

        $alias = $routes->getAliases()['@blog/id'] ?? null;

        $this->assertNotNull($alias, 'a custom permalink needs a path of its own to be reachable under');
        $this->assertSame('/blog/{slug}', $alias->getPath());
        $this->assertSame(UrlResolver::class, $alias->getDefault('_resolver'));
    }

    /**
     * The post route names its resolver whether or not a permalink is set: it
     * is the resolver that turns a post id into the parameters a URL is built
     * from, so URL generation needs it even for numeric post URLs.
     */
    public function testThePostRouteNamesItsResolverEvenWithoutAPermalink(): void
    {
        $routes = new Routes();
        $route = $this->postRoute();

        $this->listener(routes: $routes, module: $this->blogModule(''))
            ->onConfigureRoute($this->createMock(EventInterface::class), $route);

        $this->assertSame(UrlResolver::class, $route->getDefault('_resolver'));
        $this->assertSame([], $routes->getAliases(), 'numeric post URLs are the route itself, so there is nothing to alias');
    }

    public function testRoutesOtherThanThePostRouteAreLeftAlone(): void
    {
        $routes = new Routes();
        $route = (new Route('/blog/feed'))->setName('@blog/feed');

        $this->listener(routes: $routes, module: $this->blogModule('{slug}'))
            ->onConfigureRoute($this->createMock(EventInterface::class), $route);

        $this->assertNull($route->getDefault('_resolver'));
        $this->assertSame([], $routes->getAliases());
    }

    // -----------------------------------------------------------------------
    // model.post.*: the resolver's post metadata cache.
    // -----------------------------------------------------------------------

    /**
     * The resolver answers slug and date tokens out of a cache it fills from
     * the posts it has already looked up. A saved or deleted post can have
     * changed exactly those, so the entries are dropped and looked up again.
     */
    public function testAChangedPostDropsTheCachedPostMetadata(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->once())
            ->method('deleteItem')
            ->with(UrlResolver::CACHE_KEY)
            ->willReturn(true);

        $this->listener(cache: $cache)->clearCache();
    }

    public function testBothPostChangesAndTheRouteBuildAreSubscribedToTheirHandlers(): void
    {
        $subscriptions = $this->listener()->subscribe();

        $this->assertSame('clearCache', $subscriptions['model.post.saved']);
        $this->assertSame('clearCache', $subscriptions['model.post.deleted']);
        $this->assertSame('onConfigureRoute', $subscriptions['route.configure']);
    }

    /**
     * Builds the listener with its four dependencies, defaulting to a router
     * nothing is asked of, empty routes, an untouched cache and a blog whose
     * post URLs are numeric.
     */
    private function listener(?Router $router = null, ?Routes $routes = null, ?CacheItemPoolInterface $cache = null, ?Module $module = null): RouteListener
    {
        return new RouteListener(
            $router ?? $this->router(),
            $routes ?? new Routes(),
            $cache ?? $this->createMock(CacheItemPoolInterface::class),
            $module ?? $this->blogModule(''),
        );
    }

    private function router(): Router
    {
        return new Router(new Routes(), new RoutesLoader(new EventDispatcher()), new RequestStack());
    }

    /**
     * The route a post is served by: `@blog/id` under the path the blog node is
     * mounted at, which is the path a permalink alias is derived from.
     */
    private function postRoute(): Route
    {
        return (new Route('/blog/{id}'))->setName('@blog/id');
    }

    /**
     * A blog module configured with the given permalink pattern, empty for the
     * numeric post URLs a fresh blog serves.
     */
    private function blogModule(string $permalink): Module
    {
        return new Module([
            'name' => 'blog',
            'path' => '',
            'config' => ['permalink' => ['type' => $permalink, 'custom' => '{slug}']],
        ]);
    }
}
