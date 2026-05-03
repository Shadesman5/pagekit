<?php

declare(strict_types=1);

namespace Pagekit\Filter;

/**
 * This filter keeps only alphabetic characters and digits of the value.
 */
class AlnumFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    public function filter($value): ?string
    {
        return preg_replace('/[^[:alnum:]]/u', '', (string) $value);
    }
}
