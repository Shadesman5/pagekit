<?php

declare(strict_types=1);

/**
 * A package whose dependency was never installed - the most common way a module
 * file fails. PHP reports a class it cannot load as an Error rather than as an
 * exception, so isolating exceptions alone would leave this one fatal.
 */

new \Vendor\NotInstalled\Extension();

return [
    'name' => 'fixture-missing-class',
];
