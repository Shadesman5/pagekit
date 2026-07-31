<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Installer\Package\Package;
use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Installer\Package\PackageInterface;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\Migration\MigrationService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Integration tests for PackageManager::enable()/disable()/uninstall() orchestration.
 *
 * An extension's schema lifecycle runs through its scripts.php hooks: the
 * `enable` hook calls MigrationService::migrateExtension(), the `uninstall` hook
 * calls rollbackExtension(). These tests exercise that orchestration end-to-end
 * against a real in-memory-SQLite MigrationService, driving actual DDL so table
 * existence is the assertion — not a mocked call count.
 *
 * Beyond schema work the manager announces `package.disable` / `package.uninstall`
 * so other modules can archive the content an extension owns. A recording
 * dispatcher pins when those events fire relative to the extension's own hooks
 * and to the removal of the package folder (listeners may still need the package
 * object while the folder exists; node types themselves come from the loaded
 * module or remembered system config, never from requiring index.php).
 *
 * Container availability is covered by running enable() with a minimal container
 * (no config/events/log) and by building the manager both with and without the
 * container-provided path.* services, so both constructor branches are asserted
 * behaviourally.
 *
 * NOT covered here (needs a booted kernel + real Composer/network):
 * PackageManager::install() and the Composer download/update pipeline
 * (Composer::composerUpdate()). This suite targets the enable()/disable()/
 * uninstall() orchestration, not the Composer transport.
 */
class PackageManagerMigrationTest extends TestCase
{
    private static int $counter = 0;

    private Connection $connection;
    private MigrationService $migration;
    private string $baseDir;
    private string $packageDir;
    private string $migrationsDir;
    private string $namespace;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        self::$counter++;
        $this->namespace = 'PkgMgrTestMig' . self::$counter;

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->baseDir = sys_get_temp_dir() . '/pk_pkgmgr_test_' . self::$counter . '_' . getmypid();
        $this->packageDir = $this->baseDir . '/package';
        $this->migrationsDir = $this->packageDir . '/src/Migrations';
        mkdir($this->migrationsDir, 0755, true);
        mkdir($this->baseDir . '/core', 0755, true);

