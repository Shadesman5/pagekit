<?php

declare(strict_types=1);

namespace Pagekit\View\Asset;

/**
 * @implements \IteratorAggregate<string, AssetInterface>
 */
class AssetManager implements \IteratorAggregate
{
    protected ?\Pagekit\Filter\FilterManager $filters = null;

    protected \Pagekit\View\Asset\AssetFactory $factory;

    protected \Pagekit\View\Asset\AssetCollection $registered;

    /** @var array<string, true> */
    protected array $queue = [];

    /** @var array<string, array<int, string>> */
    protected array $lazy = [];

    /** @var array<string, array{pattern: string, filters: array<int, string>}> */
    protected array $combine = [];

    protected ?string $cache = null;

    /**
     * Constructor.
     */
    public function __construct(?AssetFactory $factory = null, ?string $cache = null)
    {
        $this->factory = $factory ?: new AssetFactory();
        $this->registered = new AssetCollection();

        if ($cache) {
            $this->cache = $cache;
        }
    }

    /**
     * Add shortcut.
     *
     * @see add()
     *
     * @param array<int, string>   $dependencies
     * @param array<string, mixed> $options
     */
    public function __invoke(string $name, mixed $asset = null, array $dependencies = [], array $options = []): ?AssetInterface
    {
        return $this->add($name, $asset, $dependencies, $options);
    }

    /**
     * Gets a registered asset.
     */
    public function get(string $name): ?\Pagekit\View\Asset\AssetInterface
    {
        return $this->registered->get($name);
    }

    /**
     * Adds a registered asset or a new asset to the queue.
     *
     * @param array<int, string>   $dependencies
     * @param array<string, mixed> $options
     */
    public function add(string $name, mixed $source = null, array $dependencies = [], array $options = []): ?AssetInterface
    {
        if ($source !== null) {
            $this->registered->add($asset = $this->factory->create($name, $source, $dependencies, $options));
        } else {
            $asset = $this->registered->get($name);
        }

        $this->queue[$name] = true;

        return $asset;
    }

    /**
     * Removes an asset from the queue.
     */
    public function remove(string $name): self
    {
        unset($this->queue[$name]);

        foreach ($this->lazy as &$dependencies) {
            if (false !== $index = array_search($name, $dependencies)) {
                unset($dependencies[$index]);
            }
        }

        return $this;
    }

    /**
     * Removes all assets from the queue.
     */
    public function removeAll(): self
    {
        $this->queue = [];
        $this->lazy = [];

        return $this;
    }

    /**
     * Registers an asset.
     *
     * @param array<int, string>   $dependencies
     * @param array<string, mixed> $options
     */
    public function register(string $name, mixed $source, array $dependencies = [], array $options = []): AssetInterface
    {
        $this->registered->add($asset = $this->factory->create($name, $source, $dependencies, $options));

        foreach ($asset->getDependencies() as $dependency) {
            if ($dependency[0] === '~') {
                $this->lazy[ltrim($dependency, '~')][] = $name;
            }
        }

        return $asset;
    }

    /**
     * Unregisters an asset.
     */
    public function unregister(string $name): self
    {
        $this->registered->remove($name);
        $this->remove($name);

        return $this;
    }

    /**
     * Combines a assets to a file and applies filters.
     *
     * @param array<int, string> $filters
     */
    public function combine(string $name, string $pattern, array $filters = []): self
    {
        $this->combine[$name] = compact('pattern', 'filters');

        return $this;
    }

    /**
     * Gets queued assets with resolved dependencies, optionally all registered assets.
     */
    public function all(bool $registered = false): AssetCollection
    {
        if ($registered) {
            return $this->registered;
        }

        $assets = [];

        foreach (array_keys($this->queue) as $name) {
            $asset = $this->registered->get($name);
            if ($asset !== null) {
                $this->resolveDependencies($asset, $assets);
            }
        }

        $assets = new AssetCollection($assets);

        foreach ($this->combine as $name => $options) {
            $assets = $this->doCombine($assets, $name, $options);
        }

        return $assets;
    }

    /**
     * IteratorAggregate interface implementation.
     *
     * @return \ArrayIterator<string, AssetInterface>
     */
    public function getIterator(): \ArrayIterator
    {
        return $this->all()->getIterator();
    }

    /**
     * Gets the asset factory.
     */
    public function getFactory(): AssetFactory
    {
        return $this->factory;
    }

