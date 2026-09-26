<?php

declare(strict_types=1);

namespace Pagekit\Captcha\Tests;

use Pagekit\Auth\Auth;
use Pagekit\Auth\UserInterface;
use Pagekit\Captcha\CaptchaListener;
use Pagekit\Event\EventInterface;
use Pagekit\Module\Module;
use Pagekit\Routing\Route;
use Pagekit\Routing\Router;
use Pagekit\View\Asset\AssetInterface;
use Pagekit\View\Asset\AssetManager;
use Pagekit\View\Helper\DataHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Unit tests for {@see CaptchaListener::onRequest()} verification.
 *
 * Collaborators are mocked; a real {@see RequestStack} + {@see Request} carry
 * the POST token and `_captcha_verify` attribute. An anonymous subclass overrides
 * `post()` so Google's siteverify endpoint is never hit.
 *
 * Bootstrap: {@see setUp} loads Tests/bootstrap.php, which (1) stubs the GLOBAL
 * `\__()` that `verifyToken()` reaches via unqualified call fallback, and
 * (2) `require_once`s `CaptchaListener` because `Pagekit\Captcha\` is not in
 * composer's PSR-4 map.
 */
class CaptchaListenerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function testOnRequestIsNoOpWhenCaptchaDisabled(): void
    {
        $listener = $this->makeListener(
            ['recaptcha_enable' => false],
            postResult: '{"success":true}',
        );

        // Disabled captcha must return before reading the token or calling post().
        $listener->onRequest(
            $this->createMock(EventInterface::class),
            $this->requestWithCaptcha('any-token'),
        );

        $this->addToAssertionCount(1);
    }

    public function testOnRequestThrowsWhenTokenOrSecretMissing(): void
    {
        $listener = $this->makeListener(
            [
                'recaptcha_enable' => true,
                'recaptcha_secret' => '',
            ],
            postResult: '{"success":true}',
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('reCaptcha not probably configured.');

        $listener->onRequest(
            $this->createMock(EventInterface::class),
            $this->requestWithCaptcha(''),
        );
    }

    public function testOnRequestPassesWhenSiteverifyReportsSuccess(): void
    {
        $listener = $this->makeListener(
            [
                'recaptcha_enable' => true,
                'recaptcha_secret' => 'test-secret',
            ],
            postResult: '{"success":true}',
        );

        $listener->onRequest(
            $this->createMock(EventInterface::class),
            $this->requestWithCaptcha('valid-token'),
        );

        $this->addToAssertionCount(1);
    }

    public function testOnRequestThrowsWhenSiteverifyReportsFailure(): void
    {
        $listener = $this->makeListener(
            [
                'recaptcha_enable' => true,
                'recaptcha_secret' => 'test-secret',
            ],
            postResult: '{"success":false}',
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid reCaptcha.');

        $listener->onRequest(
            $this->createMock(EventInterface::class),
            $this->requestWithCaptcha('bad-token'),
        );
    }

    public function testOnRequestVerifiesOnlyAccountsThatAreNotAuthenticated(): void
    {
        $config = [
            'recaptcha_enable' => true,
            'recaptcha_secret' => 'test-secret',
        ];

        // A signed-in account is any UserInterface, not a user-module model.
        $this->makeListener($config, '{"success":false}', new CaptchaAccount(true))
            ->onRequest($this->createMock(EventInterface::class), $this->requestWithCaptcha(''));

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('reCaptcha not probably configured.');

        $this->makeListener($config, '{"success":false}', new CaptchaAccount(false))
            ->onRequest($this->createMock(EventInterface::class), $this->requestWithCaptcha(''));
    }

    public function testOnDataPublishesCaptchaOnlyForAccountsThatAreNotAuthenticated(): void
    {
        $config = [
            'recaptcha_enable' => true,
            'recaptcha_sitekey' => 'site-key',
        ];
        $router = $this->createMock(Router::class);
        $router->expects($this->never())->method('getRoute');
        $data = new DataHelper();

        $this->makeListener($config, false, new CaptchaAccount(true), $this->requestWithCaptchaRoutes(), $router)
            ->onData($this->createMock(EventInterface::class), $data);

        $this->assertNull($data->get('$captcha'));

        $published = new DataHelper();
        $this->makeListener($config, false, new CaptchaAccount(false), $this->requestWithCaptchaRoutes(), $this->routerForRegistration())
            ->onData($this->createMock(EventInterface::class), $published);

        $this->assertSame([
            'grecaptcha' => 'site-key',
            'routes' => ['user/registration'],
        ], $published->get('$captcha'));
    }

    public function testOnScriptsRegistersTheInterceptorOnlyForAccountsThatAreNotAuthenticated(): void
    {
        $config = [
            'recaptcha_enable' => true,
            'recaptcha_sitekey' => 'site-key',
        ];
        $skipped = new RecordingScripts();

        $this->makeListener($config, false, new CaptchaAccount(true), $this->requestWithCaptchaRoutes())
            ->onScripts($this->createMock(EventInterface::class), $skipped);

        $this->assertSame([], $skipped->calls);

        $registered = new RecordingScripts();
        $this->makeListener($config, false, new CaptchaAccount(false), $this->requestWithCaptchaRoutes())
            ->onScripts($this->createMock(EventInterface::class), $registered);

        $this->assertSame([
            ['captcha-interceptor', 'system/captcha:app/bundle/captcha-interceptor.js', ['vue', 'pagekit-config']],
        ], $registered->calls);
    }

    public function testCaptchaListenerDoesNotImportTheUserModule(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/CaptchaListener.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Pagekit\\User', $source);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makeListener(
        array $config,
        string|false $postResult,
        ?UserInterface $user = null,
        ?Request $currentRequest = null,
        ?Router $router = null,
    ): CaptchaListener {
        /** @var Module&MockObject $module */
        $module = $this->createMock(Module::class);
        $module->method('config')->willReturnCallback(
            static fn (string|array|null $key = null, mixed $default = null): mixed => is_string($key)
                ? ($config[$key] ?? $default)
                : $default
        );

        /** @var Auth&MockObject $auth */
        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn($user);

        $requestStack = new RequestStack();
        $requestStack->push($currentRequest ?? Request::create('/'));

        if ($router === null) {
            /** @var Router&MockObject $router */
            $router = $this->createMock(Router::class);
        }

        return new class ($module, $auth, $requestStack, $router, $postResult) extends CaptchaListener {
            public function __construct(
                Module $captchaModule,
                Auth $auth,
                RequestStack $requestStack,
                Router $router,
                private readonly string|false $postResult,
            ) {
                parent::__construct($captchaModule, $auth, $requestStack, $router);
            }

            /**
             * @param array<string, string> $parameter
             */
            protected function post(string $url, array $parameter): string|false
            {
                return $this->postResult;
            }
        };
    }

    private function requestWithCaptcha(string $token): Request
    {
        $request = Request::create('/submit', 'POST', [
            'gRecaptchaResponse' => $token,
        ]);
        $request->attributes->set('_captcha_verify', true);

        return $request;
    }

    private function requestWithCaptchaRoutes(): Request
    {
        $request = Request::create('/');
        $request->attributes->set('_captcha_routes', ['user/registration']);

        return $request;
    }

    private function routerForRegistration(): Router
    {
        /** @var Router&MockObject $router */
        $router = $this->createMock(Router::class);
        $router->method('getRoute')->willReturn(new Route('/user/registration'));

        return $router;
    }
}

/**
 * A signed-in account that is not a {@see \Pagekit\User\Model\User}.
 */
final class CaptchaAccount implements UserInterface
{
    public function __construct(private readonly bool $authenticated)
    {
    }

    public function getId(): string
    {
        return '1';
    }

    public function getUsername(): string
    {
        return 'ada';
    }

    public function getPassword(): string
    {
        return '';
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }
}

/**
 * Records interceptor registration without building a real asset.
 */
final class RecordingScripts extends AssetManager
{
    /**
     * @var list<array{0: string, 1: mixed, 2: array<int, string>}>
     */
    public array $calls = [];

    /**
     * @param array<int, string>   $dependencies
     * @param array<string, mixed> $options
     */
    public function __invoke(string $name, mixed $asset = null, array $dependencies = [], array $options = []): ?AssetInterface
    {
        $this->calls[] = [$name, $asset, $dependencies];

        return null;
    }
}
