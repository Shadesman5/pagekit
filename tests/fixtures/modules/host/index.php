<?php

declare(strict_types=1);

/**
 * A package that brings its own modules, the way the system module registers the
 * modules it is built from. They are found through this declaration rather than
 * by the sweep that found this file, and are executed one level deeper than it.
 */

return [
    'name' => 'fixture-host',
    'include' => 'modules/*/index.php',
];
