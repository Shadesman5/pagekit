<?php

declare(strict_types=1);

namespace Pagekit\Filter;

/**
 * This filter decodes a JSON string to a array.
 */
class JsonFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     *
     * @param  mixed $value Genuinely unknown type — filter input may be any type; only string values are decoded.
     * @return mixed Genuinely unknown type — returns the decoded JSON as an array, or null if input is not a string.
     */
    public function filter(mixed $value): mixed
    {
        if (is_string($value)) {
            return json_decode($value, true);
        }

        return null;
    }
}
