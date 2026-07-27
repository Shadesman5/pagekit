<?php

declare(strict_types=1);

namespace Pagekit\Routing\Loader;

use Pagekit\Routing\Route;

interface LoaderInterface
{
    /**
     * Loads routes.
     *
     * @param  mixed $routes Genuinely unknown type — routes may be an array definition, a file path string, or a Route[] list.
     * @return mixed|Route[] Genuinely unknown type — returns loaded routes in the format required by the next stage in the loader chain.
     */
    public function load($routes);
}
