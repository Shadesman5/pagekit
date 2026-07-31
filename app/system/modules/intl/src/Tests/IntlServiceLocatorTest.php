<?php

declare(strict_types=1);

namespace Pagekit\Intl\Tests;

use Pagekit\Intl\IntlModule;
use Pagekit\Intl\IntlServiceLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

class IntlServiceLocatorTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearLocator();
    }

    protected function tearDown(): void
    {
        $this->clearLocator();
        // Restore a suite-default locator so later tests that call __() keep working.
        $translator = new Translator('en_US');
        $intl = new IntlModule([
            'name' => 'system/intl',
            'path' => dirname(__DIR__, 2),
            'config' => ['locale' => 'en_US'],
        ]);
        IntlServiceLocator::register(new IntlServiceLocator($translator, $intl));
    }

    private function clearLocator(): void
    {
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

    public function testLazyFactoryIsNotInvokedOnRegisterOrGetIntl(): void
    {
        $calls = 0;
        $translator = new Translator('en_US');
        $intl = $this->createMock(IntlModule::class);

        IntlServiceLocator::register(new IntlServiceLocator(
            static function () use (&$calls, $translator): Translator {
                ++$calls;

                return $translator;
            },
            $intl,
        ));

        $this->assertSame($intl, IntlServiceLocator::getIntl());
        $this->assertSame(0, $calls, 'registering and getIntl must not build the translator');
    }

    public function testLazyFactoryResolvesOnFirstGetTranslatorAndCaches(): void
    {
        $calls = 0;
        $intl = $this->createMock(IntlModule::class);

        IntlServiceLocator::register(new IntlServiceLocator(
            static function () use (&$calls): Translator {
                ++$calls;

                return new Translator('en_US');
            },
            $intl,
        ));

        $first = IntlServiceLocator::getTranslator();
        $this->assertSame(1, $calls);

        $second = IntlServiceLocator::getTranslator();
        $this->assertSame($first, $second, 'the factory result must be cached after the first resolution');
    }

    public function testLazyFactoryResolvesOnFirstGlobalTranslateHelper(): void
    {
        require_once dirname(__DIR__, 2) . '/functions.php';

        $intl = $this->createMock(IntlModule::class);
        $translator = $this->createMock(Translator::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with('Hello', [], 'messages', null)
            ->willReturn('Hello');

        IntlServiceLocator::register(new IntlServiceLocator(
            static fn (): Translator => $translator,
            $intl,
        ));

        $this->assertSame('Hello', __('Hello'));
    }

    public function testLazyFactoryMustReturnTranslator(): void
    {
        $intl = $this->createMock(IntlModule::class);

        IntlServiceLocator::register(new IntlServiceLocator(
            static fn (): string => 'not-a-translator',
            $intl,
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('translator factory must return an instance of Translator');

        IntlServiceLocator::getTranslator();
    }
}