    /**
     * Resolves asset dependencies.
     *
     * @param array<string, AssetInterface> $resolved
     * @param array<string, AssetInterface> $unresolved
     *
     * @return array<string, AssetInterface>
     *
     * @throws \RuntimeException
     */
    protected function resolveDependencies(AssetInterface $asset, array &$resolved = [], array &$unresolved = []): array
    {
        $name = $asset->getName();
        $unresolved[$name] = $asset;

        foreach ($asset->getDependencies() as $dependency) {

            if ($dependency[0] === '~' && !isset($resolved[$dependency = ltrim($dependency, '~')])) {
                continue;
            }

            if (!isset($resolved[$dependency])) {

                if (isset($unresolved[$dependency])) {
                    throw new \RuntimeException(sprintf('Circular asset dependency "%s > %s" detected.', $name, $dependency));
                }

                if ($d = $this->registered->get($dependency)) {
                    $this->resolveDependencies($d, $resolved, $unresolved);
                }
            }
        }

        $resolved[$name] = $asset;
        unset($unresolved[$name]);

        if (isset($this->lazy[$name])) {
            foreach ($this->lazy[$name] as $dependency) {
                if ($d = $this->registered->get($dependency)) {
                    $this->resolveDependencies($d, $resolved, $unresolved);
                }
            }
        }

        return $resolved;
    }

    /**
     * Combines assets matching a pattern to a single file asset, optionally applies filters.
     *
     * @param array{pattern?: string, filters?: array<int, string>} $options
     */
    protected function doCombine(AssetCollection $assets, string $name, array $options = []): AssetCollection
    {
        if ($this->cache === null) {
            return $assets;
        }

        $pattern = $options['pattern'] ?? '';
        $filters = $options['filters'] ?? [];

        $combine = new AssetCollection();
        $pattern = $this->globToRegex($pattern);

        foreach ($assets as $asset) {
            if (preg_match($pattern, $asset->getName())) {
                $combine->add($asset);
            }
        }

        $file = strtr($this->cache, ['%name%' => $name]);

        if ($names = $combine->names() and $file = $this->doCache($combine, $file, $filters)) {
            $assets->remove(array_slice($names, 1));
            $assets->replace(array_shift($names), $this->factory->create($name, $file));
        }

        return $assets;
    }

    /**
     * Writes an asset collection to a cache file, optionally applies filters.
     *
     * @param array<int, string> $filters
     */
    protected function doCache(AssetCollection $assets, string $file, array $filters = []): string|false
    {
        $resolved = [];
        if ($this->filters !== null) {
            foreach ($filters as $name) {
                $resolved[] = $this->filters->get($name);
            }
        }
        $filters = $resolved;

        if (count($assets)) {

            $salt = array_merge([$_SERVER['SCRIPT_NAME']], array_keys($filters));
            $file = preg_replace('/(.*?)(\.[^\.]+)?$/i', '$1-'.$assets->hash(implode(',', $salt)).'$2', $file, 1);

            if ($file === null) {
                return false;
            }

            if (!file_exists($file)) {
                file_put_contents($file, $assets->dump($filters));
            }

            return $file;
        }

        return false;
    }

    /**
     * Converts a glob to a regular expression.
     */
    protected function globToRegex(string $glob): string
    {
        $regex = '';
        $group = 0;
        $escape = false;

        for ($i = 0; $i < strlen($glob); $i++) {

            $c = $glob[$i];

            if ('.' === $c || '(' === $c || ')' === $c || '|' === $c || '+' === $c || '^' === $c || '$' === $c) {
                $regex .= "\\$c";
            } elseif ('*' === $c) {
                $regex .= $escape ? '\\*' : '.*';
            } elseif ('?' === $c) {
                $regex .= $escape ? '\\?' : '.';
            } elseif ('{' === $c) {
                $regex .= $escape ? '\\{' : '(';
                if (!$escape) {
                    ++$group;
                }
            } elseif ('}' === $c && $group) {
                $regex .= $escape ? '}' : ')';
                if (!$escape) {
                    --$group;
                }
            } elseif (',' === $c && $group) {
                $regex .= $escape ? ',' : '|';
            } elseif ('\\' === $c) {
                if ($escape) {
                    $regex .= '\\\\';
                    $escape = false;
                } else {
                    $escape = true;
                }

                continue;
            } else {
                $regex .= $c;
            }

            $escape = false;
        }

        return '#^'.$regex.'$#';
    }
}
