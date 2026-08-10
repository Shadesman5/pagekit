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

    /** @var array<string, \Throwable> */
    protected array $registrationFailures = [];

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
     * Discovery learns what a module declares by executing its file, so a
     * broken package throws here - before anything has been loaded, and long
     * before there is a site left to report it on. Each include is therefore
     * isolated: the throwing file registers no module and is kept for the boot
     * to log, while every other package registers as usual.
     *
     * The isolation reaches as far as userland code can reach. A file throwing
     * at top level is caught, and so is a ParseError, which PHP raises as a
     * throwable. A genuinely fatal compile error - a duplicate class or
     * function declaration - along with exit/die and exhausted memory or time
     * still ends the request, because none of those is a throwable. That
     * residue goes away only once discovery no longer executes the file to
     * find out what is in it.
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

                // TODO: Must be refactored in Step 2.7.3 (Static Module Registration)
                try {
                    $module = include $file;
                } catch (\Throwable $e) {
                    $this->registrationFailures[$file] = $e;

                    continue;
                }

                if (!is_array($module) || !isset($module['name'])) {
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
     * The module files that failed to register, keyed by path.
     *
     * Registration runs before the first module is loaded, so nothing that
     * could report a failure exists yet. The throwables wait here until the
     * boot has a logger to hand them to.
     *
     * @return array<string, \Throwable>
     */
    public function getRegistrationFailures(): array
    {
        return $this->registrationFailures;
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
