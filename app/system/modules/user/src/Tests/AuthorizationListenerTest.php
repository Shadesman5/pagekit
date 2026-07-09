<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\Auth\Auth;
use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Auth\Event\AuthenticateEvent;
use Pagekit\Auth\Event\AuthorizeEvent;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\Auth\UserInterface;
use Pagekit\User\Auth\UserProvider;
use Pagekit\User\Event\AuthorizationListener;
use Pagekit\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Unit tests for {@see AuthorizationListener}.
 *
 * The listener depends only on an {@see Auth} (a plain, non-final class →
 * mockable), a {@see PasswordEncoderInterface} and a {@see SessionInterface},
 * so every handler is fully unit-testable with mocks — no kernel, container or
 * database. `Auth` is mocked (its constructor is bypassed by `createMock`), so
 * `getUser()`/`logout()`/`setUserProvider()` are driven directly. Blocked/active
 * state is exercised with real `User` entities (`status` + `login` are public),
 * which keeps `isBlocked()` and the `$user->login` ternary honest.
 *
 * `__()` resolution (ticket discovery note 5): `onAuthorize()` throws
 * `AuthException(__('...'))` via the UNQUALIFIED helper. `AuthorizationListener`
 * lives in `Pagekit\User\Event` and imports no `use function`, so PHP's fallback
 * rule resolves `__()` to the GLOBAL `\__()` — not `Pagekit\__()` (the exact same
 * resolution already relied on by Step 6's LoginAttemptListenerTest, which is in
 * this namespace and passes). {@see setUp} pulls in the shared passthrough stub
 * from Tests/bootstrap.php (guarded `if (!function_exists('__'))`); no
 * `Pagekit\__()` stub is required.
 */
class AuthorizationListenerTest extends TestCase
{
    protected function setUp(): void
    {
        // Global \__() translation stub: onAuthorize() throws
        // AuthException(__('...')) via the unqualified helper (see class note).
        require_once __DIR__ . '/bootstrap.php';
    }

    // -----------------------------------------------------------------------
    // onSystemInit(): registers a UserProvider (wired to the auth encoder).
    // -----------------------------------------------------------------------

    public function testOnSystemInitRegistersUserProviderBackedByAuthEncoder(): void
    {
        [$listener, $auth, $encoder] = $this->make();

        $auth->expects($this->once())
            ->method('setUserProvider')
            ->with($this->callback(function (mixed $provider) use ($encoder): bool {
                return $provider instanceof UserProvider
                    && (new \ReflectionProperty(UserProvider::class, 'encoder'))->getValue($provider) === $encoder;
            }));

        $listener->onSystemInit();
    }

    // -----------------------------------------------------------------------
    // onRequest(): log out blocked users only.
    // -----------------------------------------------------------------------

    public function testOnRequestLogsOutBlockedUser(): void
    {
        [$listener, $auth] = $this->make();

        $user = new User();
        $user->status = User::STATUS_BLOCKED;

        $auth->method('getUser')->willReturn($user);
        $auth->expects($this->once())->method('logout');

        $listener->onRequest();
    }

    public function testOnRequestDoesNotLogOutActiveUser(): void
    {
        [$listener, $auth] = $this->make();

        $user = new User();
        $user->status = User::STATUS_ACTIVE;

        $auth->method('getUser')->willReturn($user);
        $auth->expects($this->never())->method('logout');

        $listener->onRequest();
    }

    public function testOnRequestDoesNotLogOutNonUser(): void
    {
        [$listener, $auth] = $this->make();

        // A bare UserInterface is not a User instance, so the instanceof guard
        // must skip it even though it is a (non-null) authenticated principal.
        $auth->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $auth->expects($this->never())->method('logout');

        $listener->onRequest();
    }

    // -----------------------------------------------------------------------
    // onAuthorize(): block not-activated / blocked users.
    // -----------------------------------------------------------------------

    public function testOnAuthorizeThrowsBlockedMessageWhenUserHasLoggedInBefore(): void
    {
        [$listener] = $this->make();

        $user = new User();
        $user->status = User::STATUS_BLOCKED;
        $user->login = new \DateTime();

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Your account is blocked.');

        $listener->onAuthorize(new AuthorizeEvent('auth.authorize', $user));
    }

    public function testOnAuthorizeThrowsNotActivatedMessageWhenUserNeverLoggedIn(): void
    {
        [$listener] = $this->make();

        $user = new User();
        $user->status = User::STATUS_BLOCKED;
        $user->login = null;

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Your account has not been activated.');

        $listener->onAuthorize(new AuthorizeEvent('auth.authorize', $user));
    }

    public function testOnAuthorizeDoesNotThrowForActiveUser(): void
    {
        [$listener] = $this->make();

        $user = new User();
        $user->status = User::STATUS_ACTIVE;

        $this->expectNotToPerformAssertions();

        $listener->onAuthorize(new AuthorizeEvent('auth.authorize', $user));
    }

    public function testOnAuthorizeDoesNotThrowWhenNoUserOnEvent(): void
    {
        [$listener] = $this->make();

        // A null (non-User) event user must trip the instanceof guard before
        // isBlocked() is ever reached — dropping that guard would fatal here.
        $this->expectNotToPerformAssertions();

        $listener->onAuthorize(new AuthorizeEvent('auth.authorize', null));
    }

    // -----------------------------------------------------------------------
    // onLogin / onSuccess / onFailure: session side effects.
    // -----------------------------------------------------------------------

    public function testOnLoginMigratesSession(): void
    {
        [$listener, , , $session] = $this->make();

        $session->expects($this->once())->method('migrate');

        $listener->onLogin();
    }

    public function testOnSuccessRemovesLastUsernameFromSession(): void
    {
        [$listener, , , $session] = $this->make();

        $session->expects($this->once())
            ->method('remove')
            ->with(Auth::LAST_USERNAME);

        $listener->onSuccess();
    }

    public function testOnFailureStoresAttemptedUsernameInSession(): void
    {
        [$listener, , , $session] = $this->make();

        $session->expects($this->once())
            ->method('set')
            ->with(Auth::LAST_USERNAME, 'alice');

        $listener->onFailure(new AuthenticateEvent('auth.failure', ['username' => 'alice']));
    }

    // -----------------------------------------------------------------------
    // subscribe(): event -> handler (+ priority) mapping.
    // -----------------------------------------------------------------------

    public function testSubscribeMapsEventsToHandlers(): void
    {
        [$listener] = $this->make();

        $this->assertSame([
            'request' => [
                ['onRequest', 0],
                ['onSystemInit', 50],
            ],
            'auth.authorize' => 'onAuthorize',
            'auth.login' => ['onLogin', -8],
            'auth.success' => 'onSuccess',
            'auth.failure' => 'onFailure',
        ], $listener->subscribe());
    }

    // -----------------------------------------------------------------------
    // Fixtures.
    // -----------------------------------------------------------------------

    /**
     * @return array{
     *     0: AuthorizationListener,
     *     1: Auth&MockObject,
     *     2: PasswordEncoderInterface&MockObject,
     *     3: SessionInterface&MockObject
     * }
     */
    private function make(): array
    {
        $auth = $this->createMock(Auth::class);
        $encoder = $this->createMock(PasswordEncoderInterface::class);
        $session = $this->createMock(SessionInterface::class);

        return [new AuthorizationListener($auth, $encoder, $session), $auth, $encoder, $session];
    }
}
