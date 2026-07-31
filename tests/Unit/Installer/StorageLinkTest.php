<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Installer;

use Pagekit\Installer\StorageLink;
use PHPUnit\Framework\TestCase;

/**
 * Uploads are written to a directory outside the document root and reach the
 * browser through a link in it. A checkout gets that link from the build, an
 * installation unpacked from an archive has to create it, and a host may refuse
 * to hold one at all - in which case the media loses its URLs and nothing else.
 *
 * A host that disables symlink() outright cannot be simulated in-process, so the
 * refusal is provoked through a webroot the link cannot be placed in instead.
 */
final class StorageLinkTest extends TestCase
{
    private const PHOTO = 'a photo';

    private string $workspace;
    private string $root;
    private string $public;
    private string $storage;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_storage_link_'.getmypid().'_'.uniqid();
        $this->root = $this->workspace.'/site';
        $this->public = $this->root.'/public';
        $this->storage = $this->root.'/storage';

        mkdir($this->public, 0755, true);
        mkdir($this->storage, 0755, true);
        file_put_contents($this->storage.'/photo.jpg', self::PHOTO);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testEnsurePutsTheMediaLibraryIntoTheWebroot(): void
    {
        $this->requireSymlink();

        $link = new StorageLink($this->root, $this->public, $this->storage);

        self::assertSame($this->public.'/storage', $link->getPath());
        self::assertFalse($link->exists());

        self::assertTrue($link->ensure());
        self::assertTrue($link->exists());
        self::assertTrue(is_link($this->public.'/storage'));
        self::assertSame(self::PHOTO, file_get_contents($this->public.'/storage/photo.jpg'));
    }

    public function testTheLinkSurvivesTheInstallationBeingMoved(): void
    {
        $this->requireSymlink();

        self::assertTrue((new StorageLink($this->root, $this->public, $this->storage))->ensure());
        self::assertSame('../storage', readlink($this->public.'/storage'));

        $moved = $this->workspace.'/moved';

        self::assertTrue(rename($this->root, $moved));
        self::assertSame(self::PHOTO, file_get_contents($moved.'/public/storage/photo.jpg'));
    }

    public function testEnsureLeavesALinkItAlreadyFindsInPlace(): void
    {
        $this->requireSymlink();

        $link = new StorageLink($this->root, $this->public, $this->storage);

        self::assertTrue($link->ensure());
        self::assertTrue($link->ensure(), 'Installing over an existing installation must not fail on its link');
        self::assertSame('../storage', readlink($this->public.'/storage'));
    }

    public function testADirectoryPutInPlaceOfTheLinkIsKept(): void
    {
        // A copy someone placed there by hand occupies the name just as a link does.
        mkdir($this->public.'/storage', 0755, true);
        file_put_contents($this->public.'/storage/photo.jpg', self::PHOTO);

        $link = new StorageLink($this->root, $this->public, $this->storage);

        self::assertTrue($link->ensure());
        self::assertFalse(is_link($this->public.'/storage'));
        self::assertSame(self::PHOTO, file_get_contents($this->public.'/storage/photo.jpg'));
    }

    public function testALinkPointingNowhereIsLeftAsItIs(): void
    {
        $this->requireSymlink();

        symlink('../gone', $this->public.'/storage');

        $link = new StorageLink($this->root, $this->public, $this->storage);

        self::assertTrue($link->exists());
        self::assertTrue($link->ensure());
        self::assertSame('../gone', readlink($this->public.'/storage'), 'An existing link is never repointed');
    }

    public function testAMediaLibraryNestedInTheApplicationIsLinkedFromTheSamePlaceInTheWebroot(): void
    {
        $this->requireSymlink();

        // Media URLs are derived from where the library sits relative to the
        // application, so the link has to answer under that same path.
        $nested = $this->root.'/var/media';

        mkdir($nested, 0755, true);
        file_put_contents($nested.'/photo.jpg', self::PHOTO);

        $link = new StorageLink($this->root, $this->public, $nested);

        self::assertSame($this->public.'/var/media', $link->getPath());
        self::assertTrue($link->ensure());
        self::assertSame('../../var/media', readlink($this->public.'/var/media'));
        self::assertSame(self::PHOTO, file_get_contents($this->public.'/var/media/photo.jpg'));
    }

    public function testAMediaLibraryOutsideTheApplicationIsNotLinked(): void
    {
        $outside = $this->workspace.'/media';

        mkdir($outside, 0755, true);

        $link = new StorageLink($this->root, $this->public, $outside);

        self::assertNull($link->getPath());
        self::assertFalse($link->exists());
        self::assertFalse($link->ensure());
        self::assertStringContainsString($outside, $link->getProblem());
        self::assertStringContainsString('webserver alias', $link->getProblem());
    }

    public function testADirectoryMerelyStartingWithTheApplicationsNameLiesOutsideIt(): void
    {
        $sibling = $this->workspace.'/site-backup/storage';

        mkdir($sibling, 0755, true);

        $link = new StorageLink($this->root, $this->public, $sibling);

        self::assertNull($link->getPath());
        self::assertFalse($link->ensure());
    }

    public function testTheApplicationRootItselfIsNotLinked(): void
    {
        $link = new StorageLink($this->root, $this->public, $this->root);

        self::assertNull($link->getPath());
        self::assertFalse($link->ensure());
        self::assertSame([], glob($this->public.'/*'), 'Nothing is created in the webroot');
    }

    public function testTrailingSlashesOnTheConfiguredPathsChangeNothing(): void
    {
        $this->requireSymlink();

        $link = new StorageLink($this->root.'/', $this->public.'/', $this->storage.'/');

        self::assertSame($this->public.'/storage', $link->getPath());
        self::assertTrue($link->ensure());
        self::assertSame('../storage', readlink($this->public.'/storage'));
    }

    public function testAWebrootThatCannotHoldTheLinkYieldsTheCommandToRunByHand(): void
    {
        rmdir($this->public);
        file_put_contents($this->public, '');

        $link = new StorageLink($this->root, $this->public, $this->storage);

        self::assertFalse($link->ensure());
        self::assertStringContainsString(
            'ln -s ../storage '.$this->public.'/storage',
            $link->getProblem(),
        );
    }

    /**
     * Skips when the host cannot create directory symlinks (common on Windows
     * without Developer Mode / elevated privileges).
     */
    private function requireSymlink(): void
    {
        if (!self::canCreateSymlink()) {
            $this->markTestSkipped('symlink() is unavailable on this host');
        }
    }

    private static function canCreateSymlink(): bool
    {
        static $available;

        if ($available !== null) {
            return $available;
        }

        if (!function_exists('symlink')) {
            return $available = false;
        }

        $dir = strtr(sys_get_temp_dir(), '\\', '/').'/pk_symlink_probe_'.getmypid().'_'.uniqid();
        $target = $dir.'/target';
        $link = $dir.'/link';

        mkdir($target, 0755, true);

        $available = @symlink($target, $link) && is_link($link);

        if (is_link($link) || is_file($link)) {
            @unlink($link);
        }
        @rmdir($target);
        @rmdir($dir);

        return $available;
    }

    /**
     * Deletes a tree, unlinking links rather than following them - the tree under
     * test holds links into itself, and one of them points nowhere.
     */
    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}
