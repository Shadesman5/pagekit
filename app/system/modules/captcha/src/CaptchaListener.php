<?php

declare(strict_types=1);

namespace Pagekit\Captcha;

use Pagekit\Captcha\Attribute\Captcha;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Module\Module;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reads Captcha attributes from controllers and handles captcha verification.
 */
class CaptchaListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Module $captchaModule,
        private readonly mixed $auth,
        private readonly RequestStack $requestStack,
        private readonly mixed $router,
    ) {
    }

    /**
     * Reads the #[Captcha] attributes from the controller.
     */
    public function onConfigureRoute($event, $route): void
    {
        if (!$route->getControllerClass()) {
            return;
        }

        $class = $route->getControllerClass();
        $method = $route->getControllerMethod();

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
     */
    private function processCaptchaAttribute(Captcha $annot, array &$routes, $route): void
    {
        if ($annot->getVerify()) {
            $route->setDefault('_captcha_verify', true);
        }

        if ($captchaRoute = $annot->getRoute()) {
            $routes[] = $captchaRoute;
        }
    }

    public function onData($event, $data): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$this->captchaModule->config('recaptcha_enable')
            || $this->auth->getUser()?->isAuthenticated()
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

    public function onScripts($event, $scripts): void
    {
        $request = $this->requestStack->getCurrentRequest();

        // Must match the same conditions as onData() to ensure $captcha exists
        // when the script runs
        if (!$this->captchaModule->config('recaptcha_enable')
            || $this->auth->getUser()?->isAuthenticated()
            || !$request?->attributes->get('_captcha_routes')
            || !$this->captchaModule->config('recaptcha_sitekey')
        ) {
            return;
        }

        $scripts->add('captcha-interceptor', 'system/captcha:app/bundle/captcha-interceptor.js', ['vue', 'pagekit-config']);
    }

    public function onRequest($event, $request): void
    {
        if (!$this->captchaModule->config('recaptcha_enable')
            || !($captcha = $request->attributes->get('_captcha_verify'))
            || $this->auth->getUser()?->isAuthenticated()) {
            return;
        }

        if ($error = $this->verifyToken($request->get('gRecaptchaResponse'), $this->captchaModule->config('recaptcha_secret'))) {
            throw new BadRequestHttpException($error);
        }
    }

    protected function verifyToken($gRecaptchaResponse, $secret)
    {
        if ($gRecaptchaResponse && $secret) {
            $result = json_decode($this->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $gRecaptchaResponse,
            ]), true);
            if (!$result['success']) {
                return __('Invalid reCaptcha.');
            }
        } else {
            return __('reCaptcha not probably configured.');
        }
    }

    protected function post($url, $parameter)
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

        return $result;
    }

    /**
     * {@inheritdoc}
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
