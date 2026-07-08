<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Pagekit\Database\Connection;
use Pagekit\Database\Types\JsonArrayType;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    private Connection $connection;

    public function setUp(): void
    {
        $driver = $this->createMock(Driver::class);

        $params = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'prefix' => 'pk_',
        ];

        $config = new Configuration();
        $this->connection = new Connection($params, $driver, $config);
    }

    public function tearDown(): void
    {
        $this->connection->close();
        unset($this->connection);
    }

    /**
     * Test that Connection instance can be created
     */
    public function testConnectionInstantiation(): void
    {
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test get prefix (set via constructor params)
     */
    public function testGetPrefix(): void
    {
        // Prefix is set from constructor params ('prefix' => 'pk_')
        $this->assertEquals('pk_', $this->connection->getPrefix());
    }

    /**
     * Test connection with no prefix
     */
    public function testGetPrefixNull(): void
    {
        $driver = $this->createMock(Driver::class);
        $conn = new Connection(['driver' => 'pdo_sqlite', 'memory' => true], $driver, new Configuration());
        $this->assertNull($conn->getPrefix());
    }

    /**
     * Test replace prefix in query
     */
    public function testReplacePrefix(): void
    {
        // Prefix is already 'pk_' from constructor
        $query = 'SELECT * FROM @users WHERE id = ?';
        $expected = 'SELECT * FROM pk_users WHERE id = ?';

        $result = $this->connection->replacePrefix($query);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test replace prefix preserves quoted strings
     */
    public function testReplacePrefixPreservesQuotedStrings(): void
    {
        $query = "SELECT * FROM @users WHERE name = '@notaprefix'";
        $result = $this->connection->replacePrefix($query);

        // The @users should be replaced, but '@notaprefix' inside quotes should be preserved
        $this->assertStringContainsString('pk_users', $result);
        $this->assertStringContainsString('@notaprefix', $result);
    }

    /**
     * Test createQueryBuilder returns Pagekit QueryBuilder
     */
    public function testCreateQueryBuilder(): void
    {
        $this->connection->createQueryBuilder();
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test getUtility with real SQLite connection
     */
    public function testGetUtilityWithRealConnection(): void
    {
        // Use DriverManager to create a real SQLite in-memory connection
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        // Wrap in Pagekit Connection to test getUtility
        $pagekitConn = new Connection(
            ['driver' => 'pdo_sqlite', 'memory' => true, 'prefix' => 'pk_'],
            $conn->getDriver(),
            $conn->getConfiguration()
        );

        // Force connection to establish
        $pagekitConn->executeQuery('SELECT 1');

        $utility = $pagekitConn->getUtility();
        $this->assertSame('pk_', $pagekitConn->getPrefix());

        $pagekitConn->close();
    }

    /**
     * Test getDatabasePlatform with real connection
     */
    public function testGetDatabasePlatformWithRealConnection(): void
    {
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $pagekitConn = new Connection(
            ['driver' => 'pdo_sqlite', 'memory' => true, 'prefix' => 'pk_'],
            $conn->getDriver(),
            $conn->getConfiguration()
        );

        $pagekitConn->executeQuery('SELECT 1');

        $platform = $pagekitConn->getDatabasePlatform();
        $this->assertNotEmpty($platform->getName());

        $pagekitConn->close();
    }

    /**
     * Test fetchObject with real connection
     */
    public function testFetchObjectWithRealConnection(): void
    {
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $pagekitConn = new Connection(
            ['driver' => 'pdo_sqlite', 'memory' => true, 'prefix' => 'pk_'],
            $conn->getDriver(),
            $conn->getConfiguration()
        );

        // Create a test table and insert data
        $pagekitConn->executeStatement('CREATE TABLE test_fetch (id INTEGER PRIMARY KEY, name TEXT)');
        $pagekitConn->executeStatement("INSERT INTO test_fetch (id, name) VALUES (1, 'test')");

        $result = $pagekitConn->fetchObject('SELECT * FROM test_fetch WHERE id = 1');
        if ($result === false) {
            $this->fail('fetchObject returned false for an existing row.');
        }
        $this->assertEquals(1, $result->id);
        $this->assertEquals('test', $result->name);

        // Test no result
        $noResult = $pagekitConn->fetchObject('SELECT * FROM test_fetch WHERE id = 999');
        $this->assertFalse($noResult);

        $pagekitConn->close();
    }

    /**
     * Test fetchAllObjects with real connection
     */
    public function testFetchAllObjectsWithRealConnection(): void
    {
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $pagekitConn = new Connection(
            ['driver' => 'pdo_sqlite', 'memory' => true, 'prefix' => 'pk_'],
            $conn->getDriver(),
            $conn->getConfiguration()
        );

        $pagekitConn->executeStatement('CREATE TABLE test_fetch_all (id INTEGER PRIMARY KEY, name TEXT)');
        $pagekitConn->executeStatement("INSERT INTO test_fetch_all (id, name) VALUES (1, 'Alice')");
        $pagekitConn->executeStatement("INSERT INTO test_fetch_all (id, name) VALUES (2, 'Bob')");

        $results = $pagekitConn->fetchAllObjects('SELECT * FROM test_fetch_all ORDER BY id');
        $this->assertCount(2, $results);
        $this->assertEquals('Alice', $results[0]->name);
        $this->assertEquals('Bob', $results[1]->name);

        $pagekitConn->close();
    }

    /**
     * Test a json-typed column round-trips an array via JsonArrayType.
     *
     * Since Step 2.1.7 the DBAL 'json' type resolves to the array-safe
     * JsonArrayType (the former 'json_array' alias was removed), mirroring the
     * database module bootstrap's Type::overrideType(Types::JSON, ...).
     */
    public function testJsonColumnRoundTripsArrayViaJsonArrayType(): void
    {
        if (!(Type::getType(Types::JSON) instanceof JsonArrayType)) {
            Type::overrideType(Types::JSON, JsonArrayType::class);
        }

        $type = Type::getType(Types::JSON);
        $this->assertInstanceOf(JsonArrayType::class, $type);
        $this->assertSame('json', $type->getName());

        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $pagekitConn = new Connection(
            ['driver' => 'pdo_sqlite', 'memory' => true, 'prefix' => 'pk_'],
            $conn->getDriver(),
            $conn->getConfiguration()
        );

        $pagekitConn->executeStatement('CREATE TABLE json_test (id INTEGER PRIMARY KEY, data TEXT)');

        $platform = $pagekitConn->getDatabasePlatform();
        $data = ['title' => 'Hello', 'tags' => ['a', 'b'], 'count' => 3];

        $pagekitConn->executeStatement(
            'INSERT INTO json_test (id, data) VALUES (1, ?)',
            [$type->convertToDatabaseValue($data, $platform)]
        );

        $stored = $pagekitConn->executeQuery('SELECT data FROM json_test WHERE id = 1')->fetchOne();

        $this->assertSame($data, $type->convertToPHPValue($stored, $platform));

        $pagekitConn->close();
    }
}
