<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the User module unit tests.
 *
 * Supplies the `__()` translation helper that classes under test call but which
 * is NOT available under PHPUnit: composer.json declares no `autoload.files`, so
 * neither intl `functions.php` (global `\__()`) nor `functions-pagekit-namespace.php`
 * (`Pagekit\__()`) is loaded (ticket discovery note 5).
 *
 * The target namespace matters. `User` lives in `Pagekit\User\Model` and calls
 * `__()` UNQUALIFIED with no `use function` import, so PHP's fallback rule
 * resolves `User::getStatusText()`/`getStatuses()` to the GLOBAL `\__()` — not
 * `Pagekit\__()` (verified: an unqualified call in `Pagekit\User\Model` first
 * looks for `Pagekit\User\Model\__()`, then falls back to `\__()`, never to a
 * parent namespace). This is what UserTest exercises, so the stub is declared in
 * the global namespace. The `function_exists` guard keeps it inert if a real
 * `\__()` is ever loaded first.
 *
 * The user listeners (AuthorizationListener, LoginAttemptListener) instead import
 * `use function Pagekit\__;`, so their tests (Checklist Steps 6-7) additionally
 * need a `Pagekit\__()` stub — added when those steps land.
 */

if (!function_exists('__')) {
    /**
     * Translation stub for unit tests: returns the message with parameter
     * substitution (no actual translation).
     *
     * @param array<string, string|int|float> $args
     */
    function __(string $message, array $args = []): string
    {
        return strtr($message, $args);
    }
}
