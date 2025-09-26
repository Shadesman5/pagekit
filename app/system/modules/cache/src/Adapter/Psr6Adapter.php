<?php

namespace Pagekit\Cache\Adapter;

use Doctrine\Common\Cache\CacheProvider;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\CacheItem;

/**
 * PSR-6 to Doctrine Cache Adapter
 * Provides backward compatibility for existing code using doctrine/cache
 * Also implements PSR-6 interface for direct PSR-6 usage
 */
class Psr6Adapter extends CacheProvider implements CacheItemPoolInterface
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
    protected function doFetch($id, ?bool &$isHit = null)
    {
        $item = $this->pool->getItem($this->getNamespacedId($id));
        $isHit = $item->isHit();
        return $item->isHit() ? $item->get() : false;
    }

    /**
     * {@inheritdoc}
     */
    protected function doFetchMultiple(array $keys): array
    {
        $items = $this->pool->getItems(array_map([$this, 'getNamespacedId'], $keys));
        $values = [];
        
        foreach ($items as $key => $item) {
            if ($item->isHit()) {
                // Remove namespace from key for return value
                $originalKey = $this->removeNamespace($key);
                $values[$originalKey] = $item->get();
            }
        }
        
        return $values;
    }

    /**
     * {@inheritdoc}
     */
    protected function doContains($id): bool
    {
        return $this->pool->hasItem($this->getNamespacedId($id));
    }

    /**
     * {@inheritdoc}
     */
    protected function doSave($id, $data, $lifeTime = 0): bool
    {
        $item = $this->pool->getItem($this->getNamespacedId($id));
        $item->set($data);
        
        if ($lifeTime > 0) {
            $item->expiresAfter($lifeTime);
        } elseif ($lifeTime === 0) {
            // 0 means infinite in doctrine/cache, null means default in PSR-6
            $item->expiresAfter(null);
        }
        
        return $this->pool->save($item);
    }

    /**
     * {@inheritdoc}
     */
    protected function doSaveMultiple(array $keysAndValues, $lifetime = 0): bool
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
    protected function doDelete($id): bool
    {
        return $this->pool->deleteItem($this->getNamespacedId($id));
    }

    /**
     * {@inheritdoc}
     */
    protected function doDeleteMultiple(array $keys): bool
    {
        return $this->pool->deleteItems(array_map([$this, 'getNamespacedId'], $keys));
    }

    /**
     * {@inheritdoc}
     */
    protected function doFlush(): bool
    {
        return $this->pool->clear();
    }

    /**
     * {@inheritdoc}
     */
    protected function doGetStats(): ?array
    {
        // PSR-6 doesn't define a standard way to get stats
        // Return null or implement custom stats if needed
        return null;
    }

    /**
     * Get namespaced cache key
     *
     * @param string $id
     * @return string
     */
    protected function getNamespacedId(string $id): string
    {
        if ($this->namespace) {
            // Sanitize the key to be PSR-6 compliant (no reserved characters)
            return preg_replace('/[{}()\/@:\\\\]/', '_', $this->namespace . '_' . $id);
        }
        return preg_replace('/[{}()\/@:\\\\]/', '_', $id);
    }

    /**
     * Remove namespace from key
     *
     * @param string $id
     * @return string
     */
    protected function removeNamespace(string $id): string
    {
        if ($this->namespace) {
            $prefix = $this->namespace . '_';
            if (strpos($id, $prefix) === 0) {
                return substr($id, strlen($prefix));
            }
        }
        return $id;
    }

    /**
     * Set namespace for cache keys
     *
     * @param string $namespace
     */
    public function setNamespace($namespace): void
    {
        $this->namespace = $namespace;
    }

    /**
     * Get namespace
     *
     * @return string
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    // PSR-6 CacheItemPoolInterface methods

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
        return $this->pool->getItems(array_map([$this, 'getNamespacedId'], $keys));
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
        return $this->pool->deleteItems(array_map([$this, 'getNamespacedId'], $keys));
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
}