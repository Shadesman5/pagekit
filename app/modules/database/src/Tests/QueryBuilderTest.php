<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests;

use Doctrine\DBAL\DriverManager;
use Pagekit\Database\Connection;
use PHPUnit\Framework\TestCase;

class QueryBuilderTest extends TestCase
{
    private Connection $connection;

    public function setUp(): void
    {
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection = new Connection(
            ['driver' => 'pdo_sqlite', 'memory' => true, 'prefix' => 'pk_'],
            $conn->getDriver(),
            $conn->getConfiguration()
        );

        $this->connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $this->connection->executeStatement("INSERT INTO users (id, name) VALUES (1, 'Alice'), (2, 'Bob'), (3, 'Carol')");
    }

    public function tearDown(): void
    {
        $this->connection->close();
        unset($this->connection);
    }

    /**
     * executeQuery() is typed `: Result`, so the return type is guaranteed at
     * compile time; the test exercises the Result API to prove the SELECT ran.
     */
    public function testExecuteQueryReturnsResult(): void
    {
        $result = $this->connection->createQueryBuilder()
            ->from('users')
            ->executeQuery();

        $this->assertCount(3, $result->fetchAllAssociative());
    }

    /**
     * Test executeStatement() runs an UPDATE and returns the affected-row count.
     */
    public function testExecuteStatementReturnsAffectedRowsForUpdate(): void
    {
        $affected = $this->connection->createQueryBuilder()
            ->from('users')
            ->where('id = :id', ['id' => 1])
            ->update(['name' => 'Alice Updated']);

        $this->assertSame(1, $affected);
        $this->assertSame(
            'Alice Updated',
            $this->connection->executeQuery('SELECT name FROM users WHERE id = 1')->fetchOne()
        );
    }

    /**
     * Test executeStatement() runs a DELETE and returns the affected-row count.
     */
    public function testExecuteStatementReturnsAffectedRowsForDelete(): void
    {
        $affected = $this->connection->createQueryBuilder()
            ->from('users')
            ->where('id IN (1, 2)')
            ->delete();

        $this->assertSame(2, $affected);
        $this->assertSame(1, $this->connection->createQueryBuilder()->from('users')->count());
    }
}
