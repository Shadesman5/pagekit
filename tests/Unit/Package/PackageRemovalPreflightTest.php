<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Monolog\Handler\TestHandler;
use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Database\Connection;
use Pagekit\Event\EventDispatcher;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Log\Logger;
use Pagekit\Module\ModuleManager;
use Pagekit\Package\Controller\PackageController;
use Pagekit\Package\Package;
use Pagekit\Package\PackageFactory;
use Pagekit\Package\PackageManager;
use Pagekit\Package\RemovalBlockedException;
use Pagekit\Package\Snapshot\DatabaseDumper;
use Pagekit\Package\Snapshot\DatabaseRestorer;
use Pagekit\Package\Snapshot\PackageSnapshotter;
use Pagekit\Package\Snapshot\SnapshotStore;
use Pagekit\Routing\Event\ConfigureRouteListener;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Response;
use Pagekit\Routing\Route;
use Pagekit\Routing\UrlProvider;
use Pagekit\Tests\Unit\Snapshot\ConnectionThatAnswersForAMysqlServer;
use Pagekit\Tests\Unit\Snapshot\SnapshotDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Switching packages off reports what would be left behind and refuses while an enabled module still needs one.
 */
final class PackageRemovalPreflightTest extends TestCase
{
    use SnapshotDatabase;

    private const FOLD_QUERY = "SHOW GLOBAL VARIABLES LIKE 'lower_case_table_names'";

    private string $workspace;

    private string $snapshots;

    private string $packages;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_removal_' . getmypid() . '_' . uniqid();
        $this->snapshots = $this->workspace . '/tmp/snapshots';
        $this->packages = $this->workspace . '/packages';

