<?php

namespace Pagekit\Cache\Adapter;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\CacheItem;

/**
 * PSR-6 Cache Adapter
 * Provides a PSR-6 compliant cache implementation
 */
class Psr6Adapter implements CacheItemPoolInterface
{
    /**
     * @var CacheItemPoolInterface
     */
    protected CacheItemPoolInterface $pool;

    /**
     * @var string
     */
    protected string $namespace = '';

    /**
     * @var array
     */
    protected array $deferred = [];

    /**
     * Constructor
     *
     * @param CacheItemPoolInterface $pool
     */
    public function __construct(CacheItemPoolInterface $pool)
    {
        $this->pool = $pool;
    }

    /**
     * Set the namespace to prefix all cache ids with.
     *
     * @param string $namespace
     */
    public function setNamespace(string $namespace): void
    {
        $this->namespace = $namespace;
    }

    /**
     * Get the namespace
     *
     * @return string
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Get namespaced ID
     *
     * @param string $id
     * @return string
     */
    protected function getNamespacedId(string $id): string
    {
        return $this->namespace ? $this->namespace . ':' . $id : $id;
    }

    // ===== PSR-6 CacheItemPoolInterface Methods =====

    /**
     * {@inheritdoc}
     */
    public function getItem(string $key): CacheItemInterface
    {
        return $this->pool->getItem($this->getNamespacedId($key));
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(array $keys = []): iterable
    {
        $namespacedKeys = array_map([$this, 'getNamespacedId'], $keys);
        return $this->pool->getItems($namespacedKeys);
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem(string $key): bool
    {
        return $this->pool->hasItem($this->getNamespacedId($key));
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        return $this->pool->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem(string $key): bool
    {
        return $this->pool->deleteItem($this->getNamespacedId($key));
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys): bool
    {
        $namespacedKeys = array_map([$this, 'getNamespacedId'], $keys);
        return $this->pool->deleteItems($namespacedKeys);
    }

    /**
     * {@inheritdoc}
     */
    public function save(CacheItemInterface $item): bool
    {
        return $this->pool->save($item);
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool->saveDeferred($item);
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        return $this->pool->commit();
    }

    // ===== Legacy Compatibility Methods (for extensions) =====

    /**
     * Fetches an entry from the cache.
     *
     * @param string $id The id of the cache entry to fetch.
     * @return mixed The cached data or FALSE, if no cache entry exists for the given id.
     */
    public function fetch(string $id)
    {
        $item = $this->getItem($id);
        return $item->isHit() ? $item->get() : false;
    }

    /**
     * Tests if an entry exists in the cache.
     *
     * @param string $id The cache id of the entry to check for.
     * @return bool TRUE if a cache entry exists for the given cache id, FALSE otherwise.
     */
    public function contains(string $id): bool
    {
        return $this->hasItem($id);
    }

    /**
     * Puts data into the cache.
     *
     * @param string $id The cache id.
     * @param mixed $data The cache entry/data.
     * @param int $lifeTime The cache lifetime.
     * @return bool TRUE if the entry was successfully stored in the cache, FALSE otherwise.
     */
    public function save(string $id, $data, int $lifeTime = 0): bool
    {
        $item = $this->getItem($id);
        $item->set($data);
        
        if ($lifeTime > 0) {
            $item->expiresAfter($lifeTime);
        }
        
        return $this->save($item);
    }

    /**
     * Deletes a cache entry.
     *
     * @param string $id The cache id.
     * @return bool TRUE if the cache entry was successfully deleted, FALSE otherwise.
     */
    public function delete(string $id): bool
    {
        return $this->deleteItem($id);
    }

    /**
     * Flushes all cache entries.
     *
     * @return bool TRUE if the cache entries were successfully flushed, FALSE otherwise.
     */
    public function flushAll(): bool
    {
        return $this->clear();
    }

    /**
     * Fetches multiple cache entries.
     *
     * @param array $keys Array of keys to fetch.
     * @return array Array of values keyed by cache keys.
     */
    public function fetchMultiple(array $keys): array
    {
        $result = [];
        foreach ($this->getItems($keys) as $key => $item) {
            if ($item->isHit()) {
                $result[$key] = $item->get();
            }
        }
        return $result;
    }

    /**
     * Saves multiple cache entries.
     *
     * @param array $keysAndValues Array of keys and values to save.
     * @param int $lifetime The cache lifetime.
     * @return bool TRUE if the entries were successfully stored, FALSE otherwise.
     */
    public function saveMultiple(array $keysAndValues, int $lifetime = 0): bool
    {
        $success = true;
        foreach ($keysAndValues as $key => $value) {
            if (!$this->save($key, $value, $lifetime)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Deletes multiple cache entries.
     *
     * @param array $keys Array of keys to delete.
     * @return bool TRUE if the entries were successfully deleted, FALSE otherwise.
     */
    public function deleteMultiple(array $keys): bool
    {
        return $this->deleteItems($keys);
    }

    /**
     * Retrieves cached information from the data store.
     *
     * @return array|null An associative array with server's statistics if available, NULL otherwise.
     */
    public function getStats(): ?array
    {
        // PSR-6 doesn't define stats, return null
        return null;
    }
}