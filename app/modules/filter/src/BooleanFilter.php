<?php

declare(strict_types=1);

namespace Pagekit\Filter;

/**
 * This filter converts the value to boolean.
 */
class BooleanFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    public function filter($value): bool
    {
        return (bool) @(string) $value;
    }
}
