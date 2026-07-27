<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Doctrine\DBAL\DriverManager;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Loader\AttributeLoader;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\Tests\ORM\Fixtures\CacheInvalidationEntity;
use Pagekit\Event\EventDispatcherInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Regression coverage for ORM query-cache invalidation on write (data-integrity).
 *
 * A read after a write must never serve a stale cached query result, so
 * {@see EntityManager::save()} and {@see EntityManager::delete()} route through
 * {@see EntityManager::invalidateCache()}. Two complementary layers guard this:
 *
 *  1. Mock {@see CacheItemPoolInterface} — pins the CURRENT contract: the write
 *     invalidates via `$cache->clear()` exactly once (and skips cleanly when no
 *     cache pool is configured).
 *  2. In-memory-SQLite round trip — pins the OBSERVABLE OUTCOME: a warmed cache
 *     entry is a hit before the write and a miss after it. This layer is
 *     implementation-agnostic and stays valid when the eviction mechanism
 *     changes, whereas layer 1 must be updated then.
 */
class EntityManagerCacheInvalidationTest extends TestCase
{
    /** @var Connection[] */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];
    }

    // ------------------------------------------------------------------
    // Layer 1: the current $cache->clear() invalidation contract (mock).
    // ------------------------------------------------------------------

    public function testSaveInvokesCacheClearExactlyOnce(): void
    {
        $cleared = 0;
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->once())
            ->method('clear')
            ->willReturnCallback(static function () use (&$cleared): bool {
                $cleared++;

                return true;
            });

        [$manager, $connection, $metadataManager] = $this->createMockedManager($cache);
        $metadataManager->method('get')->willReturn($this->newEntityMetadata(null));
        $connection->method('lastInsertId')->willReturn('1');

        $manager->save(new \stdClass());

        $this->assertSame(1, $cleared, 'save() must invalidate the query cache exactly once');
    }

    public function testDeleteInvokesCacheClearExactlyOnce(): void
    {
        $cleared = 0;
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->once())
            ->method('clear')
            ->willReturnCallback(static function () use (&$cleared): bool {
                $cleared++;

                return true;
            });

        [$manager, , $metadataManager] = $this->createMockedManager($cache);
        $metadataManager->method('get')->willReturn($this->newEntityMetadata(1));

        $manager->delete(new \stdClass());

        $this->assertSame(1, $cleared, 'delete() must invalidate the query cache exactly once');
    }

    // ------------------------------------------------------------------
    // Layer 1b: no cache pool configured -> invalidateCache() is a no-op
    //           but the write itself must still complete.
    // ------------------------------------------------------------------

    public function testSaveWithoutCachePoolStillPersists(): void
    {
        [$manager, $connection, $metadataManager] = $this->createMockedManager(null);
        $metadataManager->method('get')->willReturn($this->newEntityMetadata(null));
        $connection->method('lastInsertId')->willReturn('1');

        $inserted = false;
        $connection->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static function () use (&$inserted): int {
                $inserted = true;

                return 1;
            });

        $manager->save(new \stdClass());

        $this->assertTrue($inserted, 'save() must persist even when no cache pool is configured');
    }

    public function testDeleteWithoutCachePoolStillRemoves(): void
    {
        [$manager, $connection, $metadataManager] = $this->createMockedManager(null);
        $metadataManager->method('get')->willReturn($this->newEntityMetadata(1));

        $deleted = false;
        $connection->expects($this->once())
            ->method('delete')
            ->willReturnCallback(static function () use (&$deleted): int {
                $deleted = true;

                return 1;
            });

        $manager->delete(new \stdClass());

        $this->assertTrue($deleted, 'delete() must remove even when no cache pool is configured');
    }

    // ------------------------------------------------------------------
    // Layer 2: real cache-hit-then-miss round trip on in-memory SQLite.
    // ------------------------------------------------------------------

    public function testSaveEvictsWarmedQueryCache(): void
    {
        [$manager, $connection] = $this->bootSqliteManager();
        $connection->executeStatement("INSERT INTO cache_invalidation_test (id, title) VALUES (1, 'original')");

        $metadata = $manager->getMetadata(CacheInvalidationEntity::class);

        // Warm the query cache with the current row.
        $warm = $this->titlesOf((new QueryBuilder($manager, $metadata))->cache(300)->get());
        $this->assertSame(['original'], $warm);

        // An out-of-band write bypasses invalidation, so the identical query is a
        // cache HIT and still returns the stale title.
        $connection->executeStatement("UPDATE cache_invalidation_test SET title = 'raw_edit' WHERE id = 1");
        $stale = $this->titlesOf((new QueryBuilder($manager, $metadata))->cache(300)->get());
        $this->assertSame(['original'], $stale, 'the query result must be served from cache before a managed write');

        // A managed save() must evict the cache -> the next query is a MISS and
        // re-reads committed data (both the new row and the raw edit).
        $entity = new CacheInvalidationEntity();
        $entity->title = 'via_save';
        $manager->save($entity);

        $fresh = $this->titlesOf((new QueryBuilder($manager, $metadata))->cache(300)->get());
        sort($fresh);
        $this->assertSame(['raw_edit', 'via_save'], $fresh, 'save() must evict the cached query so the re-run reflects committed data');
    }

    public function testDeleteEvictsWarmedQueryCache(): void
    {
        [$manager, $connection] = $this->bootSqliteManager();
        $connection->executeStatement("INSERT INTO cache_invalidation_test (id, title) VALUES (1, 'a'), (2, 'b')");

        $metadata = $manager->getMetadata(CacheInvalidationEntity::class);

        $warm = $this->titlesOf((new QueryBuilder($manager, $metadata))->cache(300)->get());
        sort($warm);
        $this->assertSame(['a', 'b'], $warm);

        // Out-of-band write -> identical query is a cache HIT (stale).
        $connection->executeStatement("UPDATE cache_invalidation_test SET title = 'raw_edit' WHERE id = 1");
        $stale = $this->titlesOf((new QueryBuilder($manager, $metadata))->cache(300)->get());
        sort($stale);
        $this->assertSame(['a', 'b'], $stale, 'the query result must be served from cache before a managed write');

        // A managed delete() must evict the cache -> the next query is a MISS and
        // reflects both the removal and the raw edit.
        $entity = new CacheInvalidationEntity();
        $entity->id = 2;
        $manager->delete($entity);

        $fresh = $this->titlesOf((new QueryBuilder($manager, $metadata))->cache(300)->get());
        $this->assertSame(['raw_edit'], $fresh, 'delete() must evict the cached query so the re-run reflects committed data');
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    /**
     * Builds an EntityManager whose collaborators are mocks, so the invalidation
     * path can be asserted in isolation.
     *
     * @return array{EntityManager, Connection&MockObject, MetadataManager&MockObject}
     */
    private function createMockedManager(?CacheItemPoolInterface $cache): array
    {
        $connection = $this->createMock(Connection::class);

        $metadataManager = $this->createMock(MetadataManager::class);
        $metadataManager->method('getCache')->willReturn($cache);

        $manager = new EntityManager(
            $connection,
            $metadataManager,
            $this->createMock(EventDispatcherInterface::class)
        );

        return [$manager, $connection, $metadataManager];
    }

    /**
     * A Metadata mock mapped to a single `id` identifier column. The identifier
     * value selects the write branch: null -> INSERT (save), truthy -> the row
     * exists (delete proceeds).
     */
    private function newEntityMetadata(?int $identifierValue): Metadata&MockObject
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getIdentifier')->willReturn('id');
        $metadata->method('getValue')->willReturn($identifierValue);
        $metadata->method('getValues')->willReturn(['id' => $identifierValue, 'title' => 'x']);
        $metadata->method('getTable')->willReturn('items');

        return $metadata;
    }

    /**
     * Boots a real EntityManager backed by in-memory SQLite and an ArrayAdapter
     * query cache, then creates the fixture table.
     *
     * @return array{EntityManager, Connection}
     */
    private function bootSqliteManager(): array
    {
        $driverConnection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection = new Connection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $driverConnection->getDriver(),
            $driverConnection->getConfiguration()
        );
        $driverConnection->close();

        $this->connections[] = $connection;

        $events = $this->createMock(EventDispatcherInterface::class);

        $metadataManager = new MetadataManager($connection, $events);
        $metadataManager->setLoader(new AttributeLoader());
        $metadataManager->setCache(new ArrayAdapter());

        $manager = new EntityManager($connection, $metadataManager, $events);

        $connection->executeStatement('CREATE TABLE cache_invalidation_test (id INTEGER PRIMARY KEY, title TEXT)');

        return [$manager, $connection];
    }

    /**
     * Extracts the `title` of each hydrated row, asserting the ORM returned the
     * mapped fixture type.
     *
     * @param  array<int|string, object> $entities
     * @return list<string|null>
     */
    private function titlesOf(array $entities): array
    {
        $titles = [];

        foreach ($entities as $entity) {
            $this->assertInstanceOf(CacheInvalidationEntity::class, $entity);
            $titles[] = $entity->title;
        }

        return $titles;
    }
}
