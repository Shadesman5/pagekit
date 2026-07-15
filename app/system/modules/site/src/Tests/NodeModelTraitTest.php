<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Doctrine\DBAL\DriverManager;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Loader\AttributeLoader;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\ORM\Repository;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Site\Model\Node;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Unit tests for the {@see \Pagekit\Site\Model\NodeModelTrait} lifecycle handlers,
 * which reach the injected {@see EntityManager} carried by an {@see EntityEvent}.
 *
 * `saving()` drives real SQL — slug uniqueness, parent-path assembly and the
 * next-priority lookup — so it is exercised against an in-memory SQLite
 * EntityManager where those semantics actually matter. The fixture table maps
 * only the string/integer columns the handler reads, so no `simple_array`/`json`
 * conversion is required.
 *
 * `deleting()` is pure re-parenting delegation, so it is asserted against a mock
 * EntityManager whose repository/query chain and `save()` calls are observed
 * directly.
 */
class NodeModelTraitTest extends TestCase
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
    // saving(): real SQL semantics on in-memory SQLite.
    // -----------------------------------------------------------------------

    public function testSavingDefaultsSlugFromTitleSetsTypedLinkPathAndPriority(): void
    {
        [$manager] = $this->bootSqliteManager();

        $node = $this->newNode(['title' => 'About Us', 'type' => 'page', 'menu' => 'main']);

        Node::saving(new EntityEvent('node.saving', $manager), $node);

        $this->assertSame('About Us', $node->slug, 'a blank slug must fall back to the title');
        $this->assertSame('@page/id', $node->link, 'a typed node must get a type-based default link');
        $this->assertSame('/About Us', $node->path, 'a root node path is just its slug');
        $this->assertSame(1, $node->priority, 'the first node under a parent gets priority 1');
    }

    public function testSavingFallsBackToHashLinkForLinkTypeNodes(): void
    {
        [$manager] = $this->bootSqliteManager();

        $node = $this->newNode(['title' => 'External', 'slug' => 'external', 'type' => 'link', 'menu' => 'main']);

        Node::saving(new EntityEvent('node.saving', $manager), $node);

        $this->assertSame('#', $node->link, "a 'link' type (or untyped) node falls back to the '#' link");
    }

    public function testSavingAppendsNumericSuffixWhenSlugCollidesWithinParent(): void
    {
        [$manager, $connection] = $this->bootSqliteManager();
        $this->insertNode($connection, ['id' => 1, 'parent_id' => 0, 'slug' => 'about', 'path' => '/about', 'menu' => 'main', 'priority' => 0]);

        $node = $this->newNode(['title' => 'about', 'parent_id' => 0, 'menu' => 'main', 'type' => 'page']);

        Node::saving(new EntityEvent('node.saving', $manager), $node);

        $this->assertSame('about-2', $node->slug, 'a colliding slug must be suffixed to stay unique within the parent');
        $this->assertSame('/about-2', $node->path);
    }

    public function testSavingKeepsIncrementingUntilTheSlugIsUnique(): void
    {
        [$manager, $connection] = $this->bootSqliteManager();
        $this->insertNode($connection, ['id' => 1, 'parent_id' => 0, 'slug' => 'news', 'path' => '/news', 'menu' => 'main']);
        $this->insertNode($connection, ['id' => 2, 'parent_id' => 0, 'slug' => 'news-2', 'path' => '/news-2', 'menu' => 'main']);

        $node = $this->newNode(['title' => 'news', 'parent_id' => 0, 'menu' => 'main', 'type' => 'page']);

        Node::saving(new EntityEvent('node.saving', $manager), $node);

        $this->assertSame('news-3', $node->slug, 'the suffix must keep climbing past every existing collision');
    }

    public function testSavingPrependsParentPathWhenParentSharesTheMenu(): void
    {
        [$manager, $connection] = $this->bootSqliteManager();
        $this->insertNode($connection, ['id' => 1, 'parent_id' => 0, 'slug' => 'parent', 'path' => '/parent', 'menu' => 'main']);

        $node = $this->newNode(['title' => 'Child', 'slug' => 'child', 'parent_id' => 1, 'menu' => 'main', 'type' => 'page']);

        Node::saving(new EntityEvent('node.saving', $manager), $node);

        $this->assertSame('/parent/child', $node->path, "the child path must be nested under its parent's path");
        $this->assertSame(1, $node->parent_id, 'a valid same-menu parent must be preserved');
    }

    public function testSavingResetsParentWhenParentIsInADifferentMenu(): void
    {
        [$manager, $connection] = $this->bootSqliteManager();
        $this->insertNode($connection, ['id' => 1, 'parent_id' => 0, 'slug' => 'parent', 'path' => '/parent', 'menu' => 'other']);

        $node = $this->newNode(['title' => 'Child', 'slug' => 'child', 'parent_id' => 1, 'menu' => 'main', 'type' => 'page']);

        Node::saving(new EntityEvent('node.saving', $manager), $node);

        $this->assertSame(0, $node->parent_id, 'a cross-menu parent must be dropped');
        $this->assertSame('/child', $node->path, 'a dropped parent leaves the path at the root');
    }

    public function testSavingResetsParentWhenParentDoesNotExist(): void
    {
        [$manager] = $this->bootSqliteManager();

        $node = $this->newNode(['title' => 'Child', 'slug' => 'child', 'parent_id' => 999, 'menu' => 'main', 'type' => 'page']);

        Node::saving(new EntityEvent('node.saving', $manager), $node);

        $this->assertSame(0, $node->parent_id, 'a missing parent must be dropped without dereferencing null');
        $this->assertSame('/child', $node->path);
    }

    public function testSavingClearsASelfReferencingParent(): void
    {
        [$manager, $connection] = $this->bootSqliteManager();
        $this->insertNode($connection, ['id' => 1, 'parent_id' => 0, 'slug' => 'home', 'path' => '/home', 'menu' => 'main']);

        // An existing node (id set) whose parent_id points at itself; path is
        // pre-set to the recomputed value so the child-path UPDATE branch is a
        // no-op and only the self-parent guard is under test.
        $node = $this->newNode(['id' => 1, 'title' => 'Home', 'slug' => 'home', 'path' => '/home', 'parent_id' => 1, 'menu' => 'main', 'type' => 'page']);

        Node::saving(new EntityEvent('node.saving', $manager), $node);

        $this->assertSame(0, $node->parent_id, 'a node cannot be its own parent');
    }

    public function testSavingAssignsNextPriorityScopedToTheParent(): void
    {
        [$manager, $connection] = $this->bootSqliteManager();
        $this->insertNode($connection, ['id' => 1, 'parent_id' => 0, 'slug' => 'a', 'path' => '/a', 'menu' => 'main', 'priority' => 1]);
        $this->insertNode($connection, ['id' => 2, 'parent_id' => 0, 'slug' => 'b', 'path' => '/b', 'menu' => 'main', 'priority' => 3]);
        // Different parent scope — must be ignored by the MAX(priority) lookup.
        $this->insertNode($connection, ['id' => 3, 'parent_id' => 9, 'slug' => 'c', 'path' => '/c', 'menu' => 'main', 'priority' => 99]);

        $node = $this->newNode(['title' => 'new', 'slug' => 'new', 'parent_id' => 0, 'menu' => 'main', 'type' => 'page']);

        Node::saving(new EntityEvent('node.saving', $manager), $node);

        $this->assertSame(4, $node->priority, 'priority must be one past the highest sibling under the same parent');
    }

    // -----------------------------------------------------------------------
    // deleting(): re-parent children onto the removed node's parent.
    // -----------------------------------------------------------------------

    public function testDeletingReparentsEveryChildOntoTheRemovedNodesParent(): void
    {
        $node = $this->newNode(['id' => 5, 'parent_id' => 2]);

        $childOne = $this->newNode(['id' => 10, 'parent_id' => 5]);
        $childTwo = $this->newNode(['id' => 11, 'parent_id' => 5]);

        $query = $this->createMock(QueryBuilder::class);
        $query->expects($this->once())->method('get')->willReturn([$childOne, $childTwo]);

        $repository = $this->createMock(Repository::class);
        $repository->expects($this->once())
            ->method('where')
            ->with('parent_id = ?', [5])
            ->willReturn($query);

        $manager = $this->createMock(EntityManager::class);
        $manager->method('getRepository')->with(Node::class)->willReturn($repository);
        // Each re-parented child must be persisted exactly once.
        $manager->expects($this->exactly(2))->method('save');

        Node::deleting(new EntityEvent('node.deleting', $manager), $node);

        $this->assertSame(2, $childOne->parent_id, "each child inherits the deleted node's parent");
        $this->assertSame(2, $childTwo->parent_id);
    }

    public function testDeletingWithoutChildrenPersistsNothing(): void
    {
        $node = $this->newNode(['id' => 7, 'parent_id' => 0]);

        $query = $this->createMock(QueryBuilder::class);
        $query->expects($this->once())->method('get')->willReturn([]);

        $repository = $this->createMock(Repository::class);
        $repository->expects($this->once())->method('where')->with('parent_id = ?', [7])->willReturn($query);

        $manager = $this->createMock(EntityManager::class);
        $manager->method('getRepository')->willReturn($repository);
        // A leaf node has no children, so nothing is persisted.
        $manager->expects($this->never())->method('save');

        Node::deleting(new EntityEvent('node.deleting', $manager), $node);

        $this->assertSame(0, $node->parent_id, 'deleting a childless node leaves its own state untouched');
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * Builds a Node with the given public columns pre-set (bypassing the
     * constructor is unnecessary — Node has none).
     *
     * @param array<string, mixed> $values
     */
    private function newNode(array $values = []): Node
    {
        $node = new Node();

        foreach ($values as $name => $value) {
            $node->$name = $value;
        }

        return $node;
    }

    /**
     * Boots a real EntityManager backed by in-memory SQLite and creates the
     * `@system_node` fixture table (prefix-less: the placeholder resolves to the
     * bare table name). Only the string/integer columns `saving()` touches are
     * mapped, so hydration never needs the roles/data conversions.
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

        $connection->executeStatement(
            'CREATE TABLE system_node ('
            .'id INTEGER PRIMARY KEY, parent_id INTEGER, priority INTEGER, status INTEGER, '
            .'slug TEXT, path TEXT, link TEXT, title TEXT, type TEXT, menu TEXT)'
        );

        return [$manager, $connection];
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
