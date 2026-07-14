<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Database\ORM\Repository;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see \Pagekit\User\Model\UserModelTrait} lifecycle handlers.
 *
 * `saving()` (Step 2 migration to the `(EntityEvent, User)` signature) is pure
 * in-memory logic — it guarantees every saved user carries the authenticated
 * role — and never reaches the {@see EntityManager} on the event, so there the
 * event only needs to exist (its manager is a bare mock).
 *
 * `init()` (Step 2.1.11) is the security-critical `#[ORM\Init]` handler that wires
 * the per-instance role loader consumed by {@see User::hasPermission()}
 * (Architecture decision 6): it attaches a closure resolving the user's role ids
 * through the *event's* {@see EntityManager} —
 * `getRepository(Role::class)->query()->whereIn('id', $ids)->get()` — with an
 * empty-ids short-circuit. The cases below drive that wired loader end-to-end via
 * `hasPermission()` (a bare, un-hydrated `new User()` would instead trip the
 * missing-loader guard covered in {@see UserTest}), pinning both the query the
 * loader builds and the guard, all against mocks with no kernel, container or
 * database.
 */
class UserModelTraitTest extends TestCase
{
    // -----------------------------------------------------------------------
    // saving(): guarantees the authenticated role on every persisted user.
    // -----------------------------------------------------------------------

    public function testSavingAddsTheAuthenticatedRoleWhenMissing(): void
    {
        $user = new User();
        $user->roles = [];

        User::saving($this->newEvent(), $user);

        $this->assertSame([Role::ROLE_AUTHENTICATED], $user->roles, 'a user without roles must gain the authenticated role');
    }

    public function testSavingPreservesExistingRolesAndAppendsAuthenticated(): void
    {
        $user = new User();
        $user->roles = [Role::ROLE_ADMINISTRATOR];

        User::saving($this->newEvent(), $user);

        $this->assertSame(
            [Role::ROLE_ADMINISTRATOR, Role::ROLE_AUTHENTICATED],
            $user->roles,
            'existing roles must be kept and the authenticated role appended'
        );
    }

    public function testSavingDoesNotDuplicateTheAuthenticatedRole(): void
    {
        $user = new User();
        $user->roles = [Role::ROLE_AUTHENTICATED];

        User::saving($this->newEvent(), $user);

        $this->assertSame([Role::ROLE_AUTHENTICATED], $user->roles, 'the authenticated role must never be duplicated');
    }

    // -----------------------------------------------------------------------
    // init(): wires the per-instance role loader to the event EntityManager.
    // -----------------------------------------------------------------------

    /**
     * The `#[ORM\Init]` handler must attach a working role loader — not a no-op:
     * the loader resolves the user's role ids through the *event's* EntityManager
     * (`getRepository(Role::class)->query()->whereIn('id', $ids)->get()`) and
     * `hasPermission()` flattens the loaded roles' permissions. A grant carried by
     * the loaded role is honoured, an unknown one denied. A missing
     * `setRoleLoader()` call would instead trip the missing-loader guard and throw
     * before either assertion, so this also pins that the loader is actually wired.
     */
    public function testInitAttachesRoleLoaderResolvingRolesThroughTheEventEntityManager(): void
    {
        $user = new User();
        $user->roles = [1, 3];

        $role = new Role();
        $role->permissions = ['system: access admin area'];

        $roleQuery = $this->createMock(QueryBuilder::class);
        $roleQuery->expects($this->once())
            ->method('__call')
            ->with('whereIn', ['id', [1, 3]])
            ->willReturnSelf();
        $roleQuery->expects($this->once())
            ->method('get')
            ->willReturn([$role]);

        $roleRepository = $this->createMock(Repository::class);
        $roleRepository->method('query')->willReturn($roleQuery);

        $em = $this->createMock(EntityManager::class);
        $em->method('getRepository')->with(Role::class)->willReturn($roleRepository);

        User::init(new EntityEvent('user.init', $em), $user);

        $this->assertTrue($user->hasPermission('system: access admin area'));
        $this->assertFalse($user->hasPermission('system: manage users'));
    }

    /**
     * The wired loader's empty-ids guard must short-circuit: a user holding no
     * roles resolves to zero permissions without ever hitting the manager. The
     * `never()` expectation on `getRepository()` pins the `if (!$ids) return [];`
     * guard — a mutant dropping it would query the role repository for an empty id
     * set.
     */
    public function testInitAttachesRoleLoaderThatShortCircuitsForAUserWithoutRoles(): void
    {
        $user = new User();
        $user->roles = [];

        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('getRepository');

        User::init(new EntityEvent('user.init', $em), $user);

        $this->assertFalse($user->hasPermission('system: access admin area'));
    }

    private function newEvent(): EntityEvent
    {
        return new EntityEvent('user.saving', $this->createMock(EntityManager::class));
    }
}
