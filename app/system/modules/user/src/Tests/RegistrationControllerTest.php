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
use Pagekit\User\Controller\RegistrationController;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;
use Pagekit\User\UserModule;
use Pagekit\View\View;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers RegistrationController after its Step 5 migration onto the injected
 * UserRepository: registration and activation now drive create/save and
 * where()->first() through the repository instead of the former static User
 * model API. Collaborators are mocked directly, so no database is required.
 */
class RegistrationControllerTest extends TestCase
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

    public function testRegisterActionCreatesAndSavesUserThroughRepository(): void
    {
        $created = new User();

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (array $data): bool => isset($data['registered']) && $data['status'] === User::STATUS_BLOCKED))
            ->willReturn($created);
        $users->expects($this->once())->method('save')->with($created);

        $csrf = $this->createMock(CsrfProviderInterface::class);
        $csrf->method('validate')->willReturn(true);

        $encoder = $this->createMock(PasswordEncoderInterface::class);
        $encoder->expects($this->once())->method('hash')->with('secret12')->willReturn('hashed');

        $mailer = $this->createMock(Mailer::class);
        $mailer->method('create')->willReturn(new Message());

        $view = $this->createMock(View::class);
        $view->method('__invoke')->willReturn('welcome mail');

        $url = $this->createMock(UrlProvider::class);
        $url->method('__invoke')->willReturn('/login');

        $message = $this->createMock(MessageBag::class);
        $message->expects($this->once())->method('success');

        $result = $this->createController(
            users: $users,
            csrf: $csrf,
            encoder: $encoder,
            mailer: $mailer,
            view: $view,
            url: $url,
            message: $message,
            registration: 'open',
            requireVerification: false,
        )->registerAction([
            'name' => 'Alice',
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'secret12',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('/login', $result['redirect']);
        $this->assertSame(User::STATUS_ACTIVE, $created->status, 'open registration without verification activates immediately');
    }

    public function testActivateActionFindsUserThroughRepositoryWhereAndSaves(): void
    {
        $pending = new User();
        $pending->username = 'bob';
        $pending->status = User::STATUS_BLOCKED;
        $pending->activation = 'token';

        $query = $this->createMock(QueryBuilder::class);
        $query->expects($this->once())->method('first')->willReturn($pending);

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('where')
            ->with(['username' => 'bob', 'activation' => 'token', 'login IS NULL'])
            ->willReturn($query);
        $users->expects($this->once())->method('save')->with($pending);

        $mailer = $this->createMock(Mailer::class);
        $mailer->method('create')->willReturn(new Message());

        $view = $this->createMock(View::class);
        $view->method('__invoke')->willReturn('welcome mail');

        $router = $this->createMock(Router::class);
        $router->expects($this->once())->method('redirect')->with('@user/login')->willReturn(new RedirectResponse('/login'));

        $message = $this->createMock(MessageBag::class);
        $message->expects($this->once())->method('success');

        $this->createController(
            users: $users,
            mailer: $mailer,
            view: $view,
            router: $router,
            message: $message,
            registration: 'open',
            requireVerification: false,
        )->activateAction('bob', 'token');

        $this->assertSame(User::STATUS_ACTIVE, $pending->status);
    }

    public function testActivateActionThrowsWhenUserNotFound(): void
    {
        $query = $this->createMock(QueryBuilder::class);
        $query->method('first')->willReturn(null);

        $users = $this->createMock(UserRepository::class);
        $users->method('where')->willReturn($query);

        $this->expectException(BadRequestHttpException::class);

        $this->createController(users: $users)->activateAction('missing', 'bad-token');
    }

    private function createController(
        ?User $user = null,
        ?UserRepository $users = null,
        ?CsrfProviderInterface $csrf = null,
        ?PasswordEncoderInterface $encoder = null,
        ?Mailer $mailer = null,
        ?View $view = null,
        ?UrlProvider $url = null,
        ?Router $router = null,
        ?MessageBag $message = null,
        string $registration = 'open',
        bool $requireVerification = false,
    ): RegistrationController {
        $anonymous = $this->createMock(User::class);
        $anonymous->method('isAuthenticated')->willReturn(false);

        $userModule = $this->createMock(UserModule::class);
        $userModule->method('config')->willReturnMap([
            ['registration', $registration],
            ['require_verification', $requireVerification],
        ]);

        $site = $this->createMock(SiteModule::class);
        $site->method('config')->with('title')->willReturn('Pagekit');

        $mailModule = $this->createMock(\Pagekit\Module\Module::class);
        $mailModule->method('config')->with('from_address')->willReturn('admin@example.com');

        $module = $this->createMock(ModuleManager::class);
        $module->method('get')->willReturnMap([
            ['system/user', $userModule],
            ['system/site', $site],
            ['system/mail', $mailModule],
        ]);

        return new RegistrationController(
            $module,
            $user ?? $anonymous,
            $csrf ?? $this->createMock(CsrfProviderInterface::class),
            $message ?? $this->createMock(MessageBag::class),
            $url ?? $this->createMock(UrlProvider::class),
            $mailer ?? $this->createMock(Mailer::class),
            $view ?? $this->createMock(View::class),
            $router ?? $this->createMock(Router::class),
            $encoder ?? $this->createMock(PasswordEncoderInterface::class),
            $this->createPassThroughValidator(),
            $users ?? $this->createMock(UserRepository::class),
        );
    }

    private function createPassThroughValidator(): ValidatorInterface
    {
        $violations = $this->createMock(ConstraintViolationListInterface::class);
        $violations->method('count')->willReturn(0);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn($violations);

        return $validator;
    }
}
