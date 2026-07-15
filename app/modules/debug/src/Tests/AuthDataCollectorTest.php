<?php

declare(strict_types=1);

namespace Pagekit\Debug\Tests;

use Pagekit\Auth\Auth;
use Pagekit\Debug\DataCollector\AuthDataCollector;
use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers AuthDataCollector: authenticated role names come from the injected
 * UserRepository's findRoles(). Auth and the repository are mocked directly, so
 * no database is required.
 */
class AuthDataCollectorTest extends TestCase
{
    public function testCollectReturnsDisabledPayloadWhenAuthIsNull(): void
    {
        $result = (new AuthDataCollector())->collect();

        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['authenticated']);
        $this->assertSame('', $result['user']);
        $this->assertSame([], $result['roles']);
    }

    public function testCollectReturnsUnauthenticatedPayloadWhenAuthHasNoUser(): void
    {
        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn(null);

        $result = (new AuthDataCollector($auth))->collect();

        $this->assertTrue($result['enabled']);
        $this->assertFalse($result['authenticated']);
        $this->assertSame('', $result['user']);
        $this->assertSame([], $result['roles']);
    }

    public function testCollectMapsAuthenticatedUserRolesThroughRepositoryFindRoles(): void
    {
        $user = new User();
        $user->username = 'alice';
        $user->roles = [Role::ROLE_AUTHENTICATED];

        $editor = new Role();
        $editor->name = 'Editor';

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('findRoles')->with($user)->willReturn([4 => $editor]);

        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn($user);

        $result = (new AuthDataCollector($auth, $users))->collect();

        $this->assertTrue($result['enabled']);
        $this->assertTrue($result['authenticated']);
        $this->assertSame('alice', $result['user']);
        $this->assertSame(User::class, $result['user_class']);
        $this->assertSame(['Editor'], array_values($result['roles']));
    }

    public function testCollectReturnsEmptyRolesWhenRepositoryIsNotInjected(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isAuthenticated')->willReturn(true);
        $user->method('getUsername')->willReturn('bob');

        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn($user);

        $result = (new AuthDataCollector($auth))->collect();

        $this->assertSame([], $result['roles']);
    }
}
