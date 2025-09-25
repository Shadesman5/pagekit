<?php

namespace Pagekit;

use Pagekit\Application as App;

if (!function_exists('Pagekit\__')) {
    /**
     * Translates the given message, alias for method trans()
     */
    function __($id, array $parameters = [], $domain = 'messages', $locale = null) {
        return App::translator()->trans($id, $parameters, $domain, $locale);
    }
}

if (!function_exists('Pagekit\_c')) {
    /**
     * The transChoice() method is deprecated since Symfony 4.2, use the trans() one instead with a "%%count%%" parameter.
     */
    function _c($id, $number, array $parameters = [], $domain = null, $locale = null) {
        return App::translator()->trans($id, array_merge(['%count%' => $number], $parameters), $domain, $locale);
    }
}

if (!function_exists('Pagekit\_n')) {
    /**
     * Formats a number
     */
    function _n($number, $style = 'decimal', $pattern = '', $locale = null) {
        return App::intl()->formatNumber($number, $style, $pattern, $locale);
    }
}