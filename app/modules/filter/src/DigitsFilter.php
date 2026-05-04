<?php

declare(strict_types=1);

namespace Pagekit\Filter;

/**
 * This filter keeps only digits of the value.
 */
class DigitsFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    public function filter(mixed $value): string
    {
        return str_replace(['-', '+'], '', filter_var((string) $value, FILTER_SANITIZE_NUMBER_INT));
    }
}
