<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Composer\Autoload\ClassLoader;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Database\Connection;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Module\Loader\AutoLoader;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Package\Archive\PackageArchive;
use Pagekit\Package\Package;
use Pagekit\Package\PackageFactory;
use Pagekit\Package\PackageManager;
use Pagekit\Package\Snapshot\DatabaseDumper;
use Pagekit\Package\Snapshot\DatabaseRestorer;
use Pagekit\Package\Snapshot\PackageSnapshotter;
use Pagekit\Package\Snapshot\SnapshotStore;
use Pagekit\Tests\Unit\Package\PackageZip;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * An archive installs, the module it declares boots, and a removal's snapshot puts that tree back.
 */
final class UploadedPackageRoundTripTest extends TestCase
{
    use SnapshotDatabase;

    private const VENDOR = 'acme';

    private const MODULE = 'roundtrip';

    private const PACKAGE = 'acme/roundtrip';

    private const MARK = 'uploaded-round-trip';

    private const CLASS_NAME = 'Pagekit\\Fixture\\UploadedRoundTrip\\Widget';

    private const NAMESPACE_PREFIX = 'Pagekit\\Fixture\\UploadedRoundTrip\\';

    /** @var array<string, array<string, string>> */
    private const ROUTES = [
        '/roundtrip' => [
            'name' => '@roundtrip',
            'controller' => self::CLASS_NAME,
        ],
    ];

    private string $workspace = '';

    private string $packages = '';

    private string $tree = '';

    private string $snapshots = '';

    private string $witnessFile = '';

    private Connection $connection;

    private Config $system;

    private ?ClassLoader $loader = null;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_uploaded_roundtrip_'.bin2hex(random_bytes(4));
        $this->packages = $this->workspace.'/packages';
        $this->tree = $this->packages.'/'.self::VENDOR.'/'.self::MODULE;
        $this->snapshots = $this->workspace.'/snapshots';
        $this->witnessFile = $this->workspace.'/witness.txt';

        mkdir($this->packages, 0777, true);

        $this->connection = $this->openDatabase();
        $this->system = new Config(['extensions' => []]);

