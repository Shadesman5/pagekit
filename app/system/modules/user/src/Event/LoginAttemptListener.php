<?php

namespace Pagekit\User\Event;

use Pagekit\Auth\Event\AuthenticateEvent;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\Event\EventSubscriberInterface;

class LoginAttemptListener implements EventSubscriberInterface
{
    const DELAY     = 5;
    const ATTEMPTS  = 5;
    const CACHE_KEY = 'auth.login_attempts';

    public function __construct(
        private readonly mixed $cache,
    ) {}

    /**
     * Prevent authentication attempts if time in between failed attempts is too short
     *
     * @param  AuthenticateEvent $event
     * @throws AuthException
     */
    public function onPreAuthenticate(AuthenticateEvent $event): void
    {
        if (!$credentials = $event->getCredentials() or !isset($credentials['username'])) {
            return;
        }

        $attempts = $this->cache->fetch($this->getCacheKey($credentials['username'])) ?: [];

        // Block if we already have >= ATTEMPTS failures and the last one was within DELAY seconds.
        // (Use end() to read last timestamp without mutating the array.)
        $lastAttempt = is_array($attempts) && $attempts !== [] ? (int) end($attempts) : 0;
        if (count($attempts) >= self::ATTEMPTS && (time() - $lastAttempt) < self::DELAY) {
            throw new AuthException(__('Slow down a bit.'));
        }
    }

    /**
     * Save failed login attempts to cache
     *
     * @param AuthenticateEvent $event
     */
    public function onAuthFailure(AuthenticateEvent $event): void
    {
        if (!$credentials = $event->getCredentials() or !isset($credentials['username'])) {
            return;
        }

        $key = $this->getCacheKey($credentials['username']);

        $attempts = $this->cache->fetch($key) ?: [];
        $attempts[] = time();
        
        $this->cache->save($key, $attempts);
    }

    /**
     * Reset failed login attempts after successful login
     *
     * @param AuthenticateEvent $event
     */
    public function onAuthSuccess(AuthenticateEvent $event): void
    {
        if (!$credentials = $event->getCredentials() or !isset($credentials['username'])) {
            return;
        }

        $this->cache->delete($this->getCacheKey($credentials['username']));
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        return [
            'auth.pre_authenticate' => 'onPreAuthenticate',
            'auth.failure'          => 'onAuthFailure',
            'auth.success'          => 'onAuthSuccess'
        ];
    }

    protected function getCacheKey($username): string {
        return self::CACHE_KEY.'_'.$username;
    }
}
