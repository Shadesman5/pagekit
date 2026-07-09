<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\Application\Response;
use Pagekit\Application\UrlProvider;
use Pagekit\Auth\Auth;
use Pagekit\Auth\Event\AuthorizeEvent;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\Event\Event;
use Pagekit\Kernel\Event\RequestEvent;
use Pagekit\Routing\Generator\UrlGenerator;
use Pagekit\Routing\Route;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Event\AccessListener;
use Pagekit\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Unit tests for {@see AccessListener}, focused on the request-time enforcement
 * paths (the security-critical core the ticket prioritises).
 *
 * All four collaborators are mockable with no kernel/DB: {@see Auth} and
 * {@see UrlProvider}/{@see Response} are plain classes (constructors bypassed by
 * `createMock`), {@see RequestStack} is an interface-like service, and the
 * {@see Request}/{@see RequestEvent} carrying the state are constructed for real
 * (or mocked where only `setResponse()` matters). `#[Access]`-attribute reading
 * (`onConfigureRoute()`/`processAccessAttribute()`) is exercised end-to-end
 * against real {@see Route}s pointed at the fixture controllers at the foot of
 * this file, so the reflection walk, dedup and admin path-rewrite are all pinned.
 *
 * `__()` resolution (ticket discovery note 5): `onLateRequest()`/`onAuthorize()`
 * throw messages via the UNQUALIFIED helper. `AccessListener` lives in
 * `Pagekit\User\Event` and imports no `use function`, so PHP's fallback rule
 * resolves `__()` to the GLOBAL `\__()` (same resolution as the sibling
 * listeners). {@see setUp} pulls in the shared passthrough stub from
 * Tests/bootstrap.php (guarded `if (!function_exists('__'))`).
 */
class AccessListenerTest extends TestCase
{
    protected function setUp(): void
    {
        // Global \__() translation stub: onLateRequest()/onAuthorize() throw
        // AuthException/HttpException(__('...')) via the unqualified helper.
        require_once __DIR__ . '/bootstrap.php';
    }

    // -----------------------------------------------------------------------
    // onLateRequest(): evaluate the stored access expressions on the user.
    // -----------------------------------------------------------------------

    public function testOnLateRequestReturnsEarlyWhenNoAccessExpression(): void
    {
        [$listener, $auth] = $this->make();

        // No `_access` attribute -> the guard returns before the user is read.
        $auth->expects($this->never())->method('getUser');

        $listener->onLateRequest($this->createMock(RequestEvent::class), $this->request());
    }

    public function testOnLateRequestThrowsUnauthorizedForUnauthenticatedUser(): void
    {
        [$listener, $auth] = $this->make();

        // No authenticated user -> 401, distinct from the authenticated 403 path.
        $auth->method('getUser')->willReturn(null);

        $request = $this->request(['_access' => ['system: manage users']]);

        try {
            $listener->onLateRequest($this->createMock(RequestEvent::class), $request);
            $this->fail('Expected HttpException was not thrown.');
        } catch (HttpException $e) {
            $this->assertNotInstanceOf(AccessDeniedHttpException::class, $e);
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('Unauthorized', $e->getMessage());
        }
    }

