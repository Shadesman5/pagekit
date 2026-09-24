<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Monolog\Handler\TestHandler;
use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Log\Logger;
use Pagekit\Package\Archive\PackageArchive;
use Pagekit\Package\InstallProbes;
use Pagekit\Package\Package;
use Pagekit\Package\PackageFactory;
use Pagekit\Package\PackageManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * An archive is installed by replacing the package tree, and a refusal writes nothing.
 */
final class PackageInstallFromArchiveTest extends TestCase
{
    private string $workspace = '';

    private string $packages = '';

    private string $witness = '';

    private Config $system;

    private BufferedOutput $output;

    private TestHandler $records;

    private Logger $log;

    protected function setUp(): void
    {
        require_once __DIR__.'/bootstrap.php';
        InstallProbes::reset();

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_install_'.bin2hex(random_bytes(4));
        $this->packages = $this->workspace.'/packages';
        $this->witness = $this->workspace.'/witness.txt';
        mkdir($this->packages, 0777, true);
        file_put_contents($this->packages.'/canary.txt', 'stay');

        $this->system = new Config();
        $this->system->set('extensions', []);
        $this->output = new BufferedOutput();
        $this->records = new TestHandler();
        $this->log = new Logger('test', [$this->records]);
    }

    protected function tearDown(): void
    {
        InstallProbes::reset();

        try {
            if ($this->packages !== '' && is_file($this->packages.'/canary.txt')) {
                self::assertSame('stay', file_get_contents($this->packages.'/canary.txt'));
            }
        } finally {
            if ($this->workspace !== '') {
                $this->removeTree($this->workspace);
            }
        }
    }

    public function testAFreshArchiveIsInstalledAndItsVersionRecorded(): void
    {
        $this->manager()->install($this->archive());

        self::assertSame("install\n", $this->witness());
        self::assertSame([], $this->system->get('extensions'));
        $this->assertTreeReplaced();
    }

    public function testUpdatingALoadedPackageReplacesTheTreeAndEnablesFromTheRecordedVersion(): void
    {
        $this->replaceLoaded();

        self::assertSame("update\nenable\n", $this->witness());
        self::assertSame(['demo'], $this->system->get('extensions'));
        $this->assertTreeReplaced();
    }

    public function testAnUpdateUsesThePreviousPackageVersionRatherThanInstallingAfresh(): void
    {
        $this->plant('pagekit', 'demo', ['module' => 'legacy-demo'], ['marker.txt' => 'old']);
        $this->system->set('packages.legacy-demo', '1.0.0');
        $this->system->set('extensions', ['legacy-demo']);

        $this->manager(null, null, new ModuleList(['legacy-demo' => new \stdClass()]))->install($this->archive());

        // The recorded version is the previous module's, so the update runs and install does not.
        self::assertSame("update\nenable\n", $this->witness());
        self::assertSame('1.0.0', $this->system->get('packages.legacy-demo'));
        self::assertSame(['legacy-demo', 'demo'], $this->system->get('extensions'));
        $this->assertTreeReplaced();
    }

    public function testAReinstallAtTheSamePathIsAnUpdateWhenTheStoredPathUsesBackslashes(): void
    {
        $factory = new FactorySpellingTheInstalledPathWithBackslashes();
        $factory->addPath($this->packages.'/*/*/composer.json');

        $this->replaceLoaded($factory);

        self::assertSame("update\nenable\n", $this->witness());
        self::assertSame(['demo'], $this->system->get('extensions'));
        $this->assertTreeReplaced();
    }

    public function testUpdatingAPackageThatIsNotLoadedInstallsItAgain(): void
    {
        $this->plant('pagekit', 'demo', [], ['marker.txt' => 'old']);
        $this->system->set('packages.demo', '1.0.0');

        $this->manager()->install($this->archive());

        self::assertSame("install\n", $this->witness());
        self::assertSame([], $this->system->get('extensions'));
        $this->assertTreeReplaced();
    }

