<?php

declare(strict_types=1);

namespace Pagekit\Filter;

interface FilterInterface
{
    /**
     * Returns the filtered value.
     */
    public function filter(mixed $value): mixed;
}
