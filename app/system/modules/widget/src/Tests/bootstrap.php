<?php

declare(strict_types=1);

/**
 * Bootstrap for the Widget module unit tests.
 *
 * Supplies the `Pagekit\__()` translation helper the widget controllers import
 * (`use function Pagekit\__;`) but which is NOT available under PHPUnit:
 * composer.json declares no `autoload.files`, so neither intl
 * `functions-pagekit-namespace.php` (`Pagekit\__()`) nor `functions.php`
 * (`\__()`) is loaded. The `function_exists` guard keeps the stub inert if a
 * real `Pagekit\__()` is ever loaded first, matching the site module bootstrap.
 *
 * It also requires the widget source classes under test: the widget module
 * namespace (`Pagekit\Widget\`) is a runtime-loaded module and is NOT registered
 * in composer's autoload map, so PHPUnit cannot autoload the PositionHelper, the
 * controllers, the managers or the Widget entity. Mirrors
 * tests/Unit/Blog/bootstrap.php, which pulls in its own non-autoloaded classes
 * the same way (traits/parents are composer-autoloaded, so only the widget
 * classes themselves are required, in dependency order).
 */

namespace Pagekit;

if (!function_exists('Pagekit\__')) {
    /**
     * Translation stub for unit tests.
     * Returns the message with parameter substitution (no actual translation).
     *
     * @param array<string, string|int|float> $args
     */
    function __(string $message, array $args = []): string
    {
        return strtr($message, $args);
    }
}

$widgetSrc = \dirname(__DIR__);

require_once $widgetSrc . '/Model/TypeInterface.php';
require_once $widgetSrc . '/Model/Widget.php';
require_once $widgetSrc . '/PositionManager.php';
require_once $widgetSrc . '/WidgetManager.php';
require_once $widgetSrc . '/PositionHelper.php';
require_once $widgetSrc . '/Controller/WidgetController.php';
require_once $widgetSrc . '/Controller/WidgetApiController.php';
