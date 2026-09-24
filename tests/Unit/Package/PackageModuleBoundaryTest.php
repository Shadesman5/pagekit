<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use Monolog\Handler\TestHandler;
use Pagekit\Application;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Filesystem\Locator;
use Pagekit\Log\Logger;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use Pagekit\Package\Controller\PackageController;
use Pagekit\Package\Controller\SnapshotController;
use Pagekit\Package\Extension\ExtensionFailureStore;
use Pagekit\Package\PackageFactory;
use Pagekit\Package\PackageModule;
use Pagekit\Package\Snapshot\SnapshotStore;
use Pagekit\System\SystemModule;
use Pagekit\User\Attribute\Access;
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
     * The segment the package module was cut out of, closed by a semicolon or a
     * backslash. TablePrefix stays in the wizard, so the pattern stops here.
     */
    private const RETIRED_PACKAGE_NAMESPACE = '/Installer\\\\Package(\\\\|;)/';

    /**
     * The helper segment that moved with the manager, closed by a semicolon or a
     * backslash. The wizard's own classes stay past this segment.
     */
    private const RETIRED_HELPER_NAMESPACE = '/Installer\\\\Helper(\\\\|;)/';

    /**
     * Locator, alias and path the package tree must not use for the wizard.
     *
     * @var list<string>
     */
    private const INSTALLER_NAMES = [
        'installer:',
        '@installer',
        'app/installer',
    ];

    /**
     * A permission declared as a manifest key. A menu that only names it as access is a consumer.
     */
    private const MANAGE_PACKAGES_DECLARATION = '/[\'"]system: manage packages[\'"]\s*=>/';

    /**
     * @var list<string>
     */
    private const SOURCE_EXTENSIONS = [
        'php', 'inc', 'js', 'mjs', 'vue', 'json', 'neon', 'xml', 'yml', 'yaml',
        'md', 'less', 'css', 'html', 'twig', 'svg', 'txt', 'dist',
    ];

    /**
     * A namespace: Composer\ and an uppercase letter. An apostrophe, as in a sentence, is not one.
     */
    private const COMPOSER_NAMESPACE = '/Composer\\\\[A-Z]/';

    /**
     * The one Composer name the autoloader runtime still uses.
     */
    private const AUTOLOADER = 'Composer\\Autoload\\ClassLoader';

    /**
     * @var list<string>
     */
    private const DELETED_RUNTIME_NAMES = [
        'path.artifact',
        'packages/composer',
        'packages/autoload',
        'installed.json',
        'tmp/packages',
    ];

    /**
     * @var list<string>
     */
    private const BOOT_FILES = [
        'app/system/app.php',
        'app/console/app.php',
        'app/installer/app.php',
    ];

    /**
     * Returned in place of Composer's loader so the statement after register()
     * cannot construct AutoLoader. The modules are already stored by then.
     */
    private const STAND_IN_AUTOLOAD = <<<'PHP'
        <?php

        declare(strict_types=1);

        return new stdClass();

        PHP;

    public function testThePackageManifestDeclaresTheModuleAndItsSnapshotWindow(): void
    {
        $manifest = $this->manifest('app/package/index.php');

        self::assertEqualsCanonicalizing(
            ['name', 'main', 'require', 'routes', 'resources', 'permissions', 'menu', 'config'],
            array_keys($manifest),
        );
        self::assertSame('package', $manifest['name']);
        self::assertIsString($manifest['main']);
        self::assertTrue(class_exists($manifest['main']));
        self::assertSame(PackageModule::class, $manifest['main']);
        self::assertSame(['package:' => ''], $manifest['resources']);
        self::assertSame(
            ['snapshots' => ['retention_days' => SnapshotStore::DEFAULT_RETENTION_DAYS]],
            $manifest['config'],
        );
    }

    public function testTheInstallerKeepsTheWizardConfigAndNotTheSnapshotWindow(): void
    {
        $manifest = $this->manifest('app/installer/index.php');

        // The window belongs to the module that builds the snapshotter. The wizard
        // keeps only what gates its own screens.
        self::assertSame(
            ['enabled' => false, 'release_channel' => 'stable'],
            $manifest['config'],
        );
    }

    public function testThePackageManifestOwnsTheAdminSurface(): void
    {
        $package = $this->manifest('app/package/index.php');
        $installer = $this->manifest('app/installer/index.php');

        self::assertSame([
            '/system/package' => [
                'name' => '@system/package',
                'controller' => PackageController::class,
            ],
            '/system/snapshot' => [
                'name' => '@system/snapshot',
                'controller' => SnapshotController::class,
            ],
        ], $package['routes']);

        self::assertSame([
            'system: manage packages' => [
                'title' => 'Manage extensions and themes',
                'description' => 'Manage extensions and themes',
            ],
        ], $package['permissions']);

        self::assertSame([
            'system: extensions' => [
                'label' => 'Extensions',
                'parent' => 'system: system',
                'url' => '@system/package/extensions',
                'access' => 'system: manage packages',
                'priority' => 5,
            ],
            'system: themes' => [
                'label' => 'Themes',
                'parent' => 'system: system',
                'url' => '@system/package/themes',
                'access' => 'system: manage packages',
                'priority' => 10,
            ],
            'system: snapshots' => [
                'label' => 'Snapshots',
                'parent' => 'system: system',
                'url' => '@system/snapshot',
                'access' => 'system: manage packages',
                'priority' => 15,
            ],
        ], $package['menu']);

        self::assertIsArray($installer['routes']);
        self::assertIsArray($installer['permissions']);
        self::assertIsArray($installer['menu']);

        // One declaration. The wizard keeps its own routes and still consumes this permission.
        foreach (array_keys($package['routes']) as $path) {
            self::assertArrayNotHasKey($path, $installer['routes']);
        }

        foreach (array_keys($package['permissions']) as $permission) {
            self::assertArrayNotHasKey($permission, $installer['permissions']);
        }

        foreach (array_keys($package['menu']) as $entry) {
            self::assertArrayNotHasKey($entry, $installer['menu']);
        }

        foreach ($installer['routes'] as $route) {
            self::assertIsArray($route);
            self::assertNotContains(
                $route['controller'] ?? null,
                [PackageController::class, SnapshotController::class],
            );
        }

        self::assertArrayHasKey('system: marketplace', $installer['menu']);
        self::assertSame('system: manage packages', $installer['menu']['system: marketplace']['access']);
    }

    public function testThePermissionToManagePackagesIsDeclaredByThePackageModuleAlone(): void
    {
        $roots = [
            'app' => ['php'],
            'packages' => ['php'],
        ];

        self::assertNotEmpty($this->under($this->patternHits($roots, '/namespace Pagekit\\\\Package;/'), 'app/package/'));
        self::assertNotEmpty($this->under($this->patternHits($roots, '/namespace Pagekit\\\\Blog;/'), 'packages/'));

        $declarations = $this->patternHits($roots, self::MANAGE_PACKAGES_DECLARATION);

        self::assertCount(1, $declarations);
        self::assertStringStartsWith('app/package/index.php:', $declarations[0]);

        $installer = file_get_contents($this->root().'/app/installer/index.php');
        self::assertIsString($installer);
        self::assertStringContainsString("'access' => 'system: manage packages'", $installer);
        self::assertSame([], $this->patternHitsIn('app/installer/index.php', $installer, self::MANAGE_PACKAGES_DECLARATION));
    }

    public function testTheDetectorReportsAPermissionDeclaration(): void
    {
        // A consumer names the permission as a value. A declaration names it as a key.
        $contents = "<?php\n"
            ."'access' => 'system: manage packages',\n"
            ."\$user->hasAccess('system: manage packages');\n"
            ."'system: manage packages' => [\n";

        self::assertSame(
            ["fixture.php:4:'system: manage packages' => ["],
            $this->patternHitsIn('fixture.php', $contents, self::MANAGE_PACKAGES_DECLARATION),
        );
    }

    public function testBothControllersGateOnThePermissionThisModuleDeclares(): void
    {
        foreach ([PackageController::class, SnapshotController::class] as $class) {
            $reflection = new \ReflectionClass($class);

            self::assertSame('Pagekit\\Package\\Controller', $reflection->getNamespaceName(), $class);
            self::assertSame([['system: manage packages', true]], $this->accessGates($class), $class);

            $actions = array_values(array_filter(
                $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => str_ends_with($method->getName(), 'Action'),
            ));

            self::assertNotEmpty($actions, $class);

            // The class gate covers every action. A method gate would be a second permission.
            foreach ($actions as $method) {
                self::assertSame([], $method->getAttributes(Access::class), $class.'::'.$method->getName());
            }
        }
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

    public function testExecutingEachBootFileRegistersThePackageModule(): void
    {
        $path = $this->registrationRoot();

        try {
            foreach (self::BOOT_FILES as $file) {
                $module = $this->packageRegisteredBy($path, $this->root().'/'.$file);

                self::assertSame('package', $module['name'], $file);
                self::assertIsString($module['path'] ?? null, $file);

                $resolved = realpath($module['path']);
                self::assertNotFalse($resolved, $file);
                // Discovery stores the directory it read. The list slot is not part of that.
                self::assertSame($this->root().'/app/package', strtr($resolved, '\\', '/'), $file);
            }
        } finally {
            $this->removeTree($path);
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

    public function testThePackageTreeDoesNotNameTheInstaller(): void
    {
        $roots = ['app/package' => ['php', 'js', 'vue']];

        // An empty result has to mean the names are absent, so the walk has to open real files first.
        self::assertNotEmpty($this->hits($roots, 'namespace Pagekit\\Package'));
        self::assertNotEmpty($this->hits($roots, 'package:'));

        foreach (self::INSTALLER_NAMES as $name) {
            self::assertSame([], $this->hits($roots, $name), $name);
        }
    }

    public function testTheDetectorReportsAnInstallerName(): void
    {
        // The package locator, the wizard class and the citation of it stay legal.
        $legal = "<?php\n"
            ."\$view->script('extensions', 'package:app/bundle/extensions.js', ['vue']);\n"
            ."use Pagekit\\Installer\\TablePrefix;\n"
            ." * {@see \\Pagekit\\Installer\\TablePrefix}\n"
            ."import Package from '@package/app/lib/package';\n";

        $contents = $legal
            ."\$view->script('extensions', 'installer:app/bundle/extensions.js', ['vue']);\n"
            ."import Version from '@installer/app/lib/version';\n"
            ."\$path = 'app/installer/index.php';\n";

        foreach (self::INSTALLER_NAMES as $name) {
            self::assertSame([], $this->hitsIn('fixture.php', $legal, $name), $name);
        }

        self::assertSame(
            ['fixture.php:6:$view->script(\'extensions\', \'installer:app/bundle/extensions.js\', [\'vue\']);'],
            $this->hitsIn('fixture.php', $contents, 'installer:'),
        );
        self::assertSame(
            ['fixture.php:7:import Version from \'@installer/app/lib/version\';'],
            $this->hitsIn('fixture.php', $contents, '@installer'),
        );
        self::assertSame(
            ['fixture.php:8:$path = \'app/installer/index.php\';'],
            $this->hitsIn('fixture.php', $contents, 'app/installer'),
        );
    }

    public function testTheRetiredPackageNamespaceIsGone(): void
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

        self::assertSame([], $this->patternHits($roots, self::RETIRED_PACKAGE_NAMESPACE));
    }

    public function testTheDetectorReportsALineInTheRetiredPackageNamespace(): void
    {
        // Double-quoted on purpose: a nowdoc would store these names in this file and the tree scan would report them.
        $contents = "<?php\n"
            ."namespace Pagekit\\Installer\\Package;\n"
            ."use Pagekit\\Installer\\Package\\PackageManager;\n"
            ."use Pagekit\\Installer\\Package\\Package;\n"
            ."use Pagekit\\Installer\\Package\\PackageInterface;\n"
            ."use Pagekit\\Installer\\Package\\PackageFactory;\n"
            ."use Pagekit\\Installer\\Package\\Lifecycle\\PackageLifecycle;\n"
            ."use Pagekit\\Installer\\Package\\Snapshot\\SnapshotStore;\n"
            ."namespace Pagekit\\Package;\n"
            ."use Pagekit\\Package\\Package;\n"
            ."use Pagekit\\Installer\\TablePrefix;\n";

        // A declaration closes the segment with a semicolon, a child name with a
        // backslash. The last three lines stay legal: the namespace the classes
        // moved to, and the wizard class that stays.
        self::assertSame(
            [
                'fixture.php:2:namespace Pagekit\\Installer\\Package;',
                'fixture.php:3:use Pagekit\\Installer\\Package\\PackageManager;',
                'fixture.php:4:use Pagekit\\Installer\\Package\\Package;',
                'fixture.php:5:use Pagekit\\Installer\\Package\\PackageInterface;',
                'fixture.php:6:use Pagekit\\Installer\\Package\\PackageFactory;',
                'fixture.php:7:use Pagekit\\Installer\\Package\\Lifecycle\\PackageLifecycle;',
                'fixture.php:8:use Pagekit\\Installer\\Package\\Snapshot\\SnapshotStore;',
            ],
            $this->patternHitsIn('fixture.php', $contents, self::RETIRED_PACKAGE_NAMESPACE),
        );
    }

    public function testTheRetiredHelperNamespaceIsGone(): void
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

        self::assertSame([], $this->patternHits($roots, self::RETIRED_HELPER_NAMESPACE));
    }

    public function testTheDetectorReportsALineInTheRetiredHelperNamespace(): void
    {
        // Double-quoted on purpose: a nowdoc would store these names in this file and the tree scan would report them.
        $contents = "<?php\n"
            ."namespace Pagekit\\Installer\\Helper;\n"
            ."use Pagekit\\Installer\\Helper\\Composer;\n"
            ."use Pagekit\\Installer\\Helper\\Factory;\n"
            ."use Pagekit\\Installer\\Helper\\InstallerIO;\n"
            ."namespace Pagekit\\Package\\Helper;\n"
            ."use Pagekit\\Package\\Helper\\Composer;\n"
            ."use Pagekit\\Installer\\TablePrefix;\n"
            ." * {@see \\Pagekit\\Installer\\TablePrefix}\n";

        // A declaration closes the segment with a semicolon, a child name with a
        // backslash. The last four lines stay legal: the namespace the helper
        // moved to, and the wizard class that stays, citation included.
        self::assertSame(
            [
                'fixture.php:2:namespace Pagekit\\Installer\\Helper;',
                'fixture.php:3:use Pagekit\\Installer\\Helper\\Composer;',
                'fixture.php:4:use Pagekit\\Installer\\Helper\\Factory;',
                'fixture.php:5:use Pagekit\\Installer\\Helper\\InstallerIO;',
            ],
            $this->patternHitsIn('fixture.php', $contents, self::RETIRED_HELPER_NAMESPACE),
        );
    }

    public function testTheReservedMarkersStayBoundToTheWizardPrefix(): void
    {
        $source = file_get_contents($this->root().'/tests/Unit/Snapshot/RestoreTableNamesTest.php');
        self::assertIsString($source);

        $imports = $this->importedClasses($source);

        // The markers are reserved only because a prefix an installation can be
        // created with refuses them. That reading lives in the wizard, so this
        // one test is where the two modules stay bound.
        self::assertContains('Pagekit\\Package\\Snapshot\\RestoreTableNames', $imports);
        self::assertContains('Pagekit\\Installer\\TablePrefix', $imports);
        self::assertSame(3, preg_match_all('/TablePrefix::refusal\\(/', $source));

        $installable = $this->methodSource($source, 'testNoTableOfAnInstallationThatCouldBeCreatedReadsAsANameARestoreInvented');
        self::assertStringContainsString('assertNull', $installable);
        self::assertStringContainsString('RestoreTableNames::isReserved', $installable);
        self::assertSame(1, preg_match_all('/TablePrefix::refusal\\(/', $installable));

        $markers = $this->methodSource($source, 'testAMarkerARestoreNamesItsOwnTablesWithIsNoPrefixAnInstallationCanBeCreatedWith');
        self::assertStringContainsString('RestoreTableNames::SHADOW', $markers);
        self::assertStringContainsString('RestoreTableNames::BACKUP', $markers);
        self::assertSame(2, preg_match_all('/TablePrefix::refusal\\(/', $markers));
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

    public function testTheManagerHelperAndSnapshotEngineLeftTheInstallerTree(): void
    {
        foreach ([
            'app/package/src/PackageManager.php',
            'app/package/src/Snapshot/DatabaseDumper.php',
            'app/package/src/Snapshot/DatabaseRestorer.php',
            'app/package/src/Snapshot/DumpFormat.php',
            'app/package/src/Snapshot/PackageSnapshotter.php',
            'app/package/src/Snapshot/RestoreTableNames.php',
            'app/package/src/Snapshot/ShadowSchema.php',
            'app/package/src/Snapshot/SnapshotStore.php',
        ] as $file) {
            $source = file_get_contents($this->root().'/'.$file);
            self::assertIsString($source, $file);
            self::assertSame(1, preg_match('/^namespace Pagekit\\\\Package(\\\\|;)/m', $source), $file);
        }

        self::assertDirectoryDoesNotExist($this->root().'/app/installer/src/Package');
        self::assertDirectoryDoesNotExist($this->root().'/app/installer/src/Helper');
    }

    public function testTheAdminSurfaceLeftTheInstallerTree(): void
    {
        foreach ([
            'app/package/src/Controller/PackageController.php',
            'app/package/src/Controller/SnapshotController.php',
            'app/package/views/extensions.php',
            'app/package/views/themes.php',
            'app/package/views/snapshots.php',
            'app/package/app/views/extensions.js',
            'app/package/app/views/snapshots.js',
            'app/package/app/views/themes.js',
            'app/package/app/components/package-details.vue',
            'app/package/app/components/package-manager.js',
            'app/package/app/components/package-upload.vue',
            'app/package/app/lib/install.vue',
            'app/package/app/lib/output.js',
            'app/package/app/lib/package.js',
            'app/package/app/lib/uninstall.vue',
            'app/package/app/lib/update.vue',
            'app/package/app/lib/version.js',
        ] as $file) {
            self::assertFileExists($this->root().'/'.$file);
        }

        foreach ([
            'app/installer/src/Controller/PackageController.php',
            'app/installer/src/Controller/SnapshotController.php',
            'app/installer/views/extensions.php',
            'app/installer/views/themes.php',
            'app/installer/views/snapshots.php',
            'app/installer/app/views/extensions.js',
            'app/installer/app/views/snapshots.js',
            'app/installer/app/views/themes.js',
            'app/installer/app/components/package-details.vue',
            'app/installer/app/components/package-manager.js',
            'app/installer/app/components/package-upload.vue',
            'app/installer/app/lib/install.vue',
            'app/installer/app/lib/output.js',
            'app/installer/app/lib/package.js',
            'app/installer/app/lib/uninstall.vue',
            'app/installer/app/lib/update.vue',
            'app/installer/app/lib/version.js',
        ] as $file) {
            self::assertFileDoesNotExist($this->root().'/'.$file);
        }

        foreach ([
            'extensions' => 'app/package/views/extensions.php',
            'themes' => 'app/package/views/themes.php',
            'snapshots' => 'app/package/views/snapshots.php',
        ] as $page => $file) {
            $source = file_get_contents($this->root().'/'.$file);
            self::assertIsString($source, $file);
            self::assertSame(1, preg_match(
                '/\$view->script\(\s*\''.preg_quote($page, '/').'\'\s*,\s*\'package:app\/bundle\/'.preg_quote($page, '/').'\.js\'/',
                $source,
            ), $file);
        }

        $packages = file_get_contents($this->root().'/app/package/src/Controller/PackageController.php');
        $snapshots = file_get_contents($this->root().'/app/package/src/Controller/SnapshotController.php');
        self::assertIsString($packages);
        self::assertIsString($snapshots);
        self::assertStringContainsString("'name' => 'package:views/extensions.php'", $packages);
        self::assertStringContainsString("'name' => 'package:views/themes.php'", $packages);
        self::assertStringContainsString("'name' => 'package:views/snapshots.php'", $snapshots);
    }

    public function testTheBundleManifestBuildsThePackagePagesAndNotFromTheInstallerAlias(): void
    {
        $source = file_get_contents($this->root().'/scripts/bundle-entries.mjs');
        self::assertIsString($source);

        self::assertSame(1, preg_match('/\'@package\'\s*:\s*\'app\/package\'/', $source));
        self::assertStringNotContainsString('@installer', $source);

        $package = $this->bundleEntries($source, 'app/package');
        $installer = $this->bundleEntries($source, 'app/installer');

        foreach (['extensions', 'snapshots', 'themes'] as $entry) {
            self::assertSame(1, preg_match(
                '/\b'.preg_quote($entry, '/').':\s*\'app\/views\/'.preg_quote($entry, '/').'\.js\'/',
                $package,
            ), $entry);
            self::assertDoesNotMatchRegularExpression('/\b'.preg_quote($entry, '/').'\s*:/', $installer, $entry);
        }

        foreach (['installer', 'marketplace', 'update'] as $entry) {
            self::assertMatchesRegularExpression('/\b'.preg_quote($entry, '/').'\s*:/', $installer, $entry);
        }
    }

    public function testTheMarketplaceUpdateAndDashboardImportThePackageClient(): void
    {
        foreach ([
            'app/installer/app/components/marketplace.vue' => '@package/app/lib/package',
            'app/installer/app/views/update.js' => '@package/app/lib/version',
            'app/system/modules/dashboard/app/views/index.js' => '@package/app/lib/version',
        ] as $file => $import) {
            $source = file_get_contents($this->root().'/'.$file);
            self::assertIsString($source, $file);
            self::assertStringContainsString($import, $source, $file);
            self::assertStringNotContainsString('@installer', $source, $file);
        }
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
        $manager = file_get_contents($this->root().'/app/package/src/PackageManager.php');
        $bootstrap = file_get_contents($this->root().'/tests/Unit/Package/bootstrap.php');
        self::assertIsString($manager);
        self::assertIsString($bootstrap);

        self::assertSame(1, preg_match('/^namespace (Pagekit\\\\Package);/m', $manager, $namespace));

        // Unqualified __() resolves in the manager's namespace before the global helper.
        self::assertStringContainsString('namespace '.$namespace[1].';', $bootstrap);
        self::assertStringContainsString("function_exists('".$namespace[1]."\\__')", $bootstrap);
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

    public function testTheInstallerIndexBaselineStaysTheClosureBinding(): void
    {
        $entries = array_values(array_filter(
            $this->baselineEntries(),
            static fn (array $entry): bool => ($entry['path'] ?? '') === 'app/installer/index.php',
        ));

        self::assertCount(1, $entries);
        self::assertSame('#^Undefined variable\\: \\$this$#', $entries[0]['message']);
        self::assertSame('variable.undefined', $entries[0]['identifier']);
        self::assertSame('1', $entries[0]['count']);

        // A class reads its own config, so the manifest itself adds no baseline entry.
        $packageIndex = array_values(array_filter(
            $this->baselineEntries(),
            static fn (array $entry): bool => ($entry['path'] ?? '') === 'app/package/index.php',
        ));
        self::assertSame([], $packageIndex);
    }

    public function testMainRegistersTheRegistryWhateverTheContainerHolds(): void
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

        // Everything the guards leave out: the record has no directory, and
        // there is neither somewhere to keep a snapshot nor a database to dump.
        self::assertEqualsCanonicalizing(
            ['package', 'manager', 'systemApi', 'packageStaging'],
            array_values(array_diff($app->keys(), $registered)),
        );
    }

    public function testTheMovedServicesAreRegisteredInThePackageModuleAlone(): void
    {
        $roots = ['app' => ['php']];

        foreach (['package', 'manager', 'snapshotter'] as $id) {
            $hits = $this->hits($roots, "set('".$id."'");

            self::assertCount(1, $hits, $id);
            self::assertStringStartsWith('app/package/src/PackageModule.php:', $hits[0]);
        }

        // The dashboard registers the same endpoint for its own widgets. That
        // second registration is the one this module does not own.
        $endpoint = $this->hits($roots, "set('systemApi'");

        self::assertCount(2, $endpoint);
        self::assertEqualsCanonicalizing(
            [
                'app/package/src/PackageModule.php',
                'app/system/modules/dashboard/src/DashboardModule.php',
            ],
            $this->filesOf($endpoint),
        );
    }

    public function testTheRegistryReadsThePackagesDirectoryTwoLevelsDown(): void
    {
        $root = $this->temporaryDirectory();

        try {
            $this->writeComposer($root.'/packages/pagekit/blog', 'pagekit/blog');
            $this->writeComposer($root.'/packages/pagekit/theme', 'pagekit/theme');
            $this->writeComposer($root.'/packages/pagekit', 'pagekit/too-shallow');
            $this->writeComposer($root.'/packages/pagekit/blog/src', 'pagekit/too-deep');
            $this->writeComposer($root.'/elsewhere/pagekit/blog', 'pagekit/elsewhere');

            $app = new Application();
            $app->set('path', $root);
            $app->set('url', null);

            self::assertNull($this->packageModule()->main($app));

            $packages = $app->get('package');
            self::assertInstanceOf(PackageFactory::class, $packages);

            $found = array_keys($packages->all());
            sort($found);

            // Two levels under packages/: a shallower file, a deeper one and a
            // tree beside packages/ are all real composer.json files, and none
            // of them is a package this installation installed.
            self::assertSame(['pagekit/blog', 'pagekit/theme'], $found);
            self::assertSame($root.'/packages/pagekit/blog', $packages->get('pagekit/blog')?->get('path'));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testSystemApiIsTheEndpointTheContainerNames(): void
    {
        $plain = new Application();
        self::assertNull($this->packageModule()->main($plain));
        self::assertSame('https://pagekit.com', $plain->get('systemApi'));

        $configured = new Application();
        $configured->set('system.api', 'https://updates.example');
        self::assertNull($this->packageModule()->main($configured));
        self::assertSame('https://updates.example', $configured->get('systemApi'));
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

    public function testTheComposerNamespaceIsTheAutoloaderAlone(): void
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

        $hits = $this->patternHits($roots, self::COMPOSER_NAMESPACE);

        self::assertSame([], $this->besidesTheAutoloader($hits));
        self::assertNotEmpty($this->under($hits, 'app/modules/application/src/Module/Loader/AutoLoader.php'));
        self::assertNotEmpty($this->under($hits, 'app/system/modules/cache/src/Tests/bootstrap.php'));

        // This file spells a forbidden name. The walk has to leave it unread.
        self::assertSame([], $this->hits(['tests' => ['php']], 'Composer\\Console\\HtmlOutputFormatter'));
    }

    public function testTheDetectorReportsAComposerNamespaceBesidesTheAutoloader(): void
    {
        $contents = <<<'PHP'
        <?php
        // Composer's record stays prose.
        $note = 'Composer\'s';
        use Composer\Autoload\ClassLoader;
        $loader = new \Composer\Autoload\ClassLoader();
        use Composer\Console\HtmlOutputFormatter;
        use Composer\Autoload\ClassLoader, Composer\Util\Filesystem;
        PHP;

        $hits = $this->patternHitsIn('fixture.php', $contents, self::COMPOSER_NAMESPACE);

        self::assertSame(
            [
                'fixture.php:4:use Composer\\Autoload\\ClassLoader;',
                'fixture.php:5:$loader = new \\Composer\\Autoload\\ClassLoader();',
                'fixture.php:6:use Composer\\Console\\HtmlOutputFormatter;',
                'fixture.php:7:use Composer\\Autoload\\ClassLoader, Composer\\Util\\Filesystem;',
            ],
            $hits,
        );
        self::assertSame(
            [
                'fixture.php:6:use Composer\\Console\\HtmlOutputFormatter;',
                'fixture.php:7:use Composer\\Autoload\\ClassLoader, Composer\\Util\\Filesystem;',
            ],
            $this->besidesTheAutoloader($hits),
        );
    }

    public function testTheApplicationAutoloadNamesNoPackagesDirectory(): void
    {
        $autoload = file_get_contents($this->root().'/autoload.php');
        self::assertIsString($autoload);
        self::assertStringNotContainsString('packages/', $autoload);
        self::assertStringContainsString("return require __DIR__ . '/app/vendor/autoload.php';", $autoload);
    }

    public function testTheRuntimeLockDoesNotInstallComposer(): void
    {
        $lock = json_decode((string) file_get_contents($this->root().'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);
        self::assertIsArray($lock['packages'] ?? null);

        $names = [];

        foreach ($lock['packages'] as $package) {
            self::assertIsArray($package);
            $name = $package['name'] ?? null;
            self::assertIsString($name);
            $names[] = $name;
        }

        // packages[] is what a production install resolves. require cannot show a transitive re-entry.
        self::assertNotEmpty($names);
        self::assertNotContains('composer/composer', $names);
        self::assertSame('*', $lock['platform']['ext-zip'] ?? null);

        $composer = json_decode((string) file_get_contents($this->root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);
        self::assertArrayHasKey('ext-zip', $composer['require']);
        self::assertArrayNotHasKey('ext-zip', $composer['require-dev'] ?? []);
    }

    public function testNothingNamesTheDeletedComposerPaths(): void
    {
        $roots = [
            'app' => self::SOURCE_EXTENSIONS,
            'public' => self::SOURCE_EXTENSIONS,
            'tests' => self::SOURCE_EXTENSIONS,
            'scripts' => self::SOURCE_EXTENSIONS,
        ];

        // Each root has to contribute a real file, or an empty result would only mean nothing was read.
        self::assertNotEmpty($this->under($this->hits($roots, 'namespace Pagekit\\Package'), 'app/'));
        self::assertNotEmpty($this->hits(['public' => ['php']], 'Pagekit'));
        self::assertNotEmpty($this->under($this->hits($roots, 'namespace Pagekit\\Tests\\Unit\\Package'), 'tests/'));
        self::assertNotEmpty($this->hits(['scripts' => ['mjs']], 'export const'));

        foreach (self::DELETED_RUNTIME_NAMES as $name) {
            self::assertSame([], $this->hits($roots, $name), $name);
        }

        // The webroot deny list names installed.json. It is not a scanned extension.
        self::assertNotContains('htaccess', self::SOURCE_EXTENSIONS);

        $deny = $this->hits(['public' => ['htaccess']], 'installed.json');
        self::assertCount(1, $deny);
        self::assertStringStartsWith('public/.htaccess:', $deny[0]);
    }

    public function testThePackagesDirectoryHoldsNoComposerRuntime(): void
    {
        self::assertDirectoryExists($this->root().'/packages/pagekit');
        self::assertDirectoryDoesNotExist($this->root().'/packages/composer');
        self::assertFileDoesNotExist($this->root().'/packages/autoload.php');
    }

    public function testMainStagesAnUploadUnderTheTempPackagesDirectory(): void
    {
        $app = new Application();
        $app->set('path.temp', '/var/tmp/pagekit');

        self::assertNull($this->packageModule()->main($app));
        self::assertTrue($app->has('package'));
        self::assertTrue($app->has('manager'));
        self::assertTrue($app->has('packageStaging'));
        self::assertSame('/var/tmp/pagekit/packages', $app->get('packageStaging'));
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
     * A tree the boot can register from, with an autoload.php that stops the file
     * on the statement after register().
     */
    private function registrationRoot(): string
    {
        $path = $this->temporaryDirectory();

        try {
            $linked = @symlink($this->root().'/app', $path.'/app')
                && @symlink($this->root().'/packages', $path.'/packages')
                && is_link($path.'/app')
                && is_link($path.'/packages');

            if (!$linked) {
                self::markTestSkipped('symlink() is unavailable on this host');
            }

            foreach (['tmp/cache', 'tmp/logs', 'tmp/sessions'] as $directory) {
                self::assertTrue(mkdir($path.'/'.$directory, 0755, true), $directory);
            }

            // The wizard boot exits when this tree fails its requirement check.
            self::assertNotFalse(file_put_contents($path.'/.htaccess', ''));
            self::assertNotFalse(file_put_contents($path.'/autoload.php', self::STAND_IN_AUTOLOAD));

            return $path;
        } catch (\Throwable $error) {
            $this->removeTree($path);

            throw $error;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function packageRegisteredBy(string $base, string $bootFile): array
    {
        // Included boot files read these two names from the including scope.
        $path = $base;
        $config = ['path' => $path, 'config.file' => false];
        $app = null;

        try {
            include $bootFile;

            self::fail($bootFile.' continued past registration.');
        } catch (\TypeError $error) {
            self::assertStringContainsString(ClassLoader::class, $error->getMessage(), $bootFile);
            self::assertStringContainsString($bootFile, strtr($error->getMessage(), '\\', '/'), $bootFile);
        }

        self::assertInstanceOf(Application::class, $app, $bootFile);

        $manager = $app->get('module');
        self::assertInstanceOf(ModuleManager::class, $manager, $bootFile);

        // load() would run every main(). The registry is what register() stored.
        $registered = (new \ReflectionProperty(ModuleManager::class, 'registered'))->getValue($manager);
        self::assertIsArray($registered, $bootFile);
        self::assertArrayHasKey('package', $registered, $bootFile."\n".$this->failureSummary($manager));

        $module = $registered['package'];
        self::assertIsArray($module, $bootFile);

        return $module;
    }

    private function failureSummary(ModuleManager $manager): string
    {
        $messages = [];

        foreach ($manager->getRegistrationFailures() as $file => $error) {
            $messages[] = $file.': '.$error->getMessage();
        }

        return $messages === [] ? 'none' : implode('; ', $messages);
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

            // This file spells the names the scan forbids, so it is not one of the files read.
            $own = realpath(__FILE__);
            $listed = $file->getRealPath();

            if (is_string($own) && $listed === $own) {
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
                self::fail('Scan pattern did not compile.');
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

    /**
     * @return list<array{0: ?string, 1: ?bool}>
     */
    private function accessGates(string $class): array
    {
        $gates = [];

        foreach ((new \ReflectionClass($class))->getAttributes(Access::class) as $attribute) {
            $access = $attribute->newInstance();
            $gates[] = [$access->getExpression(), $access->getAdmin()];
        }

        return $gates;
    }

    private function bundleEntries(string $source, string $dir): string
    {
        $matched = preg_match(
            '/dir:\s*\''.preg_quote($dir, '/').'\',\s*(?:global:\s*[^,]*,\s*)?entries:\s*\{(?<entries>[^}]*)\}/s',
            $source,
            $matches,
        );

        self::assertSame(1, $matched, $dir);

        return $matches['entries'];
    }

    /**
     * @param list<string> $hits
     * @return list<string>
     */
    private function filesOf(array $hits): array
    {
        $files = [];

        foreach ($hits as $hit) {
            self::assertSame(1, preg_match('/^(.*):\d+:/', $hit, $matches));
            $files[$matches[1]] = $matches[1];
        }

        $files = array_values($files);
        sort($files);

        return $files;
    }

    private function methodSource(string $source, string $method): string
    {
        $matched = preg_match(
            '/^    public function '.preg_quote($method, '/').'\b.*?^    \}$/ms',
            $source,
            $matches,
        );

        self::assertSame(1, $matched, $method);

        return $matches[0];
    }

    /**
     * @param list<string> $hits
     * @return list<string>
     */
    private function besidesTheAutoloader(array $hits): array
    {
        $forbidden = [];
        $allowed = '/'.preg_quote(self::AUTOLOADER, '/').'(?![A-Za-z0-9_\\\\])/';

        foreach ($hits as $hit) {
            $remainder = preg_replace($allowed, '', $this->hitLine($hit));
            self::assertIsString($remainder);

            $result = preg_match(self::COMPOSER_NAMESPACE, $remainder);

            if ($result === false) {
                self::fail('Scan pattern did not compile.');
            }

            if ($result === 1) {
                $forbidden[] = $hit;
            }
        }

        return $forbidden;
    }

    private function hitLine(string $hit): string
    {
        self::assertSame(1, preg_match('/^(.*):\d+:(.*)$/', $hit, $matches));

        return $matches[2];
    }

    private function writeComposer(string $directory, string $name): void
    {
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0755, true));
        }

        self::assertNotFalse(file_put_contents(
            $directory.'/composer.json',
            (string) json_encode(['name' => $name, 'type' => 'pagekit-extension']),
        ));
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
