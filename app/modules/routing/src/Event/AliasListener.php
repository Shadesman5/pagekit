<?php

declare(strict_types=1);

namespace Pagekit\Routing\Event;

use Pagekit\Event\Event;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Routing\ResourceInterface;
use Pagekit\Routing\Route;
use Pagekit\Routing\Routes;
use Symfony\Component\Routing\RouteCollection;

class AliasListener implements EventSubscriberInterface
{
    protected \Pagekit\Routing\Routes $routes;

    /**
     * Constructor.
     *
     * @param Routes $routes
     */
    public function __construct(ResourceInterface $routes)
    {
        $this->routes = $routes;
    }

    /**
     * Adds all aliases for this route.
     *
     * @param Event           $event
     * @param Route           $route
     * @param RouteCollection $routes
     */
    public function onConfigureRoute($event, $route, $routes): void
    {
        $name = $route->getName();

        $aliases = array_filter($this->routes->getAliases(), fn ($alias) => $name == $alias->getName() || $name == strtok($alias->getName(), '?'));

        if (!$aliases) {
            return;
        }

        $variables = $route->compile()->getPathVariables();

        foreach ($aliases as $alias) {

            // TODO: Must be refactored in Step 2.1.6 (PHPStan Level 7→8 / Strict Typing) — dead inline-query-string parser; no caller uses the `?param=value` suffix in the alias name (the `$defaults` parameter of `Routes::alias()` has fully replaced this convenience API). Delete this block together with the dependent `strtok($alias->getName(), '?')` clause in the `array_filter` above (line 39).
            $params = [];
            $aliasName = $alias->getName();
            if (false !== ($queryPos = strpos($aliasName, '?'))) {
                $query = substr($aliasName, $queryPos + 1);
                if ($query !== '') {
                    parse_str($query, $params);
                }
            }

            $routes->add($alias->getName(), new Route($alias->getPath(), array_merge($route->getDefaults(), $params, $alias->getDefaults(), ['_variables' => $variables])));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        return [
            'route.configure' => ['onConfigureRoute', -16],
        ];
    }
}
