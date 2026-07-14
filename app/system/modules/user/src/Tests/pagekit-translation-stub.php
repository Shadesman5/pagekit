<?php

declare(strict_types=1);

namespace Pagekit;

if (!function_exists(__NAMESPACE__ . '\\__')) {
    /**
     * Translation stub for controllers that import `use function Pagekit\__;`.
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
