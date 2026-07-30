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
 *
 * The translator may be supplied eagerly (tests) or as a factory so module boot
 * can register the locator without building the translator — and freezing
 * locales — before the full module graph is loaded.
 */
final class IntlServiceLocator
{
    private static ?self $instance = null;

    private ?Translator $translator = null;

    /** @var (callable(): mixed)|null */
    private $translatorFactory = null;

    /**
     * @param Translator|callable(): mixed $translator Eager instance or lazy factory
     */
    public function __construct(
        Translator|callable $translator,
        private readonly IntlModule $intl,
    ) {
        if ($translator instanceof Translator) {
            $this->translator = $translator;
        } else {
            $this->translatorFactory = $translator;
        }
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
        return self::resolve()->resolveTranslator();
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

    private function resolveTranslator(): Translator
    {
        if ($this->translator !== null) {
            return $this->translator;
        }

        if ($this->translatorFactory === null) {
            throw new \RuntimeException('IntlServiceLocator has no translator');
        }

        $translator = ($this->translatorFactory)();
        if (!$translator instanceof Translator) {
            throw new \RuntimeException('translator factory must return an instance of Translator');
        }

        $this->translator = $translator;

        return $this->translator;
    }
}
