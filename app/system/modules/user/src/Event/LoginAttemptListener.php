<?php

namespace Pagekit\User\Event;

use Pagekit\Application as App;
use Pagekit\Auth\Event\AuthenticateEvent;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\Event\EventSubscriberInterface;

class LoginAttemptListener implements EventSubscriberInterface
{
    const DELAY     = 5;
    const ATTEMPTS  = 5;
    const CACHE_KEY = 'auth.login_attempts';

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

        $cacheItem = App::cache()->getItem($this->getCacheKey($credentials['username']));
        $attempts = $cacheItem->isHit() ? $cacheItem->get() : [];

        if (count($attempts) > self::ATTEMPTS && time() - (int) array_pop($attempts) < self::DELAY) {
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

        $cacheItem = App::cache()->getItem($key);
        $attempts = $cacheItem->isHit() ? $cacheItem->get() : [];
        $attempts[] = time();
        
        $cacheItem->set($attempts);
        App::cache()->save($cacheItem);
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

        App::cache()->deleteItem($this->getCacheKey($credentials['username']));
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
