<?php

declare(strict_types=1);

namespace Pagekit\Captcha\Tests;

use Pagekit\Auth\Auth;
use Pagekit\Captcha\CaptchaListener;
use Pagekit\Event\EventInterface;
use Pagekit\Module\Module;
use Pagekit\Routing\Router;
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

    /**
     * @param array<string, mixed> $config
     */
    private function makeListener(array $config, string|false $postResult): CaptchaListener
    {
        /** @var Module&MockObject $module */
        $module = $this->createMock(Module::class);
        $module->method('config')->willReturnCallback(
            static fn (string|array|null $key = null, mixed $default = null): mixed => is_string($key)
                ? ($config[$key] ?? $default)
                : $default
        );

        /** @var Auth&MockObject $auth */
        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn(null);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/'));

        /** @var Router&MockObject $router */
        $router = $this->createMock(Router::class);

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
}
