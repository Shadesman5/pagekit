<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\User\Model\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure, DB-free methods of {@see Role}.
 *
 * Every method under test operates purely on the entity's public state
 * ($id, $name, $permissions), so each test constructs a plain `new Role()` and
 * seeds those properties directly — no kernel, DB or container required. The
 * system-role matrix ({@see systemRoleProvider}) drives every id through
 * isAnonymous/isAuthenticated/isAdministrator/isLocked so a distinct true/false
 * vector pins the `==` comparisons and the `in_array()` membership list against
 * mutation (an item dropped from the locked list, or a swapped role constant,
 * flips exactly one expected value and fails).
 */
class RoleTest extends TestCase
{
    // -----------------------------------------------------------------------
    // permissions: hasPermission / addPermission / clearPermissions.
    // -----------------------------------------------------------------------

    public function testHasPermissionIsFalseWhenNoPermissionsSet(): void
    {
        $role = new Role();

        $this->assertFalse($role->hasPermission('system: access admin area'));
    }

    public function testAddPermissionAppendsAndIsThenReported(): void
    {
        $role = new Role();
        $role->addPermission('system: access admin area');
        $role->addPermission('system: manage users');

        $this->assertSame(
            ['system: access admin area', 'system: manage users'],
            $role->permissions
        );
        $this->assertTrue($role->hasPermission('system: access admin area'));
        $this->assertTrue($role->hasPermission('system: manage users'));
    }

    public function testHasPermissionDistinguishesPresentFromAbsent(): void
    {
        $role = new Role();
        $role->addPermission('system: access admin area');

        $this->assertTrue($role->hasPermission('system: access admin area'));
        $this->assertFalse($role->hasPermission('system: manage users'));
    }

    public function testClearPermissionsRemovesEveryPermission(): void
    {
        $role = new Role();
        $role->addPermission('system: access admin area');
        $role->addPermission('system: manage users');

        $role->clearPermissions();

        $this->assertSame([], $role->permissions);
        $this->assertFalse($role->hasPermission('system: access admin area'));
    }

    // -----------------------------------------------------------------------
    // system-role flags: isLocked / isAnonymous / isAuthenticated / isAdministrator.
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: bool, 2: bool, 3: bool, 4: bool}>
     */
    public static function systemRoleProvider(): array
    {
        return [
            // id, isAnonymous, isAuthenticated, isAdministrator, isLocked
            'anonymous' => [Role::ROLE_ANONYMOUS, true, false, false, true],
            'authenticated' => [Role::ROLE_AUTHENTICATED, false, true, false, true],
            'administrator' => [Role::ROLE_ADMINISTRATOR, false, false, true, true],
            'custom' => [99, false, false, false, false],
        ];
    }

    #[DataProvider('systemRoleProvider')]
    public function testSystemRoleFlags(
        int $id,
        bool $isAnonymous,
        bool $isAuthenticated,
        bool $isAdministrator,
        bool $isLocked
    ): void {
        $role = new Role();
        $role->id = $id;

        $this->assertSame($isAnonymous, $role->isAnonymous());
        $this->assertSame($isAuthenticated, $role->isAuthenticated());
        $this->assertSame($isAdministrator, $role->isAdministrator());
        $this->assertSame($isLocked, $role->isLocked());
    }

    public function testNewRoleWithoutIdIsNeitherSystemNorLocked(): void
    {
        $role = new Role();

        $this->assertFalse($role->isAnonymous());
        $this->assertFalse($role->isAuthenticated());
        $this->assertFalse($role->isAdministrator());
        $this->assertFalse($role->isLocked());
    }

    // -----------------------------------------------------------------------
    // __toString.
    // -----------------------------------------------------------------------

    public function testToStringReturnsName(): void
    {
        $role = new Role();
        $role->name = 'Editor';

        $this->assertSame('Editor', (string) $role);
    }

    public function testToStringReturnsEmptyStringWhenNameIsNull(): void
    {
        $role = new Role();

        $this->assertSame('', (string) $role);
    }
}
