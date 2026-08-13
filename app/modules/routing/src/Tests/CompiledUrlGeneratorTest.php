<?php

declare(strict_types=1);

namespace Pagekit\Routing\Tests;

use Pagekit\Routing\Generator\CompiledUrlGenerator;
use Pagekit\Routing\Generator\LinkReferenceType;
use Pagekit\Routing\Generator\UrlGenerator;
use Pagekit\Routing\Route;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Covers the generator that serves URLs once the routes are dumped. The dump
 * exists to save the compilation, not to change a URL, so every assertion here
 * is the same question: does generating from the dumped values answer what the
 * route collection answers?
 */
class CompiledUrlGeneratorTest extends TestCase
{
    public function testDumpedDataCarriesThePropertiesTheCollectionHolds(): void
    {
        $routes = $this->siteRoutes();
        $context = new RequestContext();

        $dumped = $this->dumpAndRead($routes);
        $collectionBacked = new UrlGenerator($routes, $context);
        $compiled = new CompiledUrlGenerator($dumped, $context);

        foreach (['@blog', '@blog/id', '@secure'] as $name) {
            $properties = $collectionBacked->getRouteProperties($name);

            $this->assertNotNull($properties);
            $this->assertSame($properties, $dumped[$name]);
            $this->assertSame($properties, $compiled->getRouteProperties($name));
        }
    }

    public function testGeneratesTheSameUrlsAsTheCollectionBackedGenerator(): void
    {
        $routes = $this->siteRoutes();
        $context = new RequestContext();

        $compiled = new CompiledUrlGenerator($this->dumpAndRead($routes), $context);
        $collectionBacked = new UrlGenerator($routes, $context);

        $this->assertSame('/blog/5', $compiled->generate('@blog/id', ['id' => 5]));
        $this->assertSame(
            $collectionBacked->generate('@blog/id', ['id' => 5]),
            $compiled->generate('@blog/id', ['id' => 5])
        );

        $this->assertSame('http://localhost/blog/5', $compiled->generate('@blog/id', ['id' => 5], UrlGenerator::ABSOLUTE_URL));
        $this->assertSame(
            $collectionBacked->generate('@blog/id', ['id' => 5], UrlGenerator::ABSOLUTE_URL),
            $compiled->generate('@blog/id', ['id' => 5], UrlGenerator::ABSOLUTE_URL)
        );

        // A parameter the path has no place for stays a query parameter.
        $this->assertSame('/blog?page=2', $compiled->generate('@blog', ['page' => 2]));

        // The host and the scheme a route demands are dumped with it, and a
        // scheme the context does not have turns the URL absolute.
        $secure = $compiled->generate('@secure', ['token' => 'abc', 'subdomain' => 'admin']);

        $this->assertSame('https://admin.example.com/secure/abc', $secure);
        $this->assertSame($collectionBacked->generate('@secure', ['token' => 'abc', 'subdomain' => 'admin']), $secure);
    }

    public function testUnknownRouteIsReportedAsMissingInsteadOfGeneratingAUrl(): void
    {
        $compiled = new CompiledUrlGenerator($this->dumpAndRead($this->siteRoutes()), new RequestContext());

        $this->assertNull($compiled->getRouteProperties('@unknown'));

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessageMatches('/@unknown/');

        $compiled->generate('@unknown');
    }

    /**
     * The link reference type answers with the route and its parameters instead
     * of a URL - the form the admin stores as a node link, and one Symfony's own
     * compiled generator has no notion of.
     */
    public function testLinkReferenceTypeReturnsTheRouteWithItsParameters(): void
    {
        $routes = $this->siteRoutes();
        $context = new RequestContext();

        $compiled = new CompiledUrlGenerator($this->dumpAndRead($routes), $context);
        $collectionBacked = new UrlGenerator($routes, $context);

        $this->assertSame('@blog/id?id=5', $compiled->generate('@blog/id', ['id' => 5], LinkReferenceType::LINK_URL));
        $this->assertSame(
            $collectionBacked->generate('@blog/id', ['id' => 5], LinkReferenceType::LINK_URL),
            $compiled->generate('@blog/id', ['id' => 5], LinkReferenceType::LINK_URL)
        );
    }

    /**
     * A page registered under the link it stands for ("@blog/id?id=5") generates
     * its own path, so generating the post's URL yields the page. The lookup
     * behind that reads a second route out of the dumped data, which is why the
     * dump has to key every route by its full name.
     */
    public function testPageRegisteredUnderALinkGeneratesItsOwnPath(): void
    {
        $routes = $this->siteRoutes();
        $routes->add('@blog/id?id=5', new Route('/my-post', ['_controller' => 'BlogController::postAction', 'id' => 5, '_node' => 7]));

        $context = new RequestContext();

        $compiled = new CompiledUrlGenerator($this->dumpAndRead($routes), $context);
        $collectionBacked = new UrlGenerator($routes, $context);

        $this->assertSame('/my-post', $compiled->generate('@blog/id', ['id' => 5]));
        $this->assertSame($collectionBacked->generate('@blog/id', ['id' => 5]), $compiled->generate('@blog/id', ['id' => 5]));

        // Any other post keeps the dynamic route.
        $this->assertSame('/blog/6', $compiled->generate('@blog/id', ['id' => 6]));
    }

    /**
     * Routes covering the properties a dump has to carry: path variables with a
     * requirement, defaults that are not variables, and a route bound to a host
     * and a scheme.
     */
    private function siteRoutes(): RouteCollection
    {
        $routes = new RouteCollection();

        $routes->add('@blog', new Route('/blog', ['_controller' => 'BlogController::indexAction']));
        $routes->add('@blog/id', new Route(
            '/blog/{id}',
            ['_controller' => 'BlogController::postAction', '_resolver' => 'Pagekit\Blog\UrlResolver'],
            ['id' => '\d+']
        ));
        $routes->add('@secure', new Route(
            '/secure/{token}',
            ['_controller' => 'SecureController::indexAction'],
            [],
            [],
            '{subdomain}.example.com',
            ['https']
        ));

        return $routes;
    }

    /**
     * Dumps the routes and reads the file back the way the router does.
     *
     * @return array<string, array<int, mixed>>
     */
    private function dumpAndRead(RouteCollection $routes): array
    {
        $file = tempnam(sys_get_temp_dir(), 'pk-route-dump-');
        $this->assertIsString($file);

        try {
            file_put_contents($file, CompiledUrlGenerator::dump($routes));

            $data = require $file;

            $this->assertIsArray($data);

            /** @var array<string, array<int, mixed>> $data */
            return $data;
        } finally {
            unlink($file);
        }
    }
}
