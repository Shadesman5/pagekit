<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Installer\Package\Package;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\Migration\MigrationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The schema a package declares, and what happens to it when the attempt that
 * applied it does not finish.
 *
 * A package names where its migrations live and the installation runs them.
 * That is what makes an activation reversible: the installation knows where the
 * schema stood before it started, so an attempt that fails halfway can be taken
 * back to that point instead of leaving tables behind that no version of the
 * package matches. An extension running its own migrations from a hook could
 * only ever leave them there.
 *
 * The schema goes back first and the configuration after it, whatever the first
 * of the two ran into: what is recorded as installed and enabled is what the
 * next boot reads. And the recovery itself may not throw - the failure that
 * started it is the one the administrator has to act on, so a second one is
 * reported and swallowed rather than raised in its place.
 *
 * The migrations here are real ones against an in-memory database, so what a
 * failed attempt left behind is read off the schema rather than off a count of
 * calls. The service is configured without all-or-nothing, which is how a
 * database that commits schema changes as it goes behaves - MySQL does, and
 * that is the case where unwinding the attempt is the only thing that removes
 * what it applied.
 */
final class PackageSchemaTest extends TestCase
{
    private static int $counter = 0;

    private Connection $connection;

    private MigrationService $migration;

    /**
     * The namespace this test's migration classes are declared in, unique per
     * test because a class is loaded into the process once.
     */
    private string $namespace;

    private string $workspace;

    /**
     * The package on disk, whose lifecycle file and migrations each test writes.
     */
    private string $packageDir;

    private string $migrationsDir;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        ++self::$counter;

        $this->namespace = 'PkgSchemaTestMig' . self::$counter;
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_package_schema_' . getmypid() . '_' . self::$counter;
        $this->packageDir = $this->workspace . '/packages/pagekit/test-ext';
        $this->migrationsDir = $this->packageDir . '/src/Migrations';

        mkdir($this->migrationsDir, 0755, true);
        mkdir($this->workspace . '/core', 0755, true);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migration = $this->migrationService();

        $this->writeManifest('1.0.0');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // Running the schema a package declares
    // ------------------------------------------------------------------

    public function testAPackagesInstallHookRunsAgainstTheSchemaItsMigrationsCreated(): void
    {
        $this->writeMigrationCreatingItems();
        $this->writeLifecycle(schema: true, hook: 'install', body: "\$app->get('db')->insert('test_ext_items', ['title' => 'seeded']);");

        $system = new Config();

        $this->manager($this->container($system, $this->migration))->enable($this->package());

        // The migrations run before the hook does, which is the order a hook
        // that seeds rows needs: the other way round it writes into tables that
        // do not exist yet.
        self::assertSame('seeded', $this->connection->fetchOne('SELECT title FROM test_ext_items'));
        self::assertSame('1.0.0', $system->get('packages.test-ext'));
        self::assertContains('test-ext', (array) $system->get('extensions'));
    }

    public function testAMigrationThatFailsHalfwayLeavesNothingItAppliedBehind(): void
    {
        $this->writeMigrationCreatingItems();
        $this->writeMigrationThatFailsOnTheWayUp();
        $this->writeLifecycle(schema: true, hook: 'enable', body: '');

        $system = new Config();

        $thrown = $this->failureOf($this->container($system, $this->migration));

        // The first migration is committed by the time the second one fails, so
        // the table it created outlives the plan it was part of. Taking the
        // attempt back is what removes it, and it has to: the next attempt runs
        // the same plan from the start and would find the table already there.
        self::assertFalse($this->connection->createSchemaManager()->tablesExist(['test_ext_items']));

        self::assertStringContainsString('Test Extension', $thrown->getMessage());
        self::assertStringContainsString('The second migration cannot run here', $thrown->getMessage());
        self::assertNull($system->get('packages.test-ext'));
        self::assertSame([], (array) $system->get('extensions'));
    }

    public function testAPackageIsStillTakenIntoUseWhereNothingCanRunItsSchema(): void
    {
        $this->writeMigrationCreatingItems();
        $this->writeLifecycle(schema: true, hook: 'enable', body: '');

        $system = new Config();

        // The manager is built over whatever container the operation runs in,
        // and a container with no migration service is one with no database
        // behind it. Reaching for it anyway is how an optional collaborator
        // turns into an operation that fails on a service that was never there.
        $this->manager($this->container($system, null))->enable($this->package());

        self::assertSame('1.0.0', $system->get('packages.test-ext'));
        self::assertFalse($this->connection->createSchemaManager()->tablesExist(['test_ext_items']));
    }

    // ------------------------------------------------------------------
    // Unwinding what one failed attempt applied
    // ------------------------------------------------------------------

