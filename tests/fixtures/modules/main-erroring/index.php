<?php

declare(strict_types=1);

/**
 * A package that is declared correctly and fails on a dependency that was never
 * installed once it is loaded - an extension left behind by the package it was
 * built against, which is the everyday way this window breaks.
 *
 * PHP reports a class it cannot load as an Error rather than as an exception, so
 * a barrier isolating exceptions alone would still let this one end the request.
 */

return [
    'name' => 'fixture-main-erroring',
    'main' => function () {
        new \Vendor\NotInstalled\Service();
    },
];
