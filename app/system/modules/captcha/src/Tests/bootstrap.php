<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the Captcha module unit tests.
 *
 * Two things are missing under PHPUnit and supplied here:
 *
 *  1. Translation helper. composer.json declares no `autoload.files`, so neither
 *     intl `functions.php` (global `\__()`) nor `functions-pagekit-namespace.php`
 *     (`Pagekit\__()`) is loaded. {@see \Pagekit\Captcha\CaptchaListener} calls
 *     `__()` UNQUALIFIED with no `use function` import, so PHP's fallback rule
 *     resolves it to the GLOBAL `\__()` — not `Pagekit\__()` (an unqualified call
 *     first looks for `Pagekit\Captcha\__()`, then falls back to `\__()`, never to
 *     a parent namespace). The stub is therefore declared in the global namespace,
 *     guarded by `function_exists` so it stays inert if a real `\__()` is ever
 *     loaded first.
 *
 *  2. Runtime-loaded class. The captcha module namespace (`Pagekit\Captcha\`) is
 *     NOT registered in composer's autoload map (module-autoload only), so
 *     PHPUnit cannot autoload {@see \Pagekit\Captcha\CaptchaListener}. Require it
 *     here, mirroring comment/site bootstraps that pull in their own
 *     non-autoloaded classes the same way.
 */

if (!function_exists('__')) {
    /**
     * Translation stub for unit tests: returns the message with parameter
     * substitution (no actual translation).
     *
     * The `$args` element type MUST mirror the real global intl `\__()`
     * (app/system/modules/intl/functions.php declares `array<string, mixed>
     * $parameters`). PHPStan sees this guarded declaration alongside the intl one
     * and may resolve unqualified `\__()` calls to either; a narrower type here
     * would wrongly flag unrelated production call sites that pass `mixed`
     * placeholders. Values are coerced to string locally so the `strtr()` body
     * stays type-clean at level 8.
     *
     * @param array<string, mixed> $args
     */
    function __(string $message, array $args = []): string
    {
        $replacements = [];

        foreach ($args as $key => $value) {
            $replacements[$key] = is_scalar($value) ? (string) $value : '';
        }

        return strtr($message, $replacements);
    }
}

require_once __DIR__ . '/../CaptchaListener.php';
