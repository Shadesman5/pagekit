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
        App::router()->setOption('blog.permalink', UrlResolver::getPermalink()); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
    }

    /**
     * Registers permalink route alias.
     */
    public function onConfigureRoute($event, $route): void
    {
        if ($route->getName() == '@blog/id') {
            // Always set resolver on @blog/id route for URL generation
            $route->setDefault('_resolver', 'Pagekit\Blog\UrlResolver');
            
            // Create alias route for custom permalink patterns
            if ($permalink = UrlResolver::getPermalink()) {
                App::routes()->alias(dirname($route->getPath()).'/'.ltrim($permalink, '/'), '@blog/id', ['_resolver' => 'Pagekit\Blog\UrlResolver']); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
            }
        }
    }

    /**
     * Clears resolver cache.
     */
    public function clearCache(): void
    {
        App::cache()->delete(UrlResolver::CACHE_KEY); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
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
