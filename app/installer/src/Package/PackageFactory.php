<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package;

use Pagekit\Application\UrlProvider;
use Pagekit\Filesystem\Path;

/**
 * @implements \ArrayAccess<string, Package>
 * @implements \IteratorAggregate<string, Package>
 */
class PackageFactory implements \ArrayAccess, \IteratorAggregate
{
    /** @var array<int, string> */
    protected array $paths = [];

    /** @var array<string, Package> */
    protected array $packages = [];

    /** Application root the packages sit below, empty when it is unknown. */
    private readonly string $root;

    /**
     * @param string $root application root, needed to address a package by the path it is published under
     */
    public function __construct(
        private readonly ?UrlProvider $url = null,
        string $root = '',
    ) {
        $this->root = $root === '' ? '' : Path::directory($root);
    }

    /**
     * Get shortcut.
     *
     * @see get()
     */
    public function __invoke(string $name): ?Package
    {
        return $this->get($name);
    }

    /**
     * Gets a package.
     */
    public function get(string $name, bool $force = false): ?Package
    {
        if ($force || empty($this->packages)) {
            $this->loadPackages();
        }

        return isset($this->packages[$name]) ? $this->packages[$name] : null;
    }

    /**
     * Gets all packages.
     *
     * @return array<string, Package>
     */
    public function all(?string $type = null, bool $force = false): array
    {
        if ($force || empty($this->packages)) {
            $this->loadPackages();
        }

        $filter = fn (Package $package) => $package->get('type') == $type;

        return $type !== null ? array_filter($this->packages, $filter) : $this->packages;
    }

    /**
     * Loads a package from data.
     *
     * @param string|array<string, mixed> $data
     */
    public function load(string|array $data): ?Package
    {
        if (is_string($data) && strpos($data, '{') !== 0) {
            $path = strtr(dirname($data), '\\', '/');
            $data = @file_get_contents($data);
        }

        if (is_string($data)) {
            $data = @json_decode($data, true);
        }

        if (is_array($data) && isset($data['name'])) {

            if (!isset($data['module'])) {
                $data['module'] = basename($data['name']);
            }

            if (isset($path)) {
                $data['path'] = $path;
                $data['url'] = $this->url?->getStatic($this->served($path)) ?? '';
            }

            return new Package($data);
        }

        return null;
    }

    /**
     * Turns a package directory into the path its published files are located
     * under, so that the package URL addresses the webroot copy and not the
     * sources - which have none.
     */
    private function served(string $path): string
    {
        if ($this->root === '' || strpos(Path::directory($path), $this->root) !== 0) {
            return $path;
        }

        return substr($path, strlen($this->root));
    }

    /**
     * Adds a package path(s).
     *
     * @param  string|array<int, string> $paths
     */
    public function addPath(string|array $paths): self
    {
        $this->paths = array_merge($this->paths, (array) $paths);

        return $this;
    }

    /**
     * Checks if a package exists.
     */
    public function offsetExists(mixed $name): bool
    {
        return isset($this->packages[$name]);
    }

    /**
     * Gets a package by name.
     */
    public function offsetGet(mixed $name): ?Package
    {
        return $this->get($name);
    }

    /**
     * Sets a package.
     */
    public function offsetSet(mixed $name, mixed $package): void
    {
        $this->packages[$name] = $package;
    }

    /**
     * Unset a package.
     */
    public function offsetUnset(mixed $name): void
    {
        unset($this->packages[$name]);
    }

    /**
     * Implements the IteratorAggregate.
     *
     * @return \ArrayIterator<string, Package>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->packages);
    }

    /**
     * Load packages from paths.
     */
    protected function loadPackages(): void
    {
        foreach ($this->paths as $path) {

            $paths = glob($path, GLOB_NOSORT) ?: [];

            foreach ($paths as $p) {

                if (!$package = $this->load($p)) {
                    continue;
                }

                $this->packages[$package->getName()] = $package;
            }
        }
    }
}
