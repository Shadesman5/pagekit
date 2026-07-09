<?php

declare(strict_types=1);

/**
 * Bootstrap for MigrationCommand CLI integration tests.
 *
 * MigrationCommand (namespace Pagekit\Console\Commands) emits its success lines
 * through the unqualified translation helper __(). PHP resolves that to
 * Pagekit\Console\Commands\__() before falling back to the global \__(), so
 * defining the stub in this exact namespace lets the command run without a booted
 * intl service — and without touching the global \__ used by other suites.
 *
 * @param array<string, string|int|float> $args
 */

namespace Pagekit\Console\Commands;

if (!function_exists('Pagekit\Console\Commands\__')) {
    function __(string $message, array $args = []): string
    {
        return strtr($message, $args);
    }
}
