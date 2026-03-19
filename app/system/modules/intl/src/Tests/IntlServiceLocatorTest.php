<?php

declare(strict_types=1);

namespace Pagekit\Intl\Tests;

use Pagekit\Intl\IntlServiceLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class IntlServiceLocatorTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset static state via reflection to isolate tests
        $ref = new \ReflectionClass(IntlServiceLocator::class);

        $translator = $ref->getProperty('translator');
        $translator->setValue(null, null);

        $intl = $ref->getProperty('intl');
        $intl->setValue(null, null);
    }

    public function testGetTranslatorThrowsWhenNotInitialized(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Translator not initialized');

        IntlServiceLocator::getTranslator();
    }

    public function testSetAndGetTranslator(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);

        IntlServiceLocator::setTranslator($translator);

        $this->assertSame($translator, IntlServiceLocator::getTranslator());
    }

    public function testGetIntlThrowsWhenNotInitialized(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Intl service not initialized');

        IntlServiceLocator::getIntl();
    }

    public function testSetAndGetIntl(): void
    {
        $intl = new \stdClass();
        $intl->locale = 'en';

        IntlServiceLocator::setIntl($intl);

        $this->assertSame($intl, IntlServiceLocator::getIntl());
    }

    public function testTranslatorAndIntlAreIndependent(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $intl = new \stdClass();

        IntlServiceLocator::setTranslator($translator);
        IntlServiceLocator::setIntl($intl);

        $this->assertSame($translator, IntlServiceLocator::getTranslator());
        $this->assertSame($intl, IntlServiceLocator::getIntl());
    }
}
