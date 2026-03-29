<?php

declare(strict_types=1);

namespace Pagekit\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Version\Version;

/**
 * Migration Service
 *
 * Core service for managing database migrations in Pagekit.
 * Provides high-level API for executing, rolling back, and generating migrations.
 */
class MigrationService
{
    /**
     * @var Connection DBAL connection
     */
    private Connection $connection;

    /**
     * @var DependencyFactory Doctrine Migrations dependency factory
     */
    private DependencyFactory $dependencyFactory;

    /**
     * @var array Migration configuration
     */
    private array $config;

    /**
     * Constructor
     *
     * @param Connection $connection DBAL connection instance
     * @param array $config Migration configuration
     */
    public function __construct(Connection $connection, array $config = [])
    {
        $this->connection = $connection;
        $this->config = $config;
        $this->initializeDependencyFactory();
    }

    /**
     * Initialize Doctrine Migrations DependencyFactory
     *
     * Sets up the dependency factory with proper configuration for Pagekit.
     */
    private function initializeDependencyFactory(): void
    {
        // Prepare configuration array with prefix replacement
        $configArray = $this->config;

        // Replace table prefix in table name
        if (isset($configArray['table_storage']['table_name'])) {
            $configArray['table_storage']['table_name'] = $this->replacePrefix(
                $configArray['table_storage']['table_name']
            );
        }

        // Create dependency factory from configuration array
        $this->dependencyFactory = DependencyFactory::fromConnection(
            new \Doctrine\Migrations\Configuration\Migration\ConfigurationArray($configArray),
            new ExistingConnection($this->connection)
        );
    }

    /**
     * Get configuration file path
     *
     * @return string Path to migrations.php config file
     */
    private function getConfigPath(): string
    {
        return __DIR__ . '/../../../config/migrations.php';
    }

    /**
     * Replace table prefix placeholder
     *
     * Replaces '@' placeholder with actual table prefix (e.g., 'pk_').
     *
     * @param string $tableName Table name with @ placeholder
     * @return string Table name with actual prefix
     */
    private function replacePrefix(string $tableName): string
    {
        // Get table prefix from connection
        $prefix = $this->connection instanceof \Pagekit\Database\Connection
            ? $this->connection->getPrefix()
            : 'pk_';

        return str_replace('@', $prefix, $tableName);
    }

    /**
     * Execute migrations up to specified version
     *
     * @param string|null $version Target version (null = latest)
     * @param bool $dryRun If true, only show SQL without executing
     * @return array Result information (executed migrations, execution time, etc.)
     */
    public function migrate(?string $version = null, bool $dryRun = false): array
    {
        $migrator = $this->dependencyFactory->getMigrator();
        $planCalculator = $this->dependencyFactory->getMigrationPlanCalculator();
        $aliasResolver = $this->dependencyFactory->getVersionAliasResolver();

        try {
            // Resolve target version
            if ($version) {
                $targetVersion = new \Doctrine\Migrations\Version\Version($version);
            } else {
                // Migrate to latest version
                $targetVersion = $aliasResolver->resolveVersionAlias('latest');
            }

            // Calculate migration plan - migrate UP to target version
            $plan = $planCalculator->getPlanUntilVersion($targetVersion);

            // Check if plan is empty
            if (count($plan) === 0) {
                return [
                    'success' => true,
                    'executed' => 0,
                    'time' => 0,
                    'sql' => [],
                ];
            }

            // Execute migrations with MigratorConfiguration
            $migratorConfig = new \Doctrine\Migrations\MigratorConfiguration();
            $migratorConfig->setDryRun($dryRun);
            $result = $migrator->migrate($plan, $migratorConfig);

            // Check if result is valid
            if (is_array($result)) {
                // Empty result or error
                return [
                    'success' => true,
                    'executed' => 0,
                    'time' => 0,
                    'sql' => [],
                ];
            }

            return [
                'success' => true,
                'executed' => count($result->getMigrations()),
                'time' => $result->getTime(),
                'sql' => $result->getSql(),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'exception' => $e,
            ];
        }
    }

