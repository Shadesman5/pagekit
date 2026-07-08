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

    protected function setUp(): void
    {
        // Create mocks
        $connection = $this->createMock(Connection::class);
        $this->metadataManager = $this->createMock(MetadataManager::class);
        $events = $this->createMock(EventDispatcherInterface::class);
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
        $this->manager = new EntityManager($connection, $this->metadataManager, $events);
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
     * Two queries eager-loading the SAME relation name but with constraint
     * closures capturing DIFFERENT filter values must produce DIFFERENT cache
     * keys — otherwise the second query would serve the first query's cached
     * related data. Both closures are defined on the same line (via the shared
     * factory) so only the bound `use` variable differs; this proves the key
     * reflects constraint values, not just relation names or line numbers.
     */
    public function testGetCacheKeyReflectsRelationConstraintValues(): void
    {
        $makeConstraint = static fn (int $status): \Closure => static function ($query) use ($status) {
            $query->where('status = ' . $status);
        };

        $qb1 = new QueryBuilder($this->manager, $this->metadata);
        $qb1->related(['comments' => $makeConstraint(1)]);

        $qb2 = new QueryBuilder($this->manager, $this->metadata);
        $qb2->related(['comments' => $makeConstraint(0)]);

        $method = new \ReflectionMethod(QueryBuilder::class, 'getCacheKey');
        $method->setAccessible(true);

        $this->assertNotSame(
            $method->invoke($qb1),
            $method->invoke($qb2),
            'Different eager-load constraint values must produce different cache keys'
        );
    }

    /**
     * Conversely, identical relation constraints must produce identical cache
     * keys, so the cache stays usable across requests (the fingerprint is based
     * on the closure definition site + bound variables, not on unstable object
     * identity).
     */
    public function testGetCacheKeyIsStableForIdenticalRelationConstraints(): void
    {
        $makeConstraint = static fn (int $status): \Closure => static function ($query) use ($status) {
            $query->where('status = ' . $status);
        };

        $qb1 = new QueryBuilder($this->manager, $this->metadata);
        $qb1->related(['comments' => $makeConstraint(1)]);

        $qb2 = new QueryBuilder($this->manager, $this->metadata);
        $qb2->related(['comments' => $makeConstraint(1)]);

        $method = new \ReflectionMethod(QueryBuilder::class, 'getCacheKey');
        $method->setAccessible(true);

        $this->assertSame(
            $method->invoke($qb1),
            $method->invoke($qb2),
            'Identical eager-load constraint values must produce identical cache keys'
        );
    }
}
