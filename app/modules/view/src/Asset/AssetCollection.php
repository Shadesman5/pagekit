<?php

declare(strict_types=1);

namespace Pagekit\View\Asset;

/**
 * @implements \IteratorAggregate<string, AssetInterface>
 */
class AssetCollection implements \IteratorAggregate, \Countable
{
    /**
     * @var array<string, AssetInterface>
     */
    protected array $assets;

    /**
     * Constructor.
     *
     * @param array<string, AssetInterface> $assets
     */
    public function __construct(array $assets = [])
    {
        $this->assets = $assets;
    }

    /**
     * Gets asset from collection.
     */
    public function get(string $name): ?AssetInterface
    {
        return isset($this->assets[$name]) ? $this->assets[$name] : null;
    }

    /**
     * Adds asset to collection.
     */
    public function add(AssetInterface $asset): void
    {
        $this->assets[$asset->getName()] = $asset;
    }

    /**
     * Replace asset in collection.
     */
    public function replace(string $name, AssetInterface $asset): void
    {
        $assets = [];

        foreach ($this->assets as $key => $val) {
            if ($key == $name) {
                $assets[$asset->getName()] = $asset;
            } else {
                $assets[$key] = $val;
            }
        }

        $this->assets = $assets;
    }

    /**
     * Removes assets from collection.
     *
     * @param string|array<int, string> $name
     */
    public function remove($name): void
    {
        $names = (array) $name;

        foreach ($names as $name) {
            unset($this->assets[$name]);
        }
    }

    /**
     * Gets the unique hash of the collection.
     */
    public function hash(string $salt = ''): string
    {
        $hashes = [];

        foreach ($this as $asset) {
            $hashes[] = $asset->hash($salt);
        }

        return hash('crc32b', implode('', $hashes));
    }

    /**
     * Dumps collection to a string.
     *
     * @param array<int, callable|object> $filters
     */
    public function dump(array $filters = []): string
    {
        $data = '';

        foreach ($this as $asset) {
            $data .= $asset->dump($filters)."\n\n";
        }

        return $data;
    }

    /**
     * Gets all asset names.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->assets);
    }

    /**
     * Countable interface implementation.
     */
    public function count(): int
    {
        return count($this->assets);
    }

    /**
     * IteratorAggregate interface implementation.
     *
     * @return \ArrayIterator<string, AssetInterface>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->assets);
    }
}