    public function testAPackageWhoseModuleIsNotAStringIsInstalledAgainEvenWhenThatNameIsLoaded(): void
    {
        $this->plant('pagekit', 'demo', ['module' => 1], ['marker.txt' => 'old']);
        $this->system->set('packages.1', '1.0.0');

        $this->manager(null, null, new ModuleList([
            '1' => new \stdClass(),
            'demo' => new \stdClass(),
        ]))->install($this->archive());

        self::assertSame("install\n", $this->witness());
        self::assertSame([], $this->system->get('extensions'));
        self::assertSame('1.0.0', $this->system->get('packages.1'));
        $this->assertTreeReplaced();
    }

    public function testAPackageAlreadyInstalledInAnotherFolderIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->plant('other', 'demo', ['name' => 'pagekit/demo'], ['marker.txt' => 'old']);
        $before = $this->entries($this->packages);

        $exception = $this->refusal($this->archive());

        self::assertStringContainsString('another folder', $exception->getMessage());
        $this->assertNamesThePackageNotItsTitle($exception->getMessage());
        self::assertSame($before, $this->entries($this->packages));
        self::assertSame('old', file_get_contents($this->packages.'/other/demo/marker.txt'));
        self::assertDirectoryDoesNotExist($this->packages.'/pagekit');
        self::assertSame('', $this->witness());
        self::assertSame('', $this->output->fetch());
        self::assertSame([], $this->records->getRecords());
    }

    public function testAPackageAlreadyInstalledAsAnotherTypeIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->plant('pagekit', 'demo', ['type' => 'pagekit-theme'], ['marker.txt' => 'old']);
        $before = $this->entries($this->packages.'/pagekit');

        $exception = $this->refusal($this->archive());

        self::assertStringContainsString('another type', $exception->getMessage());
        $this->assertNamesThePackageNotItsTitle($exception->getMessage());
        self::assertSame($before, $this->entries($this->packages.'/pagekit'));
        self::assertSame('old', file_get_contents($this->packages.'/pagekit/demo/marker.txt'));
        self::assertSame('', $this->witness());
        self::assertSame('', $this->output->fetch());
        self::assertSame([], $this->records->getRecords());
    }

    public function testASymbolicLinkIsRefusedBeforeAnythingIsWritten(): void
    {
        $elsewhere = $this->workspace.'/elsewhere';
        mkdir($elsewhere, 0777, true);
        mkdir($this->packages.'/pagekit', 0777, true);
        file_put_contents($elsewhere.'/marker.txt', 'old');
        self::assertTrue(symlink($elsewhere, $this->packages.'/pagekit/demo'));

        $exception = $this->refusal($this->archive());

        self::assertStringContainsString('symbolic link', $exception->getMessage());
        $this->assertNamesThePackageNotItsTitle($exception->getMessage());
        self::assertTrue(is_link($this->packages.'/pagekit/demo'));
        self::assertSame(['demo'], $this->entries($this->packages.'/pagekit'));
        self::assertSame('old', file_get_contents($elsewhere.'/marker.txt'));
        self::assertFileDoesNotExist($elsewhere.'/fresh.txt');
        self::assertSame('', $this->witness());
        self::assertSame('', $this->output->fetch());
        self::assertSame([], $this->records->getRecords());
    }

    public function testAFolderThatCannotBeCreatedRefusesTheInstallBeforeUnpacking(): void
    {
        $before = $this->entries($this->packages);

        $exception = $this->refusal($this->archive(), new FilesystemThatCannotCreateFolders());

        self::assertStringContainsString('folder for', $exception->getMessage());
        $this->assertNamesThePackageNotItsTitle($exception->getMessage());
        self::assertSame($before, $this->entries($this->packages));
        self::assertDirectoryDoesNotExist($this->packages.'/pagekit');
        self::assertSame('', $this->output->fetch());
        self::assertSame([], $this->records->getRecords());
    }

    public function testAnArchiveThatCannotBeReadLeavesThePackagesDirectoryUnchanged(): void
    {
        $archive = $this->archive();
        $before = $this->entries($this->packages);
        unlink($archive->path());

        $exception = $this->refusal($archive);

        self::assertStringContainsString('can no longer be read', $exception->getMessage());
        self::assertSame($before, $this->entries($this->packages));
        self::assertDirectoryDoesNotExist($this->packages.'/pagekit');
        self::assertSame('', $this->output->fetch());
        self::assertSame([], $this->records->getRecords());
    }

    public function testAFailedExtractionLeavesNoHiddenSibling(): void
    {
        $archive = $this->archive();
        $before = $this->entries($this->packages);
        $bytes = hex2bin('deadbeef');
        self::assertIsString($bytes);
        InstallProbes::$nextRandom = [$bytes];
        mkdir($this->packages.'/pagekit', 0777, true);
        file_put_contents($this->packages.'/pagekit/.demo-deadbeef', 'blocked');

        $exception = $this->refusal($archive);

        self::assertStringContainsString('folder to unpack', $exception->getMessage());
        self::assertSame($before, $this->entries($this->packages));
        self::assertFileDoesNotExist($this->packages.'/pagekit/.demo-deadbeef');
        self::assertDirectoryDoesNotExist($this->packages.'/pagekit');
        self::assertSame([], $this->records->getRecords());
        self::assertSame('', $this->output->fetch());
    }

    public function testATreeThatCannotBePutBackKeepsTheRetiredCopyAndFails(): void
    {
        $this->plant('pagekit', 'demo', [], ['marker.txt' => 'old']);
        InstallProbes::$rename = static function (string $from, string $to): bool {
            if (!str_starts_with(basename(strtr($to, '\\', '/')), '.')) {
                return false;
            }

            return \rename($from, $to);
        };

        $exception = $this->refusal($this->archive());

        self::assertStringContainsString('nor the installed ones put back', $exception->getMessage());
        $this->assertNamesThePackageNotItsTitle($exception->getMessage());
        self::assertSame('', $this->witness());
        self::assertSame('', $this->output->fetch());
        self::assertDirectoryDoesNotExist($this->packages.'/pagekit/demo');

        $hidden = $this->hidden($this->packages.'/pagekit');
        self::assertCount(1, $hidden);
        $retired = $this->packages.'/pagekit/'.$hidden[0];
        self::assertSame('old', file_get_contents($retired.'/marker.txt'));
        self::assertFileDoesNotExist($retired.'/fresh.txt');

        self::assertCount(1, $this->records->getRecords());
        $record = $this->records->getRecords()[0];
        self::assertStringContainsString('could not be put back from', $record->message);
        self::assertStringContainsString($retired, $record->message);
    }

    public function testARetiredTreeThatWillNotDeleteDoesNotFailTheInstall(): void
    {
        $this->plant('pagekit', 'demo', [], ['marker.txt' => 'old', 'old.php' => "<?php\n"]);
        $this->system->set('packages.demo', '1.0.0');
        $this->system->set('extensions', ['demo']);

        $this->manager(new FilesystemThatKeepsRetiredTrees(), null, new ModuleList(['demo' => new \stdClass()]))
            ->install($this->archive());

        self::assertSame("update\nenable\n", $this->witness());
        self::assertSame('1.2.3', $this->system->get('packages.demo'));
        self::assertSame(['demo'], $this->system->get('extensions'));

        $tree = $this->packages.'/pagekit/demo';
        self::assertSame('new', file_get_contents($tree.'/fresh.txt'));
        self::assertFileDoesNotExist($tree.'/marker.txt');
        self::assertFileDoesNotExist($tree.'/old.php');

        $hidden = $this->hidden($this->packages.'/pagekit');
        self::assertCount(1, $hidden);
        $retired = $this->packages.'/pagekit/'.$hidden[0];
        self::assertSame('old', file_get_contents($retired.'/marker.txt'));
        self::assertFileExists($retired.'/old.php');
        self::assertFileDoesNotExist($retired.'/fresh.txt');
        self::assertSame(['canary.txt', 'pagekit'], $this->entries($this->packages));

        $display = $this->output->fetch();
        self::assertStringContainsString('could not all be deleted', $display);
        self::assertStringContainsString('pagekit/demo', $display);
        self::assertStringNotContainsString('Fancy Title', $display);

        self::assertCount(1, $this->records->getRecords());
        $record = $this->records->getRecords()[0];
        self::assertStringContainsString('could not be deleted from', $record->message);
        self::assertStringContainsString($retired, $record->message);

        self::assertNotEmpty(InstallProbes::$invalidated);

        foreach (InstallProbes::$invalidated as $file) {
            self::assertStringStartsWith($tree.'/', strtr($file, '\\', '/'));
        }
    }

    private function replaceLoaded(?PackageFactory $factory = null): void
    {
        $this->plant('pagekit', 'demo', [], ['marker.txt' => 'old']);
        $this->system->set('packages.demo', '1.0.0');
        $this->system->set('extensions', ['demo']);
        $this->manager(null, $factory, new ModuleList(['demo' => new \stdClass()]))->install($this->archive());
    }

    private function assertTreeReplaced(): void
    {
        $tree = $this->packages.'/pagekit/demo';
        self::assertSame('new', file_get_contents($tree.'/fresh.txt'));
        self::assertFileDoesNotExist($tree.'/marker.txt');
        self::assertSame(['demo'], $this->entries($this->packages.'/pagekit'));
        self::assertSame(['canary.txt', 'pagekit'], $this->entries($this->packages));
        self::assertSame('1.2.3', $this->system->get('packages.demo'));

        $composer = json_decode((string) file_get_contents($tree.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertSame('1.2.3', $composer['version']);
        self::assertSame('', $this->output->fetch());
        self::assertSame([], $this->records->getRecords());
    }

    private function assertNamesThePackageNotItsTitle(string $message): void
    {
        self::assertStringContainsString('pagekit/demo', $message);
        self::assertStringNotContainsString('Fancy Title', $message);
    }

    /**
     * @param array<string, mixed>  $composer
     * @param array<string, string> $files
     */
    private function archive(array $composer = [], array $files = []): PackageArchive
    {
        $path = $this->workspace.'/incoming.zip';

        if (is_file($path)) {
            unlink($path);
        }

        PackageZip::write($path, array_merge([
            'name' => 'pagekit/demo',
            'type' => 'pagekit-extension',
            'version' => '1.2.3',
            'title' => 'Fancy Title',
            'extra' => ['scripts' => 'scripts.php'],
        ], $composer), array_merge([
            'fresh.txt' => 'new',
            'scripts.php' => $this->scripts(),
        ], $files));

        return PackageArchive::open($path);
    }

    private function scripts(): string
    {
        return str_replace('{WITNESS}', $this->witness, <<<'PHP'
            <?php

            declare(strict_types=1);

            use Pagekit\Package\Lifecycle\PackageLifecycle;
            use Psr\Container\ContainerInterface;

            return new class () extends PackageLifecycle {
                public function install(ContainerInterface $app): void
                {
                    file_put_contents('{WITNESS}', "install\n", FILE_APPEND);
                }

                public function enable(ContainerInterface $app): void
                {
                    file_put_contents('{WITNESS}', "enable\n", FILE_APPEND);
                }

                public function updates(): array
                {
                    return [
                        '1.0.5' => static function (ContainerInterface $app): void {
                            file_put_contents('{WITNESS}', "update\n", FILE_APPEND);
                        },
                    ];
                }
            };
            PHP);
    }

    private function witness(): string
    {
        if (!is_file($this->witness)) {
            return '';
        }

        return (string) file_get_contents($this->witness);
    }

    /**
     * @param array<string, mixed>  $composer
     * @param array<string, string> $files
     */
    private function plant(string $vendor, string $name, array $composer = [], array $files = []): string
    {
        $tree = $this->packages.'/'.$vendor.'/'.$name;
        mkdir($tree, 0777, true);

        file_put_contents($tree.'/composer.json', json_encode(array_merge([
            'name' => $vendor.'/'.$name,
            'type' => 'pagekit-extension',
            'version' => '1.0.0',
            'title' => 'Fancy Title',
        ], $composer), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        foreach ($files as $file => $contents) {
            $path = $tree.'/'.$file;
            $directory = dirname($path);

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($path, $contents);
        }

        return $tree;
    }

    private function manager(?Filesystem $files = null, ?PackageFactory $factory = null, ?ModuleList $modules = null): PackageManager
    {
        return new PackageManager($this->container($files, $factory, $modules), $this->output);
    }

    private function container(?Filesystem $files = null, ?PackageFactory $factory = null, ?ModuleList $modules = null): Application
    {
        $app = new Application();

        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($this->system);

        if ($factory === null) {
            $factory = new PackageFactory();
            $factory->addPath($this->packages.'/*/*/composer.json');
        }

        $app->set('config', $config);
        $app->set('package', $factory);
        $app->set('file', $files ?? new Filesystem());
        $app->set('log', $this->log);
        $app->set('module', $modules ?? new ModuleList());
        $app->set('path.temp', $this->workspace.'/tmp/temp');
        $app->set('path.cache', $this->workspace.'/tmp/cache');
        $app->set('path.vendor', $this->workspace.'/app/vendor');
        $app->set('path.packages', $this->packages);
        $app->set('system.api', 'https://example.test');

        return $app;
    }

    private function refusal(PackageArchive $archive, ?Filesystem $files = null, ?PackageFactory $factory = null, ?ModuleList $modules = null): \RuntimeException
    {
        try {
            $this->manager($files, $factory, $modules)->install($archive);
        } catch (\RuntimeException $exception) {
            return $exception;
        }

        self::fail('The install was expected to be refused.');
    }

    /**
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function hidden(string $dir): array
    {
        return array_values(array_filter(
            $this->entries($dir),
            static fn (string $entry): bool => str_starts_with($entry, '.'),
        ));
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

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}

/**
 * Modules the installation has already loaded, keyed by name.
 */
final class ModuleList
{
    /** @param array<string, object> $loaded */
    public function __construct(private array $loaded = [])
    {
    }

    public function get(string $name): ?object
    {
        return $this->loaded[$name] ?? null;
    }
}

/**
 * The first package read back is the one already installed, spelled with backslashes.
 */
final class FactorySpellingTheInstalledPathWithBackslashes extends PackageFactory
{
    private bool $spelled = false;

    public function get(string $name, bool $force = false): ?Package
    {
        $package = parent::get($name, $force);

        if (!$this->spelled && $package instanceof Package) {
            $this->spelled = true;
            $path = $package->get('path');

            if (is_string($path)) {
                $package->set('path', strtr($path, '/', '\\'));
            }
        }

        return $package;
    }
}

/**
 * The packages directory cannot gain a folder.
 */
final class FilesystemThatCannotCreateFolders extends Filesystem
{
    public function makeDir(string $dir, int $mode = 0777, bool $recursive = true): bool
    {
        return false;
    }
}

/**
 * A retired package tree stays on disk.
 */
final class FilesystemThatKeepsRetiredTrees extends Filesystem
{
    public function delete($files): bool
    {
        foreach ((array) $files as $file) {
            $name = basename(rtrim(strtr((string) $file, '\\', '/'), '/'));

            if (str_starts_with($name, '.')) {
                return false;
            }
        }

        return parent::delete($files);
    }
}
