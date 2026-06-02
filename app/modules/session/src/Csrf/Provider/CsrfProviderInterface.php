<?php

declare(strict_types=1);

namespace Pagekit\Session\Csrf\Provider;

interface CsrfProviderInterface
{
    /**
     * Generates a CSRF token.
     */
    public function generate(): string;

    /**
     * Validates a CSRF token.
     */
    public function validate(?string $token = null): bool;

    /**
     * Sets a CSRF token to validate.
     */
    public function setToken(?string $token): void;
}
