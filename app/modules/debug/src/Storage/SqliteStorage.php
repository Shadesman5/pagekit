<?php

declare(strict_types=1);

namespace Pagekit\Debug\Storage;

use DebugBar\Storage\PdoStorage;

class SqliteStorage extends PdoStorage
{
    /**
     * Maximum number of requests to keep in storage.
     */
    protected int $maxEntries = 100;

    /**
     * Constructor.
     *
     * @param string                $dsn
     * @param string                $tableName
     * @param array<string, string> $sqlQueries
     * @param int                   $maxEntries Maximum number of requests to keep (default: 100)
     */
    public function __construct($dsn, $tableName = 'phpdebugbar', array $sqlQueries = [], int $maxEntries = 100)
    {
        if (class_exists('PDO') && in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $pdo = new \PDO($dsn);
        } else {
            throw new \RuntimeException('No SQLite driver enabled.');
        }

        $this->maxEntries = $maxEntries;

        // create schema
        $pdo->exec('PRAGMA temp_store=MEMORY; PRAGMA journal_mode=MEMORY;');
        $pdo->exec("CREATE TABLE IF NOT EXISTS $tableName (id TEXT PRIMARY KEY, data TEXT, meta_utime TEXT, meta_datetime TEXT, meta_uri TEXT, meta_ip TEXT, meta_method TEXT)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_debugbar_id ON $tableName (id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_debugbar_meta_utime ON $tableName (meta_utime)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_debugbar_meta_datetime ON $tableName (meta_datetime)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_debugbar_meta_uri ON $tableName (meta_uri)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_debugbar_meta_ip ON $tableName (meta_ip)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_debugbar_meta_method ON $tableName (meta_method)");

        parent::__construct($pdo, $tableName, $sqlQueries);

        // Clean up old entries to prevent unlimited database growth
        $this->cleanup();
    }

    /**
     * Removes old entries keeping only the most recent ones.
     */
    protected function cleanup(): void
    {
        try {
            // Count total entries
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$this->tableName}");
            $count = (int) $stmt->fetchColumn();

            // If we have more than maxEntries, delete oldest ones
            if ($count > $this->maxEntries) {
                $toDelete = $count - $this->maxEntries;
                $this->pdo->exec(
                    "DELETE FROM {$this->tableName} WHERE id IN " .
                    "(SELECT id FROM {$this->tableName} ORDER BY meta_utime ASC LIMIT {$toDelete})"
                );
            }
        } catch (\Exception $e) {
            // Silently fail to avoid breaking the application
        }
    }
}
