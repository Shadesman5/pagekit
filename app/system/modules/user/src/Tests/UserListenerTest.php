<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\User\Event\UserListener;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see UserListener}.
 *
 * Only `subscribe()` is unit-testable: it is a pure event -> handler map with no
 * collaborators, so it is pinned below.
 *
 * NOTE - deferred to Step 2.1.9 (Test Coverage Expansion): the two handlers
 * `onUserLogin()` and `onRoleDelete()` are thin static delegations to
 * `User::updateLogin()` and `User::removeRole()` respectively. Both are
 * static, DB-bound `User::` calls that need a booted kernel + database and
 * belong to integration coverage, not this unit suite (ticket discovery
 * note 6). Because only `subscribe()` is covered here, those handlers sit on
 * uncovered lines: their mutants fall outside the Covered-MSI gate and needed
 * no `infection.json.dist` ignore. Killing them is deferred to Step 2.1.9
 * (integration coverage: booted kernel + database).
 */
class UserListenerTest extends TestCase
{
    public function testSubscribeMapsEventsToHandlers(): void
    {
        $this->assertSame([
            'auth.login' => 'onUserLogin',
            'model.role.deleted' => 'onRoleDelete',
        ], (new UserListener())->subscribe());
    }
}
