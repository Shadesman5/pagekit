<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DB-free logic of {@see User}: role flags, status flags,
 * status text and both branches of hasPermission().
 *
 * `isAnonymous`/`isAuthenticated`/`isAdministrator` delegate to
 * {@see \Pagekit\User\Model\AccessModelTrait::hasRole()}, which reads the public
 * `$roles` array, so each test seeds `$roles` directly. `getStatusText()` /
 * `getStatuses()` call the unqualified `__()` helper; `User` lives in
 * `Pagekit\User\Model` and imports no `use function`, so PHP's fallback rule
 * resolves that to the GLOBAL `\__()` (ticket discovery note 5). {@see setUp}
 * pulls in the shared passthrough stub from Tests/bootstrap.php.
 *
 * Since the Step 2.1.11 EntityManager-DI migration the *uncached* branch of
 * `User::hasPermission()` (`$this->permissions === null`) no longer performs a
 * static DB read: it resolves roles through a per-instance loader closure that
 * {@see \Pagekit\User\Model\UserModelTrait::init()} wires to the EntityManager at
 * hydration time. That makes the branch a pure unit test — the cases below inject
 * a fake loader via {@see User::setRoleLoader()} and exercise `hasPermission()`,
 * its memoization, the missing-loader guard, and the `hasAccess()` path that
 * builds on it, all with no kernel, container or database.
 */
class UserTest extends TestCase
{
    protected function setUp(): void
    {
        // Global \__() translation stub: User::getStatusText()/getStatuses()
        // resolve the unqualified __() to the global namespace (see class note).
        require_once __DIR__ . '/bootstrap.php';
    }

