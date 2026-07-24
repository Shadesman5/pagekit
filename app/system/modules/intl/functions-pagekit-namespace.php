<?php

declare(strict_types=1);

namespace Pagekit;

use Pagekit\Intl\IntlServiceLocator;

if (!function_exists('Pagekit\__')) {
    /**
     * Translates the given message, alias for method trans()
     *
     * @param array<string, mixed> $parameters
     */
    function __(string $id, array $parameters = [], ?string $domain = 'messages', ?string $locale = null): string
    {
        return IntlServiceLocator::getTranslator()->trans($id, $parameters, $domain, $locale);
    }
}

if (!function_exists('Pagekit\_c')) {
    /**
     * Pluralization via trans() with %count% parameter (replaces removed transChoice()).
     *
     * TODO: Must be refactored in Step 3.3.6 (Translation System Modernization) —
     * Remove _c() once all call sites use __() with ICU MessageFormat.
     *
     * @param array<string, mixed> $parameters
     */
    function _c(string $id, int|float $number, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return IntlServiceLocator::getTranslator()->trans($id, array_merge(['%count%' => $number], $parameters), $domain, $locale);
    }
}

if (!function_exists('Pagekit\_n')) {
    /**
     * Formats a number
     */
    function _n(int|float $number, string $style = 'decimal', string $pattern = '', ?string $locale = null): string
    {
        return IntlServiceLocator::getIntl()->formatNumber($number, $style, $pattern, $locale);
    }
}
