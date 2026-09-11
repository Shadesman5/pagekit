<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Pagekit\Database\Connection;
use Pagekit\Tests\DbUtil;

/**
 * A database to take a snapshot of, and to put one back into.
 *
 * A dump is the schema one database engine renders and the rows one driver hands
 * back, so nothing here stands in for either: the tables are real tables and the
 * values are read back out of the database rather than out of what was inserted.
 * SQLite in memory is what every run has, and where the connection globals name a
 * server instead, the same tests are what runs against it - which is the only way
 * the second driver the installation supports is ever exercised.
 *
 * The connection carries a table prefix, because that is what tells the tables an
 * installation owns from the ones sharing the database with it.
 */
trait SnapshotDatabase
{
    use DbUtil;

    /**
     * What the shared helper needs named before it opens anything but SQLite.
     * All of them, so that the branch taken here is the branch it takes.
     */
    private const REAL_DATABASE = [
        'db_type', 'db_username', 'db_password', 'db_host', 'db_name', 'db_port',
        'tmpdb_type', 'tmpdb_username', 'tmpdb_password', 'tmpdb_host', 'tmpdb_name', 'tmpdb_port',
    ];

    /**
     * Every connection this test opened, so a server does not keep them after
     * the run that made them is over.
     *
     * @var array<int, Connection>
     */
    private array $opened = [];

    /**
     * A database with nothing in it, under the prefix the installation names its
     * own tables with.
     *
     * One per test rather than one shared: a dump holds every table it can see,
     * so what another test left behind would be in it.
     *
     * @param class-string<Connection> $wrapper the connection as the test needs it
     *                                          to behave, where that is the thing
     *                                          under test
     */
    protected function openDatabase(string $prefix = 'pk_', string $wrapper = Connection::class): Connection
    {
        $params = ['prefix' => $prefix, 'wrapperClass' => $wrapper];

        if (!self::namesARealDatabase()) {
            return $this->open($params + ['driver' => 'pdo_sqlite', 'memory' => true]);
        }

        $this->emptyTheTestDatabase();

        return $this->open($params + [
            'driver' => $GLOBALS['db_type'],
            'user' => $GLOBALS['db_username'],
            'password' => $GLOBALS['db_password'],
            'host' => $GLOBALS['db_host'],
            'dbname' => $GLOBALS['db_name'],
            'port' => $GLOBALS['db_port'],
        ]);
    }

    /**
     * Makes the test database again from nothing.
     *
     * Through the maintenance connection the shared helper opens for it - the
     * one on a database that is always there - because the test database is what
     * is being dropped and a connection cannot be made to it while it is gone.
     */
    private function emptyTheTestDatabase(): void
    {
        $maintenance = $this->getTempConnection();
        $name = $maintenance->getDatabasePlatform()->quoteIdentifier((string) $GLOBALS['db_name']);

        $maintenance->executeStatement('DROP DATABASE IF EXISTS '.$name);
        $maintenance->executeStatement('CREATE DATABASE '.$name);
        $maintenance->close();
    }

    /**
     * Whether the database under test is the one that keeps schema changes
     * inside a transaction. The two the installation supports differ on it, and
     * what a half-applied restore leaves behind follows from that rather than
     * from anything the restore does.
     */
    protected function isSqlite(Connection $connection): bool
    {
        return $connection->getDatabasePlatform() instanceof SqlitePlatform;
    }

    protected function closeDatabases(): void
    {
        foreach ($this->opened as $connection) {
            $connection->close();
        }

        $this->opened = [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function open(array $params): Connection
    {
        $connection = DriverManager::getConnection($params);

        // The wrapper is what reads the prefix and what the snapshot classes are
        // typed against, so a driver manager that handed back anything else
        // would make every assertion below one about the wrong class.
        self::assertInstanceOf(Connection::class, $connection);

        $this->opened[] = $connection;

        return $connection;
    }

    private static function namesARealDatabase(): bool
    {
        foreach (self::REAL_DATABASE as $global) {
            if (!isset($GLOBALS[$global])) {
                return false;
            }
        }

        return true;
    }
}
