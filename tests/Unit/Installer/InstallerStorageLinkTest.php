<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Installer;

use Pagekit\Application;
use Pagekit\Installer\Installer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An installation unpacked from an archive starts without the link that puts the
 * media library inside the webroot, so the installation creates one. Where that
 * is impossible the media loses its URLs while the site itself works, which is
 * reported rather than failed over.
 */
final class InstallerStorageLinkTest extends TestCase
{
    private string $workspace;
    private string $root;
    private string $public;
    private string $storage;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_installer_storage_'.getmypid().'_'.uniqid();
        $this->root = $this->workspace.'/site';
        $this->public = $this->root.'/public';
        $this->storage = $this->root.'/storage';

        mkdir($this->public, 0755, true);
        mkdir($this->storage, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testInstallationLinksTheMediaLibraryIntoTheWebroot(): void
    {
        if (!self::canCreateSymlink()) {
            $this->markTestSkipped('symlink() is unavailable on this host');
        }

        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->never())->method('warning');

        $installer = new class ($this->application($this->storage, $log)) extends Installer {
            public function linkStorage(): void
            {
                parent::linkStorage();
            }
        };

        $installer->linkStorage();

        self::assertTrue(is_link($this->public.'/storage'));
    }

    public function testInstallationReportsAMediaLibraryItCannotLinkInsteadOfFailing(): void
    {
        // A media library configured outside the application has no place in the
        // webroot from which to answer the URLs derived from its location.
        $outside = $this->workspace.'/media';

        mkdir($outside, 0755, true);

        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->once())->method('warning')->with($this->stringContains($outside));

        $installer = new class ($this->application($outside, $log)) extends Installer {
            public function linkStorage(): void
            {
                parent::linkStorage();
            }
        };

        $installer->linkStorage();

        self::assertSame([], glob($this->public.'/*'), 'Nothing is created in the webroot');
    }

    private function application(string $storage, LoggerInterface $log): Application
    {
        return new Application([
            'path' => $this->root,
            'path.public' => $this->public,
            'path.storage' => $storage,
            'log' => $log,
        ]);
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
     * Deletes a tree, unlinking links rather than following them.
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
