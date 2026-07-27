<?php

declare(strict_types=1);

namespace Pagekit\Filesystem\Tests\Adapter;

use Pagekit\Filesystem\Adapter\FileAdapter;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Tests\FileUtil;
use Pagekit\Routing\Generator\UrlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The adapter maps the directories a webserver exposes to the URLs they answer
 * to. Two directories are exposed: the webroot, which holds everything the
 * build publishes, and the media library, which lives beside it and is reached
 * through a link into the webroot. Everything else - module sources, config,
 * temp - is unreachable and gets no URL at all.
 */
class FileAdapterTest extends TestCase
{
    use FileUtil;

    private const BASE_URL = 'http://localhost/pagekit';

    private Filesystem $file;
    private string $workspace;
    private string $public;
    private string $storage;

    public function setUp(): void
    {
        $this->workspace = strtr($this->getTempDir('filesystem_'), '\\', '/');
        $this->public = $this->workspace.'/public';
        $this->storage = $this->workspace.'/storage';

        $this->writeFile($this->public.'/app/assets/uikit.js');
        $this->writeFile($this->public.'/app/assets/my asset.js');
        $this->writeFile($this->storage.'/photo.jpg');
        $this->writeFile($this->workspace.'/publicfoo/leak.txt');
        $this->writeFile($this->workspace.'/app/system/config.php');

        $this->file = new Filesystem();
        $this->file->registerAdapter('file', $this->adapter($this->public, $this->storage));
    }

    public function tearDown(): void
    {
        $this->removeDir($this->workspace);
    }

    public function testPublishedFileIsServedFromTheWebroot(): void
    {
        $this->assertSame(
            self::BASE_URL.'/app/assets/uikit.js',
            $this->file->getUrl($this->public.'/app/assets/uikit.js', UrlGenerator::ABSOLUTE_URL),
        );
    }

    public function testWebrootItselfIsTheBaseUrl(): void
    {
        $this->assertSame(self::BASE_URL, $this->file->getUrl($this->public, UrlGenerator::ABSOLUTE_URL));
    }

    public function testMediaLibraryIsServedFromItsOwnMount(): void
    {
        $this->assertSame(
            self::BASE_URL.'/storage/photo.jpg',
            $this->file->getUrl($this->storage.'/photo.jpg', UrlGenerator::ABSOLUTE_URL),
        );
    }

    public function testRelativePathResolvesInsideTheWebroot(): void
    {
        $this->assertSame($this->public.'/app/assets/uikit.js', $this->file->getPath('app/assets/uikit.js', true));
        $this->assertSame(
            self::BASE_URL.'/app/assets/uikit.js',
            $this->file->getUrl('app/assets/uikit.js', UrlGenerator::ABSOLUTE_URL),
        );
    }

    public function testUrlEncodesFileNamesButKeepsSeparators(): void
    {
        $this->assertSame(
            self::BASE_URL.'/app/assets/my%20asset.js',
            $this->file->getUrl($this->public.'/app/assets/my asset.js', UrlGenerator::ABSOLUTE_URL),
        );
    }

    public function testFileOutsideEveryServedDirectoryHasNoUrl(): void
    {
        $source = $this->workspace.'/app/system/config.php';

        $this->assertTrue($this->file->exists($source));
        $this->assertFalse($this->file->getUrl($source));
    }

    public function testDirectorySharingThePrefixOfTheWebrootHasNoUrl(): void
    {
        $sibling = $this->workspace.'/publicfoo/leak.txt';

        $this->assertTrue($this->file->exists($sibling));
        $this->assertFalse($this->file->getUrl($sibling));
    }

    public function testFileMissingFromTheWebrootHasNoUrl(): void
    {
        $this->assertFalse($this->file->getUrl($this->public.'/app/bundle/never-built.js'));
    }

    public function testTrailingSlashesOnServedDirectoriesDoNotChangeUrls(): void
    {
        $file = new Filesystem();
        $file->registerAdapter('file', $this->adapter($this->public.'/', $this->storage.'/'));

        $this->assertSame(
            self::BASE_URL.'/app/assets/uikit.js',
            $file->getUrl($this->public.'/app/assets/uikit.js', UrlGenerator::ABSOLUTE_URL),
        );
        $this->assertSame(
            self::BASE_URL.'/storage/photo.jpg',
            $file->getUrl($this->storage.'/photo.jpg', UrlGenerator::ABSOLUTE_URL),
        );
    }

    public function testWebrootWinsOverAMediaLibraryConfiguredInsideIt(): void
    {
        // Served directories are matched in order, so a media library that was
        // pointed into the webroot is addressed as part of the webroot.
        $file = new Filesystem();
        $file->registerAdapter('file', $this->adapter($this->public, $this->public.'/media'));

        $this->writeFile($this->public.'/media/photo.jpg');

        $this->assertSame(
            self::BASE_URL.'/media/photo.jpg',
            $file->getUrl($this->public.'/media/photo.jpg', UrlGenerator::ABSOLUTE_URL),
        );
    }

    /**
     * Builds the adapter the way the request listener does: the webroot first,
     * the media library mounted under the URL it holds relative to the app root.
     */
    private function adapter(string $public, string $storage): FileAdapter
    {
        return new FileAdapter($public, self::BASE_URL, [
            $storage => self::BASE_URL.'/'.trim(substr($storage, strlen($this->workspace)), '/'),
        ]);
    }
}
