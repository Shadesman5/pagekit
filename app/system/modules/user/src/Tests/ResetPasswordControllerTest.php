<?php

declare(strict_types=1);

namespace Pagekit\User\Tests;

use Pagekit\Application\UrlProvider;
use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Database\ORM\QueryBuilder;
use Pagekit\Mail\Mailer;
use Pagekit\Mail\Message;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Router;
use Pagekit\Session\Csrf\Provider\CsrfProviderInterface;
use Pagekit\Session\MessageBag;
use Pagekit\Site\SiteModule;
use Pagekit\User\Controller\ResetPasswordController;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;
use Pagekit\View\View;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Covers ResetPasswordController against the injected UserRepository:
 * password-reset request/confirm flows resolve users via
 * findByEmail/where()->first()/find() and persist through save(). Collaborators
 * are mocked directly, so no database is required.
 */
class ResetPasswordControllerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testIndexActionRedirectsAuthenticatedUsers(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isAuthenticated')->willReturn(true);

        $router = $this->createMock(Router::class);
        $router->expects($this->once())->method('redirect')->willReturn(new RedirectResponse('/'));

        $response = $this->createController(user: $user, router: $router)->indexAction();

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testRequestActionFindsUserByEmailAndPersistsActivationKey(): void
    {
        $target = new User();
        $target->email = 'alice@example.com';
        $target->status = User::STATUS_ACTIVE;

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('findByEmail')->with('alice@example.com')->willReturn($target);
        $users->expects($this->once())->method('save')->with($this->identicalTo($target));

        $csrf = $this->createMock(CsrfProviderInterface::class);
        $csrf->method('validate')->willReturn(true);

        $mail = new Message();
        $mailer = $this->createMock(Mailer::class);
        $mailer->method('create')->willReturn($mail);
        $mailer->expects($this->once())->method('send')->with($mail);

        $view = $this->createMock(View::class);
        $view->method('__invoke')->willReturn('mail body');

        $url = $this->createMock(UrlProvider::class);
        $url->method('__invoke')->willReturn('https://example.test/reset');

        $router = $this->createMock(Router::class);
        $router->expects($this->once())->method('redirect')->with('@user/login')->willReturn(new RedirectResponse('/login'));

        $message = $this->createMock(MessageBag::class);
        $message->expects($this->once())->method('success');

        $request = new Request([], ['email' => 'alice@example.com']);

        $response = $this->createController(
            users: $users,
            csrf: $csrf,
            mailer: $mailer,
            view: $view,
            url: $url,
            router: $router,
            message: $message,
            request: $request,
        )->requestAction();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($target->activation, 'the reset key must be stored on the user before save()');
    }

    public function testConfirmActionLoadsUserByActivationThroughRepositoryWhere(): void
    {
        $target = new User();
        $target->id = 7;
        $target->status = User::STATUS_ACTIVE;
        $target->activation = 'reset-key';

        $query = $this->createMock(QueryBuilder::class);
        $query->expects($this->once())
            ->method('first')
            ->willReturn($target);

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('where')
            ->with(['activation' => 'reset-key'])
            ->willReturn($query);
        $users->expects($this->once())->method('find')->with(7)->willReturn($target);
        $users->expects($this->once())->method('save')->with($target);

        $session = new Session(new MockArraySessionStorage());
        $session->start();

        $request = Request::create('/confirm', 'GET', ['key' => 'reset-key']);

        $result = $this->createController(users: $users, session: $session, request: $request)->confirmAction();

        $this->assertIsArray($result);
        $this->assertSame('reset-key', $result['activation']);
        $this->assertNull($target->activation, 'the consumed activation key is cleared on the first GET hit');
    }

    public function testConfirmActionPostResetsPasswordThroughRepositorySave(): void
    {
        $target = new User();
        $target->id = 8;
        $target->status = User::STATUS_ACTIVE;

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('find')->with(8)->willReturn($target);
        $users->expects($this->once())->method('save')->with($target);

        $csrf = $this->createMock(CsrfProviderInterface::class);
        $csrf->method('validate')->willReturn(true);

        $encoder = $this->createMock(PasswordEncoderInterface::class);
        $encoder->expects($this->once())->method('hash')->with('newpass')->willReturn('hashed');

        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('activation', ['key' => 'session-key', 'user' => 8]);

        $router = $this->createMock(Router::class);
        $router->expects($this->once())->method('redirect')->with('@user/login')->willReturn(new RedirectResponse('/login'));

        $message = $this->createMock(MessageBag::class);
        $message->expects($this->once())->method('success');

        $request = Request::create('/confirm', 'POST', ['key' => 'session-key', 'password' => 'newpass']);

        $response = $this->createController(
            users: $users,
            session: $session,
            csrf: $csrf,
            encoder: $encoder,
            router: $router,
            message: $message,
            request: $request,
        )->confirmAction();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('hashed', $target->password);
        $this->assertNull($target->activation);
    }

    public function testConfirmActionThrowsWhenActivationKeyDoesNotMatchSession(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('activation', ['key' => 'stored-key', 'user' => 1]);

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('find');

        $request = Request::create('/confirm', 'POST', ['key' => 'wrong-key']);

        $this->expectException(BadRequestHttpException::class);

        $this->createController(users: $users, session: $session, request: $request)->confirmAction();
    }

    private function createController(
        ?User $user = null,
        ?UserRepository $users = null,
        ?Request $request = null,
        ?Session $session = null,
        ?CsrfProviderInterface $csrf = null,
        ?UrlProvider $url = null,
        ?Mailer $mailer = null,
        ?View $view = null,
        ?MessageBag $message = null,
        ?Router $router = null,
        ?PasswordEncoderInterface $encoder = null,
    ): ResetPasswordController {
        $anonymous = $this->createMock(User::class);
        $anonymous->method('isAuthenticated')->willReturn(false);

        $site = $this->createMock(SiteModule::class);
        $site->method('config')->with('title')->willReturn('Pagekit');

        $module = $this->createMock(ModuleManager::class);
        $module->method('get')->willReturnMap([
            ['system/site', $site],
        ]);

        return new ResetPasswordController(
            $user ?? $anonymous,
            $request ?? new Request(),
            $session ?? new Session(new MockArraySessionStorage()),
            $csrf ?? $this->createMock(CsrfProviderInterface::class),
            $url ?? $this->createMock(UrlProvider::class),
            $mailer ?? $this->createMock(Mailer::class),
            $module,
            $view ?? $this->createMock(View::class),
            $message ?? $this->createMock(MessageBag::class),
            $router ?? $this->createMock(Router::class),
            $encoder ?? $this->createMock(PasswordEncoderInterface::class),
            $users ?? $this->createMock(UserRepository::class),
        );
    }
}