    /**
     * Rollback migrations to specified version
     *
     * @param string|null $version Target version (null = previous version)
     * @param bool $dryRun If true, only show SQL without executing
     * @return array Result information
     */
    public function rollback(?string $version = null, bool $dryRun = false): array
    {
        $migrator = $this->dependencyFactory->getMigrator();
        $planCalculator = $this->dependencyFactory->getMigrationPlanCalculator();
        $aliasResolver = $this->dependencyFactory->getVersionAliasResolver();
        $metadataStorage = $this->dependencyFactory->getMetadataStorage();

        try {
            // Get executed migrations
            $executedMigrations = $metadataStorage->getExecutedMigrations();

            // Check if there are migrations to rollback
            if (count($executedMigrations) === 0) {
                return [
                    'success' => true,
                    'executed' => 0,
                    'time' => 0,
                    'message' => 'No migrations to rollback',
                ];
            }

            // Determine target for rollback
            if ($version === '0' || $version === 'first') {
                // Rollback ALL migrations - use 'first' which means before first migration
                $targetVersion = $aliasResolver->resolveVersionAlias('first');
            } elseif ($version) {
                // Rollback to specific version
                $targetVersion = new \Doctrine\Migrations\Version\Version($version);
            } else {
                // Rollback to previous version (one step back)
                // Get current version and find previous one
                $currentVersion = $aliasResolver->resolveVersionAlias('current');

                if ($currentVersion === null || count($executedMigrations) === 0) {
                    return [
                        'success' => true,
                        'executed' => 0,
                        'time' => 0,
                        'message' => 'Already at first migration',
                    ];
                }

                // Get all executed migrations and find previous
                $versions = array_map(fn ($m) => $m->getVersion(), $executedMigrations->getItems());
                $currentIndex = array_search($currentVersion, $versions);

                if ($currentIndex === false || $currentIndex === 0) {
                    // Already at first migration, rollback it completely
                    $targetVersion = $aliasResolver->resolveVersionAlias('first');
                } else {
                    // Rollback to previous version
                    $targetVersion = $versions[$currentIndex - 1];
                }
            }

            // Calculate rollback plan
            $plan = $planCalculator->getPlanUntilVersion($targetVersion);

            // Check if plan is empty
            if (count($plan) === 0) {
                return [
                    'success' => true,
                    'executed' => 0,
                    'time' => 0,
                    'message' => 'No migrations to rollback',
                ];
            }

            // Execute rollback
            $migratorConfig = new \Doctrine\Migrations\MigratorConfiguration();
            $migratorConfig->setDryRun($dryRun);
            $result = $migrator->migrate($plan, $migratorConfig);

            // Check if result is valid
            if (is_array($result)) {
                return [
                    'success' => true,
                    'executed' => 0,
                    'time' => 0,
                ];
            }

            return [
                'success' => true,
                'executed' => count($result->getMigrations()),
                'time' => $result->getTime(),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'exception' => $e,
            ];
        }
    }

