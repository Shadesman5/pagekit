<?php

declare(strict_types=1);

namespace Pagekit\Intl;

use Symfony\Component\Translation\Translator;

/**
 * Static accessor bridge for PHP global translation functions.
 *
 * The class itself is DI-constructed (constructor injection); the static
 * register()/get*() layer is a minimal, unavoidable bridge because PHP global
 * functions (__(), _c(), _i(), _n()) have no DI-capable constructor of their own.
 */
final class IntlServiceLocator
{
    private static ?self $instance = null;

    public function __construct(
        private readonly Translator $translator,
        private readonly IntlModule $intl,
    ) {
    }

    /**
     * Registers the DI-constructed instance as the static accessor target.
     * Called exactly once during module boot with a properly constructed instance.
     */
    public static function register(self $locator): void
    {
        self::$instance = $locator;
    }

    public static function getTranslator(): Translator
    {
        return self::resolve()->translator;
    }

    public static function getIntl(): IntlModule
    {
        return self::resolve()->intl;
    }

    private static function resolve(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('IntlServiceLocator not initialized. Was IntlModule booted?');
        }

        return self::$instance;
    }
}
