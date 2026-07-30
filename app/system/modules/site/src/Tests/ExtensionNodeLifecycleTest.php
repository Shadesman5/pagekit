<?php

declare(strict_types=1);

namespace Pagekit\Site\Tests;

use Doctrine\DBAL\DriverManager;
use Pagekit\Config\Config;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Loader\AttributeLoader;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Event\EventDispatcherInterface;
use Pagekit\Site\ExtensionNodeLifecycle;
use Pagekit\Site\Model\NodeRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class ExtensionNodeLifecycleTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
    }

    public function testDisableSnapshotsAndMovesToNotLinked(): void
    {
        [$lifecycle, $repository, $config, $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Home', 'slug' => 'home', 'type' => 'page', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/home']);
        $this->insertNode($connection, ['id' => 2, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 2, 'status' => 1, 'path' => '/blog']);
        $this->insertNode($connection, ['id' => 3, 'title' => 'About', 'slug' => 'about', 'type' => 'page', 'menu' => 'main', 'priority' => 3, 'status' => 1, 'path' => '/about']);
        $config->set('frontpage', 2);

        $lifecycle->disable(['blog']);

        $blog = $repository->find(2);
        $this->assertNotNull($blog);
        $this->assertSame(0, $blog->status);
        $this->assertSame('', $blog->menu);
        $this->assertSame(0, (int) $blog->parent_id);
        $this->assertSame(0, (int) $config->get('frontpage'));

        $snap = $blog->get(ExtensionNodeLifecycle::RESTORE_KEY);
        $this->assertIsArray($snap);
        $this->assertSame(
            ['menu', 'parent_id', 'prev_id', 'next_id'],
            array_keys($snap),
            'a snapshot records placement anchors only — never status, frontpage, or priority',
        );
        $this->assertSame('main', $snap['menu']);
        $this->assertSame(0, $snap['parent_id']);
        $this->assertSame(1, $snap['prev_id']);
        $this->assertSame(3, $snap['next_id']);
        $this->assertArrayNotHasKey('status', $snap);
        $this->assertArrayNotHasKey('frontpage', $snap);
        $this->assertArrayNotHasKey('priority', $snap);
    }

    public function testDisableLeavesTrashItemsInTrash(): void
    {
        [$lifecycle, $repository, , $connection] = $this->boot();
        $this->insertNode($connection, [
            'id' => 1,
            'title' => 'Blog',
            'slug' => 'blog',
            'type' => 'blog',
            'menu' => 'trash',
            'priority' => 1,
            'status' => 0,
            'path' => '/blog',
        ]);

        $lifecycle->disable(['blog']);

        $blog = $repository->find(1);
        $this->assertNotNull($blog);
        $this->assertSame('trash', $blog->menu, 'disable must not pull trash items back to Not Linked');
        $this->assertSame(0, $blog->status);
        $snap = $blog->get(ExtensionNodeLifecycle::RESTORE_KEY);
        $this->assertIsArray($snap);
        $this->assertSame('trash', $snap['menu']);
    }

    public function testEnableRestoresMenuButKeepsUnpublished(): void
    {
        [$lifecycle, $repository, , $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Home', 'slug' => 'home', 'type' => 'page', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/home']);
        $this->insertNode($connection, ['id' => 2, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 2, 'status' => 1, 'path' => '/blog']);
        $this->insertNode($connection, ['id' => 3, 'title' => 'About', 'slug' => 'about', 'type' => 'page', 'menu' => 'main', 'priority' => 3, 'status' => 1, 'path' => '/about']);

        $lifecycle->disable(['blog']);
        $lifecycle->enable(['blog']);

        $blog = $repository->find(2);
        $this->assertNotNull($blog);
        $this->assertSame('main', $blog->menu);
        $this->assertSame(0, $blog->status, 're-enable must not republish by surprise');
        $this->assertNull($blog->get(ExtensionNodeLifecycle::RESTORE_KEY));
        $this->assertSame(2, $blog->priority);
        $this->assertSame(4, $repository->find(3)?->priority);
    }

    public function testEnableUsesSurvivingNeighborWhenOtherMoved(): void
    {
        [$lifecycle, $repository, , $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Home', 'slug' => 'home', 'type' => 'page', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/home']);
        $this->insertNode($connection, ['id' => 2, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 2, 'status' => 1, 'path' => '/blog']);
        $this->insertNode($connection, ['id' => 3, 'title' => 'About', 'slug' => 'about', 'type' => 'page', 'menu' => 'main', 'priority' => 3, 'status' => 1, 'path' => '/about']);

        $lifecycle->disable(['blog']);

        $about = $repository->find(3);
        $home = $repository->find(1);
        $this->assertNotNull($about);
        $this->assertNotNull($home);
        $about->priority = 1;
        $repository->save($about);
        $home->menu = 'footer';
        $repository->save($home);

        $lifecycle->enable(['blog']);

        $blog = $repository->find(2);
        $this->assertNotNull($blog);
        $this->assertSame('main', $blog->menu);
        $this->assertSame(0, $blog->status);
        // Home left main, but About (next) remains → insert before About.
        $this->assertSame(1, $blog->priority);
        $this->assertSame(2, $repository->find(3)?->priority);
    }

    public function testUninstallMovesToTrashAndKeepsSnapshot(): void
    {
        [$lifecycle, $repository, , $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/blog']);

        $lifecycle->disable(['blog']);
        $lifecycle->uninstall(['blog']);

        $blog = $repository->find(1);
        $this->assertNotNull($blog);
        $this->assertSame('trash', $blog->menu);
        $this->assertSame(0, $blog->status);
        $snap = $blog->get(ExtensionNodeLifecycle::RESTORE_KEY);
        $this->assertIsArray($snap);
        $this->assertSame('main', $snap['menu'], 'original menu must survive disable → uninstall');
    }

    public function testEnableAfterUninstallRestoresFromTrashUnpublished(): void
    {
        [$lifecycle, $repository, , $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Home', 'slug' => 'home', 'type' => 'page', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/home']);
        $this->insertNode($connection, ['id' => 2, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 2, 'status' => 1, 'path' => '/blog']);

        $lifecycle->disable(['blog']);
        $lifecycle->uninstall(['blog']);
        $lifecycle->enable(['blog']);

        $blog = $repository->find(2);
        $this->assertNotNull($blog);
        $this->assertSame('main', $blog->menu);
        $this->assertSame(0, $blog->status);
        $this->assertNull($blog->get(ExtensionNodeLifecycle::RESTORE_KEY));
    }

    public function testEmptyTypeListIsANoOpForEveryTransition(): void
    {
        [$lifecycle, $repository, $config, $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/blog']);
        $config->set('frontpage', 1);

        $lifecycle->disable([]);
        $lifecycle->enable([]);
        $lifecycle->uninstall([]);

        $blog = $repository->find(1);
        $this->assertNotNull($blog);
        $this->assertSame('main', $blog->menu);
        $this->assertSame(1, $blog->status);
        $this->assertNull($blog->get(ExtensionNodeLifecycle::RESTORE_KEY));
        $this->assertSame(1, (int) $config->get('frontpage'), 'a package that declares no node types must leave the site untouched');
    }

    public function testDisableKeepsAFrontpageOwnedByAnotherType(): void
    {
        [$lifecycle, $repository, $config, $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Home', 'slug' => 'home', 'type' => 'page', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/home']);
        $this->insertNode($connection, ['id' => 2, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 2, 'status' => 1, 'path' => '/blog']);
        $config->set('frontpage', 1);

        $lifecycle->disable(['blog']);

        $this->assertSame(1, (int) $config->get('frontpage'), 'only a frontpage owned by the extension may be reset');

        $home = $repository->find(1);
        $this->assertNotNull($home);
        $this->assertSame('main', $home->menu);
        $this->assertSame(1, $home->status, 'nodes of other types keep their placement and published state');
    }

    public function testEnableDoesNotHandTheFrontpageBack(): void
    {
        [$lifecycle, $repository, $config, $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/blog']);
        $config->set('frontpage', 1);

        $lifecycle->disable(['blog']);
        $lifecycle->enable(['blog']);

        $this->assertSame(0, (int) $config->get('frontpage'), 'the recorded frontpage flag is informational — re-enabling must not silently take over the frontpage again');

        $blog = $repository->find(1);
        $this->assertNotNull($blog);
        $this->assertSame('main', $blog->menu, 'the menu placement is restored even though the frontpage assignment is not');
    }

    public function testEnableWithoutASnapshotLeavesTheNodeUntouched(): void
    {
        [$lifecycle, $repository, , $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/blog']);

        $lifecycle->enable(['blog']);

        $blog = $repository->find(1);
        $this->assertNotNull($blog);
        $this->assertSame('main', $blog->menu);
        $this->assertSame(1, $blog->status, 'enabling must not unpublish pages that were never disabled');
    }

    public function testUninstallWithoutAPriorDisableSnapshotsBeforeTrashing(): void
    {
        [$lifecycle, $repository, $config, $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Home', 'slug' => 'home', 'type' => 'page', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/home']);
        $this->insertNode($connection, ['id' => 2, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 2, 'status' => 1, 'path' => '/blog']);
        $this->insertNode($connection, ['id' => 3, 'title' => 'About', 'slug' => 'about', 'type' => 'page', 'menu' => 'main', 'priority' => 3, 'status' => 1, 'path' => '/about']);
        $config->set('frontpage', 2);

        $lifecycle->uninstall(['blog']);

        $this->assertSame(0, (int) $config->get('frontpage'));

        $blog = $repository->find(2);
        $this->assertNotNull($blog);
        $this->assertSame('trash', $blog->menu);
        $this->assertSame(0, $blog->status);

        $snap = $blog->get(ExtensionNodeLifecycle::RESTORE_KEY);
        $this->assertIsArray($snap);
        $this->assertSame(
            ['menu', 'parent_id', 'prev_id', 'next_id'],
            array_keys($snap),
            'an uninstall that skips disable still records placement anchors only',
        );
        $this->assertSame('main', $snap['menu'], 'an uninstall that skips disable must still record what a reinstall restores');
        $this->assertSame(0, $snap['parent_id']);
        $this->assertSame(1, $snap['prev_id']);
        $this->assertSame(3, $snap['next_id']);
        $this->assertArrayNotHasKey('frontpage', $snap);
        $this->assertArrayNotHasKey('priority', $snap);
    }

    public function testEnableAppendsWhenBothRecordedNeighborsLeftTheMenu(): void
    {
        [$lifecycle, $repository, , $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Home', 'slug' => 'home', 'type' => 'page', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/home']);
        $this->insertNode($connection, ['id' => 2, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 2, 'status' => 1, 'path' => '/blog']);
        $this->insertNode($connection, ['id' => 3, 'title' => 'About', 'slug' => 'about', 'type' => 'page', 'menu' => 'main', 'priority' => 3, 'status' => 1, 'path' => '/about']);

        $lifecycle->disable(['blog']);

        foreach ([1, 3] as $id) {
            $moved = $repository->find($id);
            $this->assertNotNull($moved);
            $moved->menu = 'footer';
            $repository->save($moved);
        }

        $this->insertNode($connection, ['id' => 4, 'title' => 'Contact', 'slug' => 'contact', 'type' => 'page', 'menu' => 'main', 'priority' => 7, 'status' => 1, 'path' => '/contact']);

        $lifecycle->enable(['blog']);

        $blog = $repository->find(2);
        $this->assertNotNull($blog);
        $this->assertSame('main', $blog->menu);
        $this->assertSame(8, $blog->priority, 'with no surviving neighbor the node is appended behind the last sibling');
        $this->assertSame(7, $repository->find(4)?->priority, 'appending must leave the surviving siblings where they are');
    }

    public function testEnableDropsAParentThatLeftTheRestoredMenu(): void
    {
        [$lifecycle, $repository, , $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Home', 'slug' => 'home', 'type' => 'page', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/home']);
        $this->insertNode($connection, ['id' => 2, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'parent_id' => 1, 'priority' => 2, 'status' => 1, 'path' => '/blog']);

        $lifecycle->disable(['blog']);

        $home = $repository->find(1);
        $this->assertNotNull($home);
        $home->menu = 'footer';
        $repository->save($home);

        $lifecycle->enable(['blog']);

        $blog = $repository->find(2);
        $this->assertNotNull($blog);
        $this->assertSame('main', $blog->menu);
        $this->assertSame(0, (int) $blog->parent_id, 'a parent that no longer lives in the restored menu must not be re-attached');
    }

    public function testEnableConsumesTheSnapshotOfANodeThatWasNeverLinked(): void
    {
        [$lifecycle, $repository, , $connection] = $this->boot();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => '', 'priority' => 1, 'status' => 1, 'path' => '/blog']);

        $lifecycle->disable(['blog']);
        $lifecycle->enable(['blog']);

        $blog = $repository->find(1);
        $this->assertNotNull($blog);
        $this->assertSame('', $blog->menu, 'an unlinked node has no placement to restore');
        $this->assertSame(0, $blog->status);
        $this->assertNull($blog->get(ExtensionNodeLifecycle::RESTORE_KEY), 'the snapshot is consumed even when nothing is restored');
    }

    /**
     * @return array{ExtensionNodeLifecycle, NodeRepository, Config, Connection}
     */
    private function boot(): array
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
            .'slug TEXT, path TEXT, link TEXT, title TEXT, type TEXT, menu TEXT, '
            .'data TEXT, roles TEXT)'
        );

        $repository = new NodeRepository($manager, new ArrayAdapter(0, false));
        $config = new Config(['frontpage' => 0]);

        return [new ExtensionNodeLifecycle($repository, $config), $repository, $config, $connection];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function insertNode(Connection $connection, array $values): void
    {
        $connection->insert('system_node', $values + [
            'parent_id' => 0,
            'priority' => 0,
            'status' => 1,
            'slug' => '',
            'path' => '',
            'link' => '#',
            'title' => '',
            'type' => 'page',
            'menu' => 'main',
            'data' => null,
            'roles' => null,
        ]);
    }
}
