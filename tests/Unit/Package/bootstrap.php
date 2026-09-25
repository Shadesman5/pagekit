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

/**
 * Test seams for unqualified calls in this namespace. They bind when PackageManager is compiled.
 */
final class InstallProbes
{
    /** @var (callable(string, string): bool)|null */
    public static $rename = null;

    /** @var list<string>|null */
    public static ?array $nextRandom = null;

    /** @var list<string> */
    public static array $invalidated = [];

    public static function reset(): void
    {
        self::$rename = null;
        self::$nextRandom = null;
        self::$invalidated = [];
    }
}

if (!function_exists('Pagekit\Package\rename')) {
    function rename(string $from, string $to): bool
    {
        $probe = InstallProbes::$rename;

        if ($probe !== null) {
            return $probe($from, $to);
        }

        return \rename($from, $to);
    }
}

if (!function_exists('Pagekit\Package\random_bytes')) {
    function random_bytes(int $length): string
    {
        $next = InstallProbes::$nextRandom;

        if ($next !== null && $next !== []) {
            $bytes = array_shift($next);
            InstallProbes::$nextRandom = $next;

            if (!is_string($bytes) || strlen($bytes) !== $length) {
                throw new \LengthException('Install probe random_bytes length mismatch.');
            }

            return $bytes;
        }

        return \random_bytes($length);
    }
}

if (!function_exists('Pagekit\Package\opcache_invalidate')) {
    function opcache_invalidate(string $filename, bool $force = false): bool
    {
        InstallProbes::$invalidated[] = $filename;

        return \opcache_invalidate($filename, $force);
    }
}
