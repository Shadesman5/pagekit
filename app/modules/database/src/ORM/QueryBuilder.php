<?php

declare(strict_types=1);

namespace Pagekit\Database\ORM;

use Psr\Cache\CacheItemPoolInterface;

/**
 * ORM-aware query builder that proxies the fluent verb methods of the
 * underlying {@see \Pagekit\Database\Query\QueryBuilder} via {@see __call()}.
 * The `@method` tags below mirror that proxy contract so static analysis can
 * resolve the chained calls without expanding the actual class API.
 * Methods that read variadic arguments via `func_get_args()` (`select`,
 * `groupBy`) are typed with a trailing `mixed ...$columns` to match.
 *
 * `@template T of object` carries the mapped entity type through {@see get()}
 * and {@see first()}. It resolves to its `object` bound for the shared,
 * un-parameterized builders returned by {@see \Pagekit\Database\ORM\ModelTrait::query()}
 * / `where()`, leaving those call sites (and their existing runtime type
 * guards) unchanged; callers that need a concrete element type bind it
 * explicitly via `QueryBuilder<MyEntity>`.
 *
 * @template T of object
 *
 * @method QueryBuilder<T> where(mixed $condition, array<int|string, mixed> $params = [])
 * @method QueryBuilder<T> orWhere(mixed $condition, array<int|string, mixed> $params = [])
 * @method QueryBuilder<T> whereIn(string $column, mixed $values, bool $not = false, ?string $type = null)
 * @method QueryBuilder<T> orWhereIn(string $column, mixed $values, bool $not = false)
 * @method QueryBuilder<T> whereExists(\Closure $callback, bool $not = false, ?string $type = null)
 * @method QueryBuilder<T> orWhereExists(\Closure $callback, bool $not = false)
 * @method QueryBuilder<T> whereInSet(string $column, mixed $values, bool $not = false, ?string $type = null)
 * @method QueryBuilder<T> select(mixed $columns = ['*'], mixed ...$rest)
 * @method QueryBuilder<T> from(string $table)
 * @method QueryBuilder<T> join(string $table, ?string $condition = null, string $type = 'inner')
 * @method QueryBuilder<T> innerJoin(string $table, ?string $condition = null)
 * @method QueryBuilder<T> leftJoin(string $table, ?string $condition = null)
 * @method QueryBuilder<T> rightJoin(string $table, ?string $condition = null)
 * @method QueryBuilder<T> groupBy(mixed $groupBy, mixed ...$rest)
 * @method QueryBuilder<T> having(mixed $having, string $type = 'AND')
 * @method QueryBuilder<T> orHaving(mixed $having)
 * @method QueryBuilder<T> orderBy(string $sort, ?string $order = null)
 * @method QueryBuilder<T> offset(int $offset)
 * @method QueryBuilder<T> limit(int $limit)
 * @method int count(string $column = '*')
 * @method int update(array<string, mixed> $values)
 * @method int delete()
 * @method string getSQL()
 * @method \Doctrine\DBAL\Result executeQuery()
 * @method int executeStatement()
 */
class QueryBuilder
{
    protected \Pagekit\Database\ORM\EntityManager $manager;

    protected \Pagekit\Database\ORM\Metadata $metadata;

    protected \Pagekit\Database\Query\QueryBuilder $query;

    /** @var array<string, callable> */
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
        $this->manager = $manager;
        $this->metadata = $metadata;
        $this->query = $manager->getConnection()->createQueryBuilder()->from($metadata->getTable());
    }

    /**
     * Execute the query and get all results.
     *
     * @return array<int|string, T>
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

        /** @var array<int|string, T> $entities */
        $entities = $this->manager->hydrateAll($this->query->executeQuery(), $this->metadata);

        if ($entities) {
            foreach ($this->getRelations() as $name => $query) {
                $this->manager->related($entities, $name, $query);
            }
        }

        // Save to cache if enabled
        if ($this->cache && $this->cacheTtl !== null && isset($cacheKey)) {
            $item = $this->cache->getItem($cacheKey);
            $item->set($entities);
            $item->expiresAfter($this->cacheTtl);
            $this->cache->save($item);
        }

        return $entities;
    }

    /**
     * Execute the query and get the first result.
     *
     * @return T|null
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

        $hydrated = $this->manager->hydrateOne($this->query->limit(1)->executeQuery(), $this->metadata);

        if ($hydrated === false) {
            return null;
        }

        /** @var T $entity */
        $entity = $hydrated;

        foreach ($this->getRelations() as $name => $query) {
            $this->manager->related($entity, $name, $query);
        }

        // Save to cache if enabled
        if ($this->cache && $this->cacheTtl !== null && isset($cacheKey)) {
            $item = $this->cache->getItem($cacheKey);
            $item->set($entity);
            $item->expiresAfter($this->cacheTtl);
            $this->cache->save($item);
        }

        return $entity;
    }

    /**
     * Set the relations that will be eager loaded.
     *
     * @param  mixed $related
     * @return QueryBuilder<T>
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
                $constraints = function () {
                };
            }

            // is nested ?
            if (strpos($name, '.') !== false) {

                $progress = [];

                foreach (explode('.', $name) as $part) {

                    $progress[] = $part;

                    if (!isset($relations[$last = implode('.', $progress)])) {
                        $relations[$last] = function () {
                        };
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
     *
     * @return array<string, QueryBuilder<object>>
     */
    public function getRelations(): array
    {
        $relations = [];

        foreach ($this->relations as $name => $constraints) {
            if (strpos($name, '.') === false) {

                $mapping = $this->metadata->getRelationMapping($name);
                $targetEntity = $mapping['targetEntity'];
                if (!is_string($targetEntity) || !is_callable([$targetEntity, 'query'])) {
                    throw new \LogicException(sprintf("Relation '%s' targetEntity '%s' does not expose a static query() method.", $name, (string) $targetEntity));
                }
                $query = $targetEntity::query();

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
     * @return array<string, callable>
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
     * @param  int                          $ttl   Time to live in seconds
     * @param  CacheItemPoolInterface|null  $cache Custom cache pool (optional)
     * @return QueryBuilder<T>
     */
    public function cache(int $ttl, ?CacheItemPoolInterface $cache = null): self
    {
        $this->cacheTtl = $ttl;
        $this->cache = $cache ?? $this->manager->getMetadataManager()->getCache();

        return $this;
    }

    /**
     * Generates a cache key based on the query SQL, parameters, and relations.
     *
     * Includes bound parameters in the hash to prevent cache collisions when
     * the same SQL template is used with different WHERE values.
     *
     * @param  string $suffix Optional suffix for the cache key
     * @return string
     */
    protected function getCacheKey(string $suffix = ''): string
    {
        $sql = $this->query->getSQL();

        return 'orm_query_' . md5($sql . serialize($this->relations) . serialize($this->query->params()) . $suffix);
    }

    /**
     * Proxy method call to query builder.
     *
     * @param  array<int, mixed> $args
     * @return mixed Genuinely unknown type — proxied to the underlying query builder; return type depends on the method called.
     * @throws \BadMethodCallException
     */
    public function __call(string $method, array $args): mixed
    {
        if (!method_exists($this->query, $method)) {
            throw new \BadMethodCallException(sprintf('Undefined method call "%s::%s"', get_class($this), $method));
        }

        $result = $this->query->{$method}(...$args);

        return $result === $this->query ? $this : $result;
    }
}
