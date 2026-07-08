<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Event\EventDispatcherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;

class QueryBuilderCacheTest extends TestCase
{
    private EntityManager $manager;
    private MetadataManager $metadataManager;
    private CacheItemPoolInterface $cache;
    private Metadata $metadata;
    private EventDispatcherInterface $events;

    protected function setUp(): void
    {
        // Create mocks
        $connection = $this->createMock(Connection::class);
        $this->metadataManager = $this->createMock(MetadataManager::class);
        $this->events = $this->createMock(EventDispatcherInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->metadata = $this->createMock(Metadata::class);

        // Configure metadata manager to return cache
        $this->metadataManager->method('getCache')->willReturn($this->cache);

        // Configure metadata
        $this->metadata->method('getTable')->willReturn('test_table');

        // Create query builder mock that returns itself
        $queryBuilder = $this->createMock(\Pagekit\Database\Query\QueryBuilder::class);
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('getSQL')->willReturn('SELECT * FROM test_table');
        $queryBuilder->method('params')->willReturn([]);

        $connection->method('createQueryBuilder')->willReturn($queryBuilder);

        // Create entity manager
        $this->manager = new EntityManager($connection, $this->metadataManager, $this->events);
    }

    public function testCacheMethodSetsTtl(): void
    {
        $qb = new QueryBuilder($this->manager, $this->metadata);

        $result = $qb->cache(300);

        $this->assertSame($qb, $result, 'cache() should return self for method chaining');
    }

    public function testCacheMethodAcceptsCustomCachePool(): void
    {
        $customCache = $this->createMock(CacheItemPoolInterface::class);
        $qb = new QueryBuilder($this->manager, $this->metadata);

        $result = $qb->cache(300, $customCache);

        $this->assertSame($qb, $result);
    }

    public function testGetCacheKeyGeneratesConsistentKey(): void
    {
        $qb = new QueryBuilder($this->manager, $this->metadata);

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($qb);
        $method = $reflection->getMethod('getCacheKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($qb);
        $key2 = $method->invoke($qb);

        $this->assertSame($key1, $key2, 'Cache key should be consistent');
        $this->assertStringStartsWith('orm_query_', $key1, 'Cache key should have orm_query_ prefix');
    }

    public function testGetCacheKeyIncludesSuffix(): void
    {
        $qb = new QueryBuilder($this->manager, $this->metadata);

        $reflection = new \ReflectionClass($qb);
        $method = $reflection->getMethod('getCacheKey');
        $method->setAccessible(true);

        $keyWithoutSuffix = $method->invoke($qb, '');
        $keyWithSuffix = $method->invoke($qb, 'first');

        $this->assertNotSame($keyWithoutSuffix, $keyWithSuffix, 'Suffix should change the cache key');
    }

    /**
     * Adding/removing an eager-load changes the key: two queries with the same
     * SQL but different relation names must produce different cache keys.
     */
    public function testGetCacheKeyDiffersForDifferentRelationNames(): void
    {
        $qb1 = new QueryBuilder($this->manager, $this->metadata);
        $qb1->related('user');

        $qb2 = new QueryBuilder($this->manager, $this->metadata);
        $qb2->related('comments');

        $this->assertNotSame(
            $this->invokeGetCacheKey($qb1),
            $this->invokeGetCacheKey($qb2),
            'Different eager-load relation names must produce different cache keys'
        );
    }

    /**
     * Relation-name order must not affect the key, so equivalent queries share
     * a cache entry regardless of the order relations were declared.
     */
    public function testGetCacheKeyIsRelationOrderIndependent(): void
    {
        $qb1 = new QueryBuilder($this->manager, $this->metadata);
        $qb1->related('user', 'comments');

        $qb2 = new QueryBuilder($this->manager, $this->metadata);
        $qb2->related('comments', 'user');

        $this->assertSame(
            $this->invokeGetCacheKey($qb1),
            $this->invokeGetCacheKey($qb2),
            'Relation declaration order must not change the cache key'
        );
    }

    /**
     * When two queries share SQL, params and relation names but load different
     * related data via a dynamic constraint, the caller disambiguates them with
     * an explicit cache-key discriminator — the documented, Doctrine-style way
     * to keep such queries distinct without introspecting the closures.
     */
    public function testExplicitCacheKeyDiscriminatorDistinguishesQueries(): void
    {
        $qb1 = new QueryBuilder($this->manager, $this->metadata);
        $qb1->related('user')->cache(300)->cacheKey('variant-a');

        $qb2 = new QueryBuilder($this->manager, $this->metadata);
        $qb2->related('user')->cache(300)->cacheKey('variant-b');

        $this->assertNotSame(
            $this->invokeGetCacheKey($qb1),
            $this->invokeGetCacheKey($qb2),
            'Different explicit cache keys must produce different cache keys'
        );
    }

    /**
     * Conversely, the same explicit discriminator yields the same key, so the
     * cache stays usable across requests.
     */
    public function testSameExplicitCacheKeyProducesSameKey(): void
    {
        $qb1 = new QueryBuilder($this->manager, $this->metadata);
        $qb1->related('user')->cache(300)->cacheKey('variant-a');

        $qb2 = new QueryBuilder($this->manager, $this->metadata);
        $qb2->related('user')->cache(300)->cacheKey('variant-a');

        $this->assertSame(
            $this->invokeGetCacheKey($qb1),
            $this->invokeGetCacheKey($qb2),
            'Identical explicit cache keys must produce identical cache keys'
        );
    }

    /**
     * A bound query parameter that cannot be serialized (a closure, resource,
     * or object wrapping one) must never make cache-key generation throw and
     * break an otherwise valid cache() query.
     */
    public function testGetCacheKeyToleratesNonSerializableParams(): void
    {
        $qb = $this->makeBuilder('SELECT * FROM test_table', ['callback' => static fn () => null]);

        $this->assertStringStartsWith('orm_query_', $this->invokeGetCacheKey($qb));
    }

    /**
     * Builds a QueryBuilder whose inner SQL query is fixed to $sql and whose
     * bound parameters are $params, so cache-key generation can be exercised
     * with specific SQL/parameter shapes.
     *
     * @param  array<string, mixed> $params
     * @return QueryBuilder<object>
     */
    private function makeBuilder(string $sql, array $params = []): QueryBuilder
    {
        $innerQuery = $this->createMock(\Pagekit\Database\Query\QueryBuilder::class);
        $innerQuery->method('from')->willReturnSelf();
        $innerQuery->method('getSQL')->willReturn($sql);
        $innerQuery->method('params')->willReturn($params);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($innerQuery);

        $manager = new EntityManager($connection, $this->metadataManager, $this->events);

        return new QueryBuilder($manager, $this->metadata);
    }

    /**
     * @param  QueryBuilder<object> $qb
     */
    private function invokeGetCacheKey(QueryBuilder $qb, string $suffix = ''): string
    {
        $method = new \ReflectionMethod(QueryBuilder::class, 'getCacheKey');
        $method->setAccessible(true);

        $result = $method->invoke($qb, $suffix);
        $this->assertIsString($result);

        return $result;
    }
}
