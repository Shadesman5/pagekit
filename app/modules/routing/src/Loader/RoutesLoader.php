<?php

declare(strict_types=1);

namespace Pagekit\Routing\Loader;

use Pagekit\Application;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads routes from route definitions and controller classes.
 */
class RoutesLoader implements LoaderInterface
{
    protected \Pagekit\Event\EventDispatcherInterface $events;

    protected \Pagekit\Routing\Loader\AttributeLoader $loader;

    protected ?RouteCollection $routes = null;

    /**
     * Constructor.
     *
     * @param EventDispatcherInterface $events
     * @param AttributeLoader|null $loader
     * @param Application|null $app Container used for debug-aware error reporting in addController().
     */
    public function __construct(EventDispatcherInterface $events, ?AttributeLoader $loader = null, protected ?Application $app = null)
    {
        $this->events = $events;
        $this->loader = $loader ?: new AttributeLoader();
    }

    /**
     * {@inheritdoc}
     */
    public function load($routes): RouteCollection
    {
        $collection = new RouteCollection();
        $this->routes = $collection;

        foreach ($routes as $route) {

            if ($route->getOption('controller')) {

                foreach ((array) $route->getOption('controller') as $controller) {

                    if (is_string($controller) && class_exists($controller)) {
                        $this->addController($route, $controller);
                    } else {
                        $this->addRoute($route);
                    }

                }

            } else {

                $this->addRoute($route);

            }

        }

        $this->routes = null;

        return $collection;
    }

    /**
     * Adds a route.
     *
     * @param Route $route
     */
    protected function addRoute(Route $route): void
    {
        if ($this->routes === null) {
            return;
        }
        $this->routes->add($route->getName(), $route);
        $this->events->trigger('route.configure', [$route, $this->routes]);
    }

    /**
     * Adds routes from controller class.
     *
     * @param Route  $route
     * @param string $controller
     */
    protected function addController(Route $route, string $controller): void
    {
        try {

            foreach ($this->loader->load($controller) as $r) {

                $this->addRoute(
                    $r
                    ->setName(trim("{$route->getName()}/{$r->getName()}", "/"))
                    ->setPath(rtrim($route->getPath().$r->getPath(), '/'))
                    ->addDefaults($route->getDefaults())
                    ->addRequirements($route->getRequirements())
                );

            }

        } catch (\InvalidArgumentException $e) {

            // Debug-aware handler: re-throw in dev so broken controllers surface
            // immediately; in production, log and skip the offending route so the
            // rest of the route collection still loads.
            if ($this->app && $this->app->has('debug') && $this->app->get('debug')) {
                throw $e;
            }

            $message = sprintf('Route loading failed for controller "%s": %s', $controller, $e->getMessage());

            if ($this->app && $this->app->has('log')) {
                $this->app->get('log')->warning($message);
            } else {
                error_log($message);
            }
        }
    }
}
