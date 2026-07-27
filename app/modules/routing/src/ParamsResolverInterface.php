<?php

declare(strict_types=1);

namespace Pagekit\Routing;

interface ParamsResolverInterface
{
    /**
     * Callback to modify parameters after route matching.
     *
     * @param  array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function match(array $parameters = []): array;

    /**
     * Callback to modify parameters during URL generation.
     *
     * @param  array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function generate(array $parameters = []): array;
}
