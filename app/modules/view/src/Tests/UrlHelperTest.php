<?php

declare(strict_types=1);

namespace Pagekit\View\Tests;

use Pagekit\Event\EventDispatcher;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use Pagekit\Routing\Generator\UrlGenerator;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Router;
use Pagekit\Routing\Routes;
use Pagekit\Routing\UrlProvider;
use Pagekit\View\Helper\UrlHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

class UrlHelperTest extends TestCase
{
    public function testInvokeReturnsTheProviderUrl(): void
    {
        $provider = $this->createMock(UrlProvider::class);
        $provider->expects($this->once())
            ->method('get')
            ->with('blog', ['id' => 1], UrlGenerator::ABSOLUTE_URL)
            ->willReturn('https://example.com/blog');

        $helper = new UrlHelper($provider);

        $this->assertSame('https://example.com/blog', $helper('blog', ['id' => 1], UrlGenerator::ABSOLUTE_URL));
    }

    public function testInvokeTurnsAnUnresolvedRouteIntoAnEmptyString(): void
    {
        $provider = $this->createMock(UrlProvider::class);
        $provider->method('get')->willReturn(false);

        $helper = new UrlHelper($provider);

        $this->assertSame('', $helper('@missing'));
    }

    public function testMethodsAreForwardedToTheProvider(): void
    {
        $provider = $this->createMock(UrlProvider::class);
        $provider->expects($this->once())
            ->method('base')
            ->with(UrlProvider::BASE_PATH)
            ->willReturn('');
        $provider->expects($this->once())
            ->method('previous')
            ->willReturn(null);

        $helper = new UrlHelper($provider);

        // The helper has no base() or previous() of its own; __call is the forward.
        $this->assertSame('', $helper->__call('base', [UrlProvider::BASE_PATH]));
        $this->assertNull($helper->__call('previous', []));
    }

    public function testAnUnknownMethodNamesTheRoutingProvider(): void
    {
        $helper = new UrlHelper(new UrlProvider(
            new Router(new Routes(), new RoutesLoader(new EventDispatcher()), new RequestStack()),
            new Filesystem(),
            new Locator(sys_get_temp_dir()),
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Undefined method call "Pagekit\\Routing\\UrlProvider::missing"');

        $helper->__call('missing', []);
    }
}
