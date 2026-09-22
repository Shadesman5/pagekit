<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use FilesystemIterator;
use Pagekit\Application;
use Pagekit\Package\PackageModule;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use SplFileInfo;

/**
 * The package module's place in the graph, and the names it must not use.
 */
final class PackageModuleBoundaryTest extends TestCase
{
    private const SYSTEM_NAMESPACE = 'Pagekit\\System\\';

    /**
     * @var list<string>
     */
    private const BOOT_FILES = [
        'app/system/app.php',
        'app/console/app.php',
        'app/installer/app.php',
    ];

    public function testThePackageManifestIsOnlyTheModuleSkeleton(): void
    {
        $manifest = $this->manifest('app/package/index.php');

        self::assertEqualsCanonicalizing(['name', 'main', 'require', 'resources'], array_keys($manifest));
        self::assertSame('package', $manifest['name']);
        self::assertIsString($manifest['main']);
        self::assertTrue(class_exists($manifest['main']));
        self::assertSame(PackageModule::class, $manifest['main']);
        self::assertSame(['package:' => ''], $manifest['resources']);
    }

    public function testThePackageModuleRequiresNeitherInstallerNorSystem(): void
    {
        $require = $this->manifest('app/package/index.php')['require'];
        self::assertIsArray($require);

        // Requirements are resolved by name, so the index of an entry is not part of the contract.
        self::assertEqualsCanonicalizing(
            ['application', 'migration', 'system/intl', 'system/view'],
            $require,
        );
    }

    public function testInstallerAndSystemRequireThePackageModule(): void
    {
        foreach (['app/installer/index.php', 'app/system/index.php'] as $file) {
            $require = $this->manifest($file)['require'];
            self::assertIsArray($require);

            self::assertSame(1, count(array_keys($require, 'package', true)), $file);
        }
    }

    public function testEachBootFileRegistersThePackageModuleOnce(): void
    {
        foreach (self::BOOT_FILES as $file) {
            $source = file_get_contents($this->root().'/'.$file);
            self::assertIsString($source, $file);

            $manifests = $this->registeredManifests($source);

            // Listed with the other manifests; which slot it occupies does not change discovery.
            self::assertSame(1, count(array_keys($manifests, 'app/package/index.php', true)), $file);
        }
    }

    public function testThePackageTreeDoesNotNameTheSystemNamespace(): void
    {
        $roots = [
            'app/package' => ['php', 'js', 'vue'],
            'app/installer/src' => ['php'],
        ];

        // Both halves of the walk have to open real files, or an empty result would only mean nothing was read.
        self::assertNotEmpty($this->under($this->hits($roots, 'namespace Pagekit\\Package'), 'app/package/'));
        self::assertNotEmpty($this->under($this->hits($roots, 'namespace Pagekit\\Installer'), 'app/installer/src/'));

        // The installer tree still imports the failure store; only the package tree is required to be clear of it.
        self::assertSame([], $this->under($this->hits($roots, self::SYSTEM_NAMESPACE), 'app/package/'));
    }

    public function testTheDetectorReportsASystemNamespaceLine(): void
    {
        $contents = "<?php\nuse Pagekit\\Installer\\TablePrefix;\nuse Pagekit\\System\\Extension\\ExtensionFailureStore;\n";

        self::assertSame(
            ['fixture.php:3:use Pagekit\\System\\Extension\\ExtensionFailureStore;'],
            $this->hitsIn('fixture.php', $contents, self::SYSTEM_NAMESPACE),
        );
    }

    public function testTheDetectorDoesNotTreatTheInstallerNamespaceAsSystem(): void
    {
        $contents = "<?php\n// Pagekit\\System stays above this module.\nuse Pagekit\\Installer\\TablePrefix;\n";

        self::assertSame([], $this->hitsIn('fixture.php', $contents, self::SYSTEM_NAMESPACE));
    }

