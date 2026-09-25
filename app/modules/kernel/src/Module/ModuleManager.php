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
     * Modules switched on for this request, or null until the boot names them.
     *
     * Null is not an empty site: load() of the boot module walks core
     * requirements before that list exists, and those modules are registered.
     *
     * @var array<string, true>|null
     */
    private ?array $enabled = null;

    /**
     * Boot module whose requirements stay loadable without being enabled.
     */
    private ?string $bootModule = null;

    /**
     * Transitive requirements of the boot module, including the boot module.
     *
     * @var array<string, true>
     */
    private array $alwaysLoaded = [];

    /**
     * Reverse edges of the registered manifests. Dropped whenever register()
     * runs, so a lookup does not walk every manifest again.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $requiredBy = null;

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
        /** @var array<string, array<string, mixed>> $resolved */
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
        // Manifests changed, so the reverse index from the previous registration
        // would answer for modules that are no longer the ones on disk.
        $this->requiredBy = null;

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

        // A lookup during this register() would have indexed a half-built set.
        $this->requiredBy = null;
        $this->refreshActivity();

        return $this;
    }

    /**
     * Sets which registered modules may satisfy a requirement.
     *
     * @param list<string> $enabled
     */
    public function setActivityPolicy(array $enabled, string $bootModule): void
    {
        $active = [];

        foreach ($enabled as $name) {
            if ($name !== '') {
                $active[$name] = true;
            }
        }

        $this->enabled = $active;
        $this->bootModule = $bootModule;
        $this->refreshActivity();
    }

    /**
     * Modules whose manifest lists $name under require, in registration order.
     *
     * @return list<string>
     */
    public function requiredBy(string $name): array
    {
        return $this->requiredByIndex()[$name] ?? [];
    }

    /**
     * Whether registration included a module of this name.
     */
    public function isRegistered(string $name): bool
    {
        return isset($this->registered[$name]);
    }

    /**
     * Module names on the registered manifest's require list, in that order.
     *
     * @return list<string>
     */
    public function requires(string $name): array
    {
        $module = $this->registered[$name] ?? null;

        if (!is_array($module)) {
            return [];
        }

        $names = [];

        foreach ((array) ($module['require'] ?? []) as $required) {
            if (is_string($required) && $required !== '') {
                $names[] = $required;
            }
        }

        return $names;
    }

    /**
     * Whether the boot module's requirements keep this module loadable without it being enabled.
     */
    public function isAlwaysLoaded(string $name): bool
    {
        return isset($this->alwaysLoaded[$name]);
    }

    /**
     * Node type ids declared on the registered manifest, in key order.
     *
     * @return list<string>
     */
    public function nodeTypes(string $name): array
    {
        $module = $this->registered[$name] ?? null;
        $nodes = is_array($module) ? ($module['nodes'] ?? null) : null;

        if (!is_array($nodes)) {
            return [];
        }

        $ids = [];

        foreach (array_keys($nodes) as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Refuses a registered module whose requirements are not satisfied.
     *
     * @throws UnsatisfiedRequirementException a requirement is missing or disabled
     * @throws \RuntimeException               the requirements cycle
     */
    public function assertRequirements(string $name): void
    {
        if (!isset($this->registered[$name])) {
            return;
        }

        /** @var array<string, array<string, mixed>> $resolved */
        $resolved = [];
        $this->resolveModules($this->registered[$name], $resolved);
    }

    /**
     * Refuses $name when its requirements are $require instead of the registered list.
     *
     * The registered manifests are unchanged when this returns.
     *
     * @param list<string> $require
     *
     * @throws UnsatisfiedRequirementException a requirement is missing or disabled
     * @throws \RuntimeException               the requirements cycle
     */
    public function assertRequirementsUsing(string $name, array $require): void
    {
        $existed = isset($this->registered[$name]);
        $previous = $existed ? $this->registered[$name] : null;
        $index = $this->requiredBy;

        if ($existed) {
            $this->registered[$name]['require'] = $require;
        } else {
            $this->registered[$name] = [
                'name' => $name,
                'require' => $require,
            ];
        }

        // A lookup during the walk must see this list, not the index built from the previous one.
        $this->requiredBy = null;

        try {
            $this->assertRequirements($name);
        } finally {
            if ($existed && is_array($previous)) {
                $this->registered[$name] = $previous;
            } else {
                unset($this->registered[$name]);
            }

            $this->requiredBy = $index;
        }
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
     * @param array<string, mixed>                $module
     * @param array<string, array<string, mixed>> $resolved
     * @param array<string, array<string, mixed>> $unresolved
     *
     * @throws UnsatisfiedRequirementException a requirement is missing or disabled
     * @throws \RuntimeException               the requirements cycle
     */
    protected function resolveModules(array $module, array &$resolved = [], array &$unresolved = []): void
    {
        $name = $module['name'] ?? null;

        if (!is_string($name)) {
            throw new \RuntimeException('Undefined module: ' . get_debug_type($name));
        }

        $unresolved[$name] = $module;

        foreach ((array) ($module['require'] ?? []) as $required) {
            if (!is_string($required) || $required === '') {
                throw new UnsatisfiedRequirementException(
                    $name,
                    is_string($required) ? $required : get_debug_type($required),
                    false,
                );
            }

            if (isset($resolved[$required])) {
                continue;
            }

            if (isset($unresolved[$required])) {
                throw new \RuntimeException(sprintf('Circular requirement "%s > %s" detected.', $name, $required));
            }

            if (!isset($this->registered[$required])) {
                throw new UnsatisfiedRequirementException($name, $required, false);
            }

            // Recursing would load a package the site has switched off.
            if (!$this->isActive($required)) {
                throw new UnsatisfiedRequirementException($name, $required, true);
            }

            $this->resolveModules($this->registered[$required], $resolved, $unresolved);
        }

        $resolved[$name] = $module;
        unset($unresolved[$name]);
    }

    /**
     * Whether a registered module may be loaded to satisfy a requirement.
     *
     * Null means the policy is unset, so the name is active; an empty enabled list is not.
     */
    private function isActive(string $name): bool
    {
        if ($this->enabled === null) {
            return true;
        }

        return isset($this->alwaysLoaded[$name]) || isset($this->enabled[$name]);
    }

    private function refreshActivity(): void
    {
        if ($this->bootModule === null) {
            return;
        }

        $this->alwaysLoaded = $this->alwaysLoadedClosure($this->bootModule);
    }

    /**
     * @return array<string, true>
     */
    private function alwaysLoadedClosure(string $bootModule): array
    {
        $closure = [];
        $pending = [$bootModule];

        while ($pending !== []) {
            $name = array_pop($pending);

            if (isset($closure[$name])) {
                continue;
            }

            $closure[$name] = true;
            $module = $this->registered[$name] ?? null;

            if (!is_array($module)) {
                continue;
            }

            foreach ((array) ($module['require'] ?? []) as $required) {
                if (
                    !is_string($required)
                    || $required === ''
                    || isset($closure[$required])
                    || !isset($this->registered[$required])
                ) {
                    continue;
                }

                $pending[] = $required;
            }
        }

        return $closure;
    }

    /**
     * @return array<string, list<string>>
     */
    private function requiredByIndex(): array
    {
        if ($this->requiredBy !== null) {
            return $this->requiredBy;
        }

        /** @var array<string, array<string, true>> $seen */
        $seen = [];

        foreach ($this->registered as $name => $module) {
            foreach ((array) ($module['require'] ?? []) as $required) {
                if (!is_string($required) || $required === '') {
                    continue;
                }

                $seen[$required] ??= [];
                $seen[$required][$name] = true;
            }
        }

        $index = [];

        foreach ($seen as $required => $dependers) {
            $index[$required] = array_keys($dependers);
        }

        $this->requiredBy = $index;

        return $index;
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
