<?php

declare(strict_types=1);

/**
 * Bootstrap for PackageManager integration tests.
 *
 * PackageManager (namespace Pagekit\Package) calls the unqualified translation
 * helper __(). PHP resolves that to Pagekit\Package\__() before falling back to
 * the global \__(), so defining the stub in this exact namespace lets the
 * manager run without a booted intl service — and without touching the global
 * \__ used by other suites.
 *
 * @param array<string, string|int|float> $args
 */

namespace Pagekit\Package;

if (!function_exists('Pagekit\Package\__')) {
    function __(string $message, array $args = []): string
    {
        return strtr($message, $args);
    }
}
