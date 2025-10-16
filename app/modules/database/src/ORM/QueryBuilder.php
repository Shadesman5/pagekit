<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

use Psr\Cache\CacheItemPoolInterface;

class QueryBuilder
{
    protected \Pagekit\Database\ORM\EntityManager $manager;

    protected \Pagekit\Database\ORM\Metadata $metadata;

    protected \Pagekit\Database\Query\QueryBuilder $query;

    protected array $relations = [];
    
    protected ?CacheItemPoolInterface $cache = null;
    
    protected ?int $cacheTtl = null;

    /**
     * Constructor.
     *
     * @param EntityManager $manager
     * @param Metadata      $metadata
     */
    public function __construct(EntityManager $manager, Metadata $metadata)
    {
        $this->manager  = $manager;
        $this->metadata = $metadata;
        $this->query    = $manager->getConnection()->createQueryBuilder()->from($metadata->getTable());
    }

    /**
     * Execute the query and get all results.
     */
    public function get(): array
    {
        // Check cache if enabled
        if ($this->cache && $this->cacheTtl !== null) {
            $cacheKey = $this->getCacheKey();
            $item = $this->cache->getItem($cacheKey);
            
            if ($item->isHit()) {
                return $item->get();
            }
        }
        
        if ($entities = $this->manager->hydrateAll($this->query->execute(), $this->metadata)) {
            foreach ($this->getRelations() as $name => $query) {
                $this->manager->related($entities, $name, $query);
            }
        }
        
        // Save to cache if enabled
        if ($this->cache && $this->cacheTtl !== null && isset($cacheKey) && isset($item)) {
            $item->set($entities);
            $item->expiresAfter($this->cacheTtl);
            $this->cache->save($item);
        }

        return $entities;
    }

    /**
     * Execute the query and get the first result.
     *
     * @return object|null
     */
    public function first(): ?object
    {
        // Check cache if enabled
        if ($this->cache && $this->cacheTtl !== null) {
            $cacheKey = $this->getCacheKey('first');
            $item = $this->cache->getItem($cacheKey);
            
            if ($item->isHit()) {
                return $item->get();
            }
        }
        
        if ($entity = $this->manager->hydrateOne($this->query->limit(1)->execute(), $this->metadata)) {

            foreach ($this->getRelations() as $name => $query) {
                $this->manager->related($entity, $name, $query);
            }
            
            // Save to cache if enabled
            if ($this->cache && $this->cacheTtl !== null && isset($cacheKey) && isset($item)) {
                $item->set($entity);
                $item->expiresAfter($this->cacheTtl);
                $this->cache->save($item);
            }

            return $entity;
        }

        return null;
    }

    /**
     * Set the relations that will be eager loaded.
     *
     * @param  mixed $related
     */
    public function related(mixed $related): self
    {
        if (is_string($related)) {
            $related = func_get_args();
        }

        $relations = [];

        foreach ($related as $name => $constraints) {

            // no constrains
            if (is_numeric($name)) {
                $name = $constraints;
                $constraints = function () {};
            }

            // is nested ?
            if (strpos($name, '.') !== false) {

                $progress = [];

                foreach (explode('.', $name) as $part) {

                    $progress[] = $part;

                    if (!isset($relations[$last = implode('.', $progress)])) {
                        $relations[$last] = function () {};
                    }
                }
            }

            $relations[$name] = $constraints;
        }

        $this->relations = array_merge($this->relations, $relations);

        return $this;
    }

    /**
     * Gets all relations of the query.
     */
    public function getRelations(): array
    {
        $relations = [];

        foreach ($this->relations as $name => $constraints) {
            if (strpos($name, '.') === false) {

                $mapping = $this->metadata->getRelationMapping($name);
                $query   = call_user_func("{$mapping['targetEntity']}::query");

                if ($nested = $this->getNestedRelations($name)) {
                    $query->related($nested);
                }

                call_user_func($constraints, $query);

                $relations[$name] = $query;
            }
        }

        return $relations;
    }

    /**
     * Gets all nested relations of the query.
     *
     * @param  string $relation
     */
    public function getNestedRelations(string $relation): array
    {
        $nested = [];
        $prefix = $relation.'.';

        foreach ($this->relations as $name => $constraints) {
            if ($prefix === substr($name, 0, strlen($prefix))) {
                $nested[substr($name, strlen($prefix))] = $constraints;
            }
        }

        return $nested;
    }
    
    /**
     * Enable query result caching with TTL in seconds.
     *
     * @param  int               $ttl   Time to live in seconds
     * @param  CacheItemPoolInterface|null $cache Custom cache pool (optional)
     * @return self
     */
    public function cache(int $ttl, ?CacheItemPoolInterface $cache = null): self
    {
        $this->cacheTtl = $ttl;
        $this->cache = $cache ?? $this->manager->getMetadataManager()->getCache();
        
        return $this;
    }
    
    /**
     * Generates a cache key based on the query SQL and parameters.
     *
     * @param  string $suffix Optional suffix for the cache key
     * @return string
     */
    protected function getCacheKey(string $suffix = ''): string
    {
        $sql = $this->query->getSQL();
        
        // Serialize the query parts and relations for cache key
        $key = 'orm_query_' . md5($sql . serialize($this->relations) . $suffix);
        
        return $key;
    }

    /**
     * Proxy method call to query builder.
     *
     * @param  string $method
     * @param  array  $args
     * @throws \BadMethodCallException
     * @return mixed
     */
    public function __call(string $method, array $args): mixed
    {
        if (!method_exists($this->query, $method)) {
            throw new \BadMethodCallException(sprintf('Undefined method call "%s::%s"', get_class($this), $method));
        }

        $result = call_user_func_array([$this->query, $method], $args);

        return $result === $this->query ? $this : $result;
    }
}