    /**
     * Get migration status
     *
     * Returns information about available and executed migrations.
     *
     * @return array Migration status information
     */
    public function status(): array
    {
        try {
            $statusCalculator = $this->dependencyFactory->getMigrationStatusCalculator();
            $metadataStorage = $this->dependencyFactory->getMetadataStorage();
            $migrationRepository = $this->dependencyFactory->getMigrationRepository();

            // Get available (new/pending) migrations
            $availableMigrations = $statusCalculator->getNewMigrations();

            // Get executed migrations from metadata storage
            $executedMigrations = $metadataStorage->getExecutedMigrations();

            // Get unavailable migrations (executed but files missing)
            $unavailableMigrations = $statusCalculator->getExecutedUnavailableMigrations();

            // Get current and latest versions using AliasResolver
            $aliasResolver = $this->dependencyFactory->getVersionAliasResolver();

            try {
                $currentVersion = $aliasResolver->resolveVersionAlias('current');
                $latestVersion = $aliasResolver->resolveVersionAlias('latest');
            } catch (\Exception $e) {
                $currentVersion = null;
                $latestVersion = null;
            }

            return [
                'success' => true,
                'available' => array_map(
                    fn (AvailableMigration $m) => [
                        'version' => (string) $m->getVersion(),
                        'description' => $m->getMigration()->getDescription(),
                        'executed' => false,
                    ],
                    $availableMigrations->getItems()
                ),
                'executed' => array_map(
                    fn (ExecutedMigration $m) => [
                        'version' => (string) $m->getVersion(),
                        'executed_at' => $m->getExecutedAt()?->format('Y-m-d H:i:s'),
                        'execution_time' => $m->getExecutionTime(),
                    ],
                    $executedMigrations->getItems()
                ),
                'unavailable' => array_map(
                    fn (ExecutedMigration $m) => (string) $m->getVersion(),
                    $unavailableMigrations->getItems()
                ),
                'current_version' => $currentVersion ? (string) $currentVersion : 'None',
                'latest_version' => $latestVersion ? (string) $latestVersion : 'None',
                'has_pending' => count($availableMigrations) > 0,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate new migration file
     *
     * Creates a new timestamped migration file with given name.
     *
     * @param string $name Migration name (e.g., "CreateUserTable")
     * @param string|null $namespace Target namespace (default: first configured namespace)
     * @return array Generation result (file path, version, etc.)
     */
    public function generate(string $name, ?string $namespace = null): array
    {
        try {
            // Get migration generator
            $generator = $this->dependencyFactory->getMigrationGenerator();

            // Determine namespace
            if ($namespace === null) {
                $paths = $this->config['migrations_paths'] ?? [];
                $namespace = array_key_first($paths);
            }

            // Sanitize name: convert to PascalCase and remove invalid characters
            $sanitizedName = preg_replace('/[^A-Za-z0-9]/', '', ucwords($name, '_- '));

            // Generate version identifier (timestamp + user-provided name)
            // Format: Version{timestamp}_{Name} e.g., Version20250118123456_CreateUserTable
            $timestamp = date('YmdHis');
            $version = 'Version' . $timestamp . '_' . $sanitizedName;

            // Create fully qualified class name
            $fqcn = $namespace . '\\' . $version;

            // Generate migration file
            // generateMigration() returns the file path as a string
            $path = $generator->generateMigration($fqcn);

            return [
                'success' => true,
                'version' => $version,
                'class' => $fqcn,
                'path' => $path,
                'namespace' => $namespace,
                'name' => $sanitizedName,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if migration system is initialized
     *
     * Checks if the migration version table exists.
     *
     * @return bool True if initialized, false otherwise
     */
    public function isInitialized(): bool
    {
        try {
            // Check if the migration table exists in the database
            $schemaManager = $this->connection->createSchemaManager();
            $tableName = $this->replacePrefix($this->config['table_storage']['table_name'] ?? '@migration_versions');

            return $schemaManager->tablesExist([$tableName]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Initialize migration system
     *
     * Creates the migration version tracking table.
     *
     * @return array Initialization result
     */
    public function initialize(): array
    {
        try {
            $storage = $this->dependencyFactory->getMetadataStorage();
            $storage->ensureInitialized();

            return [
                'success' => true,
                'message' => 'Migration system initialized successfully.',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get DependencyFactory instance
     *
     * Provides access to the Doctrine Migrations DependencyFactory
     * for advanced operations.
     *
     * @return DependencyFactory
     */
    public function getDependencyFactory(): DependencyFactory
    {
        return $this->dependencyFactory;
    }

    /**
     * Get DBAL Connection instance
     *
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Execute migrations for a specific extension
     *
     * This method allows extensions to run their own migrations by providing
     * their namespace and migration directory path.
     *
     * @param string $namespace Extension migration namespace (e.g., 'Pagekit\\Blog\\Migrations')
     * @param string $path Absolute path to extension migrations directory
     * @param string|null $version Target version (null = latest)
     * @return array Result information
     */
    public function migrateExtension(string $namespace, string $path, ?string $version = null): array
    {
        try {
            // Create temporary config for extension migrations
            $extensionConfig = $this->config;
            $extensionConfig['migrations_paths'] = [$namespace => $path];

            // Replace table prefix in table name for extension config
            if (isset($extensionConfig['table_storage']['table_name'])) {
                $extensionConfig['table_storage']['table_name'] = $this->replacePrefix(
                    $extensionConfig['table_storage']['table_name']
                );
            }

            // Create temporary dependency factory for extension
            $extensionFactory = DependencyFactory::fromConnection(
                new \Doctrine\Migrations\Configuration\Migration\ConfigurationArray($extensionConfig),
                new \Doctrine\Migrations\Configuration\Connection\ExistingConnection($this->connection)
            );

            // Get services from extension factory
            $migrator = $extensionFactory->getMigrator();
            $planCalculator = $extensionFactory->getMigrationPlanCalculator();
            $aliasResolver = $extensionFactory->getVersionAliasResolver();

            // Resolve target version
            if ($version) {
                $targetVersion = new \Doctrine\Migrations\Version\Version($version);
            } else {
                // Migrate to latest version
                $targetVersion = $aliasResolver->resolveVersionAlias('latest');
            }

            // Calculate migration plan
            $plan = $planCalculator->getPlanUntilVersion($targetVersion);

            // Check if plan is empty
            if (count($plan) === 0) {
                return [
                    'success' => true,
                    'executed' => 0,
                    'time' => 0,
                    'sql' => [],
                    'message' => 'No pending migrations for extension',
                ];
            }

            // Execute migrations
            $migratorConfig = new \Doctrine\Migrations\MigratorConfiguration();
            $result = $migrator->migrate($plan, $migratorConfig);

            // Check if result is valid
            if (is_array($result)) {
                return [
                    'success' => true,
                    'executed' => 0,
                    'time' => 0,
                    'sql' => [],
                ];
            }

            return [
                'success' => true,
                'executed' => count($result->getMigrations()),
                'time' => $result->getTime(),
                'sql' => $result->getSql(),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'exception' => $e,
            ];
        }
    }

    /**
     * Rollback migrations for a specific extension
     *
     * This method allows extensions to rollback their own migrations.
     * Behavior is consistent with rollback() method.
     *
     * @param string $namespace Extension migration namespace
     * @param string $path Absolute path to extension migrations directory
     * @param string|null $version Target version:
     *                             - null = rollback one step (previous version)
     *                             - '0' or 'first' = rollback all migrations
     *                             - specific version = rollback to that version
     * @return array Result information
     */
    public function rollbackExtension(string $namespace, string $path, ?string $version = null): array
    {
        try {
            // Create temporary config for extension migrations
            $extensionConfig = $this->config;
            $extensionConfig['migrations_paths'] = [$namespace => $path];

            // Replace table prefix
            if (isset($extensionConfig['table_storage']['table_name'])) {
                $extensionConfig['table_storage']['table_name'] = $this->replacePrefix(
                    $extensionConfig['table_storage']['table_name']
                );
            }

            // Create temporary dependency factory
            $extensionFactory = DependencyFactory::fromConnection(
                new \Doctrine\Migrations\Configuration\Migration\ConfigurationArray($extensionConfig),
                new \Doctrine\Migrations\Configuration\Connection\ExistingConnection($this->connection)
            );

            // Get services
            $migrator = $extensionFactory->getMigrator();
            $planCalculator = $extensionFactory->getMigrationPlanCalculator();
            $aliasResolver = $extensionFactory->getVersionAliasResolver();
            $metadataStorage = $extensionFactory->getMetadataStorage();

            // Get executed migrations
            $executedMigrations = $metadataStorage->getExecutedMigrations();

            if (count($executedMigrations) === 0) {
                return [
                    'success' => true,
                    'executed' => 0,
                    'time' => 0,
                    'message' => 'No migrations to rollback',
                ];
            }

            // Determine target for rollback
            if ($version === '0' || $version === 'first') {
                // Rollback ALL extension migrations
                $targetVersion = $aliasResolver->resolveVersionAlias('first');
            } elseif ($version) {
                // Rollback to specific version
                $targetVersion = new \Doctrine\Migrations\Version\Version($version);
            } else {
                // Rollback to previous version (one step back) - consistent with rollback()
                $currentVersion = $aliasResolver->resolveVersionAlias('current');

                if ($currentVersion === null || count($executedMigrations) === 0) {
                    return [
                        'success' => true,
                        'executed' => 0,
                        'time' => 0,
                        'message' => 'Already at first migration',
                    ];
                }

                // Get all executed migrations and find previous
                $versions = array_map(fn ($m) => $m->getVersion(), $executedMigrations->getItems());
                $currentIndex = array_search($currentVersion, $versions);

                if ($currentIndex === false || $currentIndex === 0) {
                    // Already at first migration, rollback it completely
                    $targetVersion = $aliasResolver->resolveVersionAlias('first');
                } else {
                    // Rollback to previous version
                    $targetVersion = $versions[$currentIndex - 1];
                }
            }

            // Calculate rollback plan
            $plan = $planCalculator->getPlanUntilVersion($targetVersion);

            if (count($plan) === 0) {
                return [
                    'success' => true,
                    'executed' => 0,
                    'time' => 0,
                    'message' => 'No migrations to rollback',
                ];
            }

            // Execute rollback
            $migratorConfig = new \Doctrine\Migrations\MigratorConfiguration();
            $result = $migrator->migrate($plan, $migratorConfig);

            if (is_array($result)) {
                return [
                    'success' => true,
                    'executed' => 0,
                    'time' => 0,
                ];
            }

            return [
                'success' => true,
                'executed' => count($result->getMigrations()),
                'time' => $result->getTime(),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'exception' => $e,
            ];
        }
    }
}
