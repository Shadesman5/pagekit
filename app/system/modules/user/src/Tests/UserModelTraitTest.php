<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see \Pagekit\User\Model\UserModelTrait::saving()} lifecycle
 * handler after its Step 2 migration to the `(EntityEvent, User)` signature.
 *
 * The handler is pure in-memory logic — it guarantees every saved user carries
 * the authenticated role — and never reaches the {@see EntityManager} on the
 * event, so the event only needs to exist (its manager is a bare mock).
 */
class UserModelTraitTest extends TestCase
{
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

    private function newEvent(): EntityEvent
    {
        return new EntityEvent('user.saving', $this->createMock(EntityManager::class));
    }
}
