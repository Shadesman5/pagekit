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
use Pagekit\Installer\Package\Package;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Site\ExtensionNodeLifecycle;
use Pagekit\Site\Model\NodeRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Holds the module currently returned for the "blog" name so the uninstall
 * path can simulate the module already being gone from the manager.
 */
final class BlogModuleHolder
{
    public function __construct(public mixed $current)
    {
    }
}

/**
 * Container stub exposing the services the package node listeners resolve.
 */
final class SitePackageAppStub
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly ModuleManager $modules,
        private readonly \Closure $config,
        public readonly Config $systemConfig,
        private readonly BlogModuleHolder $blogModule,
    ) {
    }

    public function unloadBlogModule(): void
    {
        $this->blogModule->current = null;
    }

    public function get(string $id): mixed
    {
        return match ($id) {
            'nodeRepository' => $this->nodes,
            'module' => $this->modules,
            'config' => $this->config,
            default => throw new \LogicException(sprintf('The node listeners must not resolve "%s".', $id)),
        };
    }
}

/**
 * The site module listens to the package lifecycle so that pages served by an
 * extension do not turn into broken links the moment the extension goes away.
 * Only extensions declare node types, so switching a theme must never reach that
 * machinery — losing every page over a design change would be a far worse bug
 * than the one the listeners exist to fix.
 *
 * The listeners live in the module definition rather than in a class, so it is
 * pulled in below and its closures bind to the container stub declared in the
 * same scope.
 */
