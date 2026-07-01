<?php

declare(strict_types=1);

namespace Pagekit\Routing\Request;

interface ParamFetcherInterface
{
    /**
     * Get a validated parameter.
     *
     * @param  string $index
     * @return mixed Genuinely unknown type — request parameters may be any scalar, array, or null depending on the route definition.
     */
    public function get($index);
}
