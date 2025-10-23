<?php

declare(strict_types=1);

namespace Pagekit\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\MigrationException;
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
     * @return array Result information (executed migrations, execution time, etc.)
     */
    public function migrate(?string $version = null): array
    {
        $migrator = $this->dependencyFactory->getMigrator();
        $planCalculator = $this->dependencyFactory->getMigrationPlanCalculator();
        
        try {
            // Calculate migration plan
            $plan = $version 
                ? $planCalculator->getPlanUntilVersion($version)
                : $planCalculator->getPlanForLatest();
            
            // Execute migrations
            $result = $migrator->migrate($plan);
            
            return [
                'success' => true,
                'executed' => count($result->getMigrations()),
                'time' => $result->getTime(),
                'sql' => $result->getSql(),
            ];
            
        } catch (MigrationException $e) {
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
     * @return array Result information
     */
    public function rollback(?string $version = null): array
    {
        $migrator = $this->dependencyFactory->getMigrator();
        $planCalculator = $this->dependencyFactory->getMigrationPlanCalculator();
        
        try {
            // Calculate rollback plan
            $plan = $version
                ? $planCalculator->getPlanUntilVersion($version)
                : $planCalculator->getPlanForPrevious();
            
            // Execute rollback
            $result = $migrator->migrate($plan);
            
            return [
                'success' => true,
                'executed' => count($result->getMigrations()),
                'time' => $result->getTime(),
            ];
            
        } catch (MigrationException $e) {
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
                    fn(AvailableMigration $m) => [
                        'version' => (string) $m->getVersion(),
                        'description' => $m->getMigration()->getDescription(),
                        'executed' => false,
                    ],
                    $availableMigrations->getItems()
                ),
                'executed' => array_map(
                    fn(ExecutedMigration $m) => [
                        'version' => (string) $m->getVersion(),
                        'executed_at' => $m->getExecutedAt()?->format('Y-m-d H:i:s'),
                        'execution_time' => $m->getExecutionTime(),
                    ],
                    $executedMigrations->getItems()
                ),
                'unavailable' => array_map(
                    fn(ExecutedMigration $m) => (string) $m->getVersion(),
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
            
            // Generate version identifier (timestamp-based)
            $version = 'Version' . date('YmdHis');
            
            // Create fully qualified class name
            $fqcn = $namespace . '\\' . $version;
            
            // Generate migration file
            $result = $generator->generateMigration($fqcn);
            
            return [
                'success' => true,
                'version' => $version,
                'class' => $fqcn,
                'path' => $result[0], // Generated file path
                'namespace' => $namespace,
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
        $storage = $this->dependencyFactory->getMetadataStorage();
        
        try {
            // Try to read metadata - this will fail if table doesn't exist
            $storage->getExecutedMigrations();
            return true;
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
}
