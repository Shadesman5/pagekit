<?php

declare(strict_types=1);

namespace Pagekit\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extension Migration Base Class
 *
 * Base class for extension-specific migrations.
 * Provides helper methods for working with extension tables and the table prefix system.
 *
 * Usage Example:
 * ```php
 * class Version001_CreatePostTable extends ExtensionMigration
 * {
 *     public function getExtensionName(): string
 *     {
 *         return 'blog';
 *     }
 *
 *     public function up(Schema $schema): void
 *     {
 *         $table = $schema->createTable($this->getTableName('post'));
 *         $table->addColumn('id', 'integer', ['autoincrement' => true]);
 *         // ... more columns
 *         $table->setPrimaryKey(['id']);
 *     }
 *
 *     public function down(Schema $schema): void
 *     {
 *         $schema->dropTable($this->getTableName('post'));
 *     }
 * }
 * ```
 */
abstract class ExtensionMigration extends AbstractMigration
{
    /**
     * Get extension name
     *
     * Returns the extension identifier used for table prefixing.
     * For example: 'blog' for the blog extension.
     *
     * @return string Extension name (lowercase, alphanumeric + underscore)
     */
    abstract public function getExtensionName(): string;

    /**
     * Get full table name with extension prefix
     *
     * Builds a fully prefixed table name for the extension.
     * Format: {prefix}{extension}_{table}
     * Example: 'pk_blog_post' for extension 'blog' and table 'post'
     *
     * @param string $tableName Base table name (without prefix)
     * @return string Full table name with prefix
     */
    protected function getTableName(string $tableName): string
    {
        $prefix = $this->getTablePrefix();
        $extension = $this->getExtensionName();
        
        return sprintf('%s%s_%s', $prefix, $extension, $tableName);
    }

    /**
     * Get table prefix from connection
     *
     * Returns the current table prefix (e.g., 'pk_').
     * Can be customized during Pagekit installation.
     *
     * @return string Table prefix
     */
    protected function getTablePrefix(): string
    {
        // Get prefix from Pagekit connection
        if ($this->connection instanceof \Pagekit\Database\Connection) {
            return $this->connection->getPrefix();
        }

        // Default prefix (standard Pagekit installation)
        return 'pk_';
    }

    /**
     * Get index name with prefix
     *
     * Builds a prefixed index name following Pagekit's naming convention.
     * Format: {prefix}{EXTENSION}_{TABLE}_{NAME}
     *
     * @param string $tableName Base table name
     * @param string $indexName Index name
     * @return string Full index name with prefix
     */
    protected function getIndexName(string $tableName, string $indexName): string
    {
        $prefix = $this->getTablePrefix();
        $extension = strtoupper($this->getExtensionName());
        $table = strtoupper($tableName);
        $name = strtoupper($indexName);
        
        return sprintf('%s%s_%s_%s', $prefix, $extension, $table, $name);
    }

    /**
     * Check if table exists
     *
     * Utility method to check if a table already exists in the database.
     *
     * @param Schema $schema Schema instance
     * @param string $tableName Table name (can use getTableName() result)
     * @return bool True if table exists
     */
    protected function tableExists(Schema $schema, string $tableName): bool
    {
        return $schema->hasTable($tableName);
    }

    /**
     * Create table if not exists
     *
     * Helper to create a table only if it doesn't already exist.
     * Returns the table object for further configuration.
     *
     * @param Schema $schema Schema instance
     * @param string $tableName Full table name
     * @return \Doctrine\DBAL\Schema\Table|null Table instance or null if exists
     */
    protected function createTableIfNotExists(Schema $schema, string $tableName): ?\Doctrine\DBAL\Schema\Table
    {
        if ($this->tableExists($schema, $tableName)) {
            $this->write(sprintf('Table "%s" already exists, skipping.', $tableName));
            return null;
        }

        return $schema->createTable($tableName);
    }

    /**
     * Drop table if exists
     *
     * Helper to drop a table only if it exists.
     *
     * @param Schema $schema Schema instance
     * @param string $tableName Full table name
     */
    protected function dropTableIfExists(Schema $schema, string $tableName): void
    {
        if ($this->tableExists($schema, $tableName)) {
            $schema->dropTable($tableName);
        } else {
            $this->write(sprintf('Table "%s" does not exist, skipping drop.', $tableName));
        }
    }
}