    // -----------------------------------------------------------------------
    // role flags: isAnonymous / isAuthenticated / isAdministrator (via hasRole).
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<int, int>, 1: bool, 2: bool, 3: bool}>
     */
    public static function roleFlagProvider(): array
    {
        return [
            // roles, isAnonymous, isAuthenticated, isAdministrator
            'anonymous only' => [[Role::ROLE_ANONYMOUS], true, false, false],
            'authenticated only' => [[Role::ROLE_AUTHENTICATED], false, true, false],
            'administrator only' => [[Role::ROLE_ADMINISTRATOR], false, false, true],
            'authenticated and administrator' => [[Role::ROLE_AUTHENTICATED, Role::ROLE_ADMINISTRATOR], false, true, true],
            'no roles' => [[], false, false, false],
        ];
    }

    /**
     * @param array<int, int> $roles
     */
    #[DataProvider('roleFlagProvider')]
    public function testRoleFlagsFromSeededRoles(
        array $roles,
        bool $isAnonymous,
        bool $isAuthenticated,
        bool $isAdministrator
    ): void {
        $user = new User();
        $user->roles = $roles;

        $this->assertSame($isAnonymous, $user->isAnonymous());
        $this->assertSame($isAuthenticated, $user->isAuthenticated());
        $this->assertSame($isAdministrator, $user->isAdministrator());
    }

    /**
     * hasRole() is public API (AccessModelTrait) consumed by callers outside the
     * class hierarchy, not just the internal is*() flags. Invoking it directly
     * pins its public visibility (a PublicVisibility mutant to protected would
     * make this external call fatal) and its in_array() membership verdict.
     */
    public function testHasRoleIsPublicApiAndReportsMembership(): void
    {
        $user = new User();
        $user->roles = [Role::ROLE_AUTHENTICATED];

        $this->assertTrue($user->hasRole(Role::ROLE_AUTHENTICATED));
        $this->assertFalse($user->hasRole(Role::ROLE_ADMINISTRATOR));
    }

    // -----------------------------------------------------------------------
    // status flags: isActive / isBlocked.
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: bool, 2: bool}>
     */
    public static function statusFlagProvider(): array
    {
        return [
            // status, isActive, isBlocked
            'active' => [User::STATUS_ACTIVE, true, false],
            'blocked' => [User::STATUS_BLOCKED, false, true],
        ];
    }

    #[DataProvider('statusFlagProvider')]
    public function testStatusFlags(int $status, bool $isActive, bool $isBlocked): void
    {
        $user = new User();
        $user->status = $status;

        $this->assertSame($isActive, $user->isActive());
        $this->assertSame($isBlocked, $user->isBlocked());
    }

    // -----------------------------------------------------------------------
    // getStatusText(): mapped values plus the unknown-status fallback.
    // -----------------------------------------------------------------------

    public function testGetStatusTextForActive(): void
    {
        $user = new User();
        $user->status = User::STATUS_ACTIVE;

        $this->assertSame('Active', $user->getStatusText());
    }

    public function testGetStatusTextForBlocked(): void
    {
        $user = new User();
        $user->status = User::STATUS_BLOCKED;

        $this->assertSame('Blocked', $user->getStatusText());
    }

    public function testGetStatusTextForUnknownStatusFallsBackToUnknown(): void
    {
        $user = new User();
        $user->status = 99;

        $this->assertSame('Unknown', $user->getStatusText());
    }

    /**
     * getStatuses() is public static API (consumed by the admin user UI), not only
     * an internal helper of getStatusText(). Calling it directly pins its public
     * visibility (a PublicVisibility mutant to protected would fatal here) and its
     * status-constant -> label map.
     */
    public function testGetStatusesIsPublicAndMapsStatusConstants(): void
    {
        $statuses = User::getStatuses();

        $this->assertSame('Active', $statuses[User::STATUS_ACTIVE]);
        $this->assertSame('Blocked', $statuses[User::STATUS_BLOCKED]);
    }

    // -----------------------------------------------------------------------
    // hasPermission(): cached memo branch + uncached role-loader branch.
    // -----------------------------------------------------------------------

    /**
     * Pre-seeding the protected `$permissions` to a non-null array drives the
     * cached branch (`$this->permissions === null` is false), so the role loader
     * is never consulted (none is attached here, which would otherwise trip the
     * missing-loader guard). Present + absent lookups pin the `in_array()` verdict
     * against mutation.
     */
    public function testHasPermissionUsesCachedPermissionsWithoutInvokingLoader(): void
    {
        $user = new User();

        $permissions = new \ReflectionProperty(User::class, 'permissions');
        $permissions->setValue($user, ['blog: manage posts', 'system: access admin area']);

        $this->assertTrue($user->hasPermission('blog: manage posts'));
        $this->assertTrue($user->hasPermission('system: access admin area'));
        $this->assertFalse($user->hasPermission('system: manage users'));
    }

    /**
     * The uncached branch resolves permissions by flattening the roles returned
     * from the injected loader. Present + absent lookups pin the `in_array()`
     * verdict; a grant carried by the loaded role is honoured, an unknown one is
     * denied.
     */
    public function testHasPermissionResolvesPermissionsThroughInjectedLoader(): void
    {
        $user = new User();
        $user->roles = [Role::ROLE_AUTHENTICATED];

        $role = new Role();
        $role->permissions = ['blog: manage posts', 'system: access admin area'];

        $user->setRoleLoader(static function (array $ids) use ($role): array {
            return [$role];
        });

        $this->assertTrue($user->hasPermission('blog: manage posts'));
        $this->assertTrue($user->hasPermission('system: access admin area'));
        $this->assertFalse($user->hasPermission('system: manage users'));
    }

    /**
     * The loader must run once: the first call fills the `$permissions` memo and
     * every later check reuses it. A counter captured by the loader closure pins
     * the memoization against a mutant that drops the `=== null` short-circuit.
     */
    public function testHasPermissionMemoizesRolesAfterFirstLoad(): void
    {
        $user = new User();
        $user->roles = [Role::ROLE_AUTHENTICATED];

        $role = new Role();
        $role->permissions = ['blog: manage posts'];

        $calls = 0;
        $user->setRoleLoader(static function (array $ids) use (&$calls, $role): array {
            $calls++;

            return [$role];
        });

        $user->hasPermission('blog: manage posts');
        $user->hasPermission('system: access admin area');

        $this->assertSame(1, $calls, 'the role loader must run once and then reuse the memoized permissions');
    }

    /**
     * A `User` that was never hydrated through the EntityManager (a bare
     * `new User()`) has no loader attached, so the uncached branch must fail
     * loudly rather than silently granting or denying access.
     */
    public function testHasPermissionThrowsWhenNoRoleLoaderAttached(): void
    {
        $user = new User();

        $this->expectException(\LogicException::class);

        $user->hasPermission('system: access admin area');
    }

    /**
     * `hasAccess()` builds directly on the uncached `hasPermission()` branch, so a
     * boolean expression is resolved end-to-end through the injected loader — no
     * `hasPermission()` stub, unlike the parser-focused {@see UserAccessTest}.
     */
    public function testHasAccessResolvesBooleanExpressionThroughInjectedLoader(): void
    {
        $user = new User();
        $user->roles = [Role::ROLE_AUTHENTICATED];

        $role = new Role();
        $role->permissions = ['read', 'write'];

        $user->setRoleLoader(static function (array $ids) use ($role): array {
            return [$role];
        });

        $this->assertTrue($user->hasAccess('read && write'));
        $this->assertFalse($user->hasAccess('read && delete'));
    }
}
