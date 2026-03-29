<?php

declare(strict_types=1);

namespace Pagekit\Intl;

use Symfony\Contracts\Translation\TranslatorInterface;

final class IntlServiceLocator
{
    private static ?TranslatorInterface $translator = null;
    private static mixed $intl = null;

    public static function setTranslator(TranslatorInterface $translator): void
    {
        self::$translator = $translator;
    }

    public static function getTranslator(): TranslatorInterface
    {
        if (self::$translator === null) {
            throw new \RuntimeException('Translator not initialized. Was IntlModule booted?');
        }

        return self::$translator;
    }

    public static function setIntl(mixed $intl): void
    {
        self::$intl = $intl;
    }

    public static function getIntl(): mixed
    {
        if (self::$intl === null) {
            throw new \RuntimeException('Intl service not initialized. Was IntlModule booted?');
        }

        return self::$intl;
    }
}
