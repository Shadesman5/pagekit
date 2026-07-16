<?php

declare(strict_types=1);

/**
 * Bootstrap for Site module tests.
 *
 * Defines the Pagekit\__() translation stub so tests don't need a booted app,
 * and requires the content module's ContentHelper: the content module namespace
 * (Pagekit\Content\) is a runtime-loaded module and is NOT registered in
 * composer's autoload map, so PHPUnit cannot autoload the ContentHelper that
 * PageControllerTest mocks. Mirrors tests/Unit/Blog/bootstrap.php, which pulls
 * in its own non-autoloaded classes the same way.
 */

namespace Pagekit;

if (!function_exists('Pagekit\__')) {
    /**
     * Translation stub for unit tests.
     * Returns the message with parameter substitution (no actual translation).
     *
     * @param array<string, string|int|float> $args
     */
    function __(string $message, array $args = []): string
    {
        return strtr($message, $args);
    }
}

require_once __DIR__ . '/../../../content/src/ContentHelper.php';