    public function testMainReturnsWithoutRegisteringServices(): void
    {
        $app = new Application();
        self::assertFalse($app->has('path.system'));
        self::assertFalse($app->has('path.snapshots'));
        self::assertFalse($app->has('db'));

        $registered = $app->keys();
        $module = new PackageModule([
            'name' => 'package',
            'path' => $this->root().'/app/package',
            'config' => [],
        ]);

        self::assertNull($module->main($app));
        self::assertEqualsCanonicalizing($registered, $app->keys());
    }

    public function testComposerPhpstanAndPhpunitKnowThePackageDirectory(): void
    {
        $composer = json_decode((string) file_get_contents($this->root().'/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame('app/package/src', $composer['autoload']['psr-4']['Pagekit\\Package\\'] ?? null);

        self::assertContains('app/package', $this->neonPaths());
        self::assertContains('app/package', $this->coverageDirectories());

        // A phpunit.xml beside the dist file would hide this include list.
        self::assertFileDoesNotExist($this->root().'/phpunit.xml');
    }

    public function testTheApplicationBootstrapDoesNotMapTheOldPackageDirectory(): void
    {
        $bootstrap = file_get_contents($this->root().'/app/modules/application/src/Tests/bootstrap.php');
        self::assertIsString($bootstrap);
        self::assertStringNotContainsString('/app/modules/package/src', $bootstrap);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $relative): array
    {
        $path = $this->root().'/'.$relative;
        self::assertFileExists($path);

        // ModuleManager::register() binds $app before including a manifest.
        // The system events capture that variable while the file is included.
        $app = new Application();

        $manifest = require $path;
        self::assertIsArray($manifest);

        return $manifest;
    }

    /**
     * @return list<string>
     */
    private function registeredManifests(string $source): array
    {
        if (preg_match('/->register\(\s*\[(.*?)\]/s', $source, $matches) !== 1) {
            return [];
        }

        if (preg_match_all('/([\'"])([^\'"]*)\1/', $matches[1], $entries) < 1) {
            return [];
        }

        return $entries[2];
    }

    /**
     * @param array<string, list<string>> $roots
     * @return list<string>
     */
    private function hits(array $roots, string $needle): array
    {
        $matches = [];

        foreach ($roots as $root => $extensions) {
            $directory = $this->root().'/'.$root;
            self::assertDirectoryExists($directory);

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS,
                ),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }

                if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if ($contents === false) {
                    self::fail($file->getPathname().' is not readable');
                }

                $matches = array_merge($matches, $this->hitsIn($this->relative($file->getPathname()), $contents, $needle));
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function hitsIn(string $path, string $contents, string $needle): array
    {
        $matches = [];

        foreach ($this->matchingLines($contents, $needle) as $line) {
            $matches[] = $path.':'.$line;
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function matchingLines(string $contents, string $needle): array
    {
        $matches = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            if (str_contains($line, $needle)) {
                $matches[] = ($index + 1).':'.$line;
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $hits
     * @return list<string>
     */
    private function under(array $hits, string $prefix): array
    {
        return array_values(array_filter(
            $hits,
            static fn (string $hit): bool => str_starts_with($hit, $prefix),
        ));
    }

    /**
     * @return list<string>
     */
    private function neonPaths(): array
    {
        $lines = file($this->root().'/phpstan.neon', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $paths = [];
        $inPaths = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*#/', $line) === 1 || trim($line) === '') {
                continue;
            }

            if (!$inPaths) {
                if (preg_match('/^    paths:\s*$/', $line) === 1) {
                    $inPaths = true;
                }

                continue;
            }

            if (preg_match('/^        - (\S+)$/', $line, $matches) === 1) {
                $paths[] = $matches[1];

                continue;
            }

            break;
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function coverageDirectories(): array
    {
        $xml = simplexml_load_file($this->root().'/phpunit.xml.dist');
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $directories = [];

        foreach ($xml->source->include->directory as $directory) {
            $directories[] = trim((string) $directory);
        }

        return $directories;
    }

    private function relative(string $path): string
    {
        $path = strtr($path, '\\', '/');
        $prefix = $this->root().'/';
        self::assertStringStartsWith($prefix, $path);

        return substr($path, strlen($prefix));
    }

    private function root(): string
    {
        return strtr(dirname(__DIR__, 3), '\\', '/');
    }
}