        if (!mkdir($this->packages, 0755, true) && !is_dir($this->packages)) {
            self::fail('The fixture workspace could not be created.');
        }
    }

    protected function tearDown(): void
    {
        $this->closeDatabases();
        $this->removeTree($this->workspace);
    }

    public function testImpactAndDisableRequireThePackageNameAndCsrf(): void
    {
        $events = new EventDispatcher();
        $events->subscribe(new ConfigureRouteListener());
        $routes = (new RoutesLoader($events))->load([
            (new Route('/system/package', [], [], ['controller' => PackageController::class]))->setName('@system/package'),
        ]);

        foreach (['impact', 'disable'] as $action) {
            $route = $routes->get('@system/package/' . $action);

            self::assertInstanceOf(Route::class, $route);
            self::assertSame('/system/package/' . $action, $route->getPath());
            self::assertSame(PackageController::class . '::' . $action . 'Action', $route->getDefault('_controller'));
            self::assertSame([
                'value' => ['name' => 'string'],
                'options' => [],
                'csrf' => true,
            ], $route->getDefault('_request'));
        }
    }

    public function testImpactListsAnEnabledThemeAndDisableIsRefused(): void
    {
        [$app, $modules, $system] = $this->site(
            ['blog' => [], 'theme' => ['blog']],
            ['blog'],
            ['site' => ['theme' => 'theme']],
        );
        $modules->load('blog');

        $package = $this->package('blog');
        $factory = $this->factory($package);
        $app->set('package', $factory);
        $controller = $this->controller($app, $factory, $modules);

        self::assertSame([
            'blockers' => ['theme'],
            'orphans' => [],
            'dataRisk' => [
                'migrations' => false,
                'config' => false,
                'nodes' => [],
                'tables' => [],
            ],
        ], $controller->impactAction('pagekit/blog'));

        $thrown = $this->refused(fn () => $controller->disableAction('pagekit/blog'));

        self::assertInstanceOf(BadRequestHttpException::class, $thrown);
        self::assertSame(400, $thrown->getStatusCode());
        self::assertSame('"theme" requires "blog", so it cannot be switched off.', $thrown->getMessage());
        self::assertInstanceOf(RemovalBlockedException::class, $thrown->getPrevious());
        self::assertSame($thrown->getMessage(), $thrown->getPrevious()->getMessage());
        self::assertSame(['blog'], $system->get('extensions'));
        self::assertSame('theme', $system->get('site.theme'));
    }

    public function testOneDependerStopsDisableBeforeTheExtensionChanges(): void
    {
        $tree = $this->plant('blog');
        $this->writeDisableMarker($tree);
        [$app, , $system] = $this->site(
            ['blog' => [], 'comments' => ['blog']],
            ['blog', 'comments'],
            ['site' => ['theme' => 'kept-theme']],
        );
        $blog = $this->package('blog', ['extra' => ['scripts' => 'scripts.php']]);

        $thrown = $this->refused(fn () => $this->manager($app)->disable($blog));

        self::assertInstanceOf(RemovalBlockedException::class, $thrown);
        self::assertSame('"comments" requires "blog", so it cannot be switched off.', $thrown->getMessage());
        self::assertSame(['blog', 'comments'], $system->get('extensions'));
        self::assertSame('kept-theme', $system->get('site.theme'));
        self::assertFileDoesNotExist($tree . '/disabled');
    }

    public function testSeveralBlockersAreNamedBeforeAnythingChanges(): void
    {
        $tree = $this->plant('blog');
        $this->writeDisableMarker($tree);
        [$app, , $system] = $this->site(
            [
                'blog' => [],
                'comments' => ['blog'],
                'pages' => ['blog'],
            ],
            ['blog', 'comments', 'pages'],
        );
        $blog = $this->package('blog', ['extra' => ['scripts' => 'scripts.php']]);

        $thrown = $this->refused(fn () => $this->manager($app)->disable($blog));

        self::assertInstanceOf(RemovalBlockedException::class, $thrown);
        self::assertSame('"comments, pages" require "blog", so nothing was switched off.', $thrown->getMessage());
        self::assertSame(['blog', 'comments', 'pages'], $system->get('extensions'));
        self::assertFileDoesNotExist($tree . '/disabled');
    }

    public function testAPackageWithoutAModuleIsNamedInTheRefusal(): void
    {
        [$app, , $system] = $this->site(
            ['blog' => [], 'comments' => ['blog']],
            ['blog', 'comments'],
        );
        $blog = $this->package('blog');
        $ghost = $this->package('ghost', ['name' => 'pagekit/ghost', 'module' => null]);

        $thrown = $this->refused(fn () => $this->manager($app)->disable([$blog, $ghost]));

        self::assertInstanceOf(RemovalBlockedException::class, $thrown);
        self::assertSame(
            '"comments" require "blog, pagekit/ghost", so nothing was switched off.',
            $thrown->getMessage(),
        );
        self::assertSame(['blog', 'comments'], $system->get('extensions'));
    }

    public function testUninstallOfAPackageAndItsEnabledDependerIsRefusedBeforeASnapshot(): void
    {
        $alphaTree = $this->plant('alpha');
        $betaTree = $this->plant('beta');
        $this->writeDisableMarker($alphaTree);
        $this->writeDisableMarker($betaTree);

        [$app, , $system] = $this->site(
            ['alpha' => [], 'beta' => ['alpha']],
            ['alpha', 'beta'],
        );
        $alpha = $this->package('alpha', ['extra' => ['scripts' => 'scripts.php']]);
        $beta = $this->package('beta', ['extra' => ['scripts' => 'scripts.php']]);
        $app->set('package', $this->factory($alpha, $beta));
        $this->listenForSnapshots($app);

        $thrown = $this->refused(fn () => $this->manager($app)->uninstall(['pagekit/alpha', 'pagekit/beta']));

        self::assertInstanceOf(RemovalBlockedException::class, $thrown);
        self::assertSame('"beta" require "alpha, beta", so nothing was switched off.', $thrown->getMessage());
        self::assertDirectoryDoesNotExist($this->snapshots);
        self::assertFileDoesNotExist($alphaTree . '/disabled');
        self::assertFileDoesNotExist($betaTree . '/disabled');
        self::assertSame(['alpha', 'beta'], $system->get('extensions'));
        self::assertFileExists($alphaTree . '/composer.json');
        self::assertFileExists($betaTree . '/composer.json');
    }

    public function testAMissingNameIsRefusedBeforeThePresentPackageIsSnapshotted(): void
    {
        $tree = $this->plant('present');
        [$app, , $system] = $this->site(['present' => []], ['present']);
        $app->set('package', $this->factory($this->package('present')));
        $this->listenForSnapshots($app);

        $thrown = $this->refused(fn () => $this->manager($app)->uninstall(['pagekit/present', 'missing']));

        self::assertSame(\RuntimeException::class, $thrown::class);
        self::assertSame('Unable to find "missing".', $thrown->getMessage());
        self::assertDirectoryDoesNotExist($this->snapshots);
        self::assertFileExists($tree . '/composer.json');
        self::assertSame(['present'], $system->get('extensions'));
    }

    public function testASelfRequirementDoesNotStopDisable(): void
    {
        [$app, , $system] = $this->site(
            ['alpha' => ['alpha']],
            ['alpha', 'kept'],
            ['site' => ['theme' => 'other']],
        );

        $this->manager($app)->disable($this->package('alpha'));

        self::assertSame(['kept'], $system->get('extensions'));
        self::assertSame('other', $system->get('site.theme'));
    }

    public function testDisablingTheActiveThemeClearsIt(): void
    {
        [$app, , $system] = $this->site(
            ['one' => []],
            ['kept'],
            ['site' => ['theme' => 'one']],
        );

        $this->manager($app)->disable($this->package('one', ['type' => 'pagekit-theme']));

        self::assertNull($system->get('site.theme'));
        self::assertSame(['kept'], $system->get('extensions'));
    }

    public function testDisablingAnotherThemeLeavesTheActiveOne(): void
    {
        [$app, , $system] = $this->site(
            ['one' => [], 'two' => []],
            ['kept'],
            ['site' => ['theme' => 'one']],
        );

        $this->manager($app)->disable($this->package('two', ['type' => 'pagekit-theme']));

        self::assertSame('one', $system->get('site.theme'));
        self::assertSame(['kept'], $system->get('extensions'));
    }

    public function testDisablingAnExtensionLeavesTheActiveTheme(): void
    {
        [$app, , $system] = $this->site(
            ['blog' => []],
            ['blog', 'kept'],
            ['site' => ['theme' => 'blog']],
        );

        $this->manager($app)->disable($this->package('blog'));

        self::assertSame('blog', $system->get('site.theme'));
        self::assertSame(['kept'], $system->get('extensions'));
    }

    public function testABlockedThemeStaysTheActiveTheme(): void
    {
        [$app, , $system] = $this->site(
            ['one' => [], 'blog' => ['one']],
            ['blog'],
            ['site' => ['theme' => 'one']],
        );

        $thrown = $this->refused(fn () => $this->manager($app)->disable($this->package('one', ['type' => 'pagekit-theme'])));

        self::assertInstanceOf(RemovalBlockedException::class, $thrown);
        self::assertSame('"blog" requires "one", so it cannot be switched off.', $thrown->getMessage());
        self::assertSame('one', $system->get('site.theme'));
        self::assertSame(['blog'], $system->get('extensions'));
    }

    public function testABootRequirementIsAnOrphanOnlyBeforeTheActivityPolicy(): void
    {
        [$app, $modules, $system] = $this->site(
            [
                'system' => ['user'],
                'user' => [],
                'blog' => ['blog', 'user', 'comments'],
                'comments' => [],
            ],
            ['blog'],
        );
        $blog = $this->package('blog');

        self::assertFalse($modules->isAlwaysLoaded('user'));
        self::assertSame(['user', 'comments'], $this->impact($app, $blog)['orphans']);

        $modules->setActivityPolicy(['blog'], 'system');

        self::assertTrue($modules->isAlwaysLoaded('user'));
        self::assertFalse($modules->isAlwaysLoaded('comments'));
        self::assertSame(['comments'], $this->impact($app, $blog)['orphans']);
        self::assertSame(['blog'], $system->get('extensions'));
    }

    public function testARequirementIsAnOrphanOnlyWhenNothingOutsideTheCallNeedsIt(): void
    {
        [$app, $modules] = $this->site(
            [
                'system' => [],
                'blog' => ['comments'],
                'pages' => ['comments'],
                'comments' => [],
            ],
            ['blog', 'pages'],
        );
        $modules->setActivityPolicy(['blog', 'pages'], 'system');
        $blog = $this->package('blog');
        $pages = $this->package('pages');
        $comments = $this->package('comments');

        self::assertSame([], $this->impact($app, $blog)['orphans']);
        self::assertSame(['comments'], $this->impact($app, [$blog, $pages])['orphans']);
        self::assertSame([], $this->impact($app, [$blog, $comments])['orphans']);
    }

    public function testThePackageVersionOnTheSystemRowIsNotConfigAtRisk(): void
    {
        [$app] = $this->site(
            ['blog' => []],
            ['blog'],
            ['packages' => ['blog' => '1.0.0']],
        );

        self::assertFalse($this->impact($app, $this->package('blog'))['dataRisk']['config']);
    }

    public function testAConfigRowNamedForTheModuleIsAtRisk(): void
    {
        [$app] = $this->site(['blog' => []], ['blog'], [], [], ['blog' => true]);

        self::assertTrue($this->impact($app, $this->package('blog'))['dataRisk']['config']);
    }

    public function testOrphansAndDataRiskDoNotStopDisable(): void
    {
        [$app, $package, $system] = $this->riskyBlog();

        $this->assertReportedButNotBlocking($this->impact($app, $package));

        $this->manager($app)->disable($package);

        self::assertSame(['kept'], $system->get('extensions'));
        self::assertSame("disable\n", (string) file_get_contents($this->workspace . '/hooks'));
    }

    public function testOrphansAndDataRiskDoNotStopUninstall(): void
    {
        [$app, $package, $system, $connection] = $this->riskyBlog();
        $app->set('package', $this->factory($package));

        $this->assertReportedButNotBlocking($this->impact($app, $package));

        $this->manager($app)->uninstall('pagekit/blog');

        self::assertSame(['kept'], $system->get('extensions'));
        self::assertSame("disable\nuninstall\n", (string) file_get_contents($this->workspace . '/hooks'));
        self::assertDirectoryDoesNotExist($package->get('path'));
        self::assertContains('pk_blog_post', $connection->createSchemaManager()->listTableNames());
    }

    public function testThePreflightAndTheRemovalHooksShareOneLifecycle(): void
    {
        $tree = $this->plant('blog');
        $this->writeSharedLifecycle($tree);
        [$app, , $system] = $this->site(
            ['blog' => []],
            ['blog', 'kept'],
            ['packages' => ['blog' => '1.0.0']],
        );
        $package = $this->package('blog', ['extra' => ['scripts' => 'scripts.php']]);
        $app->set('package', $this->factory($package));

        $this->manager($app)->uninstall('pagekit/blog');

        // The file body runs once per require. One byte means the hooks saw the object the pre-flight read.
        self::assertSame('1', (string) file_get_contents($this->workspace . '/loads'));
        self::assertSame("disable\nuninstall\n", (string) file_get_contents($this->workspace . '/hooks'));
        self::assertSame(['kept'], $system->get('extensions'));
        self::assertNull($system->get('packages.blog'));
        self::assertDirectoryDoesNotExist($tree);
    }

    public function testALifecycleThatReturnsNothingStillDisablesAndReportsNoMigrations(): void
    {
        $tree = $this->plant('blog');
        file_put_contents($tree . '/scripts.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");
        [$app, , $system] = $this->site(['blog' => []], ['blog', 'kept']);
        $package = $this->package('blog', ['extra' => ['scripts' => 'scripts.php']]);

        self::assertFalse($this->impact($app, $package)['dataRisk']['migrations']);

        $this->manager($app)->disable($package);

        self::assertSame(['kept'], $system->get('extensions'));
    }

    public function testModuleTablesMatchByBytesWithoutAskingTheServer(): void
    {
        $connection = $this->mysqlServer();
        $connection->folding = '1';

        foreach (['other_blog_post', 'pk_blogpost', 'pk_blog_zeta', 'pk_blog_alpha', 'pk_news_item'] as $name) {
            $this->tableNamed($connection, $name);
        }

        [$app] = $this->site(['blog' => [], 'news' => []], []);
        $app->set('db', $connection);

        $tables = $this->impact($app, [$this->package('blog'), $this->package('news')])['dataRisk']['tables'];

        self::assertSame(['pk_blog_alpha', 'pk_blog_zeta', 'pk_news_item'], $tables);
        self::assertSame([], $connection->asked);
    }

    /**
     * @return array<string, array{string|int|null, bool}>
     */
    public static function foldingAnswers(): array
    {
        return [
            'stored folded, text' => ['1', true],
            'stored folded, integer' => [1, true],
            'matched folded, text' => ['2', true],
            'matched folded, integer' => [2, true],
            'matched as written, text' => ['0', false],
            'matched as written, integer' => [0, false],
            'any other value' => [3, false],
            'no row' => [null, false],
        ];
    }

    #[DataProvider('foldingAnswers')]
    public function testADifferentCaseMatchesOnlyWhenTheServerFolds(string|int|null $folding, bool $matches): void
    {
        $connection = $this->mysqlServer();
        $connection->folding = $folding;
        $this->tableNamed($connection, 'PK_blog_post');
        $this->requireNamesKeptAsGiven($connection, 'PK_blog_post');

        [$app] = $this->site(['blog' => []], []);
        $app->set('db', $connection);

        $tables = $this->impact($app, $this->package('blog'))['dataRisk']['tables'];

        self::assertSame($matches ? ['PK_blog_post'] : [], $tables);
        self::assertSame([self::FOLD_QUERY], $connection->asked);
    }

    public function testSqliteKeepsADifferentCaseOutOfTheModuleTables(): void
    {
        $connection = $this->openDatabase();

        if (!$this->isSqlite($connection)) {
            self::markTestSkipped('SQLite is the engine that must not ask how table names fold');
        }

        // The same spelling in another case is one table here, so the case difference has to be a different name.
        $this->tableNamed($connection, 'PK_blog_post');
        $this->tableNamed($connection, 'pk_blog_comment');
        $this->tableNamed($connection, 'other_blog_post');

        [$app] = $this->site(['blog' => []], []);
        $app->set('db', $connection);

        self::assertSame(['pk_blog_comment'], $this->impact($app, $this->package('blog'))['dataRisk']['tables']);
    }

    public function testTablesAreEmptyWhenThereIsNoConnection(): void
    {
        [$app] = $this->site(['blog' => []], []);
        $package = $this->package('blog');

        self::assertSame([], $this->impact($app, $package)['dataRisk']['tables']);

        $app->set('db', new \stdClass());

        self::assertSame([], $this->impact($app, $package)['dataRisk']['tables']);
    }

    /**
     * @param array<string, list<string>>                  $requires
     * @param list<string>                                 $extensions
     * @param array<string, mixed>                         $system
     * @param array<string, array<int|string, mixed>>      $nodes
     * @param array<string, true>                          $rows
     * @return array{Application, ModuleManager, Config}
     */
    private function site(array $requires, array $extensions, array $system = [], array $nodes = [], array $rows = []): array
    {
        $config = new Config(array_replace(['extensions' => $extensions], $system));
        $app = new Application();
        $modules = $app->get('module');
        self::assertInstanceOf(ModuleManager::class, $modules);

        $files = [];

        foreach ($requires as $name => $require) {
            $files[] = $this->declareModule($name, $require, $nodes[$name] ?? []);
        }

        if ($files !== []) {
            $modules->register($files);
        }

        $app->set('config', new RemovalConfig($config, $rows));
        $app->set('file', new Filesystem());

        return [$app, $modules, $config];
    }

    /**
     * @param Package|array<int, Package> $packages
     * @return array{blockers: list<string>, orphans: list<string>, dataRisk: array{migrations: bool, config: bool, nodes: list<string>, tables: list<string>}}
     */
    private function impact(Application $app, Package|array $packages): array
    {
        return $this->manager($app)->removalImpact($packages);
    }

    private function manager(Application $app): PackageManager
    {
        return new PackageManager($app, new NullOutput());
    }

    private function controller(Application $app, PackageFactory $packages, ModuleManager $modules): PackageController
    {
        $log = new Logger('package');
        $log->pushHandler(new TestHandler());

        return new PackageController(
            $this->manager($app),
            $packages,
            $modules,
            $this->createMock(UrlProvider::class),
            Request::create('/'),
            $this->createMock(Response::class),
            $this->workspace . '/staging',
            false,
            $log,
        );
    }

    /**
     * Blog leaves a module behind and names migrations, a config row, nodes, and a table.
     *
     * @return array{Application, Package, Config, Connection}
     */
    private function riskyBlog(): array
    {
        $tree = $this->plant('blog');
        $this->writeSharedLifecycle($tree);

        [$app, $modules, $system] = $this->site(
            [
                'blog' => ['comments'],
                'comments' => [],
            ],
            ['blog', 'kept'],
            [],
            [
                'blog' => [
                    'post' => ['label' => 'Post'],
                    0 => ['label' => 'Index'],
                    '' => ['label' => 'Blank'],
                    'page' => ['label' => 'Page'],
                ],
            ],
            ['blog' => true],
        );
        $modules->setActivityPolicy(['blog'], 'system');

        $connection = $this->openDatabase();
        $this->tableNamed($connection, 'other_blog_post');
        $this->tableNamed($connection, 'pk_blogpost');
        $this->tableNamed($connection, 'pk_blog_post');
        $app->set('db', $connection);

        return [$app, $this->package('blog', ['extra' => ['scripts' => 'scripts.php']]), $system, $connection];
    }

    /**
     * @param array{blockers: list<string>, orphans: list<string>, dataRisk: array{migrations: bool, config: bool, nodes: list<string>, tables: list<string>}} $impact
     */
    private function assertReportedButNotBlocking(array $impact): void
    {
        self::assertSame([], $impact['blockers']);
        self::assertSame(['comments'], $impact['orphans']);
        self::assertSame([
            'migrations' => true,
            'config' => true,
            'nodes' => ['post', 'page'],
            'tables' => ['pk_blog_post'],
        ], $impact['dataRisk']);
    }

    private function listenForSnapshots(Application $app): void
    {
        $connection = $this->openDatabase();
        $files = new Filesystem();

        $app->set('snapshotter', new PackageSnapshotter(
            new SnapshotStore($this->snapshots, $files),
            new DatabaseDumper($connection),
            new DatabaseRestorer($connection),
            $files,
            new NullLogger(),
            $this->packages,
        ));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function package(string $module, array $overrides = []): Package
    {
        return new Package(array_replace([
            'name' => 'pagekit/' . $module,
            'type' => 'pagekit-extension',
            'module' => $module,
            'title' => $module,
            'version' => '1.0.0',
            'path' => $this->packages . '/pagekit/' . $module,
        ], $overrides));
    }

    private function factory(Package ...$packages): PackageFactory
    {
        $factory = new PackageFactory();

        foreach ($packages as $package) {
            $factory[$package->getName()] = $package;
        }

        return $factory;
    }

    private function plant(string $module): string
    {
        $tree = $this->packages . '/pagekit/' . $module;

        if (!is_dir($tree) && !mkdir($tree, 0755, true) && !is_dir($tree)) {
            self::fail('The fixture package directory could not be created.');
        }

        file_put_contents($tree . '/composer.json', (string) json_encode([
            'name' => 'pagekit/' . $module,
            'type' => 'pagekit-extension',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));

        return $tree;
    }

    /**
     * @param list<string>                $require
     * @param array<int|string, mixed>    $nodes
     */
    private function declareModule(string $name, array $require, array $nodes): string
    {
        $directory = $this->workspace . '/modules/' . $name;

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            self::fail('The fixture module directory could not be created.');
        }

        $module = ['name' => $name, 'require' => array_values($require)];

        if ($nodes !== []) {
            $module['nodes'] = $nodes;
        }

        $file = $directory . '/index.php';
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($module, true) . ";\n";

        if (file_put_contents($file, $contents) === false) {
            self::fail('The fixture module could not be written.');
        }

        return $file;
    }

    private function writeDisableMarker(string $tree): void
    {
        file_put_contents($tree . '/scripts.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Pagekit\Package\Lifecycle\PackageLifecycle;
            use Psr\Container\ContainerInterface;

            return new class () extends PackageLifecycle {
                public function disable(ContainerInterface $app): void
                {
                    file_put_contents(__DIR__ . '/disabled', '1');
                }
            };

            PHP);
    }

    private function writeSharedLifecycle(string $tree): void
    {
        $contents = str_replace(
            ['{LOADS}', '{HOOKS}', '{DIR}'],
            [
                var_export($this->workspace . '/loads', true),
                var_export($this->workspace . '/hooks', true),
                var_export($tree, true),
            ],
            <<<'PHP'
                <?php

                declare(strict_types=1);

                use Pagekit\Package\Lifecycle\MigrationSet;
                use Pagekit\Package\Lifecycle\PackageLifecycle;
                use Psr\Container\ContainerInterface;

                file_put_contents({LOADS}, '1', FILE_APPEND);

                return new class () extends PackageLifecycle {
                    public function migrations(): ?MigrationSet
                    {
                        return new MigrationSet('Fixture\\Blog', {DIR});
                    }

                    public function disable(ContainerInterface $app): void
                    {
                        file_put_contents({HOOKS}, "disable\n", FILE_APPEND);
                    }

                    public function uninstall(ContainerInterface $app): void
                    {
                        file_put_contents({HOOKS}, "uninstall\n", FILE_APPEND);
                    }
                };

                PHP,
        );

        file_put_contents($tree . '/scripts.php', $contents);
    }

    private function mysqlServer(): ConnectionThatAnswersForAMysqlServer
    {
        $connection = $this->openDatabase('pk_', ConnectionThatAnswersForAMysqlServer::class);

        self::assertInstanceOf(ConnectionThatAnswersForAMysqlServer::class, $connection);

        return $connection;
    }

    private function tableNamed(Connection $connection, string $name): void
    {
        $table = new Table($name);
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('title', Types::STRING, ['length' => 191]);
        $table->setPrimaryKey(['id']);

        $connection->createSchemaManager()->createTable($table);
    }

    private function requireNamesKeptAsGiven(Connection $connection, string $name): void
    {
        if (!in_array($name, $connection->createSchemaManager()->listTableNames(), true)) {
            self::markTestSkipped(sprintf('This server stores table names folded, so it cannot hold a table called "%s"', $name));
        }
    }

    /**
     * @param callable(): void $call
     */
    private function refused(callable $call): \RuntimeException
    {
        try {
            $call();
        } catch (\RuntimeException $e) {
            return $e;
        }

        self::fail('Switching the package off was expected to be refused.');
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}

/**
 * System settings the operation writes, and config rows named apart from the version key inside that row.
 */
final class RemovalConfig extends ConfigManager
{
    /**
     * @param array<string, true> $rows module names that have their own config row
     */
    public function __construct(
        private readonly Config $system,
        private readonly array $rows = [],
    ) {
    }

    public function __invoke(string $name): ?Config
    {
        return $name === 'system' ? $this->system : null;
    }

    public function has(string $name): bool
    {
        return isset($this->rows[$name]);
    }
}
