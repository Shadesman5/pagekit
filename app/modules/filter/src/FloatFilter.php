<?php

declare(strict_types=1);

namespace Pagekit\Filter;

/**
 * This filter converts the value to float.
 */
class FloatFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    public function filter($value): float
    {
        return (float) ((string) $value);
    }
}
