<?php

declare(strict_types=1);

namespace Pagekit\Routing\Attribute;

/**
 * Request attribute for defining request parameter mapping.
 *
 * Usage: #[Request(['param' => 'type'])]
 * Example: #[Request(['id' => 'int', 'filter' => 'array'])]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Request
{
    private array $data;

    /**
     * @param array $data Parameter mapping configuration
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Returns the parameter mapping data.
     */
    public function getData(): array
    {
        return $this->data;
    }
}
