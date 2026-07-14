<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\Auth\Event\LoginEvent;
use Pagekit\Auth\UserInterface;
use Pagekit\Database\ORM\EntityEvent;
use Pagekit\Database\ORM\EntityManager;
use Pagekit\User\Event\UserListener;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see UserListener}.
 *
 * Since the Step 2.1.11 EntityManager-DI migration the listener takes an injected
 * {@see UserRepository}, so all three members are pure unit tests against a mocked
 * repository — no kernel, container or database:
 *   - `onUserLogin()` stamps the last-login time through
 *     {@see UserRepository::updateLogin()}, skipping non-`User` principals;
 *   - `onRoleDelete()` strips the deleted role id from every user via
 *     {@see UserRepository::removeRole()};
 *   - `subscribe()` is the pure event -> handler map.
 */
class UserListenerTest extends TestCase
{
    public function testOnUserLoginStampsLoginThroughRepository(): void
    {
        $user = new User();

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('updateLogin')->with($user);

        (new UserListener($users))->onUserLogin(new LoginEvent('auth.login', $user));
    }

    /**
     * A non-`User` principal (a bare {@see UserInterface}) has no persistent row
     * to stamp, so the login handler must skip the repository call entirely.
     */
    public function testOnUserLoginIgnoresNonUserPrincipal(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('updateLogin');

        (new UserListener($users))->onUserLogin(
            new LoginEvent('auth.login', $this->createMock(UserInterface::class))
        );
    }

    public function testOnRoleDeleteRemovesRoleByIdThroughRepository(): void
    {
        $role = new Role();
        $role->id = 42;

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('removeRole')->with(42);

        (new UserListener($users))->onRoleDelete(
            new EntityEvent('model.role.deleted', $this->createMock(EntityManager::class)),
            $role
        );
    }

    public function testSubscribeMapsEventsToHandlers(): void
    {
        $this->assertSame([
            'auth.login' => 'onUserLogin',
            'model.role.deleted' => 'onRoleDelete',
        ], (new UserListener($this->createMock(UserRepository::class)))->subscribe());
    }
}
