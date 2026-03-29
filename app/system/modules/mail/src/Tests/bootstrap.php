<?php

declare(strict_types=1);

/**
 * Bootstrap for Mail module tests.
 *
 * Defines the Pagekit\__() translation stub so tests don't need eval().
 */

namespace Pagekit;

if (!function_exists('Pagekit\__')) {
    /**
     * Translation stub for unit tests.
     * Returns the message with parameter substitution (no actual translation).
     */
    function __($message, $args = [])
    {
        return strtr($message, $args);
    }
}
