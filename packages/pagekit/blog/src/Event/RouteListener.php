<?php

namespace Pagekit\Blog\Event;

use Pagekit\Application as App;
use Pagekit\Blog\UrlResolver;
use Pagekit\Event\EventSubscriberInterface;

class RouteListener implements EventSubscriberInterface
{
    /**
     * Adds cache breaker to router.
     */
    public function onAppRequest(): void
    {
        error_log("RouteListener::onAppRequest called");
        $permalink = UrlResolver::getPermalink();
        error_log("Blog permalink format: " . ($permalink ?: 'none'));
        App::router()->setOption('blog.permalink', $permalink);
    }

    /**
     * Registers permalink route alias.
     */
    public function onConfigureRoute($event, $route): void
    {
        error_log("RouteListener::onConfigureRoute called for route: " . $route->getName());
        if ($route->getName() == '@blog/id' && UrlResolver::getPermalink()) {
            $alias = dirname($route->getPath()).'/'.ltrim(UrlResolver::getPermalink(), '/');
            error_log("Creating route alias: $alias -> @blog/id");
            App::routes()->alias($alias, '@blog/id', ['_resolver' => 'Pagekit\Blog\UrlResolver']);
        }
    }

    /**
     * Clears resolver cache.
     */
    public function clearCache(): void
    {
        App::cache()->delete(UrlResolver::CACHE_KEY);
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        return [
            'request' => ['onAppRequest', 130],
            'route.configure' => 'onConfigureRoute',
            'model.post.saved' => 'clearCache',
            'model.post.deleted' => 'clearCache'
        ];
    }
}
