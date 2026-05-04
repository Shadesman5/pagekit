<?php

declare(strict_types=1);

namespace Pagekit\Session\Csrf\Provider;

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class SessionCsrfProvider extends DefaultCsrfProvider
{
    /**
     * The session.
     */
    protected SessionInterface $session;

    public function __construct(SessionInterface $session, string $name = '_csrf')
    {
        parent::__construct($name);

        $this->session = $session;
    }

    /**
     * {@inheritdoc}
     */
    protected function getSessionId(): string
    {
        if (!$this->session->isStarted()) {
            $this->session->start();
        }

        return $this->session->getId();
    }

    /**
     * {@inheritdoc}
     */
    protected function getSessionToken(): string
    {
        if (!$this->session->has($this->name)) {
            $this->session->set($this->name, sha1(uniqid((string) rand(), true)));
        }

        return $this->session->get($this->name);
    }
}
