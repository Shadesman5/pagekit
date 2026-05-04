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
     */
    public function filter(mixed $value): mixed
    {
        if (is_string($value)) {
            return json_decode($value, true);
        }

        return null;
    }
}
