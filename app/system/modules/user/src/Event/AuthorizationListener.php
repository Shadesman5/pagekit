<?php

declare(strict_types=1);

namespace Pagekit\User\Event;

use Pagekit\Auth\Auth;
use Pagekit\Auth\Event\AuthenticateEvent;
use Pagekit\Auth\Event\AuthorizeEvent;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\User\Auth\UserProvider;

class AuthorizationListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly mixed $auth,
        private readonly mixed $authPassword,
        private readonly mixed $session,
    ) {
    }

    /**
     * Initialize system.
     */
    public function onSystemInit(): void
    {
        $this->auth->setUserProvider(new UserProvider($this->authPassword));
    }

    /**
     * Logout blocked users.
     */
    public function onRequest(): void
    {
        if ($user = $this->auth->getUser() and $user->isBlocked()) {
            $this->auth->logout();
        }
    }

    /**
     * Blocks users that are either not activated or blocked.
     *
     * @param  AuthorizeEvent $event
     * @throws AuthException
     */
    public function onAuthorize(AuthorizeEvent $event): void
    {
        if ($event->getUser()->isBlocked()) {
            throw new AuthException($event->getUser()->login ? __('Your account is blocked.') : __('Your account has not been activated.'));
        }
    }

    /**
     * Redirects a user after successful login.
     */
    public function onLogin(): void
    {
        $this->session->migrate();
    }

    public function onSuccess(): void
    {
        $this->session->remove(Auth::LAST_USERNAME);
    }

    public function onFailure(AuthenticateEvent $event): void
    {
        $credentials = $event->getCredentials();
        $this->session->set(Auth::LAST_USERNAME, $credentials['username']);
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        return [
            'request' => [
                ['onRequest', 0],
                ['onSystemInit', 50],
            ],
            'auth.authorize' => 'onAuthorize',
            'auth.login' => ['onLogin', -8],
            'auth.success' => 'onSuccess',
            'auth.failure' => 'onFailure',
        ];
    }
}
