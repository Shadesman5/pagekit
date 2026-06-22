<?php

declare(strict_types=1);

namespace Pagekit\Database;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Constraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;

class Utility
{
    protected \Pagekit\Database\Connection $connection;

    /** @var AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform> */
    protected AbstractSchemaManager $manager;

    protected Schema $schema;

    /**
     * Constructor.
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->manager = $this->connection->getSchemaManager();
        $this->schema = $this->manager->createSchema();
    }

    /**
     * Return the DBAL schema manager.
     *
     * @return AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform>
     */
    public function getSchemaManager(): AbstractSchemaManager
    {
        return $this->manager;
    }

    /**
     * Returns true if the given table exists.
     */
    public function tableExists(string $table): bool
    {
        return $this->tablesExist($table);
    }

    /**
     * Returns an existing database table.
     *
     * @throws SchemaException
     */
    public function getTable(string $table): Table
    {
        return new Table($this->schema->getTable($this->replacePrefix($table)), $this->connection);
    }

    /**
     * Returns true if all the given tables exist.
     *
     * @param string|array<int, string> $tables
     */
    public function tablesExist(string|array $tables): bool
    {
        $tables = array_map(fn ($query) => $this->replacePrefix($query), (array) $tables);

        return $this->manager->tablesExist($tables);
    }

    /**
     * Creates a new database table.
     */
    public function createTable(string $table, \Closure $callback): void
    {
        $table = $this->schema->createTable($this->replacePrefix($table));

        $callback(new Table($table, $this->connection));

        $this->manager->createTable($table);
    }

    /**
     * {@see AbstractSchemaManager::createConstraint}
     */
    public function createConstraint(Constraint $constraint, string $table): void
    {
        $this->manager->createConstraint($constraint, $this->replacePrefix($table));
    }

    /**
     * {@see AbstractSchemaManager::createIndex}
     */
    public function createIndex(Index $index, string $table): void
    {
        $this->manager->createIndex($index, $this->replacePrefix($table));
    }

    /**
     * {@see AbstractSchemaManager::createForeignKey}
     */
    public function createForeignKey(ForeignKeyConstraint $foreignKey, string $table): void
    {
        $this->manager->createForeignKey($foreignKey, $this->replacePrefix($table));
    }

    /**
     * {@see AbstractSchemaManager::dropAndCreateConstraint}
     */
    public function dropAndCreateConstraint(Constraint $constraint, string $table): void
    {
        $this->manager->dropAndCreateConstraint($constraint, $this->replacePrefix($table));
    }

    /**
     * {@see AbstractSchemaManager::dropAndCreateIndex}
     */
    public function dropAndCreateIndex(Index $index, string $table): void
    {
        $this->manager->dropAndCreateIndex($index, $this->replacePrefix($table));
    }

    /**
     * {@see AbstractSchemaManager::dropAndCreateForeignKey}
     */
    public function dropAndCreateForeignKey(ForeignKeyConstraint $foreignKey, string $table): void
    {
        $this->manager->dropAndCreateForeignKey($foreignKey, $this->replacePrefix($table));
    }

    /**
     * {@see AbstractSchemaManager::dropAndCreateTable}
     */
    public function dropAndCreateTable(\Doctrine\DBAL\Schema\Table $table): void
    {
        $this->manager->dropAndCreateTable($table);
    }

    /**
     * {@see AbstractSchemaManager::renameTable}
     */
    public function renameTable(string $name, string $newName): void
    {
        $this->manager->renameTable($this->replacePrefix($name), $this->replacePrefix($newName));
    }

    /**
     * @see AbstractSchemaManager::dropTable
     */
    public function dropTable(string $table): void
    {
        $this->manager->dropTable($this->replacePrefix($table));
    }

    /**
     * {@see AbstractSchemaManager::dropIndex}
     */
    public function dropIndex(string $index, string $table): void
    {
        $this->manager->dropIndex($index, $this->replacePrefix($table));
    }

    /**
     * {@see AbstractSchemaManager::dropConstraint}
     */
    public function dropConstraint(Constraint $constraint, string $table): void
    {
        $this->manager->dropConstraint($constraint, $this->replacePrefix($table));
    }

    /**
     * {@see AbstractSchemaManager::dropForeignKey}
     *
     * @param ForeignKeyConstraint|string $foreignKey
     */
    public function dropForeignKey(ForeignKeyConstraint|string $foreignKey, string $table): void
    {
        $this->manager->dropForeignKey($foreignKey, $this->replacePrefix($table));
    }

    /**
     * {@see AbstractSchemaManager::listTableColumns}
     *
     * @return array<string, \Doctrine\DBAL\Schema\Column>
     */
    public function listTableColumns(string $table, ?string $database = null): array
    {
        return $this->manager->listTableColumns($this->replacePrefix($table), $database);
    }

    /**
     * {@see AbstractSchemaManager::listTableIndexes}
     *
     * @return array<string, \Doctrine\DBAL\Schema\Index>
     */
    public function listTableIndexes(string $table): array
    {
        return $this->manager->listTableIndexes($this->replacePrefix($table));
    }

    /**
     * {@see AbstractSchemaManager::listTableDetails}
     */
    public function listTableDetails(string $tableName): \Doctrine\DBAL\Schema\Table
    {
        return $this->manager->listTableDetails($this->replacePrefix($tableName));
    }

    /**
     * {@see AbstractSchemaManager::listTableForeignKeys}
     *
     * @return array<int, \Doctrine\DBAL\Schema\ForeignKeyConstraint>
     */
    public function listTableForeignKeys(string $table, ?string $database = null): array
    {
        return $this->manager->listTableForeignKeys($this->replacePrefix($table), $database);
    }

    /**
     * Proxy method call to database schema manager.
     *
     * @param array<int, mixed> $args
     * @throws \BadMethodCallException
     */
    public function __call(string $method, array $args): mixed
    {
        if (!method_exists($this->manager, $method)) {
            throw new \BadMethodCallException(sprintf('Undefined method call "%s::%s"', get_class($this->manager), $method));
        }

        return $this->manager->{$method}(...$args);
    }

    /**
     * Migrates the database.
     */
    public function migrate(): void
    {
        $comparator = new Comparator();
        $diff = $comparator->compareSchemas($this->manager->createSchema(), $this->schema);

        foreach ($diff->toSaveSql($this->connection->getDatabasePlatform()) as $query) {
            $this->connection->executeQuery($query);
        }
    }

    /**
     * Replaces the table prefix placeholder with actual one.
     */
    protected function replacePrefix(string $query): string
    {
        return $this->connection->replacePrefix($query);
    }
}
