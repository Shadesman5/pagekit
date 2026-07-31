<?php

declare(strict_types=1);

/**
 * PHPUnit suite bootstrap.
 *
 * Controllers and templates call __() / Pagekit\__() via IntlServiceLocator.
 * Unit tests rarely fire the module boot event, but any call to IntlModule::main()
 * (or a require of the intl function files) installs those helpers. Without a
 * registered locator the full suite then fails with "IntlServiceLocator not
 * initialized" as soon as a later test hits __().
 *
 * Load the real helpers once and register a passthrough translator so every
 * test process starts with a working platform API. Tests that assert the
 * uninitialized state must clear and restore via pagekit_phpunit_install_intl_locator().
 */

require_once dirname(__DIR__) . '/app/vendor/autoload.php';

require_once dirname(__DIR__) . '/app/system/modules/intl/functions.php';
require_once dirname(__DIR__) . '/app/system/modules/intl/functions-pagekit-namespace.php';

/**
 * Registers a suite-default IntlServiceLocator (identity translations).
 */
function pagekit_phpunit_install_intl_locator(): void
{
    $translator = new Symfony\Component\Translation\Translator('en_US');
    $intl = new Pagekit\Intl\IntlModule([
        'name' => 'system/intl',
        'path' => dirname(__DIR__) . '/app/system/modules/intl',
        'config' => ['locale' => 'en_US'],
    ]);

    Pagekit\Intl\IntlServiceLocator::register(
        new Pagekit\Intl\IntlServiceLocator($translator, $intl)
    );
}

pagekit_phpunit_install_intl_locator();
