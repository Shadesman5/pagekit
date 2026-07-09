<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\User\Model\Role;
use Pagekit\User\Model\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DB-free logic of {@see User}: role flags, status flags,
 * status text and the cached branch of hasPermission().
 *
 * `isAnonymous`/`isAuthenticated`/`isAdministrator` delegate to
 * {@see \Pagekit\User\Model\AccessModelTrait::hasRole()}, which reads the public
 * `$roles` array, so each test seeds `$roles` directly. `getStatusText()` /
 * `getStatuses()` call the unqualified `__()` helper; `User` lives in
 * `Pagekit\User\Model` and imports no `use function`, so PHP's fallback rule
 * resolves that to the GLOBAL `\__()` (ticket discovery note 5). {@see setUp}
 * pulls in the shared passthrough stub from Tests/bootstrap.php.
 *
 * NOTE - deferred to Step 2.1.9 (Test Coverage Expansion): the *uncached* branch
 * of `User::hasPermission()` (`$this->permissions === null`) delegates to
 * `UserModelTrait::findRoles()`, a static `Role::where(...)->get()` read that
 * needs a booted kernel + database and belongs to integration coverage, not this
 * unit suite (ticket discovery note 6). The cached branch is pinned below by
 * pre-seeding the protected `$permissions` via Reflection.
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
    // hasPermission(): cached branch only (uncached DB path deferred to 2.1.9).
    // -----------------------------------------------------------------------

    /**
     * Pre-seeding the protected `$permissions` to a non-null array drives the
     * cached branch (`$this->permissions === null` is false), so `findRoles()`
     * and its `Role::where(...)` DB read are never reached — the test would
     * otherwise throw "EntityManager has not been initialized". Present + absent
     * lookups pin the `in_array()` verdict against mutation.
     */
    public function testHasPermissionUsesCachedPermissionsWithoutHittingDatabase(): void
    {
        $user = new User();

        $permissions = new \ReflectionProperty(User::class, 'permissions');
        $permissions->setValue($user, ['blog: manage posts', 'system: access admin area']);

        $this->assertTrue($user->hasPermission('blog: manage posts'));
        $this->assertTrue($user->hasPermission('system: access admin area'));
        $this->assertFalse($user->hasPermission('system: manage users'));
    }
}