        // A dump with no tables is not a snapshot a restore will apply.
        $table = new Table('pk_roundtrip');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('title', Types::STRING, ['length' => 64]);
        $table->setPrimaryKey(['id']);
        $this->connection->createSchemaManager()->createTable($table);
        $this->connection->insert('pk_roundtrip', ['title' => 'row']);
    }

    protected function tearDown(): void
    {
        $this->loader?->unregister();
        $this->closeDatabases();

        if ($this->workspace !== '') {
            $this->removeTree($this->workspace);
        }
    }

    public function testAnUploadedPackageBootsAndComesBackFromItsRemovalSnapshot(): void
    {
        $app = $this->installation();
        $manager = new PackageManager($app, new BufferedOutput());

        $manager->install(PackageArchive::open($this->zip()));

        self::assertSame("install\n", $this->witness());
        self::assertSame([], $this->system->get('extensions'));
        self::assertSame([self::VENDOR], $this->listing($this->packages));

        $package = $app->get('package')->get(self::PACKAGE, true);

        self::assertInstanceOf(Package::class, $package);

        $manager->enable($package);

        self::assertSame("install\nenable\n", $this->witness());
        self::assertSame([self::MODULE], $this->system->get('extensions'));
        self::assertSame('1.0.0', $this->system->get('packages.'.self::MODULE));

        $modules = $app->get('module');

        self::assertInstanceOf(ModuleManager::class, $modules);

        $this->boot($modules, true);

        $manager->uninstall(self::PACKAGE);

        self::assertSame("install\nenable\nuninstall\n", $this->witness());
        self::assertFileDoesNotExist($this->classFile());
        self::assertSame([], $this->listing($this->packages));

        $snapshotter = $app->get('snapshotter');

        self::assertInstanceOf(PackageSnapshotter::class, $snapshotter);

        $snapshots = $snapshotter->list();

        self::assertCount(1, $snapshots);

        $id = array_key_first($snapshots);

        self::assertIsString($id);

        $snapshotter->restore($id);

        self::assertSame([self::VENDOR], $this->listing($this->packages));
        self::assertStringContainsString(self::MARK, (string) file_get_contents($this->classFile()));

        // The class is already loaded, so a new manager has to resolve it from the restored tree.
        $this->boot(new ModuleManager($app), false);
    }

    private function boot(ModuleManager $modules, bool $autoload): void
    {
        $loader = new ClassLoader();

        if ($autoload) {
            self::assertFalse(class_exists(self::CLASS_NAME, false));
            $loader->register();
            $this->loader = $loader;
        }

        $modules->addLoader(new AutoLoader($loader));
        $modules->register($this->packages.'/*/*/index.php');

        self::assertSame([], $modules->getRegistrationFailures());

        $modules->load(self::MODULE);

        $module = $modules->get(self::MODULE);

        self::assertInstanceOf(Module::class, $module);
        self::assertSame(self::ROUTES, $module->get('routes'));

        $found = $loader->findFile(self::CLASS_NAME);

        self::assertIsString($found);
        self::assertSame($this->classFile(), strtr($found, '\\', '/'));

        if (!$autoload) {
            return;
        }

        self::assertTrue(class_exists(self::CLASS_NAME));
        self::assertSame(self::MARK, constant(self::CLASS_NAME.'::MARK'));
    }

    private function installation(): Application
    {
        $app = new Application();
        $files = new Filesystem();
        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($this->system);

        $factory = new PackageFactory();
        $factory->addPath($this->packages.'/*/*/composer.json');

        $app->set('config', $config);
        $app->set('package', $factory);
        $app->set('file', $files);
        $app->set('log', new NullLogger());
        $app->set('path.temp', $this->workspace.'/tmp/temp');
        $app->set('path.cache', $this->workspace.'/tmp/cache');
        $app->set('path.vendor', $this->workspace.'/vendor');
        $app->set('path.artifact', $this->workspace.'/artifact');
        $app->set('path.packages', $this->packages);
        $app->set('system.api', 'https://example.test');
        $app->set('db', $this->connection);
        $app->set('snapshotter', fn () => new PackageSnapshotter(
            new SnapshotStore($this->snapshots, $files),
            new DatabaseDumper($this->connection),
            new DatabaseRestorer($this->connection),
            $files,
            new NullLogger(),
            $this->packages,
        ));

        return $app;
    }

    private function zip(): string
    {
        $path = $this->workspace.'/incoming.zip';

        PackageZip::write($path, [
            'name' => self::PACKAGE,
            'type' => 'pagekit-extension',
            'version' => '1.0.0',
            'title' => 'Round Trip',
            'extra' => ['scripts' => 'scripts.php'],
        ], [
            'scripts.php' => $this->lifecycle(),
            'src/Widget.php' => $this->widget(),
        ], $this->manifest());

        return $path;
    }

    private function manifest(): string
    {
        $module = var_export([
            'name' => self::MODULE,
            'autoload' => [self::NAMESPACE_PREFIX => 'src'],
            'routes' => self::ROUTES,
        ], true);

        return "<?php\n\ndeclare(strict_types=1);\n\nreturn {$module};\n";
    }

    private function widget(): string
    {
        $namespace = rtrim(self::NAMESPACE_PREFIX, '\\');
        $mark = self::MARK;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            final class Widget
            {
                public const MARK = '{$mark}';
            }
            PHP;
    }

    private function lifecycle(): string
    {
        $witness = var_export($this->witnessFile, true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Pagekit\Package\Lifecycle\PackageLifecycle;
            use Psr\Container\ContainerInterface;

            if (!class_exists(PagekitUploadedRoundTripLifecycle::class, false)) {
                class PagekitUploadedRoundTripLifecycle extends PackageLifecycle
                {
                    public function install(ContainerInterface \$app): void
                    {
                        file_put_contents({$witness}, "install\\n", FILE_APPEND);
                    }

                    public function enable(ContainerInterface \$app): void
                    {
                        file_put_contents({$witness}, "enable\\n", FILE_APPEND);
                    }

                    public function uninstall(ContainerInterface \$app): void
                    {
                        file_put_contents({$witness}, "uninstall\\n", FILE_APPEND);
                    }
                }
            }

            return new PagekitUploadedRoundTripLifecycle();
            PHP;
    }

    private function witness(): string
    {
        if (!is_file($this->witnessFile)) {
            return '';
        }

        return (string) file_get_contents($this->witnessFile);
    }

    private function classFile(): string
    {
        return $this->tree.'/src/Widget.php';
    }

    /**
     * @return list<string>
     */
    private function listing(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
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

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}
