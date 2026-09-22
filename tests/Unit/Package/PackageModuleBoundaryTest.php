<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use FilesystemIterator;
use Monolog\Handler\TestHandler;
use Pagekit\Application;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use Pagekit\Log\Logger;
use Pagekit\Module\Module;
use Pagekit\Package\Extension\ExtensionFailureStore;
use Pagekit\Package\PackageModule;
use Pagekit\System\SystemModule;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
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
     * Retired registry and lifecycle names. PackageManager and TablePrefix still
     * live under Installer, so the pattern stops at those three classes and the
     * lifecycle segment.
     */
    private const RETIRED_REGISTRY = '/Installer\\\\Package\\\\(?:Package\\b|PackageInterface|PackageFactory|Lifecycle)/';

    /**
     * @var list<string>
     */
    private const SOURCE_EXTENSIONS = [
        'php', 'inc', 'js', 'mjs', 'vue', 'json', 'neon', 'xml', 'yml', 'yaml',
        'md', 'less', 'css', 'html', 'twig', 'svg', 'txt', 'dist',
    ];

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

    public function testThePackageAndInstallerTreesDoNotNameTheSystemNamespace(): void
    {
        $roots = [
            'app/package' => ['php', 'js', 'vue'],
            'app/installer/src' => ['php'],
        ];

        // Both halves of the walk have to open real files, or an empty result would only mean nothing was read.
        self::assertNotEmpty($this->under($this->hits($roots, 'namespace Pagekit\\Package'), 'app/package/'));
        self::assertNotEmpty($this->under($this->hits($roots, 'namespace Pagekit\\Installer'), 'app/installer/src/'));

        self::assertSame([], $this->hits($roots, self::SYSTEM_NAMESPACE));
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

    public function testTheRetiredRegistryAndLifecycleNamesAreGone(): void
    {
        $roots = [
            'app' => self::SOURCE_EXTENSIONS,
            'packages' => self::SOURCE_EXTENSIONS,
            'tests' => self::SOURCE_EXTENSIONS,
        ];

        // Each root has to contribute a real file, or an empty result would only mean nothing was read.
        self::assertNotEmpty($this->under($this->patternHits($roots, '/namespace Pagekit\\\\Package;/'), 'app/package/'));
        self::assertNotEmpty($this->under($this->patternHits($roots, '/namespace Pagekit\\\\Blog;/'), 'packages/'));
        self::assertNotEmpty($this->under($this->patternHits($roots, '/namespace Pagekit\\\\Tests\\\\Unit\\\\Package;/'), 'tests/'));

        self::assertSame([], $this->patternHits($roots, self::RETIRED_REGISTRY));
    }

    public function testTheDetectorReportsARetiredRegistryName(): void
    {
        // Double-quoted on purpose: a nowdoc would store these names in this file and the tree scan would report them.
        $contents = "<?php\n"
            ."use Pagekit\\Installer\\Package\\PackageManager;\n"
            ."use Pagekit\\Installer\\Package\\Package;\n"
            ."use Pagekit\\Installer\\Package\\PackageInterface;\n"
            ."use Pagekit\\Installer\\Package\\PackageFactory;\n"
            ."use Pagekit\\Installer\\Package\\Lifecycle\\PackageLifecycle;\n"
            ."use Pagekit\\Package\\Package;\n"
            ."use Pagekit\\Installer\\TablePrefix;\n";

        self::assertSame(
            [
                'fixture.php:3:use Pagekit\\Installer\\Package\\Package;',
                'fixture.php:4:use Pagekit\\Installer\\Package\\PackageInterface;',
                'fixture.php:5:use Pagekit\\Installer\\Package\\PackageFactory;',
                'fixture.php:6:use Pagekit\\Installer\\Package\\Lifecycle\\PackageLifecycle;',
            ],
            $this->patternHitsIn('fixture.php', $contents, self::RETIRED_REGISTRY),
        );
    }

    public function testTheRegistryAndLifecycleFilesLeftTheInstallerTree(): void
    {
        foreach ([
            'app/package/src/Package.php',
            'app/package/src/PackageInterface.php',
            'app/package/src/PackageFactory.php',
            'app/package/src/Lifecycle/LifecycleRunner.php',
            'app/package/src/Lifecycle/MigrationSet.php',
            'app/package/src/Lifecycle/PackageLifecycle.php',
            'app/package/src/Lifecycle/PackageLifecycleInterface.php',
        ] as $file) {
            self::assertFileExists($this->root().'/'.$file);
        }

        foreach ([
            'app/installer/src/Package/Package.php',
            'app/installer/src/Package/PackageInterface.php',
            'app/installer/src/Package/PackageFactory.php',
            'app/installer/src/Package/Lifecycle',
        ] as $file) {
            self::assertFileDoesNotExist($this->root().'/'.$file);
        }
    }

    public function testTheFailureStoreLeftTheSystemTree(): void
    {
        self::assertFileExists($this->root().'/app/package/src/Extension/ExtensionFailureStore.php');
        self::assertFileDoesNotExist($this->root().'/app/system/src/Extension/ExtensionFailureStore.php');
    }

    public function testTheManagerImportsOnlyTheRegistryInterfaceAndTheFailureStore(): void
    {
        $source = file_get_contents($this->root().'/app/installer/src/Package/PackageManager.php');
        self::assertIsString($source);

        $packageImports = array_values(array_filter(
            $this->importedClasses($source),
            static fn (string $class): bool => str_starts_with($class, 'Pagekit\\Package\\'),
        ));

        // The factory is the container id `package`, parameters are typed on the interface,
        // and the store is the type the optional failure record is narrowed to.
        self::assertEqualsCanonicalizing(
            [
                'Pagekit\\Package\\Extension\\ExtensionFailureStore',
                'Pagekit\\Package\\Lifecycle\\LifecycleRunner',
                'Pagekit\\Package\\Lifecycle\\MigrationSet',
                'Pagekit\\Package\\PackageInterface',
            ],
            $packageImports,
        );
    }

    public function testTheMarketplaceControllerImportsTheMovedFactory(): void
    {
        $source = file_get_contents($this->root().'/app/installer/src/Controller/MarketplaceController.php');
        self::assertIsString($source);

        // The action type-hints the factory, so the import has to name the class that moved.
        self::assertContains('Pagekit\\Package\\PackageFactory', $this->importedClasses($source));
    }

    public function testTheTranslationStubLivesInTheManagersNamespace(): void
    {
        $manager = file_get_contents($this->root().'/app/installer/src/Package/PackageManager.php');
        $bootstrap = file_get_contents($this->root().'/tests/Unit/Package/bootstrap.php');
        self::assertIsString($manager);
        self::assertIsString($bootstrap);

        self::assertSame(1, preg_match('/^namespace (Pagekit\\\\Installer\\\\Package);/m', $manager, $namespace));

        // Unqualified __() resolves in the manager's namespace before the global helper.
        self::assertStringContainsString('namespace '.$namespace[1].';', $bootstrap);
        self::assertStringContainsString("function_exists('".$namespace[1]."\\__')", $bootstrap);
        self::assertDoesNotMatchRegularExpression('/^namespace Pagekit\\\\Package;/m', $bootstrap);
    }

    public function testThePackageFactoryBaselineEntryKeepsItsFindingInPathOrder(): void
    {
        $entries = $this->baselineEntries();
        $factory = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => ($entry['path'] ?? '') === 'app/package/src/PackageFactory.php',
        ));

        self::assertCount(1, $factory);
        self::assertSame('#^Possibly invalid array key type string\|null\.$#', $factory[0]['message']);
        self::assertSame('offsetAccess.invalidOffset', $factory[0]['identifier']);
        self::assertSame('1', $factory[0]['count']);

        $paths = array_column($entries, 'path');
        self::assertNotContains('app/installer/src/Package/PackageFactory.php', $paths);

        $index = array_search('app/package/src/PackageFactory.php', $paths, true);
        self::assertIsInt($index);
        self::assertGreaterThan(0, $index);
        self::assertLessThan(count($paths) - 1, $index);

        // The file is path-sorted, so the entry's neighbors have to sort around it.
        self::assertLessThan(0, strcmp($paths[$index - 1], $paths[$index]));
        self::assertLessThan(0, strcmp($paths[$index], $paths[$index + 1]));
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

    public function testTheFailureRecordIsRegisteredExactlyWhereADirectoryIsNamed(): void
    {
        $without = $this->applicationWithAFilesystem();

        self::assertNull($this->packageModule()->main($without));
        self::assertFalse($without->has('extension.failures'));

        $root = $this->temporaryDirectory();

        try {
            $directory = $root.'/system';
            $with = $this->applicationWithAFilesystem();
            $with->set('path.system', $directory);

            self::assertNull($this->packageModule()->main($with));
            self::assertTrue($with->has('extension.failures'));

            $store = $with->get('extension.failures');
            self::assertInstanceOf(ExtensionFailureStore::class, $store);
            self::assertTrue($store->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('boom')));

            // The next boot opens a file in the directory the container named.
            self::assertFileExists($directory.'/extension-failures.json');
            self::assertSame(
                ExtensionFailureStore::TYPE_EXTENSION,
                (new ExtensionFailureStore($directory, new Filesystem()))->all()['blog']['type'],
            );
        } finally {
            $this->removeTree($root);
        }
    }

    public function testTheSystemModuleDoesNotRegisterTheFailureRecord(): void
    {
        $root = $this->temporaryDirectory();

        try {
            $log = new TestHandler();
            $logger = new Logger('log');
            $logger->pushHandler($log);

            $app = new Application();
            $app->set('log', $logger);
            $app->set('locator', new Locator($this->root()));
            $app->set('assets', fn () => new \stdClass());
            $app->set('file', fn () => new Filesystem());
            // Named, so a registration that followed the system module would have a directory.
            $app->set('path.system', $root.'/system');

            $system = new SystemModule([
                'name' => 'system',
                'path' => '',
                'config' => [
                    'extensions' => [],
                ],
            ]);

            self::assertNull($system->main($app));
            self::assertFalse($app->has('extension.failures'));
            self::assertInstanceOf(Module::class, $app->get('theme'));
            self::assertSame([], $log->getRecords());
            self::assertDirectoryDoesNotExist($root.'/system');
        } finally {
            $this->removeTree($root);
        }
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
        return $this->scan($roots, fn (string $contents): array => $this->matchingLines($contents, $needle));
    }

    /**
     * @param array<string, list<string>> $roots
     * @return list<string>
     */
    private function patternHits(array $roots, string $pattern): array
    {
        return $this->scan($roots, fn (string $contents): array => $this->matchingPatternLines($contents, $pattern));
    }

    /**
     * @param array<string, list<string>> $roots
     * @param callable(string): list<string> $lines
     * @return list<string>
     */
    private function scan(array $roots, callable $lines): array
    {
        $matches = [];

        foreach ($roots as $root => $extensions) {
            foreach ($this->filesUnder($root, $extensions) as $file) {
                $contents = file_get_contents($file->getPathname());

                if ($contents === false) {
                    self::fail($file->getPathname().' is not readable');
                }

                foreach ($lines($contents) as $line) {
                    $matches[] = $this->relative($file->getPathname()).':'.$line;
                }
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $extensions
     * @return \Generator<int, SplFileInfo>
     */
    private function filesUnder(string $root, array $extensions): \Generator
    {
        $directory = $this->root().'/'.$root;
        self::assertDirectoryExists($directory);

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
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }

            $relative = $this->relative($file->getPathname());

            if (preg_match('#(^|/)vendor/#', $relative) === 1 || preg_match('#(^|/)node_modules/#', $relative) === 1) {
                continue;
            }

            yield $file;
        }
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
    private function patternHitsIn(string $path, string $contents, string $pattern): array
    {
        $matches = [];

        foreach ($this->matchingPatternLines($contents, $pattern) as $line) {
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
     * @return list<string>
     */
    private function matchingPatternLines(string $contents, string $pattern): array
    {
        $matches = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            $result = preg_match($pattern, $line);

            if ($result === false) {
                self::fail('Registry pattern did not compile.');
            }

            if ($result === 1) {
                $matches[] = ($index + 1).':'.$line;
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function importedClasses(string $source): array
    {
        if (preg_match_all('/^\s*use\s+([^;]+);/m', $source, $matches) < 1) {
            return [];
        }

        $classes = [];

        foreach ($matches[1] as $clause) {
            $clause = trim($clause);

            if (str_contains($clause, '{') || str_starts_with($clause, 'function ') || str_starts_with($clause, 'const ')) {
                continue;
            }

            $classes[] = trim((string) preg_replace('/\s+as\s+\S+$/', '', $clause));
        }

        return $classes;
    }

    /**
     * @return list<array{message: string, identifier: string, count: string, path: string}>
     */
    private function baselineEntries(): array
    {
        $lines = file($this->root().'/phpstan-baseline.neon', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $entries = [];
        $current = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s+-\s*$/', $line) === 1) {
                if ($current !== []) {
                    $entries[] = $current;
                    $current = [];
                }

                continue;
            }

            if (preg_match('/^\s+(message|identifier|count|path):\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $current[$matches[1]] = trim($matches[2], " \t'\"");
        }

        if ($current !== []) {
            $entries[] = $current;
        }

        return $entries;
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

    private function packageModule(): PackageModule
    {
        return new PackageModule([
            'name' => 'package',
            'path' => '',
            'config' => [],
        ]);
    }

    private function applicationWithAFilesystem(): Application
    {
        $app = new Application();
        $app->set('file', fn () => new Filesystem());

        return $app;
    }

    private function temporaryDirectory(): string
    {
        $directory = strtr(sys_get_temp_dir(), '\\', '/').'/pk_package_boundary_'.getmypid().'_'.uniqid();
        self::assertTrue(mkdir($directory, 0755, true));

        return $directory;
    }

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