    public function testOnLateRequestThrowsAccessDeniedForAuthenticatedUserWithoutRights(): void
    {
        [$listener, $auth] = $this->make();

        $user = $this->createMock(User::class);
        $user->method('hasAccess')->willReturn(false);
        $user->method('isAuthenticated')->willReturn(true);
        $auth->method('getUser')->willReturn($user);

        $request = $this->request(['_access' => ['system: manage users']]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Insufficient User Rights.');

        $listener->onLateRequest($this->createMock(RequestEvent::class), $request);
    }

    public function testOnLateRequestPassesWhenUserHasAccess(): void
    {
        [$listener, $auth] = $this->make();

        $user = $this->createMock(User::class);
        $user->expects($this->once())
            ->method('hasAccess')
            ->with('system: manage users')
            ->willReturn(true);
        $auth->method('getUser')->willReturn($user);

        $request = $this->request(['_access' => ['system: manage users']]);

        $listener->onLateRequest($this->createMock(RequestEvent::class), $request);
    }

    // -----------------------------------------------------------------------
    // onRequest(): guard the admin area and redirect anonymous users to login.
    // -----------------------------------------------------------------------

    public function testOnRequestReturnsEarlyForXmlHttpRequest(): void
    {
        [$listener, $auth, , $response] = $this->make();

        // The XHR short-circuit must fire before the user is even read.
        $auth->expects($this->never())->method('getUser');
        $response->expects($this->never())->method('redirect');

        $event = $this->createMock(RequestEvent::class);
        $event->expects($this->never())->method('setResponse');

        $listener->onRequest($event, $this->request(['_access' => ['system: access admin area']], 'GET', true));
    }

    public function testOnRequestReturnsEarlyWhenUserAlreadyAuthenticated(): void
    {
        [$listener, $auth, , $response] = $this->make();

        $auth->method('getUser')->willReturn($this->createMock(User::class));
        $response->expects($this->never())->method('redirect');

        $event = $this->createMock(RequestEvent::class);
        $event->expects($this->never())->method('setResponse');

        $listener->onRequest($event, $this->request(['_access' => ['system: access admin area']]));
    }

    public function testOnRequestReturnsEarlyWhenRouteDoesNotRequireAdminArea(): void
    {
        [$listener, $auth, , $response] = $this->make();

        $auth->method('getUser')->willReturn(null);
        $response->expects($this->never())->method('redirect');

        $event = $this->createMock(RequestEvent::class);
        $event->expects($this->never())->method('setResponse');

        $listener->onRequest($event, $this->request(['_access' => ['blog: manage posts']]));
    }

    public function testOnRequestRedirectsAnonymousUserToLoginWithReturnUrl(): void
    {
        [$listener, $auth, $url, $response] = $this->make();

        $auth->method('getUser')->willReturn(null);
        $url->method('current')->willReturn('/admin/dashboard');

        $redirect = new RedirectResponse('/login');
        $response->expects($this->once())
            ->method('redirect')
            ->with('@system/login', ['redirect' => '/admin/dashboard'])
            ->willReturn($redirect);

        $event = $this->createMock(RequestEvent::class);
        $event->expects($this->once())->method('setResponse')->with($redirect);

        $listener->onRequest($event, $this->request(['_access' => ['system: access admin area'], '_route' => 'blog/index']));
    }

    public function testOnRequestRedirectsWithoutReturnUrlForPostRequests(): void
    {
        [$listener, $auth, $url, $response] = $this->make();

        $auth->method('getUser')->willReturn(null);
        // A POST must not attach a `redirect` param, so current() is never read.
        $url->expects($this->never())->method('current');

        $redirect = new RedirectResponse('/login');
        $response->expects($this->once())
            ->method('redirect')
            ->with('@system/login', [])
            ->willReturn($redirect);

        $event = $this->createMock(RequestEvent::class);
        $event->expects($this->once())->method('setResponse')->with($redirect);

        $listener->onRequest($event, $this->request(['_access' => ['system: access admin area'], '_route' => 'blog/index'], 'POST'));
    }

    public function testOnRequestRedirectsWithoutReturnUrlForSystemLoginRoute(): void
    {
        [$listener, $auth, $url, $response] = $this->make();

        $auth->method('getUser')->willReturn(null);
        // On the @system route itself the return URL is skipped as well.
        $url->expects($this->never())->method('current');

        $redirect = new RedirectResponse('/login');
        $response->expects($this->once())
            ->method('redirect')
            ->with('@system/login', [])
            ->willReturn($redirect);

        $event = $this->createMock(RequestEvent::class);
        $event->expects($this->once())->method('setResponse')->with($redirect);

        $listener->onRequest($event, $this->request(['_access' => ['system: access admin area'], '_route' => '@system']));
    }

    // -----------------------------------------------------------------------
    // onAuthorize(): block admin-area logins for users without admin access.
    // -----------------------------------------------------------------------

    public function testOnAuthorizeThrowsWhenAdminRedirectAndUserLacksAdminAccess(): void
    {
        [$listener, , $url, , $requestStack] = $this->make();

        $requestStack->method('getCurrentRequest')
            ->willReturn(new Request(['redirect' => 'http://localhost/admin/system']));
        $url->method('__invoke')
            ->with('@system', [], UrlGenerator::ABSOLUTE_URL)
            ->willReturn('http://localhost/');

        $user = $this->createMock(User::class);
        $user->method('hasAccess')->with('system: access admin area')->willReturn(false);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('You do not have access to the administration area of this site.');

        $listener->onAuthorize(new AuthorizeEvent('auth.authorize', $user));
    }

    public function testOnAuthorizeDoesNotThrowWhenThereIsNoRedirect(): void
    {
        [$listener, , $url, , $requestStack] = $this->make();

        $requestStack->method('getCurrentRequest')->willReturn(new Request());
        // No redirect -> the admin base URL is never resolved (short-circuit).
        $url->expects($this->never())->method('__invoke');

        $listener->onAuthorize(new AuthorizeEvent('auth.authorize', $this->createMock(User::class)));
    }

    public function testOnAuthorizeDoesNotThrowWhenThereIsNoCurrentRequest(): void
    {
        [$listener, , $url, , $requestStack] = $this->make();

        // No current request -> the null-safe operator yields a null redirect and
        // the admin-area guard is skipped. Dropping `?->` would fatal on
        // `null->get('redirect')`, so this pins the NullSafeMethodCall mutant.
        $requestStack->method('getCurrentRequest')->willReturn(null);
        $url->expects($this->never())->method('__invoke');

        $user = $this->createMock(User::class);
        $user->expects($this->never())->method('hasAccess');

        $listener->onAuthorize(new AuthorizeEvent('auth.authorize', $user));
    }

    public function testOnAuthorizeDoesNotThrowWhenRedirectIsNotWithinAdminArea(): void
    {
        [$listener, , $url, , $requestStack] = $this->make();

        $requestStack->method('getCurrentRequest')
            ->willReturn(new Request(['redirect' => 'http://localhost/frontend']));
        $url->method('__invoke')
            ->with('@system', [], UrlGenerator::ABSOLUTE_URL)
            ->willReturn('http://localhost/admin');

        // The redirect is outside the admin base, so access is never inspected.
        $user = $this->createMock(User::class);
        $user->expects($this->never())->method('hasAccess');

        $listener->onAuthorize(new AuthorizeEvent('auth.authorize', $user));
    }

    public function testOnAuthorizeDoesNotThrowWhenUserHasAdminAccess(): void
    {
        [$listener, , $url, , $requestStack] = $this->make();

        $requestStack->method('getCurrentRequest')
            ->willReturn(new Request(['redirect' => 'http://localhost/admin/system']));
        $url->method('__invoke')
            ->with('@system', [], UrlGenerator::ABSOLUTE_URL)
            ->willReturn('http://localhost/');

        $user = $this->createMock(User::class);
        $user->expects($this->once())
            ->method('hasAccess')
            ->with('system: access admin area')
            ->willReturn(true);

        $listener->onAuthorize(new AuthorizeEvent('auth.authorize', $user));
    }

    // -----------------------------------------------------------------------
    // onConfigureRoute(): read #[Access] attributes into the "_access" default.
    // -----------------------------------------------------------------------

    public function testOnConfigureRouteReturnsEarlyWhenControllerIsUndefined(): void
    {
        [$listener] = $this->make();

        $route = new Route('/foo');
        $listener->onConfigureRoute(new Event('route.configure'), $route);

        $this->assertNull($route->getDefault('_access'));
    }

    public function testOnConfigureRouteLeavesAccessUnsetWhenControllerHasNoAttributes(): void
    {
        [$listener] = $this->make();

        $route = new Route('/plain', ['_controller' => AccessListenerPlainController::class . '::plain']);
        $listener->onConfigureRoute(new Event('route.configure'), $route);

        $this->assertNull($route->getDefault('_access'));
    }

    public function testOnConfigureRouteCollectsClassAndMethodExpressions(): void
    {
        [$listener] = $this->make();

        $route = new Route('/foo', ['_controller' => AccessListenerFixtureController::class . '::expression']);
        $listener->onConfigureRoute(new Event('route.configure'), $route);

        $this->assertSame(['fixture.class', 'fixture.method'], $route->getDefault('_access'));
    }

    public function testOnConfigureRouteDeduplicatesRepeatedExpressions(): void
    {
        [$listener] = $this->make();

        $route = new Route('/foo', ['_controller' => AccessListenerFixtureController::class . '::duplicate']);
        $listener->onConfigureRoute(new Event('route.configure'), $route);

        $this->assertSame(['fixture.class'], $route->getDefault('_access'));
    }

    public function testOnConfigureRouteAdminAttributeAddsPermissionAndRewritesPath(): void
    {
        [$listener] = $this->make();

        $route = new Route('/dashboard', ['_controller' => AccessListenerFixtureController::class . '::admin']);
        $listener->onConfigureRoute(new Event('route.configure'), $route);

        $this->assertSame(['fixture.class', 'system: access admin area'], $route->getDefault('_access'));
        $this->assertSame('/admin/dashboard', $route->getPath());
    }

    public function testOnConfigureRouteAdminAttributeStripsTrailingSlashWhenRewritingPath(): void
    {
        [$listener] = $this->make();

        // Symfony's Route::setPath() preserves trailing slashes, so the rtrim() must
        // drop the source path's trailing "/" before the "admin" prefix is applied —
        // otherwise the rewritten path would be "/admin/dashboard/". Kills UnwrapRtrim.
        $route = new Route('/dashboard/', ['_controller' => AccessListenerFixtureController::class . '::admin']);
        $listener->onConfigureRoute(new Event('route.configure'), $route);

        $this->assertSame('/admin/dashboard', $route->getPath());
    }

    public function testOnConfigureRouteAdminFalseRemovesPreviouslyGrantedPermission(): void
    {
        [$listener] = $this->make();

        // #[Access(admin: true)] grants the permission, a following
        // #[Access(admin: false)] removes it again, leaving the expressions.
        $route = new Route('/dashboard', ['_controller' => AccessListenerFixtureController::class . '::adminRemoved']);
        $listener->onConfigureRoute(new Event('route.configure'), $route);

        $this->assertSame(['fixture.class', 'fixture.keep'], $route->getDefault('_access'));
    }

    // -----------------------------------------------------------------------
    // subscribe(): event -> handler (+ priority) mapping.
    // -----------------------------------------------------------------------

    public function testSubscribeMapsEventsToHandlers(): void
    {
        [$listener] = $this->make();

        $this->assertSame([
            'route.configure' => 'onConfigureRoute',
            'auth.authorize' => 'onAuthorize',
            'request' => [
                ['onLateRequest', -100],
                ['onRequest', -50],
            ],
        ], $listener->subscribe());
    }

    // -----------------------------------------------------------------------
    // Fixtures.
    // -----------------------------------------------------------------------

    /**
     * @return array{
     *     0: AccessListener,
     *     1: Auth&MockObject,
     *     2: UrlProvider&MockObject,
     *     3: Response&MockObject,
     *     4: RequestStack&MockObject
     * }
     */
    private function make(): array
    {
        $auth = $this->createMock(Auth::class);
        $url = $this->createMock(UrlProvider::class);
        $response = $this->createMock(Response::class);
        $requestStack = $this->createMock(RequestStack::class);

        return [new AccessListener($auth, $url, $response, $requestStack), $auth, $url, $response, $requestStack];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function request(array $attributes = [], string $method = 'GET', bool $xhr = false): Request
    {
        $request = new Request([], [], $attributes, [], [], ['REQUEST_METHOD' => $method]);

        if ($xhr) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        return $request;
    }
}

/**
 * Controller fixture whose #[Access] attributes drive {@see AccessListener::onConfigureRoute()}.
 * The class-level expression is merged with each method's attributes.
 */
#[Access('fixture.class')]
class AccessListenerFixtureController
{
    #[Access('fixture.method')]
    public function expression(): void
    {
    }

    #[Access('fixture.class')]
    public function duplicate(): void
    {
    }

    #[Access(admin: true)]
    public function admin(): void
    {
    }

    #[Access('fixture.keep')]
    #[Access(admin: true)]
    #[Access(admin: false)]
    public function adminRemoved(): void
    {
    }
}

/**
 * Controller fixture without any #[Access] attributes.
 */
class AccessListenerPlainController
{
    public function plain(): void
    {
    }
}
