<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Console\Commands\MigrationCommand;
use Pagekit\Migration\MigrationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CLI integration tests for the unified `migrate` command.
 *
 * MigrationCommand drives the two-stage update pipeline the CLI exposes:
 *   1. Doctrine migrations — MigrationService::migrate() against the core paths;
 *   2. the version-keyed updates the system's lifecycle file declares.
 * Only once BOTH stages succeed does it write the new version to config — the
 * "version-bump guard" introduced to stop silent version bumps on a failed
 * migration or a throwing update hook.
 *
 * The happy path runs a real MigrationService over in-memory SQLite (the pattern
 * from tests/Unit/Migration/MigrationServiceTest.php) plus a real scripts.php, so
 * table existence + a script side-effect marker are the assertions. The guard
 * branches assert the recorded version is left untouched when either stage fails.
 * The command is exercised through Symfony's CommandTester (real run() lifecycle,
 * captured output, real exit code).
 */
class MigrationCommandTest extends TestCase
{
    private static int $counter = 0;

    private Connection $connection;
    private string $baseDir;
    private string $systemDir;
    private string $coreMigrationsDir;
    private string $coreNamespace;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        self::$counter++;
        $this->coreNamespace = 'ConsoleMigTestCore' . self::$counter;

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->baseDir = sys_get_temp_dir() . '/pk_migrate_cmd_test_' . self::$counter . '_' . getmypid();
        $this->systemDir = $this->baseDir . '/app/system';
        $this->coreMigrationsDir = $this->baseDir . '/core';
        mkdir($this->systemDir, 0755, true);
        mkdir($this->coreMigrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->recursiveRemove($this->baseDir);
    }

    // ------------------------------------------------------------------
    // Happy path — Doctrine migrations + scripts pipeline, then version bump
    // ------------------------------------------------------------------

