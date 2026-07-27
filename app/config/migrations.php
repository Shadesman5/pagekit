<?php

declare(strict_types=1);

/**
 * Doctrine Migrations Configuration for Pagekit
 *
 * This configuration file defines settings for the Doctrine Migrations system.
 * It manages database schema versioning and provides rollback functionality.
 *
 * @see https://www.doctrine-project.org/projects/doctrine-migrations/en/latest/reference/configuration.html
 */

return [
    /**
     * Table Storage Configuration
     *
     * Defines how migration version information is stored in the database.
     * The table stores executed migration versions to track which migrations have been run.
     */
    'table_storage' => [
        /**
         * Migration Version Table Name
         *
         * Table name for storing migration versions.
         * Uses '@' prefix placeholder which gets replaced with actual table prefix (default: 'pk_').
         * Final table name will be: pk_migration_versions
         */
        'table_name' => '@migration_versions',

        /**
         * Version Column Name
         *
         * Column name that stores the migration version identifier.
         */
        'version_column_name' => 'version',

        /**
         * Version Column Length
         *
         * Maximum length for the version identifier column.
         */
        'version_column_length' => 191,

        /**
         * Executed At Column Name
         *
         * Column name that stores the timestamp when migration was executed.
         */
        'executed_at_column_name' => 'executed_at',

        /**
         * Execution Time Column Name
         *
         * Column name that stores the execution time in milliseconds.
         */
        'execution_time_column_name' => 'execution_time',
    ],

    /**
     * Migrations Paths Configuration
     *
     * Maps migration namespaces to their directory paths.
     * Key: Namespace
     * Value: Absolute path or path relative to this file
     */
    'migrations_paths' => [
        'Pagekit\\Migration' => __DIR__ . '/../migrations',
    ],

    /**
     * All or Nothing Transaction Mode
     *
     * When true, wraps all migrations in a single transaction.
     * If any migration fails, all changes are rolled back.
     * Recommended: true for safety
     */
    'all_or_nothing' => true,

    /**
     * Check Database Platform
     *
     * When true, checks if migrations are compatible with the current database platform.
     * Recommended: true for safety
     */
    'check_database_platform' => true,

    /**
     * Organize Migrations
     *
     * Defines how migration files are organized in the filesystem.
     * Options:
     * - 'none': All migrations in the base directory (default)
     * - 'year': Organize by year (e.g., 2025/)
     * - 'year_and_month': Organize by year and month (e.g., 2025/10/)
     */
    'organize_migrations' => 'year',

    /**
     * Custom Migration Template
     *
     * Path to a custom migration template file (optional).
     * If not set, Doctrine Migrations uses its default template.
     */
    // 'custom_template' => __DIR__ . '/migration-template.php.tpl',

    /**
     * Connection Configuration
     *
     * Note: Connection is provided by Pagekit's DBAL integration.
     * No explicit connection configuration needed here.
     */
];
