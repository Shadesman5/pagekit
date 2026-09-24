<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use FilesystemIterator;
use Pagekit\Application;
use Pagekit\Console\Commands\ExtensionTranslateCommand;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * The system catalogue keeps the strings of the package pages, in whichever tree those pages live.
 */
final class ExtensionTranslateCommandTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private const PAGE_HEADINGS = [
        'extensions' => "{{ 'Extensions' | trans }}",
        'themes' => "{{ 'Themes' | trans }}",
        'snapshots' => "{{ 'No snapshot has been taken.' | trans }}",
    ];

    public function testTheSystemCatalogueWalksTheTreeThatHoldsThePackagePages(): void
    {
        $pages = [];

        foreach (array_keys(self::PAGE_HEADINGS) as $bundle) {
            $found = $this->pagesRegistering($bundle);
            self::assertCount(1, $found, $bundle);
            $pages[$bundle] = $found[0];
        }

        $module = dirname($pages['extensions'], 2);

        foreach ($pages as $bundle => $page) {
            // The three pages move together, so they share the tree the catalogue has to walk.
            self::assertSame($module, dirname($page, 2), $bundle);
        }

        $system = $this->catalogue('system');
        $extension = $this->catalogue('pagekit/blog');
        $blog = $this->real($this->root().'/packages/pagekit/blog');

        // The system walk still starts at the system tree and still includes the wizard.
        self::assertContains($this->real($this->root().'/app/system/index.php'), $system);
        self::assertContains($this->real($this->root().'/app/installer/views/update.php'), $system);
        self::assertContains($this->real($blog.'/index.php'), $extension);

        foreach ($extension as $file) {
            self::assertStringStartsWith($blog.'/', $file);
        }

        foreach ($pages as $bundle => $page) {
            $source = file_get_contents($page);
            self::assertIsString($source);
            self::assertStringContainsString(self::PAGE_HEADINGS[$bundle], $source);

            // A string is extracted only from a file this walk returns.
            self::assertContains($page, $system, $bundle);
            self::assertNotContains($page, $extension, $bundle);

            $script = $this->real($module.'/app/views/'.$bundle.'.js');
            self::assertContains($script, $system, $bundle);
            self::assertNotContains($script, $extension, $bundle);
        }

        foreach ([
            $module.'/app/lib/package.js',
            $module.'/app/lib/uninstall.vue',
            $module.'/app/views/snapshots.js',
        ] as $file) {
            $path = $this->real($file);
            self::assertStringContainsString('$trans(', (string) file_get_contents($path));
            self::assertContains($path, $system, $file);
            self::assertNotContains($path, $extension, $file);
        }
    }

    /**
     * @return list<string>
     */
    private function catalogue(string $extension): array
    {
        $command = new ExtensionTranslateCommand($this->application());
        $path = (new \ReflectionMethod(ExtensionTranslateCommand::class, 'getPath'))->invoke($command, $extension);
        self::assertIsString($path);

        $expected = $extension === 'system'
            ? $this->root().'/app/system'
            : $this->root().'/packages/'.$extension;

        self::assertSame($this->real($expected), $this->real($path));

        $finder = (new \ReflectionMethod(ExtensionTranslateCommand::class, 'getFiles'))->invoke($command, $path, $extension);
        self::assertInstanceOf(Finder::class, $finder);

        $files = [];

        foreach ($finder as $file) {
            $files[] = $this->real($file->getPathname());
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function pagesRegistering(string $bundle): array
    {
        $needle = 'package:app/bundle/'.$bundle.'.js';
        $found = [];
        $directory = $this->root().'/app';

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS,
                ),
                static function (SplFileInfo $file): bool {
                    return !$file->isDir()
                        || ($file->getFilename() !== 'vendor' && $file->getFilename() !== 'node_modules');
                },
            ),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents !== false && str_contains($contents, $needle)) {
                $found[] = $this->real($file->getPathname());
            }
        }

        return $found;
    }

    private function application(): Application
    {
        $app = new Application();
        $app->set('path', $this->root());

        return $app;
    }

    private function real(string $path): string
    {
        $real = realpath($path);
        self::assertNotFalse($real, $path);

        return strtr($real, '\\', '/');
    }

    private function root(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/');
    }
}
