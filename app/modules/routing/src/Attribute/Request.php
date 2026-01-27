<?php

declare(strict_types=1);

namespace Pagekit\Routing\Attribute;

/**
 * Request attribute for defining request parameter mapping.
 *
 * Usage: #[Request(['param' => 'type'])]
 * Example: #[Request(['id' => 'int', 'filter' => 'array'])]
 * With CSRF: #[Request(['id' => 'int'], csrf: true)]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Request
{
    private array $data;
    private bool $csrf;

    /**
     * @param array $data Parameter mapping configuration
     * @param bool $csrf Whether to require CSRF validation
     */
    public function __construct(array $data = [], bool $csrf = false)
    {
        $this->data = $data;
        $this->csrf = $csrf;
    }

    /**
     * Returns the parameter mapping data.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Returns whether CSRF validation is required.
     */
    public function getCsrf(): bool
    {
        return $this->csrf;
    }
}