    public function testAnActivationThatFailsTakesTheSchemaItAppliedWithIt(): void
    {
        $this->writeMigrationCreatingItems();
        $this->writeMigrationCreatingSettings();
        $this->writeLifecycle(schema: true, hook: 'enable', body: "throw new \\RuntimeException('Broken on the way in');");

        $system = new Config();

        $thrown = $this->failureOf($this->container($system, $this->migration));

        // Installing the package migrated it and switching it on migrated it
        // again, and everything both runs applied has to go. Where that is
        // measured from is the point: from the schema the attempt started with,
        // not from the one its own first run left, which would leave the tables
        // of the installation standing under a package that is not installed.
        $schema = $this->connection->createSchemaManager();

        self::assertFalse($schema->tablesExist(['test_ext_items']));
        self::assertFalse($schema->tablesExist(['test_ext_settings']));

        self::assertNull($system->get('packages.test-ext'));
        self::assertSame([], (array) $system->get('extensions'));

        // Schema and configuration go back together: an extension recorded as
        // installed whose tables were just dropped is the state the rollback
        // exists to avoid.
        self::assertStringContainsString('Broken on the way in', $thrown->getMessage());
        self::assertSame('Broken on the way in', $thrown->getPrevious()?->getMessage());
    }

    public function testTheSchemaTheInstallationAlreadyHadIsLeftStanding(): void
    {
        $this->writeMigrationCreatingItems();

        $applied = $this->migration->migrateExtension($this->namespace, $this->migrationsDir);

        self::assertTrue($applied['success'], (string) ($applied['error'] ?? ''));

        // The package is installed and switched off, and the version being
        // switched on now brings a second migration with it.
        $this->writeManifest('2.0.0');
        $this->writeMigrationCreatingSettings();
        $this->writeLifecycle(schema: true, hook: 'enable', body: "throw new \\RuntimeException('Broken on the way in');");

        $system = new Config();
        $system->set('packages.test-ext', '1.0.0');

        $this->failureOf($this->container($system, $this->migration));

        // Only what this attempt applied comes back off. The schema the
        // installation was already running on is not this attempt's to unwind -
        // dropping it would take the extension's existing data with it, over a
        // version that never came into use.
        $schema = $this->connection->createSchemaManager();

        self::assertTrue($schema->tablesExist(['test_ext_items']));
        self::assertFalse($schema->tablesExist(['test_ext_settings']));

        self::assertSame('1.0.0', $system->get('packages.test-ext'));
        self::assertSame([], (array) $system->get('extensions'));
    }

    public function testAnAttemptThatMigratedNothingHasNothingUnwoundForIt(): void
    {
        $migration = new RecordedSchemaRuns($this->connection);

        $this->writeLifecycle(schema: false, hook: 'enable', body: "throw new \\RuntimeException('Broken on the way in');");

        $system = new Config();

        $thrown = $this->failureOf($this->container($system, $migration));

        // Most extensions ship no schema at all. A recovery that went looking
        // for migrations to unwind anyway would be rolling an extension back to
        // "nothing executed" over an activation that never touched a table.
        self::assertSame([], $migration->snapshots);
        self::assertSame([], $migration->migrated);
        self::assertSame([], $migration->rolledBack);

        self::assertSame('Broken on the way in', $thrown->getPrevious()?->getMessage());
        self::assertNull($system->get('packages.test-ext'));
        self::assertSame([], (array) $system->get('extensions'));
    }

    // ------------------------------------------------------------------
    // When the recovery itself fails
    // ------------------------------------------------------------------

