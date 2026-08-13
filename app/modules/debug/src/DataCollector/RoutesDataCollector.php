<?php

declare(strict_types=1);

namespace Pagekit\Debug\DataCollector;

use DebugBar\DataCollector\DataCollectorInterface;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Routing\Router;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\RouterInterface;

class RoutesDataCollector implements DataCollectorInterface
{
    protected \Pagekit\Routing\Router $router;
    protected ?string $route = null;
    protected string $cache;
    protected string $file;

    /**
     * Constructor.
     *
     * @param Router                   $router
     * @param EventDispatcherInterface $events
     * @param string                   $cache
     * @param string                   $file
     */
    public function __construct(RouterInterface $router, EventDispatcherInterface $events, $cache, $file = '%s.cache')
    {
        $this->router = $router;
        $this->cache = $cache;
        $this->file = $file;

        $events->on('request', function ($event, $request): void {
            $route = $request->attributes->get('_route');
            $this->route = is_string($route) ? $route : null;
        });
    }

    /**
     * {@inheritdoc}
     *
     * @return array{routes: array<int, array<string, mixed>>, route: string|null}
     */
    public function collect(): array
    {
        $collection = $this->router->getRouteCollection();

        // Key the collected list on the routes themselves: they are what makes
        // it stale, and no file of the router's tracks them. Read as the fields
        // the list shows rather than as the collection object, because a route
        // whose controller is a closure cannot be serialized at all - a panel
        // that a route definition can throw out of is a panel that takes the
        // request down with it.
        $signature = '';
        foreach ($collection as $name => $route) {
            $signature .= $name."\0"
                .$route->getPath()."\0"
                .implode(',', $route->getMethods())."\0"
                .$this->controllerOf($route)."\n";
        }

        $path = sprintf($this->cache.'/'.$this->file, sha1($signature));

        if (!file_exists($path)) {

            $routes = [];
            foreach ($collection as $name => $route) {
                $routes[] = [
                    'name' => $name,
                    'path' => $route->getPath(),
                    'methods' => $route->getMethods(),
                    'controller' => $this->controllerOf($route),
                ];
            }

            file_put_contents($path, '<?php return '.var_export($routes, true).';');

        } else {
            $routes = require $path;
        }

        $route = $this->route;

        return compact('routes', 'route');
    }

    /**
     * Names the controller a route runs, for a panel that can only show text.
     *
     * A callback route carries no controller name to show: the router keeps the
     * callable aside and the route default stays empty.
     */
    private function controllerOf(SymfonyRoute $route): string
    {
        return is_string($controller = $route->getDefault('_controller')) ? $controller : 'Closure';
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'routes';
    }
}
