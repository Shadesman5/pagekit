<?php

declare(strict_types=1);

namespace Pagekit\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Pagekit\Migration\MigrationService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Pagekit\Migration\MigrationService
 */
class MigrationServiceTest extends TestCase
{
    private static int $counter = 0;

    private Connection $connection;
    private MigrationService $service;
    private string $baseDir;
    private string $coreMigrationsDir;
    private string $extMigrationsDir;
    private string $coreNamespace;
    private string $extNamespace;

    protected function setUp(): void
    {
        self::$counter++;

        $this->coreNamespace = 'TestCoreMig' . self::$counter;
        $this->extNamespace  = 'TestExtMig' . self::$counter;

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->baseDir           = sys_get_temp_dir() . '/pk_mig_test_' . self::$counter . '_' . getmypid();
        $this->coreMigrationsDir = $this->baseDir . '/core';
        $this->extMigrationsDir  = $this->baseDir . '/ext';
        mkdir($this->coreMigrationsDir, 0755, true);
        mkdir($this->extMigrationsDir, 0755, true);

        $this->writeCoreMigration();

        $this->service = new MigrationService($this->connection, [
            'table_storage' => [
                'table_name'               => 'test_migration_versions',
                'version_column_name'      => 'version',
                'version_column_length'    => 191,
                'executed_at_column_name'  => 'executed_at',
                'execution_time_column_name' => 'execution_time',
            ],
            'migrations_paths' => [
                $this->coreNamespace => $this->coreMigrationsDir,
            ],
            'all_or_nothing'         => false,
            'check_database_platform' => false,
            'organize_migrations'    => 'none',
        ]);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->recursiveRemove($this->baseDir);
    }

    public function testMigrateRunsPendingMigrations(): void
    {
        $result = $this->service->migrate();

        self::assertTrue($result['success'], $result['error'] ?? '');
        self::assertGreaterThan(0, $result['executed']);
        self::assertTrue(
            $this->connection->createSchemaManager()->tablesExist(['test_core_items']),
        );
    }

    public function testRollbackRevertsLastMigration(): void
    {
        $up = $this->service->migrate();
        self::assertTrue($up['success'], $up['error'] ?? '');
        self::assertTrue(
            $this->connection->createSchemaManager()->tablesExist(['test_core_items']),
        );

        $down = $this->service->rollback('0');

        self::assertTrue($down['success'], $down['error'] ?? '');
        self::assertGreaterThan(0, $down['executed']);
        self::assertFalse(
            $this->connection->createSchemaManager()->tablesExist(['test_core_items']),
        );
    }

    public function testStatusReturnsPendingCount(): void
    {
        $status = $this->service->status();

        self::assertTrue($status['success'], $status['error'] ?? '');
        self::assertTrue($status['has_pending']);
        self::assertCount(1, $status['available']);
    }

    public function testMigrateExtensionRunsExtensionMigrations(): void
    {
        $this->writeExtensionMigration();

        $result = $this->service->migrateExtension(
            $this->extNamespace,
            $this->extMigrationsDir,
        );

        self::assertTrue($result['success'], $result['error'] ?? '');
        self::assertGreaterThan(0, $result['executed']);
        self::assertTrue(
            $this->connection->createSchemaManager()->tablesExist(['test_ext_items']),
        );
    }

    public function testRollbackExtensionOnlyAffectsExtensionNamespace(): void
    {
        $this->writeExtensionMigration();

        $core = $this->service->migrate();
        self::assertTrue($core['success'], $core['error'] ?? '');

        $ext = $this->service->migrateExtension(
            $this->extNamespace,
            $this->extMigrationsDir,
        );
        self::assertTrue($ext['success'], $ext['error'] ?? '');

        $sm = $this->connection->createSchemaManager();
        self::assertTrue($sm->tablesExist(['test_core_items']));
        self::assertTrue($sm->tablesExist(['test_ext_items']));

        $rollback = $this->service->rollbackExtension(
            $this->extNamespace,
            $this->extMigrationsDir,
            '0',
        );
        self::assertTrue($rollback['success'], $rollback['error'] ?? '');

        self::assertTrue(
            $this->connection->createSchemaManager()->tablesExist(['test_core_items']),
            'Core table must survive extension rollback',
        );
        self::assertFalse(
            $this->connection->createSchemaManager()->tablesExist(['test_ext_items']),
            'Extension table must be dropped after extension rollback',
        );
    }

    public function testMigrateWithNoMigrationsReturnsZeroExecuted(): void
    {
        $first = $this->service->migrate();
        self::assertTrue($first['success'], $first['error'] ?? '');

        $second = $this->service->migrate();

        self::assertTrue($second['success'], $second['error'] ?? '');
        self::assertSame(0, $second['executed']);
    }

    // ---- helpers ----

    private function writeCoreMigration(): void
    {
        $ns = $this->coreNamespace;
        $php = str_replace(
            '{NS}',
            $ns,
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
MIG
        );

        file_put_contents(
            $this->coreMigrationsDir . '/Version20250101000001_CreateTestTable.php',
            $php,
        );
    }

    private function writeExtensionMigration(): void
    {
        $ns = $this->extNamespace;
        $php = str_replace(
            '{NS}',
            $ns,
            <<<'MIG'
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
MIG
        );

        file_put_contents(
            $this->extMigrationsDir . '/Version20250101000001_CreateExtTable.php',
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
