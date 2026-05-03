<?php

declare(strict_types=1);

use Pagekit\Intl\IntlServiceLocator;
use Symfony\Component\Translation\Formatter\IntlFormatter;

// Global namespace functions
if (!function_exists('__')) {
    /**
     * Translates the given message, alias for method trans()
     */
    function __($id, array $parameters = [], $domain = 'messages', $locale = null)
    {
        return IntlServiceLocator::getTranslator()->trans($id, $parameters, $domain, $locale);
    }
}

if (!function_exists('_c')) {
    /**
     * Legacy pluralization helper — replaces all %param% with %count% as a
     * brute-force bridge from the removed transChoice() API.
     *
     * TODO: Must be refactored in Step 3.4.6 (Translation System Modernization) —
     * Remove _c() and all call sites (~28 files), migrate to __() with ICU MessageFormat.
     * Also remove transChoice() from Vue plugin (trans.js).
     */
    function _c($id, $number, array $parameters = [], $domain = null, $locale = null)
    {

        $id = preg_replace('/(%)(.*?)(%)/', '%count%', $id);

        $params = [];
        foreach ($parameters as $key => $value) {
            $params[preg_replace('/(%)(.*?)(%)/', '%count%', $key)] = $value;
        }

        return IntlServiceLocator::getTranslator()->trans($id, $params, $domain, $locale);
    }
}

if (!function_exists('_i')) {
    /**
     * Translate messages using ICU MessageFormat.
     * @see https://symfony.com/doc/current/translation/message_format.html
     *
     * TODO: Must be refactored in Step 3.4.6 (Translation System Modernization) —
     * PHP-side (_i) is done. Add Vue equivalent $transICU() to trans.js.
     */
    function _i($id, array $parameters = [], $domain = null, $locale = null)
    {

        if (null === $domain) {
            $domain = 'messages';
        }

        $catalogue = IntlServiceLocator::getTranslator()->getCatalogue($locale);
        $locale = $catalogue->getLocale();
        while (!$catalogue->defines($id, $domain)) {
            if ($cat = $catalogue->getFallbackCatalogue()) {
                $catalogue = $cat;
                $locale = $catalogue->getLocale();
            } else {
                break;
            }
        }

        return (new IntlFormatter())->formatIntl($catalogue->get($id, $domain), $locale, $parameters);
    }
}
