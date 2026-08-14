<?php

declare(strict_types=1);

/**
 * A package that fails every time it is executed and leaves behind how often
 * that happened.
 *
 * A failure reaches the log and the failure record whether it happened once or
 * on every request, so neither of them says what a barrier that keeps trying a
 * broken module actually costs: the work the module does before it fails. The
 * count is that cost. It is kept in the container the boot hands to main(),
 * which outlives the module manager that executed it.
 */

use Pagekit\Application;

return [
    'name' => 'fixture-main-counting',
    'main' => function (Application $app): void {
        $executions = 'fixture-main-counting.executions';

        $app->set($executions, ($app->has($executions) ? (int) $app->get($executions) : 0) + 1);

        throw new \RuntimeException('The module could not be loaded');
    },
];
