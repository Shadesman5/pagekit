<?php

declare(strict_types=1);

namespace Pagekit\Filter;

/**
 * This filter keeps only alphabetic characters of the value.
 */
class AlphaFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    public function filter(mixed $value): ?string
    {
        return preg_replace('/[^[:alpha:]]/u', '', (string) $value);
    }
}
