<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Pagekit\Application\UrlProvider;
use Pagekit\Filesystem\Adapter\FileAdapter;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * A package advertises the URL its icon and other servable files are reachable
 * under. The factory reads a package from its sources, which a webserver never
 * exposes, so that URL has to address the copy the build published into the
 * webroot instead.
 *
 * The resolution chain behind it runs for real — locator overlay, filesystem
 * mounts, temporary package trees on disk. Only the router is stubbed: static
 * URLs are resolved from the filesystem and never routed.
 */
class PackageFactoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = strtr(sys_get_temp_dir(), '\\', '/').'/pk_package_factory_'.getmypid().'_'.uniqid();

        $this->writePackage('pagekit/blog');
        $this->writePackage('pagekit/unbuilt');

        // Only the blog package made it through the build's publication pass.
        mkdir($this->root.'/public/packages/pagekit/blog', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->root);
    }

    public function testPackageUrlAddressesThePublishedCopy(): void
    {
        $package = $this->factory($this->root)->get('pagekit/blog');

        self::assertNotNull($package);
        self::assertSame('/packages/pagekit/blog', $package->get('url'));
        self::assertSame(
            $this->root.'/packages/pagekit/blog',
            $package->get('path'),
            'The package is still read from its sources — only its URL points into the webroot',
        );
    }

    public function testPackageWithoutAPublishedCopyHasNoUrl(): void
    {
        $package = $this->factory($this->root)->get('pagekit/unbuilt');

        self::assertNotNull($package);
        self::assertSame('', $package->get('url'), 'Unpublished sources must not be advertised under a URL');
    }

    public function testTrailingSlashOnTheApplicationRootDoesNotChangeTheUrl(): void
    {
        $package = $this->factory($this->root.'/')->get('pagekit/blog');

        self::assertNotNull($package);
        self::assertSame('/packages/pagekit/blog', $package->get('url'));
    }

    public function testPackageLoadsWithoutAUrlProvider(): void
    {
        // Console commands build the factory without one.
        $packages = (new PackageFactory())->addPath($this->root.'/packages/*/*/composer.json');
        $package = $packages->get('pagekit/blog');

        self::assertNotNull($package);
        self::assertSame('blog', $package->get('module'));
        self::assertSame('', $package->get('url'));
    }

    /**
     * Builds the factory against the real resolution chain, rooted at $root.
     */
    private function factory(string $root): PackageFactory
    {
        $file = new Filesystem();
        $file->registerAdapter('file', new FileAdapter($this->root.'/public', 'http://localhost'));

        $url = new UrlProvider(
            $this->createMock(Router::class),
            $file,
            new Locator($this->root, $this->root.'/public'),
        );

        return (new PackageFactory($url, $root))->addPath($this->root.'/packages/*/*/composer.json');
    }

    private function writePackage(string $name): void
    {
        $dir = $this->root.'/packages/'.$name;

        mkdir($dir, 0755, true);
        file_put_contents($dir.'/composer.json', (string) json_encode([
            'name' => $name,
            'type' => 'pagekit-extension',
        ]));
    }

    private function recursiveRemove(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            unlink($path);

            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $item) {
            $this->recursiveRemove($path.'/'.$item);
        }

        rmdir($path);
    }
}
