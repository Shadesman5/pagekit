<?php

declare(strict_types=1);

/**
 * Bootstrap for PackageManager integration tests.
 *
 * PackageManager (namespace Pagekit\Installer\Package) calls the unqualified
 * translation helper __(). PHP resolves that to Pagekit\Installer\Package\__()
 * before falling back to the global \__(), so defining the stub in this exact
 * namespace lets the manager run without a booted intl service — and without
 * touching the global \__ used by other suites.
 *
 * @param array<string, string|int|float> $args
 */

namespace Pagekit\Installer\Package;

if (!function_exists('Pagekit\Installer\Package\__')) {
    function __(string $message, array $args = []): string
    {
        return strtr($message, $args);
    }
}
