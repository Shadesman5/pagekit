<?php

declare(strict_types=1);

namespace Pagekit\Routing\Attribute;

/**
 * Request attribute for defining request parameter mapping.
 *
 * Usage: #[Request(['param' => 'type'])]
 * Example: #[Request(['id' => 'int', 'filter' => 'array'])]
 * With CSRF: #[Request(['id' => 'int'], csrf: true)]
 * With filter options (e.g. pregreplace): #[Request(['folders' => 'pregreplace[]'], options: ['folders' => ['pattern' => '/[^a-z0-9_-]/i']])]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Request
{
    /** @var array<string, string> */
    private array $data;
    private bool $csrf;
    /** @var array<string, array<string, mixed>> */
    private array $options;

    /**
     * @param array<string, string>                $data    Parameter mapping configuration
     * @param bool                                 $csrf    Whether to require CSRF validation
     * @param array<string, array<string, mixed>>  $options Filter-specific options, keyed by parameter name (e.g. ['folders' => ['pattern' => '/[^a-z0-9_-]/i']])
     */
    public function __construct(array $data = [], bool $csrf = false, array $options = [])
    {
        $this->data = $data;
        $this->csrf = $csrf;
        $this->options = $options;
    }

    /**
     * Returns the parameter mapping data.
     *
     * @return array<string, string>
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

    /**
     * Returns filter-specific options keyed by parameter name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
