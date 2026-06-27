<?php

declare(strict_types=1);

namespace Pagekit\User\Event;

use Pagekit\Application\Response as PagekitResponse;
use Pagekit\Application\UrlProvider;
use Pagekit\Auth\Auth;
use Pagekit\Auth\Event\AuthorizeEvent;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\Event\Event;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Kernel\Event\RequestEvent;
use Pagekit\Routing\Generator\UrlGenerator;
use Pagekit\Routing\Route;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reads Access attributes from controllers and enforces access control.
 */
class AccessListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Auth $auth,
        private readonly UrlProvider $url,
        private readonly PagekitResponse $response,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Reads the #[Access] attributes from the controller and stores them in the "access" route option.
     */
    public function onConfigureRoute(Event $event, Route $route): void
    {
        $class = $route->getControllerClass();
        $method = $route->getControllerMethod();

        if ($class === null || $method === null) {
            return;
        }

        $access = [];

        $classAttributes = $class->getAttributes(Access::class, \ReflectionAttribute::IS_INSTANCEOF);
        foreach ($classAttributes as $attr) {
            $this->processAccessAttribute($attr->newInstance(), $access, $route);
        }

        $methodAttributes = $method->getAttributes(Access::class, \ReflectionAttribute::IS_INSTANCEOF);
        foreach ($methodAttributes as $attr) {
            $this->processAccessAttribute($attr->newInstance(), $access, $route);
        }

        if ($access) {
            $route->setDefault('_access', array_unique($access));
        }
    }

    /**
     * Process a single Access attribute.
     *
     * @param array<int, string> $access
     */
    private function processAccessAttribute(Access $annot, array &$access, Route $route): void
    {
        if ($expression = $annot->getExpression()) {
            $access[] = $expression;
        }

        $adminValue = $annot->getAdmin();
        if ($adminValue !== null) {
            $route->setPath('admin' . rtrim($route->getPath(), '/'));
            $permission = 'system: access admin area';

            if ($adminValue) {
                $access[] = $permission;
            } elseif (($key = array_search($permission, $access)) !== false) {
                unset($access[$key]);
            }
        }
    }

    /**
     * Checks if the user is authorized to login to administration section.
     *
     * @throws AuthException
     */
    public function onAuthorize(AuthorizeEvent $event): void
    {
        $redirect = $this->requestStack->getCurrentRequest()?->get('redirect');
        $eventUser = $event->getUser();
        if ($redirect && strpos($redirect, (string) ($this->url)('@system', [], UrlGenerator::ABSOLUTE_URL)) === 0 && !($eventUser instanceof User && $eventUser->hasAccess('system: access admin area'))) {
            throw new AuthException(__('You do not have access to the administration area of this site.'));
        }
    }

    /**
     * Reads the access expressions and evaluates them on the current user.
     */
    public function onLateRequest(RequestEvent $event, Request $request): void
    {
        if (!$access = $request->attributes->get('_access')) {
            return;
        }

        $authUser = $this->auth->getUser();
        $user = $authUser instanceof User ? $authUser : null;

        foreach ($access as $expression) {
            if (!$user?->hasAccess($expression)) {
                if (!$user?->isAuthenticated()) {
                    throw new HttpException(401, __('Unauthorized'));
                } else {
                    throw new AccessDeniedHttpException(__('Insufficient User Rights.'));
                }
            }
        }
    }

    /**
     * Checks for the "system: access admin area" and redirects to login.
     */
    public function onRequest(RequestEvent $event, Request $request): void
    {
        if ($request->isXmlHttpRequest() || $this->auth->getUser() || !in_array('system: access admin area', $request->attributes->get('_access', []))) {
            return;
        }

        $params = [];

        if ('POST' !== $request->getMethod() && $request->attributes->get('_route') != '@system') {
            $params['redirect'] = $this->url->current();
        }

        $event->setResponse($this->response->redirect('@system/login', $params));
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function subscribe(): array
    {
        return [
            'route.configure' => 'onConfigureRoute',
            'auth.authorize' => 'onAuthorize',
            'request' => [
                ['onLateRequest', -100],
                ['onRequest', -50],
            ],
        ];
    }
}
