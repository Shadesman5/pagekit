<?php

declare(strict_types=1);

/**
 * A package that is declared correctly and only fails once it is loaded. Nothing
 * about its registration says so, which is what keeps the failure in the load
 * window, where it can still be attributed to a module name.
 */

return [
    'name' => 'fixture-main-throwing',
    'main' => function () {
        throw new \RuntimeException('The module could not be loaded');
    },
];
