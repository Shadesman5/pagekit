<?php

declare(strict_types=1);

namespace Pagekit\Module;

use Pagekit\Application;
use Pagekit\Module\Loader\CallableLoader;
use Pagekit\Module\Loader\LoaderInterface;
use Pagekit\Module\Loader\ModuleLoader;

/**
 * @implements \IteratorAggregate<string, mixed>
 */
class ModuleManager implements \IteratorAggregate
{
    protected Application $app;

    /** @var array<string, mixed> */
    protected array $modules = [];

    /** @var array<string, array<string, mixed>> */
    protected array $registered = [];

    /**
     * @var LoaderInterface[]
     */
    protected array $preLoaders = [];

    /**
     * @var LoaderInterface[]
     */
    protected array $postLoaders = [];

    /** @var array<string, mixed> */
    protected array $defaults = [
        'main' => null,
        'type' => 'module',
        'class' => 'Pagekit\Module\Module',
        'config' => [],
    ];

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->postLoaders = [new ModuleLoader($app)];
    }

    /**
     * Get shortcut.
     *
     * @see get()
     * @return mixed Genuinely unknown type — the module registry may hold ModuleInterface instances, plain arrays, or null during loading; type is narrowed by callers.
     */
    public function __invoke(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Gets a module.
     *
     * @return mixed Genuinely unknown type — the module registry may hold ModuleInterface instances, plain arrays, or null; type is narrowed by callers via instanceof checks.
     */
    public function get(string $name): mixed
    {
        return $this->modules[$name] ?? null;
    }

    /**
     * Gets all modules.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * Loads modules by name.
     *
     * @param string|array<int, string> $modules
     */
    public function load(string|array $modules): self
    {
        $resolved = [];

        if (is_string($modules)) {
            $modules = (array) $modules;
        }

        foreach ((array) $modules as $name) {

            if (!isset($this->registered[$name])) {
                throw new \RuntimeException("Undefined module: $name");
            }

            $this->resolveModules($this->registered[$name], $resolved);
        }

        $resolved = array_diff_key($resolved, $this->modules);

        foreach ($resolved as $name => $module) {

            foreach ($this->preLoaders as $loader) {
                $module = $loader->load($module);
            }

            foreach ($this->postLoaders as $loader) {
                $module = $loader->load($module);
            }

            $this->modules[$name] = $module;
        }

        return $this;
    }

    /**
     * Registers modules from path(s).
     *
     * @param string|array<int, string> $paths
     */
    public function register(string|array $paths, ?string $basePath = null): self
    {
        $app = $this->app;
        $includes = [];

        foreach ((array) $paths as $path) {

            $files = glob($this->resolvePath($path, $basePath), GLOB_NOSORT) ?: [];

            foreach ($files as $file) {

                if (!is_array($module = include $file) || !isset($module['name'])) {
                    continue;
                }

                $module = array_replace($this->defaults, $module);
                $module['path'] = strtr(dirname($file), '\\', '/');

                if (isset($module['include'])) {
                    foreach ((array) $module['include'] as $include) {
                        $includes[] = $this->resolvePath($include, $module['path']);
                    }
                }

                $this->registered[$module['name']] = $module;
            }
        }

        if ($includes) {
            $this->register($includes);
        }

        return $this;
    }

    /**
     * Adds a module loader.
     */
    public function addLoader(LoaderInterface|callable $loader, bool $post = false): self
    {
        if (!$loader instanceof LoaderInterface) {
            $loader = new CallableLoader($loader);
        }

        if (!$post) {
            $this->preLoaders[] = $loader;
        } else {
            $this->postLoaders[] = $loader;
        }

        return $this;
    }

    /**
     * Implements the IteratorAggregate.
     *
     * @return \ArrayIterator<string, mixed>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }

    /**
     * Resolves module requirements.
     *
     * @param array<string, mixed>            $module
     * @param array<array<string, mixed>>     $resolved
     * @param array<array<string, mixed>>     $unresolved
     *
     * @throws \RuntimeException
     */
    protected function resolveModules(array $module, array &$resolved = [], array &$unresolved = []): void
    {
        $unresolved[$module['name']] = $module;

        if (isset($module['require'])) {
            foreach ((array) $module['require'] as $required) {
                if (!isset($resolved[$required])) {

                    if (isset($unresolved[$required])) {
                        throw new \RuntimeException(sprintf('Circular requirement "%s > %s" detected.', $module['name'], $required));
                    }

                    if (isset($this->registered[$required])) {
                        $this->resolveModules($this->registered[$required], $resolved, $unresolved);
                    }
                }
            }
        }

        $resolved[$module['name']] = $module;
        unset($unresolved[$module['name']]);
    }

    /**
     * Resolves a absolute path to a given base path.
     */
    protected function resolvePath(string $path, ?string $basePath = null): string
    {
        $path = strtr($path, '\\', '/');

        if ($path[0] != '/' && !(strlen($path) > 3 && ctype_alpha($path[0]) && $path[1] == ':' && $path[2] == '/')) {
            $path = "$basePath/$path";
        }

        return $path;
    }
}
