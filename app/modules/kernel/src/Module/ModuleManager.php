<?php

declare(strict_types=1);

namespace Pagekit\Module;

use Pagekit\Application;
use Pagekit\Module\Loader\AutoLoader;
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
     * The autoload map is applied before the entry point runs, because that
     * file can name a class at file scope. Every other preLoader runs after
     * the merge, from the list on the manager when this module's pass starts.
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
            // A loader appended from this module's own main() is not on this list.
            $preLoaders = $this->preLoaders;
            $record = $module;

            foreach ($preLoaders as $loader) {
                if ($loader instanceof AutoLoader) {
                    $record = $loader->load($record);
                }
            }

            $merged = array_replace($this->defaults, $module);
            $path = $module['path'] ?? null;

            if (!is_string($path)) {
                throw new \RuntimeException(sprintf('Module "%s" has no path.', $name));
            }

            $entry = $path.'/'.ModuleManifest::ENTRY;

            if (is_file($entry)) {
                $returned = $this->includeEntry($entry);

                if (!is_array($returned)) {
                    throw new \RuntimeException(sprintf('Module "%s" entry point must return an array.', $name));
                }

                $merged = array_replace($merged, $returned);
            }

            foreach (['name', 'require', 'include', 'autoload', 'nodes', 'path'] as $key) {
                if (array_key_exists($key, $module)) {
                    $merged[$key] = $module[$key];
                } else {
                    unset($merged[$key]);
                }
            }

            foreach ($preLoaders as $loader) {
                if (!$loader instanceof AutoLoader) {
                    $merged = $loader->load($merged);
                }
            }

            foreach ($this->postLoaders as $loader) {
                $merged = $loader->load($merged);
            }

            $this->modules[$name] = $merged;
        }

        return $this;
    }

    /**
     * Registers modules from static manifests and does not execute the entry point.
     *
     * @param string|array<int, string> $paths
     *
     * @return self
     */
    public function register(string|array $paths, ?string $basePath = null): self
    {
        // Manifests changed, so the reverse index from the previous registration
        // would answer for modules that are no longer the ones on disk.
        $this->requiredBy = null;

        $includes = $this->registerPaths($paths, $basePath, []);

        while ($includes !== []) {
            // Taken before the pass. A name already stored is left alone; two includes
            // in this pass still resolve last-wins for a name that was free.
            $protected = [];

            foreach (array_keys($this->registered) as $existing) {
                $protected[$existing] = true;
            }

            $includes = $this->registerIncluded($includes, $protected);
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
     * The entry point closes over $app from this scope.
     *
     * @return mixed Genuinely unknown type — PHP include returns the file's value, or 1 when the file returns nothing; the caller rejects a non-array.
     */
    private function includeEntry(string $file): mixed
    {
        $app = $this->app;

        return include $file;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(string $file): ?array
    {
        $size = filesize($file);

        if ($size === false) {
            throw new ModuleManifestException('The module manifest could not be read.');
        }

        if ($size > ModuleManifest::MAX_BYTES) {
            throw new ModuleManifestException(sprintf('The module manifest exceeds %d bytes.', ModuleManifest::MAX_BYTES));
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new ModuleManifestException('The module manifest could not be read.');
        }

        $json = stream_get_contents($handle, ModuleManifest::MAX_BYTES + 1);
        fclose($handle);

        if (!is_string($json) || strlen($json) > ModuleManifest::MAX_BYTES) {
            throw new ModuleManifestException(sprintf('The module manifest exceeds %d bytes.', ModuleManifest::MAX_BYTES));
        }

        return ModuleManifest::decode($json);
    }

    private function hasHiddenSegment(string $path): bool
    {
        foreach (explode('/', strtr($path, '\\', '/')) as $segment) {
            if ($segment !== '' && str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Joins a relative path onto a base.
     */
    protected function resolvePath(string $path, ?string $basePath = null): string
    {
        $path = strtr($path, '\\', '/');
        $base = $this->normalizedBase($basePath);

        if ($base === null) {
            return $path;
        }

        // An empty base, an absolute path, or ".." is that base, so the caller does not glob it.
        if ($base === '' || !ModuleManifest::includeStaysInModule($path)) {
            return $base;
        }

        return $base.'/'.ltrim($path, '/');
    }

    private function normalizedBase(?string $basePath): ?string
    {
        if ($basePath === null) {
            return null;
        }

        return rtrim(strtr($basePath, '\\', '/'), '/');
    }

    /**
     * @param string|array<int, string> $paths
     * @param array<string, true>       $protected
     *
     * @return list<array{pattern: string, directory: string}>
     */
    private function registerPaths(string|array $paths, ?string $basePath, array $protected): array
    {
        $files = [];
        $base = $this->normalizedBase($basePath);

        foreach ((array) $paths as $path) {
            $resolved = $this->resolvePath($path, $basePath);

            // The base is the stand-in for a pattern that must not be read, including an empty base.
            if ($base !== null && $resolved === $base) {
                continue;
            }

            foreach (glob($resolved, GLOB_NOSORT) ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $this->registerFiles($files, $protected);
    }

    /**
     * @param list<array{pattern: string, directory: string}> $includes
     * @param array<string, true>                             $protected
     *
     * @return list<array{pattern: string, directory: string}>
     */
    private function registerIncluded(array $includes, array $protected): array
    {
        $files = [];

        foreach ($includes as $include) {
            foreach ($this->includeFiles($include['pattern'], $include['directory']) as $file) {
                $files[] = $file;
            }
        }

        return $this->registerFiles($files, $protected);
    }

    /**
     * Manifests the glob names that stay inside the module. Absolute patterns and ".." match nothing.
     *
     * @return list<string>
     */
    private function includeFiles(string $pattern, string $directory): array
    {
        $base = rtrim(strtr($directory, '\\', '/'), '/');
        $resolved = $this->resolvePath($pattern, $directory);

        // Joining ".." onto the module still begins with that directory, so the prefix is not enough.
        if ($base === '' || !str_starts_with($resolved, $base.'/') || !ModuleManifest::includeStaysInModule($pattern)) {
            return [];
        }

        $files = [];

        foreach (glob($resolved, GLOB_NOSORT) ?: [] as $file) {
            if ($this->pathStaysInside($file, $directory)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @param list<string>         $files
     * @param array<string, true>  $protected
     *
     * @return list<array{pattern: string, directory: string}>
     */
    private function registerFiles(array $files, array $protected): array
    {
        /** @var array<string, true> $written */
        $written = [];

        foreach ($files as $file) {
            $name = $this->registerManifest($file, $protected);

            if ($name !== null) {
                // The last write owns the include. An earlier slot lets a later file replace a child it registers.
                unset($written[$name]);
                $written[$name] = true;
            }
        }

        return $this->includesFrom($written);
    }

    /**
     * @param array<string, true> $protected
     */
    private function registerManifest(string $file, array $protected): ?string
    {
        // A segment that starts with "." is a hidden sibling of an installed package, not a module.
        if ($this->hasHiddenSegment($file) || !is_file($file)) {
            return null;
        }

        try {
            $module = $this->readManifest($file);
        } catch (ModuleManifestException $exception) {
            $this->registrationFailures[$file] = $exception;

            return null;
        }

        if ($module === null) {
            return null;
        }

        $module['path'] = strtr(dirname($file), '\\', '/');
        $name = $module['name'];

        if (!is_string($name) || $name === '' || isset($protected[$name])) {
            return null;
        }

        $this->registered[$name] = $module;

        return $name;
    }

    /**
     * The last manifest written for a name is the one stored, so only its include is followed.
     *
     * @param array<string, true> $names
     *
     * @return list<array{pattern: string, directory: string}>
     */
    private function includesFrom(array $names): array
    {
        $includes = [];

        foreach (array_keys($names) as $name) {
            $module = $this->registered[$name] ?? null;

            if (!is_array($module)) {
                continue;
            }

            $directory = $module['path'] ?? null;
            $declared = $module['include'] ?? null;

            if (!is_string($directory) || $directory === '' || $declared === null) {
                continue;
            }

            foreach (is_array($declared) ? $declared : [$declared] as $pattern) {
                if (!is_string($pattern) || $pattern === '') {
                    continue;
                }

                $includes[] = ['pattern' => $pattern, 'directory' => $directory];
            }
        }

        return $includes;
    }

    /**
     * glob() follows a symlink out of the module; the file that is read has to stay inside it.
     */
    private function pathStaysInside(string $file, string $directory): bool
    {
        $root = realpath($directory);
        $real = realpath($file);

        if ($root === false || $real === false) {
            return false;
        }

        $root = rtrim(strtr($root, '\\', '/'), '/');

        return str_starts_with(strtr($real, '\\', '/'), $root.'/');
    }
}
