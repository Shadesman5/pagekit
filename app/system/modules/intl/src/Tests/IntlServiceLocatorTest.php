<?php

declare(strict_types=1);

namespace Pagekit\Intl\Tests;

use Pagekit\Intl\IntlModule;
use Pagekit\Intl\IntlServiceLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

class IntlServiceLocatorTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset static instance via reflection to isolate tests
        $ref = new \ReflectionClass(IntlServiceLocator::class);
        $instance = $ref->getProperty('instance');
        $instance->setValue(null, null);
    }

    public function testGetTranslatorThrowsWhenNotInitialized(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IntlServiceLocator not initialized');

        IntlServiceLocator::getTranslator();
    }

    public function testGetIntlThrowsWhenNotInitialized(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IntlServiceLocator not initialized');

        IntlServiceLocator::getIntl();
    }

    public function testRegisterAndGetTranslator(): void
    {
        $translator = $this->createMock(Translator::class);
        $intl = $this->createMock(IntlModule::class);

        IntlServiceLocator::register(new IntlServiceLocator($translator, $intl));

        $this->assertSame($translator, IntlServiceLocator::getTranslator());
    }

    public function testRegisterAndGetIntl(): void
    {
        $translator = $this->createMock(Translator::class);
        $intl = $this->createMock(IntlModule::class);

        IntlServiceLocator::register(new IntlServiceLocator($translator, $intl));

        $this->assertSame($intl, IntlServiceLocator::getIntl());
    }

    public function testBothServicesAccessibleAfterRegister(): void
    {
        $translator = $this->createMock(Translator::class);
        $intl = $this->createMock(IntlModule::class);

        IntlServiceLocator::register(new IntlServiceLocator($translator, $intl));

        $this->assertSame($translator, IntlServiceLocator::getTranslator());
        $this->assertSame($intl, IntlServiceLocator::getIntl());
    }
}
