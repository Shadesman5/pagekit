<?php

declare(strict_types=1);

namespace Pagekit\Routing\Tests;

use Pagekit\Event\EventDispatcher;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use Pagekit\Routing\Generator\UrlGenerator;
use Pagekit\Routing\Loader\RoutesLoader;
use Pagekit\Routing\Router;
use Pagekit\Routing\Routes;
use Pagekit\Routing\UrlProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class UrlProviderTest extends TestCase
{
    private Routes $routes;

    private RequestStack $stack;

    private Router $router;

    private UrlProvider $provider;

    protected function setUp(): void
    {
        $this->routes = new Routes();
        $this->stack = new RequestStack();
        $this->router = new Router($this->routes, new RoutesLoader(new EventDispatcher()), $this->stack);
        $this->provider = new UrlProvider($this->router, new Filesystem(), new Locator(sys_get_temp_dir()));
    }

    public function testPathsAreRootedAtTheRequestBase(): void
    {
        $this->pushRequest();

        $this->assertSame('/pagekit', $this->provider->base());
        $this->assertSame('https://example.com/pagekit', $this->provider->base(UrlGenerator::ABSOLUTE_URL));
        $this->assertSame('', $this->provider->base(UrlProvider::BASE_PATH));
        $this->assertSame('/pagekit/index.php/admin?keep=1', $this->provider->current());
        $this->assertSame('https://example.com/pagekit/index.php/admin?keep=1', $this->provider->current(UrlGenerator::ABSOLUTE_URL));
        $this->assertSame('https://example.com/before', $this->provider->previous());
        $this->assertSame('/pagekit/blog/post', $this->provider->get('blog/post'));
        $this->assertSame('/pagekit/blog/post', $this->provider->get('/blog/post'));
        $this->assertSame('https://example.com/pagekit/blog/post', $this->provider->get('blog/post', [], UrlGenerator::ABSOLUTE_URL));
        $this->assertSame('/pagekit/', $this->provider->get(null));
        $this->assertSame($this->provider->get('blog/post'), $this->provider->__invoke('blog/post'));
    }

    public function testThereIsNoBaseWithoutARequest(): void
    {
        $this->assertSame('', $this->provider->base());
        $this->assertSame('', $this->provider->base(UrlGenerator::ABSOLUTE_URL));
        $this->assertSame('', $this->provider->current());
        $this->assertNull($this->provider->previous());
        $this->assertSame('/blog/post', $this->provider->get('blog/post'));
        $this->assertSame('/blog/post', $this->provider->get('blog/post', [], UrlGenerator::ABSOLUTE_URL));
        $this->assertSame('/', $this->provider->get(null));
    }

    public function testQueryParametersMergeAndAUrlQueryWins(): void
    {
        $this->pushRequest();

        // Parameters already in the path override the array, so a caller cannot
        // silently replace a query the URL itself named.
        $this->assertSame(
            '/pagekit/search?q=kept&page=2',
            $this->provider->get('search?q=kept', ['q' => 'dropped', 'page' => '2']),
        );
        $this->assertSame(
            'https://cdn.example/app.js?v=9&x=2',
            $this->provider->get('https://cdn.example/app.js?v=9', ['v' => '1', 'x' => '2']),
        );
    }

    public function testANamedRouteIsGeneratedFromTheRouterContext(): void
    {
        $this->addBlogRoutes();
        $this->pushRequest();

        $this->assertSame('/pagekit/index.php/blog/5', $this->provider->get('@blog/id', ['id' => 5]));
        $this->assertSame(
            'https://example.com/pagekit/index.php/blog/5',
            $this->provider->route('@blog/id', ['id' => 5], UrlGenerator::ABSOLUTE_URL),
        );
        $this->assertSame(
            $this->provider->getRoute('@blog/id', ['id' => 5]),
            $this->provider->route('@blog/id', ['id' => 5]),
        );
    }

    public function testBasePathStripsTheFrontControllerFromAGeneratedRoute(): void
    {
        $this->addBlogRoutes();
        $this->pushRequest();

        $this->assertSame('/blog/5', $this->provider->get('@blog/id', ['id' => 5], UrlProvider::BASE_PATH));
    }

    public function testBasePathKeepsTheGeneratedBaseWhenThereIsNoRequest(): void
    {
        $this->addBlogRoutes();
        $this->router->getContext()->setBaseUrl('/pagekit/index.php');

        $this->assertSame('/pagekit/index.php/blog', $this->provider->get('@blog', [], UrlProvider::BASE_PATH));
    }

    public function testAnUnknownOrInvalidRouteIsNotAUrl(): void
    {
        $this->addBlogRoutes();

        // Callers treat a false URL as "no link", so these stay failures
        // instead of the routing exceptions the generator throws.
        $this->assertFalse($this->provider->get('@missing'));
        $this->assertFalse($this->provider->route('@missing'));
        $this->assertFalse($this->provider->get('@blog/id'));
        $this->assertFalse($this->provider->get('@blog/id', ['id' => 'nope']));
    }

    public function testANonIntegerReferenceTypeFallsBackToAnAbsolutePath(): void
    {
        $this->addBlogRoutes();
        $this->pushRequest();

        $this->assertSame(
            $this->provider->getRoute('@blog/id', ['id' => 5], UrlGenerator::ABSOLUTE_PATH),
            $this->provider->getRoute('@blog/id', ['id' => 5], 'not-a-type'),
        );
    }

    public function testStaticPathsUseTheFilesystemAndCanDropTheBasePath(): void
    {
        $locator = $this->createMock(Locator::class);
        $file = $this->createMock(Filesystem::class);
        $locator->expects($this->exactly(2))
            ->method('get')
            ->with('asset://app.js')
            ->willReturn('/srv/public/app.js');
        $file->expects($this->exactly(2))
            ->method('getUrl')
            ->with('/srv/public/app.js', UrlGenerator::ABSOLUTE_PATH)
            ->willReturn('/pagekit/assets/app.js');

        $provider = new UrlProvider($this->router, $file, $locator);

        $this->assertSame('/pagekit/assets/app.js?v=1', $provider->getStatic('asset://app.js', ['v' => '1']));

        $this->pushRequest();

        $this->assertSame(
            '/assets/app.js?v=1',
            $provider->getStatic('asset://app.js', ['v' => '1'], UrlProvider::BASE_PATH),
        );
    }

    public function testAStaticPathTheFilesystemCannotUrlIsEmpty(): void
    {
        $locator = $this->createMock(Locator::class);
        $file = $this->createMock(Filesystem::class);
        $locator->expects($this->once())->method('get')->with('missing.js')->willReturn(false);
        $file->expects($this->once())
            ->method('getUrl')
            ->with('missing.js', UrlGenerator::ABSOLUTE_PATH)
            ->willReturn(false);

        $provider = new UrlProvider($this->router, $file, $locator);

        $this->assertSame('', $provider->getStatic('missing.js'));
    }

    private function addBlogRoutes(): void
    {
        $this->routes->add([
            'name' => '@blog',
            'path' => '/blog',
            'defaults' => ['_controller' => 'BlogController::indexAction'],
        ]);
        $this->routes->add([
            'name' => '@blog/id',
            'path' => '/blog/{id}',
            'defaults' => ['_controller' => 'BlogController::postAction'],
            'requirements' => ['id' => '\d+'],
        ]);
    }

    private function pushRequest(): Request
    {
        $request = Request::create('https://example.com/pagekit/index.php/admin?keep=1', 'GET', [], [], [], [
            'SCRIPT_NAME' => '/pagekit/index.php',
            'SCRIPT_FILENAME' => '/var/www/public/index.php',
        ]);
        $request->headers->set('referer', 'https://example.com/before');
        $this->stack->push($request);

        $context = $this->router->getContext();
        $context->setBaseUrl($request->getBaseUrl());
        $context->setScheme('https');
        $context->setHost('example.com');

        return $request;
    }
}
