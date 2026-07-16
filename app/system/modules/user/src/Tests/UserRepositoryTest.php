<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Doctrine\DBAL\Result;
use Pagekit\Database\Connection;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\Metadata;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\ORM\Repository;
use Pagekit\Database\Query\QueryBuilder as DbalQueryBuilder;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see UserRepository}.
 *
 * `findByUsername`/`findByEmail`/`findByCredentials`/`updateLogin` build a query
 * through the shared ORM {@see QueryBuilder}, so the EntityManager, its connection
 * and the inner DBAL query builder are mocked and each delegation is asserted in
 * isolation with no database. `findRoles()` is covered separately: its empty-roles
 * guard short-circuits before touching the manager, and the populated path both
 * queries the role ids (`whereIn`) and intersects the result down to exactly the
 * user's role ids.
 */
class UserRepositoryTest extends TestCase
{
    // -----------------------------------------------------------------------
    // findByUsername / findByEmail / findByCredentials: WHERE + first().
    // -----------------------------------------------------------------------

    public function testFindByUsernameQueriesTheUsernameColumn(): void
    {
        $user = new User();

        $em = $this->mockQueryPipeline(['username' => 'bob'], hydrated: $user);

        $repository = new UserRepository($em);

        $this->assertSame($user, $repository->findByUsername('bob'));
    }

    public function testFindByEmailQueriesTheEmailColumn(): void
    {
        $user = new User();

        $em = $this->mockQueryPipeline(['email' => 'bob@example.com'], hydrated: $user);

        $repository = new UserRepository($em);

        $this->assertSame($user, $repository->findByEmail('bob@example.com'));
    }

    public function testFindByEmailReturnsNullWhenNoRowMatches(): void
    {
        $em = $this->mockQueryPipeline(['email' => 'ghost@example.com'], hydrated: false);

        $repository = new UserRepository($em);

        $this->assertNull($repository->findByEmail('ghost@example.com'));
    }

    public function testFindByCredentialsQueriesEveryGivenCredential(): void
    {
        $user = new User();
        $credentials = ['username' => 'bob', 'password' => 'secret'];

        $em = $this->mockQueryPipeline($credentials, hydrated: $user);

        $repository = new UserRepository($em);

        $this->assertSame($user, $repository->findByCredentials($credentials));
    }

    // -----------------------------------------------------------------------
    // updateLogin(): stamps the login timestamp for the user's id.
    // -----------------------------------------------------------------------

    public function testUpdateLoginStampsTheCurrentTimestampForTheUserId(): void
    {
        $user = new User();
        $user->id = 5;

        $captured = null;

        $innerQuery = $this->createMock(DbalQueryBuilder::class);
        $innerQuery->method('from')->willReturnSelf();
        $innerQuery->expects($this->once())
            ->method('where')
            ->with(['id' => 5], [])
            ->willReturnSelf();
        $innerQuery->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (array $values) use (&$captured): int {
                $captured = $values;

                return 1;
            });

        $em = $this->newEntityManager($innerQuery);

        $repository = new UserRepository($em);
        $repository->updateLogin($user);

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('login', $captured);

        $login = $captured['login'];
        $this->assertIsString($login);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $login,
            'updateLogin must write a Y-m-d H:i:s timestamp'
        );
    }

    // -----------------------------------------------------------------------
    // findRoles(): empty-ids guard + query/intersect for populated users.
    // -----------------------------------------------------------------------

    public function testFindRolesShortCircuitsWhenTheUserHasNoRoles(): void
    {
        $user = new User();
        $user->roles = [];

        $em = $this->createMock(EntityManager::class);
        $em->method('getMetadata')->with(User::class)->willReturn($this->createMock(Metadata::class));
        $em->expects($this->never())->method('getRepository');

        $repository = new UserRepository($em);

        $this->assertSame([], $repository->findRoles($user), 'a user with no roles must never hit the role repository');
    }

    public function testFindRolesQueriesTheRoleIdsAndKeepsOnlyTheUsersRoles(): void
    {
        $user = new User();
        $user->roles = [1, 3];

        $role1 = new Role();
        $role1->id = 1;
        $role3 = new Role();
        $role3->id = 3;
        // A stray row the query might return must be dropped by the intersect.
        $role9 = new Role();
        $role9->id = 9;

        $roleQuery = $this->createMock(QueryBuilder::class);
        $roleQuery->expects($this->once())
            ->method('__call')
            ->with('whereIn', ['id', [1, 3]])
            ->willReturnSelf();
        $roleQuery->expects($this->once())
            ->method('get')
            ->willReturn([1 => $role1, 3 => $role3, 9 => $role9]);

        $roleRepository = $this->createMock(Repository::class);
        $roleRepository->method('query')->willReturn($roleQuery);

        $em = $this->createMock(EntityManager::class);
        $em->method('getMetadata')->with(User::class)->willReturn($this->createMock(Metadata::class));
        $em->method('getRepository')->with(Role::class)->willReturn($roleRepository);

        $repository = new UserRepository($em);

        $this->assertSame(
            [1 => $role1, 3 => $role3],
            $repository->findRoles($user),
            'findRoles must return only the roles whose id the user actually holds'
        );
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * Wires a mock EntityManager whose connection yields the given inner DBAL
     * query builder, so a UserRepository built on it queries through a fully
     * controlled pipeline.
     */
    private function newEntityManager(DbalQueryBuilder $innerQuery): EntityManager
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($innerQuery);

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getTable')->willReturn('@system_user');

        $em = $this->createMock(EntityManager::class);
        $em->method('getMetadata')->with(User::class)->willReturn($metadata);
        $em->method('getConnection')->willReturn($connection);

        return $em;
    }

    /**
     * Builds the mock query pipeline for a `where($condition)->first()` finder:
     * the inner DBAL builder asserts the WHERE condition and the manager
     * hydrates the given result.
     *
     * @param array<string, mixed> $condition the exact WHERE array the finder must build
     * @param object|false         $hydrated  the value `hydrateOne()` returns (an entity or `false`)
     */
    private function mockQueryPipeline(array $condition, object|false $hydrated): EntityManager
    {
        $result = $this->createMock(Result::class);

        $innerQuery = $this->createMock(DbalQueryBuilder::class);
        $innerQuery->method('from')->willReturnSelf();
        $innerQuery->method('limit')->willReturnSelf();
        $innerQuery->expects($this->once())
            ->method('where')
            ->with($condition, [])
            ->willReturnSelf();
        $innerQuery->method('executeQuery')->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($innerQuery);

        $metadata = $this->createMock(Metadata::class);
        $metadata->method('getTable')->willReturn('@system_user');

        $em = $this->createMock(EntityManager::class);
        $em->method('getMetadata')->with(User::class)->willReturn($metadata);
        $em->method('getConnection')->willReturn($connection);
        $em->expects($this->once())
            ->method('hydrateOne')
            ->with($result, $metadata)
            ->willReturn($hydrated);

        return $em;
    }
}
