<?php

declare(strict_types=1);

namespace Pagekit;

use Pagekit\Intl\IntlServiceLocator;

if (!function_exists('Pagekit\__')) {
    /**
     * Translates the given message, alias for method trans()
     */
    function __($id, array $parameters = [], $domain = 'messages', $locale = null)
    {
        return IntlServiceLocator::getTranslator()->trans($id, $parameters, $domain, $locale);
    }
}

if (!function_exists('Pagekit\_c')) {
    /**
     * Pluralization via trans() with %count% parameter (replaces removed transChoice()).
     *
     * TODO: Must be refactored in Step 3.4.6 (Translation System Modernization) —
     * Remove _c() once all call sites use __() with ICU MessageFormat.
     */
    function _c($id, $number, array $parameters = [], $domain = null, $locale = null)
    {
        return IntlServiceLocator::getTranslator()->trans($id, array_merge(['%count%' => $number], $parameters), $domain, $locale);
    }
}

if (!function_exists('Pagekit\_n')) {
    /**
     * Formats a number
     */
    function _n($number, $style = 'decimal', $pattern = '', $locale = null)
    {
        return IntlServiceLocator::getIntl()->formatNumber($number, $style, $pattern, $locale);
    }
}
