<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Doctrine\DBAL\DriverManager;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Loader\AttributeLoader;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Site\Model\Node;
use Pagekit\Site\Model\NodeRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Unit tests for {@see NodeRepository} — the request-scoped node cache.
 *
 * The caching is exercised against a real in-memory SQLite EntityManager so the
 * find/findAll/hydration round trip is genuine, and the cache pool is a real
 * `ArrayAdapter(0, false)` — the exact wiring the composition root uses.
 * `storeSerialized: false` preserves shared-object semantics, so the
 * shared-instance assertions below (`assertSame` across cached reads, plus stale
 * reads after an out-of-band write) pin that behaviour: with the adapter's
 * default serializing mode each cached read would return a fresh clone and they
 * would fail.
 *
 * `findAll(true)` memoizes the full set and `find($id, true)` memoizes per id,
 * and neither is invalidated on write.
 */
class NodeRepositoryTest extends TestCase
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

    // -----------------------------------------------------------------------
    // find(): per-id request cache vs. always-fresh reads.
    // -----------------------------------------------------------------------

    public function testFindReturnsNullWhenTheNodeIsMissing(): void
    {
        [$repository] = $this->bootRepository();

        $this->assertNull($repository->find(999), 'a missing id must resolve to null');
    }

    public function testFindServesTheSameInstanceFromTheRequestCache(): void
    {
        [$repository, $connection] = $this->bootRepository();
        $this->insertNode($connection, ['id' => 1, 'slug' => 'about', 'menu' => 'main']);

        $first = $repository->find(1, true);
        $this->assertInstanceOf(Node::class, $first);
        $this->assertSame('about', $first->slug);

        // Out-of-band write the cached read must NOT observe (no invalidation).
        $connection->executeStatement("UPDATE system_node SET slug = 'changed' WHERE id = 1");

        $second = $repository->find(1, true);

        $this->assertInstanceOf(Node::class, $second);
        $this->assertSame($first, $second, 'a cached find must hand back the very same instance');
        $this->assertSame('about', $second->slug, 'the cached instance must keep its original (stale) state');
    }

    public function testFindWithoutCacheAlwaysReadsFreshRows(): void
    {
        [$repository, $connection] = $this->bootRepository();
        $this->insertNode($connection, ['id' => 1, 'slug' => 'about', 'menu' => 'main']);

        $first = $repository->find(1);
        $this->assertInstanceOf(Node::class, $first);
        $this->assertSame('about', $first->slug);

        $connection->executeStatement("UPDATE system_node SET slug = 'changed' WHERE id = 1");

        $second = $repository->find(1);

        $this->assertInstanceOf(Node::class, $second);
        $this->assertNotSame($first, $second, 'an uncached find must re-hydrate a fresh instance');
        $this->assertSame('changed', $second->slug, 'an uncached find must observe the committed change');
    }

    // -----------------------------------------------------------------------
    // findAll(): memoized full set vs. always-fresh reads.
    // -----------------------------------------------------------------------

    public function testFindAllMemoizesTheFullSetUnderCache(): void
    {
        [$repository, $connection] = $this->bootRepository();
        $this->insertNode($connection, ['id' => 1, 'slug' => 'a', 'menu' => 'main']);
        $this->insertNode($connection, ['id' => 2, 'slug' => 'b', 'menu' => 'main']);

        $warm = $repository->findAll(true);
        $this->assertCount(2, $warm);

        // A row added out-of-band after the set is warmed must not appear in a
        // subsequent cached read, but must appear in an uncached one.
        $this->insertNode($connection, ['id' => 3, 'slug' => 'c', 'menu' => 'main']);

        $this->assertCount(2, $repository->findAll(true), 'a cached findAll must serve the memoized full set');
        $this->assertCount(3, $repository->findAll(false), 'an uncached findAll must re-read every row');
    }

    public function testFindAllReturnsSharedInstancesFromCache(): void
    {
        [$repository, $connection] = $this->bootRepository();
        $this->insertNode($connection, ['id' => 1, 'slug' => 'a', 'menu' => 'main']);

        $first = $repository->findAll(true);
        $second = $repository->findAll(true);

        $this->assertArrayHasKey(1, $first);
        $this->assertSame(
            $first[1],
            $second[1],
            'the cached full set must hand back the same node instances (ArrayAdapter storeSerialized: false)'
        );
    }

    // -----------------------------------------------------------------------
    // findByMenu(): filtered view over the (optionally cached) full set.
    // -----------------------------------------------------------------------

    public function testFindByMenuKeepsOnlyNodesOfTheGivenMenu(): void
    {
        [$repository, $connection] = $this->bootRepository();
        $this->insertNode($connection, ['id' => 1, 'slug' => 'home', 'menu' => 'main']);
        $this->insertNode($connection, ['id' => 2, 'slug' => 'imprint', 'menu' => 'footer']);

        $main = $repository->findByMenu('main', true);

        $this->assertCount(1, $main, 'only nodes assigned to the requested menu must be returned');
        $this->assertArrayHasKey(1, $main);
        $this->assertSame('main', $main[1]->menu);
    }

    // -----------------------------------------------------------------------
    // fixOrphanedNodes(): reset parent_id of nodes whose parent is gone.
    // -----------------------------------------------------------------------

    public function testFixOrphanedNodesResetsParentOfNodesWithMissingParent(): void
    {
        [$repository, $connection] = $this->bootRepository();
        $this->insertNode($connection, ['id' => 1, 'parent_id' => 0, 'slug' => 'root', 'menu' => 'main']);
        // Points at a parent that does not exist -> orphaned.
        $this->insertNode($connection, ['id' => 2, 'parent_id' => 99, 'slug' => 'orphan', 'menu' => 'main']);

        $affected = $repository->fixOrphanedNodes();

        $this->assertSame(1, $affected, 'exactly the one orphaned node must be repaired');
        $this->assertSame(
            0,
            (int) $connection->executeQuery('SELECT parent_id FROM system_node WHERE id = 2')->fetchOne(),
            "the orphan's parent_id must be reset to zero"
        );
        $this->assertSame(
            0,
            (int) $connection->executeQuery('SELECT parent_id FROM system_node WHERE id = 1')->fetchOne(),
            'a well-parented node must be left untouched'
        );
    }

    public function testFixOrphanedNodesReturnsZeroWhenEveryNodeIsWellParented(): void
    {
        [$repository, $connection] = $this->bootRepository();
        $this->insertNode($connection, ['id' => 1, 'parent_id' => 0, 'slug' => 'root', 'menu' => 'main']);
        $this->insertNode($connection, ['id' => 2, 'parent_id' => 1, 'slug' => 'child', 'menu' => 'main']);

        $this->assertSame(0, $repository->fixOrphanedNodes(), 'no orphans means no rows are touched');
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * Boots a real EntityManager on in-memory SQLite and wraps it in a
     * NodeRepository backed by the production cache adapter
     * (`ArrayAdapter(0, false)`). The fixture table maps only the string/integer
     * columns the finders read, so hydration never needs the roles/data
     * conversions (mirrors {@see NodeModelTraitTest}).
     *
     * @return array{NodeRepository, Connection}
     */
    private function bootRepository(): array
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

        $connection->executeStatement(
            'CREATE TABLE system_node ('
            .'id INTEGER PRIMARY KEY, parent_id INTEGER, priority INTEGER, status INTEGER, '
            .'slug TEXT, path TEXT, link TEXT, title TEXT, type TEXT, menu TEXT)'
        );

        return [new NodeRepository($manager, new ArrayAdapter(0, false)), $connection];
    }

    /**
     * Inserts a fixture node row, defaulting the columns a test does not care
     * about so every INSERT is complete.
     *
     * @param array<string, mixed> $values
     */
    private function insertNode(Connection $connection, array $values): void
    {
        $row = $values + [
            'parent_id' => 0,
            'priority' => 0,
            'status' => 1,
            'slug' => '',
            'path' => '',
            'link' => '#',
            'title' => '',
            'type' => 'page',
            'menu' => 'main',
        ];

        $connection->insert('system_node', $row);
    }
}
