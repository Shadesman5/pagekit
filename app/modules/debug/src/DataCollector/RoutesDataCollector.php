<?php

declare(strict_types=1);

namespace Pagekit\Debug\DataCollector;

use DebugBar\DataCollector\DataCollectorInterface;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Routing\Router;
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

        // Key the collected list on the routes themselves: they are what makes it
        // stale, and no file of the router's tracks them.
        $path = sprintf($this->cache.'/'.$this->file, sha1(serialize($collection)));

        if (!file_exists($path)) {

            $routes = [];
            foreach ($collection as $name => $route) {
                $routes[] = [
                    'name' => $name,
                    'path' => $route->getPath(),
                    'methods' => $route->getMethods(),
                    'controller' => is_string($ctrl = $route->getDefault('_controller')) ? $ctrl : 'Closure',
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
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'routes';
    }
}