    public function testARollbackThatFailsIsReportedTogetherWithWhatCausedIt(): void
    {
        $this->writeMigrationCreatingItems(down: "throw new \\RuntimeException('The table is in use');");
        $this->writeLifecycle(schema: true, hook: 'enable', body: "throw new \\RuntimeException('Broken on the way in');");

        $system = new Config();
        $log = new RecordedSchemaLog();

        $app = $this->container($system, $this->migration);
        $app->set('log', $log);

        $thrown = $this->failureOf($app);

        // The recovery ran into a second failure. Raising it would put it in
        // the place of the one the administrator has to act on, and would leave
        // the configuration unrolled behind it, so it is reported instead.
        self::assertStringContainsString('Broken on the way in', $thrown->getMessage());
        self::assertSame('Broken on the way in', $thrown->getPrevious()?->getMessage());
        self::assertNull($system->get('packages.test-ext'));
        self::assertSame([], (array) $system->get('extensions'));

        // What stays behind is a schema nobody unwound, and the report is all
        // there is to find it by: it has to name the package, where the schema
        // should have gone back to, and what stopped it.
        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['test_ext_items']));

        self::assertCount(2, $log->records);
        self::assertStringContainsString('Failed to roll the schema of package "pagekit/test-ext" back to "0"', $log->records[0]['message']);
        self::assertStringContainsString('The table is in use', $log->records[0]['message']);
        self::assertSame('test-ext', $log->records[0]['context']['package'] ?? null);

        // Both failures travel with the report, because either of them on its
        // own reads as something that did not happen: a package that refused to
        // start, or a rollback of an activation that was going fine.
        $cause = $log->records[0]['context']['exception'] ?? null;

        self::assertInstanceOf(\RuntimeException::class, $cause);
        self::assertSame('Broken on the way in', $cause->getMessage());
        self::assertArrayHasKey('rollback', $log->records[0]['context']);
        self::assertNull(
            $log->records[0]['context']['rollback'],
            'The migration service reported this failure rather than raising it, so there is no second throwable to carry',
        );

        // The activation's own failure is still reported after it.
        self::assertStringContainsString('Failed to enable package "pagekit/test-ext"', $log->records[1]['message']);
    }

    public function testARollbackThatBreaksOnAnErrorIsReportedTheSameWay(): void
    {
        // A migration whose class went missing underneath it fails on an Error,
        // which the migration service does not catch - it reports what it
        // caught, and an Error is not that. The recovery is the only thing
        // between it and the caller.
        $this->writeMigrationCreatingItems(down: '\Pagekit\Absent\Vanished::gone();');
        $this->writeLifecycle(schema: true, hook: 'enable', body: "throw new \\RuntimeException('Broken on the way in');");

        $system = new Config();
        $log = new RecordedSchemaLog();

        $app = $this->container($system, $this->migration);
        $app->set('log', $log);

        $thrown = $this->failureOf($app);

        self::assertSame('Broken on the way in', $thrown->getPrevious()?->getMessage());
        self::assertNull($system->get('packages.test-ext'));

        self::assertStringContainsString('Failed to roll the schema of package "pagekit/test-ext"', $log->records[0]['message']);
        self::assertStringContainsString('Vanished', $log->records[0]['message']);
        self::assertInstanceOf(\Error::class, $log->records[0]['context']['rollback'] ?? null);
    }

    public function testNowhereToReportIsNoReasonToLeaveTheRecoveryUnfinished(): void
    {
        $this->writeMigrationCreatingItems(down: "throw new \\RuntimeException('The table is in use');");
        $this->writeLifecycle(schema: true, hook: 'enable', body: "throw new \\RuntimeException('Broken on the way in');");

        $system = new Config();

        // Reporting is the last thing the recovery does and the least of what
        // it owes. A container that has nowhere to report to still gets the
        // configuration rolled back and still hears why the package refused.
        $thrown = $this->failureOf($this->container($system, $this->migration));

        self::assertNull($system->get('packages.test-ext'));
        self::assertSame([], (array) $system->get('extensions'));
        self::assertSame('Broken on the way in', $thrown->getPrevious()?->getMessage());
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * The failure an activation reports, captured rather than expected.
     *
     * A failed assertion is itself a RuntimeException, so asserting inside the
     * catch would swallow the report of it.
     */
    private function failureOf(Application $app): \RuntimeException
    {
        try {
            $this->manager($app)->enable($this->package());
        } catch (\RuntimeException $e) {
            return $e;
        }

        self::fail('An activation that fails has to be reported to whoever asked for it');
    }

    /**
     * The container a package operation runs in: what it records the
     * installation in, the database its schema is applied to, and the service
     * that applies it.
     *
     * @param MigrationService|null $migration the service to run the schema through, or null for a container that has none
     */
    private function container(Config $system, ?MigrationService $migration): Application
    {
        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($system);

        $app = new Application();
        $app->set('config', $config);
        $app->set('db', $this->connection);

        if ($migration !== null) {
            $app->set('migration', $migration);
        }

        return $app;
    }

    private function manager(Application $app): PackageManager
    {
        return new PackageManager($app, new NullOutput());
    }

    private function package(): Package
    {
        return new Package([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'module' => 'test-ext',
            'title' => 'Test Extension',
            'path' => $this->packageDir,
            'extra' => ['scripts' => 'scripts.php'],
        ]);
    }

    /**
     * A migration service over the test's database, keeping its bookkeeping in
     * a table of its own.
     *
     * All-or-nothing is off: it is what a database that cannot unwind schema
     * changes in a transaction leaves the framework with, and the case the
     * rollback is there for.
     */
    private function migrationService(): MigrationService
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
                $this->namespace . 'Core' => $this->workspace . '/core',
            ],
            'all_or_nothing' => false,
            'check_database_platform' => false,
            'organize_migrations' => 'none',
        ]);
    }

    private function writeManifest(string $version): void
    {
        file_put_contents($this->packageDir . '/composer.json', (string) json_encode([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'version' => $version,
        ]));
    }

    /**
     * The package's lifecycle file: what it declares about its schema, and the
     * one hook a test drives.
     *
     * @param bool   $schema whether the package declares where its migrations live
     * @param string $hook   the hook the package overrides
     * @param string $body   what that hook does
     */
    private function writeLifecycle(bool $schema, string $hook, string $body): void
    {
        $declaration = '';

        if ($schema) {
            $declaration = <<<'PHP'
                    public function migrations(): ?MigrationSet
                    {
                        return new MigrationSet('{NS}', __DIR__ . '/src/Migrations');
                    }

                PHP;
        }

        file_put_contents(
            $this->packageDir . '/scripts.php',
            str_replace(
                ['{MIGRATIONS}', '{NS}', '{HOOK}', '{BODY}'],
                [$declaration, $this->namespace, $hook, $this->indent($body, 8)],
                <<<'PHP'
                    <?php

                    declare(strict_types=1);

                    use Pagekit\Installer\Package\Lifecycle\MigrationSet;
                    use Pagekit\Installer\Package\Lifecycle\PackageLifecycle;
                    use Psr\Container\ContainerInterface;

                    return new class () extends PackageLifecycle {
                    {MIGRATIONS}
                        public function {HOOK}(ContainerInterface $app): void
                        {
                    {BODY}
                        }
                    };
                    PHP,
            ),
        );
    }

    private function writeMigrationCreatingItems(?string $down = null): void
    {
        $this->writeMigration(
            'Version20250101000001_CreateItems',
            <<<'PHP'
                $table = $schema->createTable('test_ext_items');
                $table->addColumn('id', 'integer', ['autoincrement' => true]);
                $table->addColumn('title', 'string', ['length' => 255]);
                $table->setPrimaryKey(['id']);
                PHP,
            $down ?? "\$schema->dropTable('test_ext_items');",
        );
    }

    private function writeMigrationCreatingSettings(): void
    {
        $this->writeMigration(
            'Version20250102000001_CreateSettings',
            <<<'PHP'
                $table = $schema->createTable('test_ext_settings');
                $table->addColumn('id', 'integer', ['autoincrement' => true]);
                $table->addColumn('value', 'string', ['length' => 255]);
                $table->setPrimaryKey(['id']);
                PHP,
            "\$schema->dropTable('test_ext_settings');",
        );
    }

    private function writeMigrationThatFailsOnTheWayUp(): void
    {
        $this->writeMigration(
            'Version20250102000001_Broken',
            "throw new \\RuntimeException('The second migration cannot run here');",
            '',
        );
    }

    /**
     * One migration class in the package's migrations directory.
     */
    private function writeMigration(string $class, string $up, string $down): void
    {
        file_put_contents(
            $this->migrationsDir . '/' . $class . '.php',
            str_replace(
                ['{NS}', '{CLASS}', '{UP}', '{DOWN}'],
                [$this->namespace, $class, $this->indent($up, 8), $this->indent($down, 8)],
                <<<'PHP'
                    <?php

                    declare(strict_types=1);

                    namespace {NS};

                    use Doctrine\DBAL\Schema\Schema;
                    use Doctrine\Migrations\AbstractMigration;

                    final class {CLASS} extends AbstractMigration
                    {
                        public function up(Schema $schema): void
                        {
                    {UP}
                        }

                        public function down(Schema $schema): void
                        {
                    {DOWN}
                        }
                    }
                    PHP,
            ),
        );
    }

    private function indent(string $body, int $spaces): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : str_repeat(' ', $spaces) . $line,
            explode("\n", $body),
        ));
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
 * What a package operation asked of the migration service, in place of a
 * service that would run any of it.
 *
 * The service is a class rather than an interface and the manager takes it as
 * one, so the stand-in is one too - over the test's own connection, which it
 * never touches.
 */
final class RecordedSchemaRuns extends MigrationService
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $snapshots = [];

    /** @var array<int, array{0: string, 1: string}> */
    public array $migrated = [];

    /** @var array<int, array{0: string, 1: string, 2: string|null}> */
    public array $rolledBack = [];

    public function getExtensionCurrentVersion(string $namespace, string $path): string
    {
        $this->snapshots[] = [$namespace, $path];

        return '0';
    }

    /**
     * @return array<string, mixed>
     */
    public function migrateExtension(string $namespace, string $path, ?string $version = null): array
    {
        $this->migrated[] = [$namespace, $path];

        return ['success' => true, 'executed' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    public function rollbackExtension(string $namespace, string $path, ?string $version = null): array
    {
        $this->rolledBack[] = [$namespace, $path, $version];

        return ['success' => true];
    }
}

/**
 * What was reported, with the context that carries both failures.
 */
final class RecordedSchemaLog
{
    /** @var array<int, array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->records[] = ['message' => $message, 'context' => $context];
    }
}
