<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Doctrine\DBAL\DriverManager;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Loader\AttributeLoader;
use Pagekit\Database\ORM\MetadataManager;
use Pagekit\Database\Tests\ORM\Fixtures\RelationBrokenTargetFixture;
use Pagekit\Event\EventDispatcherInterface;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the invalid-targetEntity guard in
 * {@see \Pagekit\Database\ORM\QueryBuilder::getRelations()}.
 *
 * getRelations() only runs once at least one main row has been hydrated, so a
 * row is inserted before the eager-load. When a relation's mapped targetEntity
 * is not a loadable class the query must raise a LogicException rather than
 * attempting the repository lookup on a non-existent class.
 */
class QueryBuilderInvalidRelationTest extends TestCase
{
    /** @var Connection[] */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];
    }

    public function testEagerLoadRejectsUnloadableTargetEntity(): void
    {
        [$manager, $connection] = $this->bootSqliteManager();
        $connection->executeStatement('INSERT INTO rel_broken_target (id, author_id) VALUES (1, 1)');

        $this->expectException(\LogicException::class);

        $manager->getRepository(RelationBrokenTargetFixture::class)
            ->query()
            ->related('author')
            ->get();
    }

    /**
     * Boots a real EntityManager backed by in-memory SQLite and creates the
     * fixture table.
     *
     * @return array{EntityManager, Connection}
     */
    private function bootSqliteManager(): array
    {
        $driverConnection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection = new Connection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $driverConnection->getDriver(),
            $driverConnection->getConfiguration()
        );
        $driverConnection->close();

        $this->connections[] = $connection;

        $events = $this->createMock(EventDispatcherInterface::class);

        $metadataManager = new MetadataManager($connection, $events);
        $metadataManager->setLoader(new AttributeLoader());

        $manager = new EntityManager($connection, $metadataManager, $events);

        $connection->executeStatement('CREATE TABLE rel_broken_target (id INTEGER PRIMARY KEY, author_id INTEGER)');

        return [$manager, $connection];
    }
}
