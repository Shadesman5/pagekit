<?php

declare(strict_types=1);

namespace Pagekit\Blog\Event;

use Pagekit\Blog\UrlResolver;
use Pagekit\Event\EventInterface;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Routing\Route;
use Pagekit\Routing\Router;
use Pagekit\Routing\Routes;
use Psr\Cache\CacheItemPoolInterface;

class RouteListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Router $router,
        private readonly Routes $routes,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Adds cache breaker to router.
     */
    public function onAppRequest(): void
    {
        $this->router->setOption('blog.permalink', UrlResolver::getPermalink());
    }

    /**
     * Registers permalink route alias.
     */
    public function onConfigureRoute(EventInterface $event, Route $route): void
    {
        if ($route->getName() == '@blog/id') {
            // Always set resolver on @blog/id route for URL generation
            $route->setDefault('_resolver', 'Pagekit\Blog\UrlResolver');

            // Create alias route for custom permalink patterns
            if ($permalink = UrlResolver::getPermalink()) {
                $this->routes->alias(dirname($route->getPath()).'/'.ltrim($permalink, '/'), '@blog/id', ['_resolver' => 'Pagekit\Blog\UrlResolver']);
            }
        }
    }

    /**
     * Clears resolver cache.
     */
    public function clearCache(): void
    {
        $this->cache->deleteItem(UrlResolver::CACHE_KEY);
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, array{string, int}|string>
     */
    public function subscribe(): array
    {
        return [
            'request' => ['onAppRequest', 130],
            'route.configure' => 'onConfigureRoute',
            'model.post.saved' => 'clearCache',
            'model.post.deleted' => 'clearCache',
        ];
    }
}
