<?php

namespace Pagekit\Cache\Adapter;

use Pagekit\Cache\CacheInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * PSR-6 Cache Adapter
 * Implements Pagekit's CacheInterface for backward compatibility
 * Internally uses PSR-6 CacheItemPoolInterface
 */
class Psr6Adapter implements CacheInterface
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
     * Constructor
     *
     * @param CacheItemPoolInterface $pool
     */
    public function __construct(CacheItemPoolInterface $pool)
    {
        $this->pool = $pool;
    }

    /**
     * {@inheritdoc}
     */
    public function setNamespace($namespace)
    {
        $this->namespace = $namespace;
    }

    /**
     * {@inheritdoc}
     */
    public function getNamespace()
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
        // PSR-6 doesn't allow certain characters in keys
        // Replace reserved characters: {}()/\@:
        $safeId = str_replace([':', '\\', '/', '@', '{', '}', '(', ')'], '_', $id);

        if ($this->namespace) {
            $safeNamespace = str_replace([':', '\\', '/', '@', '{', '}', '(', ')'], '_', $this->namespace);

            return $safeNamespace . '.' . $safeId;
        }

        return $safeId;
    }

    /**
     * {@inheritdoc}
     */
    public function fetch($id)
    {
        $item = $this->pool->getItem($this->getNamespacedId($id));

        return $item->isHit() ? $item->get() : false;
    }

    /**
     * {@inheritdoc}
     */
    public function contains($id)
    {
        return $this->pool->hasItem($this->getNamespacedId($id));
    }

    /**
     * {@inheritdoc}
     */
    public function save($id, $data, $lifeTime = 0)
    {
        $item = $this->pool->getItem($this->getNamespacedId($id));
        $item->set($data);

        if ($lifeTime > 0) {
            $item->expiresAfter($lifeTime);
        }

        return $this->pool->save($item);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id)
    {
        return $this->pool->deleteItem($this->getNamespacedId($id));
    }

    /**
     * {@inheritdoc}
     */
    public function flushAll()
    {
        return $this->pool->clear();
    }

    // ===== PSR-6 Methods for internal use =====

    /**
     * Returns a Cache Item representing the specified key.
     */
    public function getItem(string $key): CacheItemInterface
    {
        return $this->pool->getItem($this->getNamespacedId($key));
    }

    /**
     * Returns a traversable set of cache items.
     */
    public function getItems(array $keys = []): iterable
    {
        $namespacedKeys = array_map([$this, 'getNamespacedId'], $keys);

        return $this->pool->getItems($namespacedKeys);
    }

    /**
     * Confirms if the cache contains specified cache item.
     */
    public function hasItem(string $key): bool
    {
        return $this->pool->hasItem($this->getNamespacedId($key));
    }

    /**
     * Deletes all items in the pool.
     */
    public function clear(): bool
    {
        return $this->pool->clear();
    }

    /**
     * Removes the item from the pool.
     */
    public function deleteItem(string $key): bool
    {
        return $this->pool->deleteItem($this->getNamespacedId($key));
    }

    /**
     * Removes multiple items from the pool.
     */
    public function deleteItems(array $keys): bool
    {
        $namespacedKeys = array_map([$this, 'getNamespacedId'], $keys);

        return $this->pool->deleteItems($namespacedKeys);
    }

    /**
     * Persists a cache item immediately.
     */
    public function saveCacheItem(CacheItemInterface $item): bool
    {
        return $this->pool->save($item);
    }

    /**
     * Sets a cache item to be persisted later.
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool->saveDeferred($item);
    }

    /**
     * Persists any deferred cache items.
     */
    public function commit(): bool
    {
        return $this->pool->commit();
    }
}
