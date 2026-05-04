<?php

declare(strict_types=1);

namespace Pagekit\Session\Csrf\Event;

use Pagekit\Event\EventInterface;
use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Session\Csrf\Exception\CsrfException;
use Pagekit\Session\Csrf\Provider\CsrfProviderInterface;
use Symfony\Component\HttpFoundation\Request;

class CsrfListener implements EventSubscriberInterface
{
    protected CsrfProviderInterface $provider;

    public function __construct(CsrfProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Checks for the CSRF token and throws 401 exception if invalid.
     *
     * @throws CsrfException
     */
    public function onRequest(EventInterface $event, Request $request): void
    {
        $this->provider->setToken($request->get('_csrf', $request->headers->get('X-XSRF-TOKEN')));
        $attributes = $request->attributes->get('_request', []);

        if (isset($attributes['csrf']) && !$this->provider->validate()) {
            throw new CsrfException('Invalid CSRF token.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function subscribe(): array
    {
        return [
            'request' => ['onRequest', -150],
        ];
    }
}
