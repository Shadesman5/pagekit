<?php

declare(strict_types=1);

namespace Pagekit\Filter;

interface FilterInterface
{
    /**
     * Returns the filtered value.
     *
     * @param  mixed $value Genuinely unknown type — filters are a plugin extension point that must accept any input (strings, arrays, objects) and return any transformed type.
     * @return mixed Genuinely unknown type — the output type mirrors the input; no single return type can capture all filter implementations.
     */
    public function filter(mixed $value): mixed;
}