    public function testHappyPathMigratesRunsScriptUpdatesAndBumpsVersion(): void
    {
        $this->writeCoreMigration();
        $this->writeSystemScripts($this->markerUpdateScript());

        $config = new Config();
        $config->set('version', '1.0.0');

        $tester = $this->runMigrateCommand([
            'migration' => $this->makeMigrationService(),
            'config' => $this->configService($config),
            'path' => $this->baseDir,
            'version' => '2.0.0',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertStringContainsString('Executed 1 Doctrine migration(s).', $display);
        self::assertStringContainsString('updated successfully', $display);

        // Stage 1: the pending Doctrine migration executed.
        self::assertTrue(
            $this->connection->createSchemaManager()->tablesExist(['test_core_items']),
            'The pending Doctrine migration must be executed by the migrate command',
        );
        // Stage 2: the version-gated lifecycle update fired.
        self::assertFileExists($this->systemDir . '/update-ran.marker');
        // Version is bumped to the app version only after both stages succeed.
        self::assertSame('2.0.0', $config->get('version'));
    }

    public function testUpToDateWhenNoMigrationsAndNoScriptUpdates(): void
    {
        $this->writeCoreMigration();
        // Update keyed BELOW the recorded version → the version-bump guard filters
        // it out, so the runner reports nothing to run.
        $this->writeSystemScripts($this->staleUpdateScript());

        $migration = $this->makeMigrationService();
        // Apply the pending migration up-front so the command sees a clean slate
        // (0 executed) and takes the "up to date" branch.
        $pre = $migration->migrate();
        self::assertTrue($pre['success'], (string) ($pre['error'] ?? ''));

        $config = new Config();
        $config->set('version', '2.0.0');

        $tester = $this->runMigrateCommand([
            'migration' => $migration,
            'config' => $this->configService($config),
            'path' => $this->baseDir,
            'version' => '2.0.0',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('up to date', $tester->getDisplay());
        self::assertFileDoesNotExist($this->systemDir . '/stale-update-ran.marker');
        self::assertSame('2.0.0', $config->get('version'));
    }

    // ------------------------------------------------------------------
    // Version-bump guard — a failing stage must never advance the version
    // ------------------------------------------------------------------

    public function testScriptUpdateFailureGuardsAgainstVersionBump(): void
    {
        $this->writeCoreMigration();
        $this->writeSystemScripts($this->throwingUpdateScript());

        $config = new Config();
        $config->set('version', '1.0.0');

        $tester = $this->runMigrateCommand([
            'migration' => $this->makeMigrationService(),
            'config' => $this->configService($config),
            'path' => $this->baseDir,
            'version' => '2.0.0',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Script update failed: boom during script update', $tester->getDisplay());

        // The migration ran, but the throwing update hook must guard the bump:
        // the recorded version stays at its pre-update value.
        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['test_core_items']));
        self::assertSame('1.0.0', $config->get('version'));
    }

    public function testDoctrineMigrationFailureGuardsAgainstVersionBump(): void
    {
        $config = new Config();
        $config->set('version', '1.0.0');

        $tester = $this->runMigrateCommand([
            'migration' => $this->failingMigrationService('doctrine exploded'),
            'config' => $this->configService($config),
            'path' => $this->baseDir,
            'version' => '2.0.0',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Doctrine Migration failed: doctrine exploded', $tester->getDisplay());
        // A failed Doctrine migration must short-circuit before the version bump.
        self::assertSame('1.0.0', $config->get('version'));
    }

    public function testMissingMigrationServiceFails(): void
    {
        $tester = $this->runMigrateCommand([
            'version' => '2.0.0',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Migration service not available', $tester->getDisplay());
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Build the command on a real Application container seeded with the given
     * services, then run it through a CommandTester.
     *
     * @param array<string, mixed> $services
     */
    private function runMigrateCommand(array $services): CommandTester
    {
        $app = new Application();
        foreach ($services as $id => $value) {
            $app->set($id, $value);
        }

        $tester = new CommandTester(new MigrationCommand($app));
        $tester->execute([]);

        return $tester;
    }

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
                $this->coreNamespace => $this->coreMigrationsDir,
            ],
            'all_or_nothing' => false,
            'check_database_platform' => false,
            'organize_migrations' => 'none',
        ]);
    }

    /**
     * A stand-in `migration` service whose migrate() reports failure, exercising
     * the Doctrine-migration guard branch without a contrived real failure.
     */
    private function failingMigrationService(string $error): object
    {
        return new class ($error) {
            public function __construct(private string $error)
            {
            }

            /**
             * @return array<string, mixed>
             */
            public function migrate(?string $version = null, bool $dryRun = false): array
            {
                return ['success' => false, 'error' => $this->error];
            }
        };
    }

    private function configService(Config $config): ConfigManager
    {
        $manager = $this->createMock(ConfigManager::class);
        $manager->method('__invoke')->willReturn($config);

        return $manager;
    }

    private function writeSystemScripts(string $php): void
    {
        file_put_contents($this->systemDir . '/scripts.php', $php);
    }

    private function markerUpdateScript(): string
    {
        return $this->updateScript('2.0.0', "file_put_contents(__DIR__ . '/update-ran.marker', 'ok');");
    }

    private function throwingUpdateScript(): string
    {
        return $this->updateScript('2.0.0', "throw new \\RuntimeException('boom during script update');");
    }

    private function staleUpdateScript(): string
    {
        return $this->updateScript('1.0.0', "file_put_contents(__DIR__ . '/stale-update-ran.marker', 'should-not-run');");
    }

    /**
     * A lifecycle file declaring one update under the given version, which is
     * what the command reads app/system/scripts.php as.
     */
    private function updateScript(string $version, string $body): string
    {
        return str_replace(
            ['{VERSION}', '{BODY}'],
            [$version, $body],
            <<<'PHP'
                <?php

                declare(strict_types=1);

                use Pagekit\Installer\Package\Lifecycle\PackageLifecycle;
                use Psr\Container\ContainerInterface;

                return new class () extends PackageLifecycle {
                    public function updates(): array
                    {
                        return [
                            '{VERSION}' => function (ContainerInterface $app): void {
                                {BODY}
                            },
                        ];
                    }
                };
                PHP,
        );
    }

    private function writeCoreMigration(): void
    {
        $php = str_replace(
            '{NS}',
            $this->coreNamespace,
            <<<'MIG'
                <?php
                declare(strict_types=1);
                namespace {NS};

                use Doctrine\DBAL\Schema\Schema;
                use Doctrine\Migrations\AbstractMigration;

                final class Version20250101000001_CreateTestTable extends AbstractMigration
                {
                    public function getDescription(): string
                    {
                        return 'Create test_core_items table';
                    }

                    public function up(Schema $schema): void
                    {
                        $t = $schema->createTable('test_core_items');
                        $t->addColumn('id', 'integer', ['autoincrement' => true]);
                        $t->addColumn('name', 'string', ['length' => 255]);
                        $t->setPrimaryKey(['id']);
                    }

                    public function down(Schema $schema): void
                    {
                        $schema->dropTable('test_core_items');
                    }
                }
                MIG,
        );

        file_put_contents(
            $this->coreMigrationsDir . '/Version20250101000001_CreateTestTable.php',
            $php,
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