class PackageLifecycleWiringTest extends TestCase
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

    public function testDisablingAThemeNeverReachesTheNodeLifecycle(): void
    {
        // A container that refuses to hand anything out, so a listener that
        // should have bailed out early is caught red-handed.
        $app = new class () {
            /** @var array<int, string> */
            public array $resolved = [];

            public function get(string $id): never
            {
                $this->resolved[] = $id;

                throw new \LogicException(sprintf('A package without node types must not resolve "%s".', $id));
            }
        };

        $this->listener('package.disable', $app)(null, $this->makeTheme());

        $this->assertSame([], $app->resolved, 'a theme declares no node types, so nothing may be resolved for it');
    }

    public function testUninstallingAThemeNeverReachesTheNodeLifecycle(): void
    {
        $app = new class () {
            /** @var array<int, string> */
            public array $resolved = [];

            public function get(string $id): never
            {
                $this->resolved[] = $id;

                throw new \LogicException(sprintf('A package without node types must not resolve "%s".', $id));
            }
        };

        $this->listener('package.uninstall', $app)(null, $this->makeTheme());

        $this->assertSame([], $app->resolved);
    }

    public function testDisablingAnExtensionTakesItsPagesOutOfTheMenu(): void
    {
        [$repository, $connection] = $this->bootRepository();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/blog']);

        $app = $this->createSiteApp($repository, new Config(['frontpage' => 0]));

        $this->listener('package.disable', $app)(null, $this->makeExtension());

        $blog = $repository->find(1);
        $this->assertNotNull($blog);
        $this->assertSame('', $blog->menu, 'the page of a disabled extension has to leave the menu');
        $this->assertSame(0, $blog->status);
        $this->assertIsArray($blog->get(ExtensionNodeLifecycle::RESTORE_KEY), 'its placement must be recorded so re-enabling can undo this');
        $this->assertSame(
            ['blog'],
            $app->systemConfig->get('_extension_nodes.blog'),
            'disable must remember the node types while the module is still loaded',
        );
    }

    public function testUninstallingAnExtensionMovesItsPagesToTrash(): void
    {
        [$repository, $connection] = $this->bootRepository();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/blog']);

        $app = $this->createSiteApp($repository, new Config(['frontpage' => 0]));

        $this->listener('package.uninstall', $app)(null, $this->makeExtension());

        $blog = $repository->find(1);
        $this->assertNotNull($blog);
        $this->assertSame('trash', $blog->menu, 'an uninstalled extension leaves its pages recoverable in the trash');
        $this->assertSame(0, $blog->status);
        $this->assertNull(
            $app->systemConfig->get('_extension_nodes.blog'),
            'uninstall forgets remembered types once the package is gone',
        );
    }

    public function testEnablingAnExtensionRestoresThePagesOfAnEarlierInstall(): void
    {
        [$repository, $connection] = $this->bootRepository();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/blog']);

        $app = $this->createSiteApp($repository, new Config(['frontpage' => 0]));

        $this->listener('package.disable', $app)(null, $this->makeExtension());
        $this->assertSame(['blog'], $app->systemConfig->get('_extension_nodes.blog'));

        $this->listener('package.enable', $app)(null, $this->makeExtension());

        $blog = $repository->find(1);
        $this->assertNotNull($blog);
        $this->assertSame('main', $blog->menu, 'a reinstalled extension gets its pages back where they were');
        $this->assertSame(0, $blog->status, 'they stay unpublished until an editor says otherwise');
        $this->assertNull(
            $app->systemConfig->get('_extension_nodes.blog'),
            'enable forgets the remembered types once the module is back',
        );
    }

    public function testUninstallUsesRememberedTypesWhenTheModuleIsAlreadyUnloaded(): void
    {
        [$repository, $connection] = $this->bootRepository();
        $this->insertNode($connection, ['id' => 1, 'title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'menu' => 'main', 'priority' => 1, 'status' => 1, 'path' => '/blog']);

        $app = $this->createSiteApp($repository, new Config(['frontpage' => 0]));
        $this->listener('package.disable', $app)(null, $this->makeExtension());

        // After disable the module may already be gone from ModuleManager; uninstall
        // must still find the node types through the remembered system config entry.
        $app->unloadBlogModule();

        $this->listener('package.uninstall', $app)(null, $this->makeExtension());

        $blog = $repository->find(1);
        $this->assertNotNull($blog);
        $this->assertSame('trash', $blog->menu, 'remembered types must still drive a soft-delete into trash');
        $this->assertIsArray($blog->get(ExtensionNodeLifecycle::RESTORE_KEY));
        $this->assertNull($app->systemConfig->get('_extension_nodes.blog'));
    }

    /**
     * Hands back one of the module definition's package listeners, bound to the
     * given container stub.
     */
    private function listener(string $event, object $app): callable
    {
        /** @var array{events: array<string, callable>} $module */
        $module = require __DIR__ . '/../../index.php';

        return $module['events'][$event];
    }

    private function makeTheme(): Package
    {
        return new Package(['name' => 'pagekit/theme-one', 'module' => 'theme-one', 'type' => 'pagekit-theme']);
    }

    private function makeExtension(): Package
    {
        return new Package(['name' => 'pagekit/blog', 'module' => 'blog', 'type' => 'pagekit-extension']);
    }

    /**
     * A container holding the services the node listeners use, with the blog
     * extension reporting one node type.
     */
    private function createSiteApp(NodeRepository $nodes, Config $siteConfig): SitePackageAppStub
    {
        $module = $this->createMock(Module::class);
        $module->method('get')->with('nodes')->willReturn(['blog' => ['label' => 'Blog']]);

        $blogModule = new BlogModuleHolder($module);

        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->with('blog')->willReturnCallback(
            static fn () => $blogModule->current
        );

        $systemConfig = new Config([]);

        $config = static function (string $name) use ($siteConfig, $systemConfig): Config {
            return match ($name) {
                'system/site' => $siteConfig,
                'system' => $systemConfig,
                default => throw new \LogicException(sprintf('The node listeners must not read config "%s".', $name)),
            };
        };

        return new SitePackageAppStub($nodes, $modules, $config, $systemConfig, $blogModule);
    }

    /**
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
            .'slug TEXT, path TEXT, link TEXT, title TEXT, type TEXT, menu TEXT, '
            .'data TEXT, roles TEXT)'
        );

        return [new NodeRepository($manager, new ArrayAdapter(0, false)), $connection];
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
