<?php

namespace Pagekit\Database\Tests;

use PHPUnit\Framework\TestCase;
use Pagekit\Database\Connection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\DriverManager;

class ConnectionTest extends TestCase
{
    protected ?Connection $connection = null;

    public function setUp(): void
    {
        // Create a mock driver for testing
        $driver = $this->createMock(Driver::class);
        
        $params = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'prefix' => 'pk_'
        ];
        
        $config = new Configuration();
        
        // Create connection with mock driver
        $this->connection = new Connection($params, $driver, $config);
    }

    public function tearDown(): void
    {
        if ($this->connection) {
            $this->connection->close();
        }
        $this->connection = null;
    }

    /**
     * Test that Connection instance can be created
     */
    public function testConnectionInstantiation(): void
    {
        $this->assertInstanceOf(Connection::class, $this->connection);
    }

    /**
     * Test get utility
     */
    public function testGetUtility(): void
    {
        $utility = $this->connection->getUtility();
        $this->assertInstanceOf(\Pagekit\Database\Utility::class, $utility);
    }

    /**
     * Test get and set prefix
     */
    public function testGetSetPrefix(): void
    {
        // Test default prefix
        $this->assertEquals('@', $this->connection->getPrefix());
        
        // Set new prefix
        $this->connection->setPrefix('pk_');
        $this->assertEquals('pk_', $this->connection->getPrefix());
    }

    /**
     * Test replace prefix in query
     */
    public function testReplacePrefix(): void
    {
        $this->connection->setPrefix('pk_');
        
        $query = "SELECT * FROM @users WHERE id = ?";
        $expected = "SELECT * FROM pk_users WHERE id = ?";
        
        $result = $this->connection->replacePrefix($query);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test escape column name
     */
    public function testEscapeColumn(): void
    {
        $column = 'user_name';
        $escaped = $this->connection->escape($column);
        
        // For SQLite, it should be quoted
        $this->assertStringContainsString($column, $escaped);
    }

    /**
     * Test getDatabasePlatform
     */
    public function testGetDatabasePlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->assertInstanceOf(\Doctrine\DBAL\Platforms\AbstractPlatform::class, $platform);
    }

    /**
     * Test table existence methods
     */
    public function testTableExistence(): void
    {
        // Create a test table
        $schema = $this->connection->getSchemaManager();
        
        if (!$this->connection->getSchemaManager()->tablesExist(['test_table'])) {
            $table = new \Doctrine\DBAL\Schema\Table('test_table');
            $table->addColumn('id', 'integer', ['autoincrement' => true]);
            $table->setPrimaryKey(['id']);
            $schema->createTable($table);
        }
        
        // Test table exists
        $exists = $this->connection->getSchemaManager()->tablesExist(['test_table']);
        $this->assertTrue($exists);
        
        // Clean up
        $schema->dropTable('test_table');
    }

    /**
     * Test query builder
     */
    public function testQueryBuilder(): void
    {
        $qb = $this->connection->createQueryBuilder();
        $this->assertInstanceOf(\Doctrine\DBAL\Query\QueryBuilder::class, $qb);
        
        // Build a simple query
        $query = $qb->select('*')
                    ->from('users')
                    ->where('id = :id')
                    ->setParameter('id', 1)
                    ->getSQL();
        
        $this->assertStringContainsString('SELECT', $query);
        $this->assertStringContainsString('FROM users', $query);
        $this->assertStringContainsString('WHERE id = :id', $query);
    }
}