<?php

declare(strict_types=1);

namespace Pagekit\Database\Tests\ORM;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\ORM\Repository;
use Pagekit\Database\Query\QueryBuilder as DbalQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the generic {@see Repository} data-mapper.
 *
 * `create()`/`save()`/`delete()` are thin delegations onto the injected
 * {@see EntityManager}; `find()`/`findAll()`/`where()` build a query through the
 * shared ORM {@see QueryBuilder}. The EntityManager and the inner DBAL query
 * builder are mocked so each delegation — and the `removeRole()` `roles`-field
 * guard — is asserted in isolation, with no database (mirroring the mock-backed
 * ORM wiring in {@see QueryBuilderCacheTest}).
 */
class RepositoryTest extends TestCase
{
    // -----------------------------------------------------------------------
    // create()/save()/delete(): pure delegation onto the EntityManager.
    // -----------------------------------------------------------------------

    public function testCreateDelegatesHydrationToEntityManagerLoad(): void
    {
        $entity = new \stdClass();
        $metadata = $this->createMock(Metadata::class);

        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())
            ->method('load')
            ->with($metadata, ['title' => 'x'])
            ->willReturn($entity);

        $repository = new Repository($em, $metadata);

        $this->assertSame($entity, $repository->create(['title' => 'x']));
    }

    public function testSaveDelegatesToEntityManager(): void
    {
        $entity = new \stdClass();
        $metadata = $this->createMock(Metadata::class);

        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())
            ->method('save')
            ->with($entity, ['title' => 'x']);

        $repository = new Repository($em, $metadata);

        $repository->save($entity, ['title' => 'x']);
    }

    public function testDeleteDelegatesToEntityManager(): void
    {
        $entity = new \stdClass();
        $metadata = $this->createMock(Metadata::class);

        $em = $this->createMock(EntityManager::class);
        $em->expects($this->once())
            ->method('delete')
            ->with($entity);

        $repository = new Repository($em, $metadata);

        $repository->delete($entity);
    }

    // -----------------------------------------------------------------------
    // find()/findAll()/where(): queries through the ORM QueryBuilder.
    // -----------------------------------------------------------------------

    public function testFindQueriesByIdentifierAndReturnsFirstResult(): void
    {
        $entity = new \stdClass();
        $result = $this->createMock(Result::class);

        $innerQuery = $this->createMock(DbalQueryBuilder::class);
        $innerQuery->method('from')->willReturnSelf();
        $innerQuery->method('limit')->willReturnSelf();
        $innerQuery->expects($this->once())
            ->method('where')
            ->with(['id' => 42], [])
            ->willReturnSelf();
        $innerQuery->method('executeQuery')->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($innerQuery);

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getIdentifier')->willReturn('id');
        $metadata->method('getTable')->willReturn('@system_node');

        $em = $this->createMock(EntityManager::class);
        $em->method('getConnection')->willReturn($connection);
        $em->expects($this->once())
            ->method('hydrateOne')
            ->with($result, $metadata)
            ->willReturn($entity);

        $repository = new Repository($em, $metadata);

        $this->assertSame($entity, $repository->find(42));
    }

    public function testFindReturnsNullWhenNoRowMatches(): void
    {
        $result = $this->createMock(Result::class);

        $innerQuery = $this->createMock(DbalQueryBuilder::class);
        $innerQuery->method('from')->willReturnSelf();
        $innerQuery->method('where')->willReturnSelf();
        $innerQuery->method('limit')->willReturnSelf();
        $innerQuery->method('executeQuery')->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($innerQuery);

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getIdentifier')->willReturn('id');
        $metadata->method('getTable')->willReturn('@system_node');

        $em = $this->createMock(EntityManager::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('hydrateOne')->willReturn(false);

        $repository = new Repository($em, $metadata);

        $this->assertNull($repository->find(7));
    }

    public function testFindThrowsWhenEntityHasNoIdentifier(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getIdentifier')->willReturn(null);
        $metadata->method('getClass')->willReturn(\stdClass::class);

        $repository = new Repository($this->createMock(EntityManager::class), $metadata);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("No identifier field found for entity 'stdClass'.");

        $repository->find(1);
    }

    public function testFindAllHydratesEveryRow(): void
    {
        $entities = [new \stdClass(), new \stdClass()];
        $result = $this->createMock(Result::class);

        $innerQuery = $this->createMock(DbalQueryBuilder::class);
        $innerQuery->method('from')->willReturnSelf();
        $innerQuery->method('executeQuery')->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($innerQuery);

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getTable')->willReturn('@system_node');

        $em = $this->createMock(EntityManager::class);
        $em->method('getConnection')->willReturn($connection);
        $em->expects($this->once())
            ->method('hydrateAll')
            ->with($result, $metadata)
            ->willReturn($entities);

        $repository = new Repository($em, $metadata);

        $this->assertSame($entities, $repository->findAll());
    }

    public function testWhereReturnsQueryBuilderCarryingTheCondition(): void
    {
        $innerQuery = $this->createMock(DbalQueryBuilder::class);
        $innerQuery->method('from')->willReturnSelf();
        $innerQuery->expects($this->once())
            ->method('where')
            ->with(['status' => 1], [])
            ->willReturnSelf();

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($innerQuery);

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getTable')->willReturn('@system_node');

        $em = $this->createMock(EntityManager::class);
        $em->method('getConnection')->willReturn($connection);

        $repository = new Repository($em, $metadata);

        // The static return type is already QueryBuilder<T>, so a direct
        // assertInstanceOf would be redundant; assert the exact runtime class
        // instead (the condition applied is verified by the mock above).
        $query = $repository->where(['status' => 1]);
        $this->assertSame(QueryBuilder::class, $query::class);
    }

    // -----------------------------------------------------------------------
    // removeRole(): the roles-field guard + the SQL strip.
    // -----------------------------------------------------------------------

    public function testRemoveRoleThrowsWhenEntityHasNoRolesField(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getField')->with('roles')->willReturn(null);
        $metadata->method('getClass')->willReturn(\stdClass::class);

        $repository = new Repository($this->createMock(EntityManager::class), $metadata);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Entity 'stdClass' has no 'roles' field");

        $repository->removeRole(3);
    }

    public function testRemoveRoleExecutesUpdateStrippingTheRoleId(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getField')->with('roles')->willReturn(['name' => 'roles', 'type' => 'simple_array', 'column' => 'roles']);
        $metadata->method('getTable')->willReturn('@system_user');

        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getConcatExpression')->willReturn('CONCAT_EXPR');
        $platform->method('getTrimExpression')->willReturnCallback(
            static fn (string $str): string => "TRIM($str)"
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('quote')->willReturn("','");

        $captured = null;
        $connection->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$captured): int {
                $captured = $sql;

                return 7;
            });

        $em = $this->createMock(EntityManager::class);
        $em->method('getConnection')->willReturn($connection);

        $repository = new Repository($em, $metadata);

        $this->assertSame(7, $repository->removeRole(3), 'removeRole() must return the affected-row count');

        $this->assertIsString($captured);
        $this->assertStringContainsString('UPDATE @system_user SET roles = NULLIF(', $captured);
        $this->assertStringContainsString("',3,'", $captured, 'the role id must be strip-matched inside the roles column');
    }
}
