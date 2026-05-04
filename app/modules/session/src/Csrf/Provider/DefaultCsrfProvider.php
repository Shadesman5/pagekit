<?php

declare(strict_types=1);

namespace Pagekit\Session\Csrf\Provider;

class DefaultCsrfProvider implements CsrfProviderInterface
{
    protected string $name;

    protected ?string $token = null;

    public function __construct(string $name = '_csrf')
    {
        $this->name = $name;
    }

    public function generate(): string
    {
        return sha1($this->getSessionId().$this->getSessionToken());
    }

    public function validate(?string $token = null): bool
    {
        if ($token === null) {
            $token = $this->token;
        }

        return $token === $this->generate();
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
    }

    /**
     * Returns the session id.
     */
    protected function getSessionId(): string
    {
        if (!session_id()) {
            session_start();
        }

        return session_id();
    }

    /**
     * Returns the session token.
     */
    protected function getSessionToken(): string
    {
        if (!isset($_SESSION[$this->name])) {
            $_SESSION[$this->name] = sha1(uniqid((string) rand(), true));
        }

        return $_SESSION[$this->name];
    }
}
