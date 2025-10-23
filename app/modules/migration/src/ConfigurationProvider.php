<?php

declare(strict_types=1);

namespace Pagekit\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;

/**
 * Configuration Provider
 *
 * Provides Doctrine Migrations configuration for Pagekit.
 * Handles loading and parsing of migration configuration.
 */
class ConfigurationProvider
{
    /**
     * @var Connection DBAL connection
     */
    private Connection $connection;

    /**
     * @var array Configuration array
     */
    private array $config;

    /**
     * Constructor
     *
     * @param Connection $connection DBAL connection instance
     * @param array $config Configuration array
     */
    public function __construct(Connection $connection, array $config = [])
    {
        $this->connection = $connection;
        $this->config = $config;
    }

    /**
     * Create Doctrine Migrations Configuration
     *
     * Builds a Configuration instance from Pagekit's migration config.
     *
     * @return Configuration
     */
    public function createConfiguration(): Configuration
    {
        $configuration = new Configuration();

        // Set migrations paths
        foreach ($this->config['migrations_paths'] ?? [] as $namespace => $path) {
            $configuration->addMigrationsDirectory($namespace, $path);
        }

        // Configure table storage
        $tableStorage = $this->config['table_storage'] ?? [];
        
        if (isset($tableStorage['table_name'])) {
            $tableName = $this->replacePrefix($tableStorage['table_name']);
            $configuration->setMigrationsTableName($tableName);
        }

        if (isset($tableStorage['version_column_name'])) {
            $configuration->setMigrationsColumnName($tableStorage['version_column_name']);
        }

        if (isset($tableStorage['version_column_length'])) {
            $configuration->setMigrationsColumnLength($tableStorage['version_column_length']);
        }

        if (isset($tableStorage['executed_at_column_name'])) {
            $configuration->setMigrationsExecutedAtColumnName($tableStorage['executed_at_column_name']);
        }

        // Set configuration options
        if (isset($this->config['all_or_nothing'])) {
            $configuration->setAllOrNothing($this->config['all_or_nothing']);
        }

        if (isset($this->config['check_database_platform'])) {
            $configuration->setCheckDatabasePlatform($this->config['check_database_platform']);
        }

        // Set custom template if configured
        if (isset($this->config['custom_template'])) {
            $configuration->setCustomTemplate($this->config['custom_template']);
        }

        return $configuration;
    }

    /**
     * Create connection loader
     *
     * @return ExistingConnection
     */
    public function createConnectionLoader(): ExistingConnection
    {
        return new ExistingConnection($this->connection);
    }

    /**
     * Replace table prefix placeholder
     *
     * Replaces '@' placeholder with actual table prefix.
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
     * Load configuration from file
     *
     * @param string $path Configuration file path
     * @return array Configuration array
     */
    public static function loadFromFile(string $path): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Configuration file not found: {$path}");
        }

        $config = require $path;

        if (!is_array($config)) {
            throw new \RuntimeException("Configuration file must return an array: {$path}");
        }

        return $config;
    }

    /**
     * Get raw configuration array
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
