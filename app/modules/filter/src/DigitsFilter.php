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
        $sanitized = filter_var((string) $value, FILTER_SANITIZE_NUMBER_INT);

        return str_replace(['-', '+'], '', $sanitized === false ? '' : $sanitized);
    }
}
