<?php

declare(strict_types=1);

namespace Pagekit\User\Event;

use Pagekit\Auth\Auth;
use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Auth\Event\AuthenticateEvent;
use Pagekit\Auth\Event\AuthorizeEvent;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\User\Auth\UserProvider;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class AuthorizationListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Auth $auth,
        private readonly PasswordEncoderInterface $authPassword,
        private readonly SessionInterface $session,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Initialize system.
     */
    public function onSystemInit(): void
    {
        $this->auth->setUserProvider(new UserProvider($this->authPassword, $this->users));
    }

    /**
     * Logout blocked users.
     */
    public function onRequest(): void
    {
        $user = $this->auth->getUser();
        if ($user instanceof User && $user->isBlocked()) {
            $this->auth->logout();
        }
    }

    /**
     * Blocks users that are either not activated or blocked.
     *
     * @throws AuthException
     */
    public function onAuthorize(AuthorizeEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User && $user->isBlocked()) {
            throw new AuthException($user->login ? __('Your account is blocked.') : __('Your account has not been activated.'));
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
     *
     * @return array<string, mixed>
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