        $this->migration = $this->makeMigrationService();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->recursiveRemove($this->baseDir);
    }

    // ------------------------------------------------------------------
    // enable() — auto-migrate
    // ------------------------------------------------------------------

    public function testEnableRunsExtensionMigrationAndRecordsVersion(): void
    {
        $this->writeComposerJson('1.0.0');
        $this->writeMigrationV1();
        $this->writeScripts($this->enableMigrateScript());

        $system = new Config();
        $events = new class () {
            /** @var array<int, string> */
            public array $fired = [];

            /** @param array<int, mixed> $params */
            public function trigger(string $event, array $params = []): void
            {
                $this->fired[] = $event;
            }
        };

        $app = $this->makeContainer([
            'migration' => $this->migration,
            'config' => $this->configService($system),
            'events' => $events,
        ]);

        $this->makeManager($app)->enable($this->makePackage());

        self::assertTrue(
            $this->connection->createSchemaManager()->tablesExist(['test_ext_items']),
            'enable() must auto-run the extension migration via the scripts.php enable hook',
        );
        self::assertSame('1.0.0', $system->get('packages.test-ext'));
        self::assertContains('test-ext', (array) $system->get('extensions'));
        self::assertContains('package.enable', $events->fired);
    }

    public function testEnableFiresPackageEnableOnlyAfterScriptsSucceed(): void
    {
        $this->writeComposerJson('1.0.0');
        $this->writeScripts($this->announcingScript('enable'));

        $system = new Config();
        $system->set('packages.test-ext', '1.0.0');

        $events = new class () {
            /** @var array<int, string> */
            public array $fired = [];

            /** @param array<int, mixed> $params */
            public function trigger(string $event, array $params = []): void
            {
                $this->fired[] = $event;
            }
        };

        $app = $this->makeContainer([
            'config' => $this->configService($system),
            'events' => $events,
        ]);

        $this->makeManager($app)->enable($this->makePackage());

        self::assertSame(
            ['script.enable', 'package.enable'],
            $events->fired,
            'package.enable must fire only after scripts->enable() succeeds so listeners never see a half-enabled package',
        );
        self::assertContains('test-ext', (array) $system->get('extensions'));
    }

    public function testEnableWithoutContainerConfigStillRunsMigration(): void
    {
        $this->writeComposerJson('1.0.0');
        $this->writeMigrationV1();
        $this->writeScripts($this->enableMigrateScript());

        // Minimal container: only `migration`. No config/events/log services, so
        // enable() takes the else branch and must still migrate (DI-wiring audit:
        // PackageManager degrades gracefully without optional container services).
        $app = $this->makeContainer(['migration' => $this->migration]);

        $this->makeManager($app)->enable($this->makePackage());

        self::assertTrue(
            $this->connection->createSchemaManager()->tablesExist(['test_ext_items']),
            'enable() must auto-migrate even when the container exposes no config/events services',
        );
    }

    // ------------------------------------------------------------------
    // uninstall() — auto-rollback
    // ------------------------------------------------------------------

    public function testUninstallRunsRollbackHookDroppingExtensionTable(): void
    {
        $this->writeMigrationV1();
        $this->writeScripts($this->fullRollbackUninstallScript());

        $up = $this->migration->migrateExtension($this->namespace, $this->migrationsDir);
        self::assertTrue($up['success'], (string) ($up['error'] ?? ''));
        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['test_ext_items']));

        $system = new Config();
        $system->set('packages.test-ext', '1.0.0');
        $system->set('extensions', ['test-ext']);

        $file = new class () {
            /** @var array<int, string> */
            public array $deleted = [];

            public function delete(string $path): bool
            {
                $this->deleted[] = $path;

                return true;
            }
        };

        $package = $this->makePackage();
        $app = $this->makeContainer(array_merge($this->pathServices(), [
            'migration' => $this->migration,
            'config' => $this->configService($system),
            'package' => $this->makePackageFactory($package),
            'file' => $file,
        ]));

        $this->makeManager($app)->uninstall('pagekit/test-ext');

        self::assertFalse(
            $this->connection->createSchemaManager()->tablesExist(['test_ext_items']),
            'uninstall() must run the rollback hook and drop the extension table',
        );
        self::assertNull($system->get('packages.test-ext'));
        self::assertSame([], (array) $system->get('extensions'));
        self::assertContains($this->packageDir, $file->deleted);
    }

    public function testUninstallPartiallyRollsBackToPreMigrationVersion(): void
    {
        $this->writeMigrationV1();
        $this->writeMigrationV2();
        $this->writeScripts($this->partialRollbackUninstallScript());

        $up = $this->migration->migrateExtension($this->namespace, $this->migrationsDir);
        self::assertTrue($up['success'], (string) ($up['error'] ?? ''));
        self::assertSame(2, $up['executed']);

        $sm = $this->connection->createSchemaManager();
        self::assertTrue($sm->tablesExist(['test_ext_items']));
        self::assertTrue($sm->tablesExist(['test_ext_settings']));

        $system = new Config();
        $system->set('packages.test-ext', '2.0.0');
        $system->set('extensions', ['test-ext']);

        $file = new class () {
            /** @var array<int, string> */
            public array $deleted = [];

            public function delete(string $path): bool
            {
                $this->deleted[] = $path;

                return true;
            }
        };

        $package = $this->makePackage();
        $app = $this->makeContainer(array_merge($this->pathServices(), [
            'migration' => $this->migration,
            'config' => $this->configService($system),
            'package' => $this->makePackageFactory($package),
            'file' => $file,
        ]));

        $this->makeManager($app)->uninstall('pagekit/test-ext');

        self::assertTrue(
            $this->connection->createSchemaManager()->tablesExist(['test_ext_items']),
            'V1 table must survive the partial rollback to the pre-migration version',
        );
        self::assertFalse(
            $this->connection->createSchemaManager()->tablesExist(['test_ext_settings']),
            'V2 table must be dropped by the partial rollback',
        );
    }

    // ------------------------------------------------------------------
    // disable()/uninstall() — extension lifecycle events
    // ------------------------------------------------------------------

    public function testDisableFiresPackageDisableAfterTheExtensionsOwnHook(): void
    {
        $this->writeScripts($this->announcingScript('disable'));

        $system = new Config();
        $system->set('extensions', ['test-ext']);

        $events = new class () {
            /** @var array<int, string> */
            public array $fired = [];

            /** @param array<int, mixed> $params */
            public function trigger(string $event, array $params = []): void
            {
                $this->fired[] = $event;
            }
        };

        $app = $this->makeContainer([
            'config' => $this->configService($system),
            'events' => $events,
        ]);

        $this->makeManager($app)->disable($this->makePackage());

        self::assertSame(
            ['script.disable', 'package.disable'],
            $events->fired,
            'listeners that archive extension content must run after the extension had its own say',
        );
        self::assertSame([], (array) $system->get('extensions'), 'disable() must drop the module from the enabled extensions');
    }

    public function testUninstallFiresPackageUninstallWhileThePackageFolderStillExists(): void
    {
        $this->writeScripts($this->announcingScript('uninstall'));

        $system = new Config();
        $system->set('packages.test-ext', '1.0.0');
        $system->set('extensions', ['test-ext']);

        $events = new class () {
            /** @var array<int, string> */
            public array $fired = [];

            /** @var array<string, bool> */
            public array $packageOnDisk = [];

            /** @param array<int, mixed> $params */
            public function trigger(string $event, array $params = []): void
            {
                $this->fired[] = $event;

                $package = $params[0] ?? null;
                if ($package instanceof PackageInterface) {
                    $path = $package->get('path');
                    $this->packageOnDisk[$event] = is_string($path) && is_dir($path);
                }
            }
        };

        // The production `file` service really removes the folder; doing the same
        // here is what gives the "still on disk" assertion below any meaning.
        $file = new class () {
            /** @var array<int, string> */
            public array $deleted = [];

            public function delete(string $path): bool
            {
                $this->deleted[] = $path;

                return $this->remove($path);
            }

            private function remove(string $path): bool
            {
                if (!is_dir($path)) {
                    return unlink($path);
                }

                foreach (array_diff((array) scandir($path), ['.', '..']) as $item) {
                    $this->remove($path . '/' . $item);
                }

                return rmdir($path);
            }
        };

        $package = $this->makePackage();
        $app = $this->makeContainer(array_merge($this->pathServices(), [
            'config' => $this->configService($system),
            'package' => $this->makePackageFactory($package),
            'file' => $file,
            'events' => $events,
        ]));

        $this->makeManager($app)->uninstall('pagekit/test-ext');

        self::assertSame(
            ['package.disable', 'script.uninstall', 'package.uninstall'],
            $events->fired,
            'the extension runs its own uninstall hook first, then listeners get to archive its content',
        );
        self::assertTrue(
            $events->packageOnDisk['package.uninstall'] ?? false,
            'package.uninstall must fire while the package folder still exists so listeners can finish cleanup',
        );
        self::assertSame([$this->packageDir], $file->deleted);
        self::assertNull($system->get('packages.test-ext'));
    }

    // ------------------------------------------------------------------
    // enable() — failure rolls back to the pre-migration state
    // ------------------------------------------------------------------

    public function testFailedEnableRollsBackConfigToPreMigrationState(): void
    {
        $this->writeComposerJson('1.0.0');
        $this->writeScripts($this->throwingEnableScript());

        // packages.test-ext is absent → doInstall() advances it to the installed
        // version, then the enable hook throws: rollbackEnable() must restore the
        // pre-migration (absent) state.
        $system = new Config();
        $events = new class () {
            /** @var array<int, string> */
            public array $fired = [];

            /** @param array<int, mixed> $params */
            public function trigger(string $event, array $params = []): void
            {
                $this->fired[] = $event;
            }
        };

        $app = $this->makeContainer([
            'config' => $this->configService($system),
            'events' => $events,
        ]);
        $package = $this->makePackage();

        // Capture rather than self::fail() inside the catch: PHPUnit's
        // AssertionFailedError itself extends \RuntimeException, so a self::fail()
        // in the try would be swallowed by the catch.
        $thrown = null;

        try {
            $this->makeManager($app)->enable($package);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $thrown, 'enable() must rethrow when the enable hook fails');
        self::assertStringContainsString('Test Extension', $thrown->getMessage());
        self::assertStringContainsString('Simulated migration failure', $thrown->getMessage());
        self::assertNull(
            $system->get('packages.test-ext'),
            'A failed enable() must roll the package version back to its pre-migration (absent) state',
        );
        self::assertNotContains(
            'package.enable',
            $events->fired,
            'package.enable must not fire when scripts->enable() fails — node restore / type forget stay unrestored',
        );
        self::assertSame([], (array) $system->get('extensions'));
    }

    public function testFailedEnableLogsErrorWhenLogServiceAvailable(): void
    {
        $this->writeComposerJson('1.0.0');
        $this->writeScripts($this->throwingEnableScript());

        $system = new Config();
        $log = new class () {
            /** @var array<int, string> */
            public array $errors = [];

            /** @param array<string, mixed> $context */
            public function error(string $message, array $context = []): void
            {
                $this->errors[] = $message;
            }
        };

        $app = $this->makeContainer([
            'config' => $this->configService($system),
            'log' => $log,
        ]);

        $thrown = null;

        try {
            $this->makeManager($app)->enable($this->makePackage());
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $thrown, 'enable() must rethrow when the enable hook fails');
        self::assertCount(1, $log->errors);
        self::assertStringContainsString('Failed to enable package "pagekit/test-ext"', $log->errors[0]);
    }

    // ------------------------------------------------------------------
    // Constructor DI-wiring — with / without container-provided paths
    // ------------------------------------------------------------------

    public function testConstructorResolvesPathsWithAndWithoutContainer(): void
    {
        $this->writeComposerJson('1.0.0');
        $this->writeMigrationV1();
        $this->writeScripts($this->enableMigrateScript());

        // (a) Container WITHOUT path.temp → constructor computes default paths.
        $withoutPaths = $this->makeContainer(['migration' => $this->migration]);
        $this->makeManager($withoutPaths)->enable($this->makePackage());
        self::assertTrue(
            $this->connection->createSchemaManager()->tablesExist(['test_ext_items']),
            'Manager built on the default-path branch must still orchestrate enable()',
        );

        // (b) Container WITH path.* → constructor consumes the injected paths.
        $system = new Config();
        $withPaths = $this->makeContainer(array_merge($this->pathServices(), [
            'migration' => $this->migration,
            'config' => $this->configService($system),
        ]));
        $this->makeManager($withPaths)->enable($this->makePackage());
        self::assertSame(
            '1.0.0',
            $system->get('packages.test-ext'),
            'Manager built from container-provided paths must orchestrate enable() + config bookkeeping',
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function makeMigrationService(): MigrationService
    {
        return new MigrationService($this->connection, [
            'table_storage' => [
                'table_name' => 'test_migration_versions',
                'version_column_name' => 'version',
                'version_column_length' => 191,
                'executed_at_column_name' => 'executed_at',
                'execution_time_column_name' => 'execution_time',
            ],
            'migrations_paths' => [
                $this->namespace . 'Core' => $this->baseDir . '/core',
            ],
            'all_or_nothing' => false,
            'check_database_platform' => false,
            'organize_migrations' => 'none',
        ]);
    }

    /**
     * @param array<string, mixed> $services
     */
    private function makeContainer(array $services): ContainerInterface
    {
        return new class ($services) implements ContainerInterface {
            /** @param array<string, mixed> $services */
            public function __construct(private array $services)
            {
            }

            public function get(string $id): mixed
            {
                if (!array_key_exists($id, $this->services)) {
                    throw new class (sprintf('Service "%s" is not registered.', $id)) extends \RuntimeException implements NotFoundExceptionInterface {
                    };
                }

                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
    }

    private function makeManager(ContainerInterface $app): PackageManager
    {
        return new PackageManager($app, new NullOutput());
    }

    private function configService(Config $system): ConfigManager
    {
        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($system);

        return $config;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makePackage(array $overrides = []): Package
    {
        return new Package(array_replace([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'module' => 'test-ext',
            'title' => 'Test Extension',
            'path' => $this->packageDir,
            'extra' => ['scripts' => 'scripts.php'],
        ], $overrides));
    }

    private function makePackageFactory(Package $package): PackageFactory
    {
        $factory = new PackageFactory();
        $factory[$package->getName()] = $package;

        return $factory;
    }

    /**
     * Temp path.* services so Composer::isInstalled() reads a clean (missing)
     * installed.json and the uninstall folder-removal branch runs deterministically.
     *
     * @return array<string, string>
     */
    private function pathServices(): array
    {
        $root = $this->baseDir . '/paths';

        return [
            'path.temp' => $root . '/temp',
            'path.cache' => $root . '/cache',
            'path.vendor' => $root . '/vendor',
            'path.artifact' => $root . '/artifact',
            'path.packages' => $root . '/packages',
            'system.api' => 'https://example.test',
        ];
    }

    private function writeComposerJson(string $version): void
    {
        file_put_contents(
            $this->packageDir . '/composer.json',
            (string) json_encode([
                'name' => 'pagekit/test-ext',
                'type' => 'pagekit-extension',
                'version' => $version,
            ]),
        );
    }

    private function writeScripts(string $php): void
    {
        file_put_contents(
            $this->packageDir . '/scripts.php',
            str_replace('{NS}', $this->namespace, $php),
        );
    }

    private function enableMigrateScript(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'enable' => function ($app) {
                    $result = $app->get('migration')->migrateExtension('{NS}', __DIR__ . '/src/Migrations');

                    if (!$result['success']) {
                        throw new \RuntimeException('Extension migration failed: ' . ($result['error'] ?? 'unknown error'));
                    }
                },
            ];
            PHP;
    }

    /**
     * A scripts.php whose hook announces itself on the event dispatcher, so the
     * order of the extension's own hook and the manager's lifecycle event is
     * observable in one recorded sequence.
     */
    private function announcingScript(string $hook): string
    {
        return str_replace('{HOOK}', $hook, <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                '{HOOK}' => function ($app) {
                    $app->get('events')->trigger('script.{HOOK}', []);
                },
            ];
            PHP);
    }

    private function throwingEnableScript(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'enable' => function ($app) {
                    throw new \RuntimeException('Simulated migration failure');
                },
            ];
            PHP;
    }

    private function fullRollbackUninstallScript(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'uninstall' => function ($app) {
                    $result = $app->get('migration')->rollbackExtension('{NS}', __DIR__ . '/src/Migrations', '0');

                    if (!$result['success']) {
                        throw new \RuntimeException('Extension rollback failed: ' . ($result['error'] ?? 'unknown error'));
                    }
                },
            ];
            PHP;
    }

    private function partialRollbackUninstallScript(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'uninstall' => function ($app) {
                    $result = $app->get('migration')->rollbackExtension(
                        '{NS}',
                        __DIR__ . '/src/Migrations',
                        '{NS}\\Version20250101000001_CreateExtTable'
                    );

                    if (!$result['success']) {
                        throw new \RuntimeException('Extension rollback failed: ' . ($result['error'] ?? 'unknown error'));
                    }
                },
            ];
            PHP;
    }

    private function writeMigrationV1(): void
    {
        file_put_contents(
            $this->migrationsDir . '/Version20250101000001_CreateExtTable.php',
            str_replace('{NS}', $this->namespace, <<<'MIG'
                <?php
                declare(strict_types=1);
                namespace {NS};

                use Doctrine\DBAL\Schema\Schema;
                use Doctrine\Migrations\AbstractMigration;

                final class Version20250101000001_CreateExtTable extends AbstractMigration
                {
                    public function getDescription(): string
                    {
                        return 'Create test_ext_items table';
                    }

                    public function up(Schema $schema): void
                    {
                        $t = $schema->createTable('test_ext_items');
                        $t->addColumn('id', 'integer', ['autoincrement' => true]);
                        $t->addColumn('title', 'string', ['length' => 255]);
                        $t->setPrimaryKey(['id']);
                    }

                    public function down(Schema $schema): void
                    {
                        $schema->dropTable('test_ext_items');
                    }
                }
                MIG),
        );
    }

    private function writeMigrationV2(): void
    {
        file_put_contents(
            $this->migrationsDir . '/Version20250102000001_CreateExtSettings.php',
            str_replace('{NS}', $this->namespace, <<<'MIG'
                <?php
                declare(strict_types=1);
                namespace {NS};

                use Doctrine\DBAL\Schema\Schema;
                use Doctrine\Migrations\AbstractMigration;

                final class Version20250102000001_CreateExtSettings extends AbstractMigration
                {
                    public function getDescription(): string
                    {
                        return 'Create test_ext_settings table';
                    }

                    public function up(Schema $schema): void
                    {
                        $t = $schema->createTable('test_ext_settings');
                        $t->addColumn('id', 'integer', ['autoincrement' => true]);
                        $t->addColumn('key', 'string', ['length' => 255]);
                        $t->addColumn('value', 'text');
                        $t->setPrimaryKey(['id']);
                    }

                    public function down(Schema $schema): void
                    {
                        $schema->dropTable('test_ext_settings');
                    }
                }
                MIG),
        );
    }

    private function recursiveRemove(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path)) {
            unlink($path);

            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $item) {
            $this->recursiveRemove($path . '/' . $item);
        }
        rmdir($path);
    }
}
