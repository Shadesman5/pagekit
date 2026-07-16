<?php

declare(strict_types=1);

namespace Pagekit\Captcha;

use Pagekit\Auth\Auth;
use Pagekit\Captcha\Attribute\Captcha;
use Pagekit\Event\EventInterface;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Module\Module;
use Pagekit\Routing\Route;
use Pagekit\Routing\Router;
use Pagekit\User\Model\User;
use Pagekit\View\Asset\AssetManager;
use Pagekit\View\Helper\DataHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reads Captcha attributes from controllers and handles captcha verification.
 */
class CaptchaListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Module $captchaModule,
        private readonly Auth $auth,
        private readonly RequestStack $requestStack,
        private readonly Router $router,
    ) {
    }

    /**
     * Reads the #[Captcha] attributes from the controller.
     */
    public function onConfigureRoute(EventInterface $event, Route $route): void
    {
        $class = $route->getControllerClass();
        $method = $route->getControllerMethod();

        if ($class === null || $method === null) {
            return;
        }

        $routes = [];

        // Get class-level Captcha attributes
        $classAttributes = $class->getAttributes(Captcha::class, \ReflectionAttribute::IS_INSTANCEOF);
        foreach ($classAttributes as $attr) {
            $this->processCaptchaAttribute($attr->newInstance(), $routes, $route);
        }

        // Get method-level Captcha attributes
        $methodAttributes = $method->getAttributes(Captcha::class, \ReflectionAttribute::IS_INSTANCEOF);
        foreach ($methodAttributes as $attr) {
            $this->processCaptchaAttribute($attr->newInstance(), $routes, $route);
        }

        if ($routes) {
            $route->setDefault('_captcha_routes', array_unique($routes));
        }
    }

    /**
     * Process a single Captcha attribute.
     *
     * @param array<int, string> $routes
     */
    private function processCaptchaAttribute(Captcha $annot, array &$routes, Route $route): void
    {
        if ($annot->getVerify()) {
            $route->setDefault('_captcha_verify', true);
        }

        if ($captchaRoute = $annot->getRoute()) {
            $routes[] = $captchaRoute;
        }
    }

    public function onData(EventInterface $event, DataHelper $data): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $user = $this->auth->getUser();

        if (!$this->captchaModule->config('recaptcha_enable')
            || ($user instanceof User && $user->isAuthenticated())
            || !($routes = $request?->attributes->get('_captcha_routes'))
            || !($sitekey = $this->captchaModule->config('recaptcha_sitekey'))
        ) {
            return;
        }

        $routes = array_filter(array_map(function ($route) {
            if ($route = $this->router->getRoute($route)) {
                return ltrim($route->getPath(), '/');
            }

            return false;
        }, $routes));

        // Add captcha config to JSON data container
        $data->add('$captcha', [
            'grecaptcha' => $this->captchaModule->config('recaptcha_sitekey'),
            'routes' => $routes,
        ]);
    }

    public function onScripts(EventInterface $event, AssetManager $scripts): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $user = $this->auth->getUser();

        // Must match the same conditions as onData() to ensure $captcha exists
        // when the script runs
        if (!$this->captchaModule->config('recaptcha_enable')
            || ($user instanceof User && $user->isAuthenticated())
            || !$request?->attributes->get('_captcha_routes')
            || !$this->captchaModule->config('recaptcha_sitekey')
        ) {
            return;
        }

        $scripts(
            'captcha-interceptor',
            'system/captcha:app/bundle/captcha-interceptor.js',
            ['vue', 'pagekit-config']
        );
    }

    public function onRequest(EventInterface $event, Request $request): void
    {
        $user = $this->auth->getUser();

        if (!$this->captchaModule->config('recaptcha_enable')
            || !($captcha = $request->attributes->get('_captcha_verify'))
            || ($user instanceof User && $user->isAuthenticated())) {
            return;
        }

        if ($error = $this->verifyToken($request->request->getString('gRecaptchaResponse'), (string) $this->captchaModule->config('recaptcha_secret'))) {
            throw new BadRequestHttpException($error);
        }
    }

    protected function verifyToken(string $gRecaptchaResponse, string $secret): ?string
    {
        if ($gRecaptchaResponse && $secret) {
            $result = json_decode($this->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $gRecaptchaResponse,
            ]) ?: '{}', true);
            if (!$result['success']) {
                return __('Invalid reCaptcha.');
            }

            return null;
        }

        return __('reCaptcha not probably configured.');
    }

    /**
     * @param array<string, string> $parameter
     */
    protected function post(string $url, array $parameter): string|false
    {
        $ch = curl_init($url);
        $parameterQuery = http_build_query($parameter);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_POST => count($parameter),
            CURLOPT_POSTFIELDS => $parameterQuery,
        ];
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);

        curl_close($ch);

        return is_string($result) ? $result : false;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, array{string, int}|string>
     */
    public function subscribe(): array
    {
        return [
            'route.configure' => 'onConfigureRoute',
            'request' => ['onRequest', -100],
            'view.data' => ['onData', 100],
            'view.scripts' => ['onScripts', 100],
        ];
    }
}
