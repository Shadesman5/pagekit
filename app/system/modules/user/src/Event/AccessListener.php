<?php

declare(strict_types=1);

namespace Pagekit\User\Event;

use Pagekit\Application as App;
use Pagekit\Auth\Event\AuthorizeEvent;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\User\Attribute\Access;

/**
 * Reads Access attributes from controllers and enforces access control.
 */
class AccessListener implements EventSubscriberInterface
{
    /**
     * Reads the #[Access] attributes from the controller and stores them in the "access" route option.
     */
    public function onConfigureRoute($event, $route): void
    {
        if (!$route->getControllerClass()) {
            return;
        }

        $class = $route->getControllerClass();
        $method = $route->getControllerMethod();

        $access = [];

        // Get class-level Access attributes
        $classAttributes = $class->getAttributes(Access::class, \ReflectionAttribute::IS_INSTANCEOF);
        foreach ($classAttributes as $attr) {
            $this->processAccessAttribute($attr->newInstance(), $access, $route);
        }

        // Get method-level Access attributes
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
     */
    private function processAccessAttribute(Access $annot, array &$access, $route): void
    {
        if ($expression = $annot->getExpression()) {
            $access[] = $expression;
        }

        if ($admin = $annot->getAdmin() !== null) {
            $route->setPath('admin' . rtrim($route->getPath(), '/'));
            $permission = 'system: access admin area';

            if ($admin) {
                $access[] = $permission;
            } elseif ($key = array_search($permission, $access)) {
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
        $redirect = App::request()->get('redirect');
        if ($redirect && strpos($redirect, App::url('@system', [], true)) === 0 && !$event->getUser()->hasAccess('system: access admin area')) {
            throw new AuthException(__('You do not have access to the administration area of this site.'));
        }
    }

    /**
     * Reads the access expressions and evaluates them on the current user.
     */
    public function onLateRequest($event, $request): void
    {
        if (!$access = $request->attributes->get('_access')) {
            return;
        }

        foreach ($access as $expression) {
            if (!App::user()->hasAccess($expression)) {
                if (!App::user()->isAuthenticated()) {
                    App::abort(401, __('Unauthorized'));
                } else {
                    App::abort(403, __('Insufficient User Rights.'));
                }
            }
        }
    }

    /**
     * Checks for the "system: access admin area" and redirects to login.
     */
    public function onRequest($event, $request): void
    {
        if ($request->isXmlHttpRequest() || App::auth()->getUser() || !in_array('system: access admin area', $request->attributes->get('_access', []))) {
            return;
        }

        $params = [];

        // redirect to default URL for POST requests and don't explicitly redirect the default URL
        if ('POST' !== $request->getMethod() && $request->attributes->get('_route') != '@system') {
            $params['redirect'] = App::url()->current();
        }

        $event->setResponse(App::response()->redirect('@system/login', $params));
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        return [
            'route.configure' => 'onConfigureRoute',
            'auth.authorize' => 'onAuthorize',
            'request' => [
                ['onLateRequest', -100],
                ['onRequest', -50]
            ]
        ];
    }
}
