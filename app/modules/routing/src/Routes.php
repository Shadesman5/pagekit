<?php

declare(strict_types=1);

namespace Pagekit\Routing;

/**
 * @implements \IteratorAggregate<int, Route>
 */
class Routes implements \Serializable, \IteratorAggregate, ResourceInterface
{
    /** @var array<int, Route> */
    protected array $routes = [];

    /**
     * @var callable[]
     */
    protected array $callbacks = [];

    /**
     * @var array<string, Route>
     */
    protected array $aliases = [];

    protected string $prefix = '@';

    protected int $modified = 0;

    /**
     * Adds a route.
     *
     * @param Route|array<string, mixed> $route
     */
    public function add($route): Route
    {
        if (is_array($route)) {
            $route = $this->createRoute($route);
        }

        return $this->routes[] = $route;
    }

    /**
     * Maps a route to a callback (GET).
     *
     * @param  string   $path
     * @param  callable $callback
     */
    public function get($path, $callback): Route
    {
        return $this->match($path, $callback)->setMethods('GET');
    }

    /**
     * Maps a route to a callback (POST).
     *
     * @param  string   $path
     * @param  callable $callback
     */
    public function post($path, $callback): Route
    {
        return $this->match($path, $callback)->setMethods('POST');
    }

    /**
     * Maps a route to a callback.
     *
     * @param  string   $path
     * @param  callable $callback
     */
    public function match($path, $callback): Route
    {
        return $this->add(['path' => $path, 'controller' => $callback]);
    }

    /**
     * Gets a registered callback.
     *
     * @param  string $name
     * @return callable|null
     */
    public function getCallback($name): ?callable
    {
        return isset($this->callbacks[$name]) ? $this->callbacks[$name] : null;
    }

    /**
     * Adds an alias.
     *
     * @param string                $path
     * @param string                $name
     * @param array<string, mixed>  $defaults
     */
    public function alias($path, $name, array $defaults = []): Route
    {
        $path = preg_replace('/^[^\/]/', '/$0', $path);

        return $this->aliases[$name] = $this->createRoute(compact('name', 'path', 'defaults'));
    }

    /**
     * Adds a redirect route.
     *
     * @param string                $path
     * @param string                $redirect
     * @param array<string, mixed>  $defaults
     */
    public function redirect($path, $redirect, array $defaults = []): Route
    {
        $defaults['_redirect'] = $redirect;

        return $this->add(compact('path', 'defaults'));
    }

    /**
     * Gets aliases.
     *
     * @return array<string, Route>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * {@inheritdoc}
     *
     * @return \ArrayIterator<int, Route>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->routes);
    }

    /**
     * {@inheritdoc}
     */
    public function getModified(): int
    {
        return $this->modified;
    }

    /**
     * {@inheritdoc}
     */
    public function serialize(): string
    {
        return serialize([$this->routes, $this->aliases]);
    }

    /**
     * {@inheritdoc}
     */
    public function unserialize($serialized): void
    {
        list($this->routes, $this->aliases) = unserialize($serialized);
    }

    /**
     * Serializes the routes.
     *
     * @return array<int, Route>
     */
    public function __serialize(): array
    {
        return $this->routes;
    }

    /**
     * Unserializes the routes.
     *
     * @param array<int, Route> $data
     */
    public function __unserialize(array $data): void
    {
        $this->routes = $data;
    }


    /**
     * Creates a route from array definition.
     *
     * @param array<string, mixed> $config
     */
    protected function createRoute(array $config): Route
    {
        $name = isset($config['name']) ? $config['name'] : $this->generateRouteName($config);
        $defaults = isset($config['defaults']) ? $config['defaults'] : [];
        $requirements = isset($config['requirements']) ? $config['requirements'] : [];
        $options = isset($config['options']) ? $config['options'] : [];
        $host = isset($config['host']) ? $config['host'] : '';
        $schemes = isset($config['schemes']) ? $config['schemes'] : [];
        $methods = isset($config['methods']) ? $config['methods'] : [];
        $condition = isset($config['condition']) ? $config['condition'] : '';

        $options['controller'] = isset($config['controller']) ? $config['controller'] : '';

        if (!is_string($options['controller']) && is_callable($options['controller'])) {
            $this->callbacks[$name] = $options['controller'];
            unset($options['controller']);
        } elseif ($options['controller']) {
            foreach ((array) $options['controller'] as $controller) {

                if (is_string($controller) && is_callable($controller) && str_contains($controller, '::')) {
                    [$class, $method] = explode('::', $controller, 2);
                    $refl = new \ReflectionMethod($class, $method);
                    $defaults['_controller'] = $controller;
                } elseif (is_string($controller) && class_exists($controller)) {
                    $refl = new \ReflectionClass($controller);
                } else {
                    continue;
                }

                $file = $refl->getFileName();
                if ($file === false) {
                    continue;
                }

                $mtime = filemtime($file);
                if ($mtime === false) {
                    continue;
                }

                $this->modified = max($this->modified, $mtime);
            }
        }

        return (new Route($config['path'], $defaults, $requirements, $options, $host, $schemes, $methods, $condition))->setName($name);
    }

    /**
     * Creates a route name from the routes path.
     *
     * @param array<string, mixed> $config
     */
    protected function generateRouteName(array $config): string
    {
        $name = ltrim($config['path'], '/');
        $name = trim(str_replace([':', '|', '-'], '_', $name), '_');
        $name = preg_replace('/[^a-z0-9A-Z_.\/]+/', '', $name);

        return $this->prefix.$name;
    }
}
