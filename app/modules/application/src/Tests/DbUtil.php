<?php

declare(strict_types=1);

namespace Pagekit\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;

trait DbUtil
{
    /**
     * Gets a <b>real</b> database connection using the following parameters
     * of the $GLOBALS array:
     *
     * 'db_type' : The name of the Doctrine DBAL database driver to use.
     * 'db_username' : The username to use for connecting.
     * 'db_password' : The password to use for connecting.
     * 'db_host' : The hostname of the database to connect to.
     * 'db_name' : The name of the database to connect to.
     * 'db_port' : The port of the database to connect to.
     *
     * Usually these variables of the $GLOBALS array are filled by PHPUnit based
     * on an XML configuration file. If no such parameters exist, an SQLite
     * in-memory database is used.
     *
     * IMPORTANT:
     * 1) Each invocation of this method returns a NEW database connection.
     * 2) The database is dropped and recreated to ensure it's clean.
     *
     * @return Connection The database connection instance.
     */
    public function getConnection(): Connection
    {
        if (isset($GLOBALS['db_type'], $GLOBALS['db_username'], $GLOBALS['db_password'],
            $GLOBALS['db_host'], $GLOBALS['db_name'], $GLOBALS['db_port'],
            $GLOBALS['tmpdb_type'], $GLOBALS['tmpdb_username'], $GLOBALS['tmpdb_password'],
            $GLOBALS['tmpdb_host'], $GLOBALS['tmpdb_name'], $GLOBALS['tmpdb_port'])) {
            $realDbParams = [
                'driver' => $GLOBALS['db_type'],
                'user' => $GLOBALS['db_username'],
                'password' => $GLOBALS['db_password'],
                'host' => $GLOBALS['db_host'],
                'dbname' => $GLOBALS['db_name'],
                'port' => $GLOBALS['db_port'],
            ];
            $tmpDbParams = [
                'driver' => $GLOBALS['tmpdb_type'],
                'user' => $GLOBALS['tmpdb_username'],
                'password' => $GLOBALS['tmpdb_password'],
                'host' => $GLOBALS['tmpdb_host'],
                'dbname' => $GLOBALS['tmpdb_name'],
                'port' => $GLOBALS['tmpdb_port'],
            ];

            $realConn = DriverManager::getConnection($realDbParams);

            $platform = $realConn->getDatabasePlatform();

            if ($platform->supportsCreateDropDatabase()) {

                $dbname = $realConn->getDatabase();
                if ($dbname === null) {
                    throw new \RuntimeException('Cannot determine database name; getDatabase() returned null.');
                }
                // Connect to tmpdb in order to drop and create the real test db.
                $tmpConn = DriverManager::getConnection($tmpDbParams);
                $realConn->close();

                $tmpConn->createSchemaManager()->dropDatabase($dbname);
                $tmpConn->createSchemaManager()->createDatabase($dbname);

                $tmpConn->close();
            } else {

                $sm = $realConn->createSchemaManager();

                // DBAL 3 dropped Schema::toDropSql(), so build the DROP SQL per table from the
                // introspected schema via the platform (passing the quoted name; passing a Table
                // object is deprecated). This branch only runs on platforms without CREATE/DROP
                // DATABASE support (e.g. SQLite, Oracle), where dropping foreign keys first is not
                // portable (SQLite has no "ALTER TABLE ... DROP FOREIGN KEY"). Teardown is therefore
                // best-effort: log-and-continue so one undroppable table cannot abort the whole
                // cleanup, and failures are surfaced via error_log() instead of being swallowed.
                foreach ($sm->introspectSchema()->getTables() as $table) {
                    $dropSql = $platform->getDropTableSQL($table->getQuotedName($platform));

                    try {
                        $realConn->executeStatement($dropSql);
                    } catch (DBALException $e) {
                        error_log(sprintf(
                            'DbUtil::getConnection(): could not drop table "%s" during test-database teardown: %s',
                            $table->getName(),
                            $e->getMessage(),
                        ));
                    }
                }
            }

            $conn = DriverManager::getConnection(array_merge(['wrapperClass' => 'Pagekit\Database\Connection'], $realDbParams), null, null);
        } else {
            $params = [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ];
            if (isset($GLOBALS['db_path'])) {
                $params['path'] = $GLOBALS['db_path'];
                unlink($GLOBALS['db_path']);
            }
            $conn = DriverManager::getConnection(array_merge(['wrapperClass' => 'Pagekit\Database\Connection'], $params));
        }

        return $conn;
    }

    public function getTempConnection(): Connection
    {
        $tmpDbParams = [
            'driver' => $GLOBALS['tmpdb_type'],
            'user' => $GLOBALS['tmpdb_username'],
            'password' => $GLOBALS['tmpdb_password'],
            'host' => $GLOBALS['tmpdb_host'],
            'dbname' => $GLOBALS['tmpdb_name'],
            'port' => $GLOBALS['tmpdb_port'],
        ];

        // Connect to tmpdb in order to drop and create the real test db.
        return DriverManager::getConnection($tmpDbParams);
    }

    public function getSharedConnection(): Connection
    {
        /** @var Connection|null $connection */
        static $connection = null;
        /** @var \Exception|null $error */
        static $error = null;

        if ($connection === null && $error === null) {

            try {
                $connection = $this->getConnection();
            } catch (\Exception $e) {
                $error = $e;
            }

        }

        if ($error !== null) {
            throw $error;
        }

        return $connection;
    }
}
