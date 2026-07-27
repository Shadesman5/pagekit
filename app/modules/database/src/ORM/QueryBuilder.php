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
 * un-parameterized builders returned by {@see \Pagekit\Database\ORM\Repository::query()}
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
     * Optional caller-supplied cache-key discriminator.
     *
     * The auto-generated key covers the base SQL, its bound parameters and the
     * eager-load relation *names*. Provide this when two `cache()` queries share
     * all of those but must load different related data via dynamic eager-load
     * constraints (e.g. a closure that reads `$this` or computes the nested
     * relation at runtime), so they do not share a cache entry.
     */
    protected ?string $cacheKey = null;

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
                if (!is_string($targetEntity) || !class_exists($targetEntity)) {
                    throw new \LogicException(sprintf("Relation '%s' targetEntity '%s' is not a mapped entity class.", $name, is_string($targetEntity) ? $targetEntity : get_debug_type($targetEntity)));
                }
                $query = $this->manager->getRepository($targetEntity)->query();

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
     * Sets an explicit cache-key discriminator (see {@see $cacheKey}).
     *
     * Modeled on Doctrine's setResultCacheId(): provide a distinct value to keep
     * queries with identical SQL, parameters and relation names — but different
     * dynamic eager-load constraints — from sharing a cache entry. Kept separate
     * from {@see cache()} so the existing `cache($ttl, $pool)` signature does not
     * change.
     *
     * @param  string|null $key
     * @return QueryBuilder<T>
     */
    public function cacheKey(?string $key): self
    {
        $this->cacheKey = $key;

        return $this;
    }

    /**
     * Generates a cache key from the query SQL, its bound parameters, the
     * eager-load relation names and the optional caller-supplied discriminator.
     *
     * Following common ORM practice (e.g. Doctrine's result cache), the key is
     * derived from the SQL + bindings, not by introspecting or executing
     * eager-load constraint closures. Relation *names* are included so that
     * adding or removing an eager-load changes the key; queries that differ only
     * in a dynamic constraint's runtime effect must pass an explicit
     * {@see cache()} `$key` to remain distinct. Relation-name and parameter
     * order do not affect the key.
     *
     * @param  string $suffix Optional suffix for the cache key
     * @return string
     */
    protected function getCacheKey(string $suffix = ''): string
    {
        $relationNames = array_keys($this->relations);
        sort($relationNames);

        return 'orm_query_' . md5(
            $this->query->getSQL()
            . serialize($this->normalizeForCacheKey($this->query->params()))
            . serialize($relationNames)
            . (string) $this->cacheKey
            . $suffix
        );
    }

    /**
     * Recursively converts a value into a representation that is always safe to
     * serialize, so a non-serializable bound query parameter (a closure,
     * resource, or object wrapping either) can never make cache-key generation
     * throw and break an otherwise valid `cache()` query.
     *
     * @param  mixed $value
     * @return mixed
     */
    private function normalizeForCacheKey(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeForCacheKey($item);
            }

            return $normalized;
        }

        if ($value instanceof \Closure) {
            $reflection = new \ReflectionFunction($value);

            return '__closure:' . ($reflection->getFileName() ?: '?') . ':' . ($reflection->getStartLine() ?: 0);
        }

        if (is_object($value)) {
            try {
                return '__object:' . md5(serialize($value));
            } catch (\Throwable) {
                return '__object:' . get_class($value) . ':' . spl_object_id($value);
            }
        }

        return '__resource';
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
