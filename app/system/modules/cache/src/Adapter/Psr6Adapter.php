<?php

namespace Pagekit\Cache\Adapter;

use Doctrine\Common\Cache\CacheProvider;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\CacheItem;

/**
 * PSR-6 Cache Adapter
 * Extends doctrine/cache CacheProvider for backward compatibility
 * Internally uses PSR-6 CacheItemPoolInterface
 * Note: This class does NOT implement CacheItemPoolInterface directly due to method signature conflicts
 */
class Psr6Adapter extends CacheProvider
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
    public function setNamespace($namespace)
    {
        $this->namespace = $namespace;
        parent::setNamespace($namespace);
    }

    /**
     * Get the namespace
     *
     * @return string
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
        if ($this->namespace) {
            return $this->namespace . ':' . $id;
        }
        return $id;
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
     * Save PSR-6 cache item
     * Internal method to avoid conflict with doctrine/cache save()
     *
     * @param CacheItemInterface $item
     * @return bool
     */
    protected function saveCacheItem(CacheItemInterface $item): bool
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

    // ===== Doctrine\Common\Cache\CacheProvider Abstract Methods =====

    /**
     * {@inheritdoc}
     */
    protected function doFetch($id)
    {
        $item = $this->getItem($id);
        return $item->isHit() ? $item->get() : false;
    }

    /**
     * {@inheritdoc}
     */
    protected function doContains($id)
    {
        return $this->hasItem($id);
    }

    /**
     * {@inheritdoc}
     */
    protected function doSave($id, $data, $lifeTime = 0)
    {
        $item = $this->getItem($id);
        $item->set($data);
        
        if ($lifeTime > 0) {
            $item->expiresAfter($lifeTime);
        }
        
        return $this->saveCacheItem($item);
    }

    /**
     * {@inheritdoc}
     */
    protected function doDelete($id)
    {
        return $this->deleteItem($id);
    }

    /**
     * {@inheritdoc}
     */
    protected function doFlush()
    {
        return $this->clear();
    }

    /**
     * {@inheritdoc}
     */
    protected function doGetStats()
    {
        // PSR-6 doesn't define stats
        return null;
    }

    /**
     * {@inheritdoc}
     */
    protected function doFetchMultiple(array $keys)
    {
        $result = [];
        $items = $this->getItems($keys);
        
        foreach ($items as $key => $item) {
            if ($item->isHit()) {
                // Remove namespace prefix from key if present
                $originalKey = $key;
                if ($this->namespace && strpos($key, $this->namespace . ':') === 0) {
                    $originalKey = substr($key, strlen($this->namespace) + 1);
                }
                $result[$originalKey] = $item->get();
            }
        }
        
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function doSaveMultiple(array $keysAndValues, $lifetime = 0)
    {
        $success = true;
        
        foreach ($keysAndValues as $key => $value) {
            if (!$this->doSave($key, $value, $lifetime)) {
                $success = false;
            }
        }
        
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    protected function doDeleteMultiple(array $keys)
    {
        return $this->deleteItems($keys);
    }
}