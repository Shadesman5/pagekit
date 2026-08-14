<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Console;

use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Console\Commands\UninstallCommand;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Package\Package;
use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Installer\Package\Snapshot\DatabaseDumper;
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Installer\Package\Snapshot\SnapshotStore;
use Pagekit\Tests\Unit\Snapshot\SnapshotDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Removing a package from the command line, which is the only way to do it on an
 * installation whose admin panel can no longer be reached - the situation a
 * broken extension creates.
 *
 * The command owns nothing of the removal itself. It reads the names off the
 * command line and hands them to the same package manager the admin panel drives,
 * built against the application's own container: that is what gives the console
 * removal the snapshot the web one takes, and what would otherwise leave the two
 * with separate ideas of what removing a package involves. Handed the output
 * where the container belongs, it never got as far as reading its argument.
 */
final class UninstallCommandTest extends TestCase
{
    use SnapshotDatabase;

    private string $workspace;

    private string $snapshots;

    private string $packages;

    /**
     * What the next boot reads: which packages are installed, and which
     * extensions it runs.
     */
    private Config $system;

    protected function setUp(): void
    {
        // The manager emits its lines through the translation helper of its own
        // namespace, which no booted intl service stands behind here.
        require_once dirname(__DIR__) . '/Package/bootstrap.php';

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_uninstall_cmd_' . getmypid() . '_' . uniqid();
        $this->snapshots = $this->workspace . '/tmp/snapshots';
        $this->packages = $this->workspace . '/packages';

        $this->plant('test-ext');

        $this->system = new Config();
        $this->system->set('packages.test-ext', '1.0.0');
        $this->system->set('extensions', ['test-ext']);
    }

    protected function tearDown(): void
    {
        $this->closeDatabases();
        $this->removeTree($this->workspace);
    }

    public function testAPackageNamedOnTheCommandLineIsRemovedFromTheInstallation(): void
    {
        $tester = $this->uninstall(['pagekit/test-ext']);

        // Nothing of this happens where the manager is handed something other
        // than the container it reads the installation out of: the command
        // fails before the first package is even looked up.
        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($this->packages . '/pagekit/test-ext');
        self::assertNull($this->system->get('packages.test-ext'));
        self::assertSame([], (array) $this->system->get('extensions'));
    }

    public function testAConsoleRemovalIsPutAsideTheSameWayOneInTheAdminPanelIs(): void
    {
        // Whichever way a package is removed, the way back is the same one - and
        // the console is the way that is used when the panel cannot be reached,
        // which is exactly when a restore is most likely to be needed.
        $tester = $this->uninstall(['pagekit/test-ext'], snapshotted: true);

        $snapshots = $this->store()->list();
        $id = (string) array_key_first($snapshots);

        self::assertCount(1, $snapshots);
        self::assertSame('test-ext', $snapshots[$id]['module']);
        self::assertSame(PackageSnapshotter::REASON_UNINSTALL, $snapshots[$id]['reason']);
        self::assertFileExists($this->snapshots . '/' . $id . '/' . SnapshotStore::DUMP_FILE);
        self::assertFileExists(
            $this->snapshots . '/' . $id . '/' . SnapshotStore::FILES_DIR . '/pagekit/test-ext/composer.json',
        );

        // The id is what a restore is asked for, so it goes to whoever ran the
        // command rather than to the log alone.
        self::assertStringContainsString('Snapshot ' . $id . ' taken.', $tester->getDisplay());
    }

    public function testEveryPackageNamedOnTheCommandLineIsRemoved(): void
    {
        $this->plant('other-ext');
        $this->system->set('packages.other-ext', '2.0.0');
        $this->system->set('extensions', ['test-ext', 'other-ext']);

        $tester = $this->uninstall(['pagekit/test-ext', 'pagekit/other-ext'], snapshotted: true);

        self::assertSame(SymfonyCommand::SUCCESS, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($this->packages . '/pagekit/test-ext');
        self::assertDirectoryDoesNotExist($this->packages . '/pagekit/other-ext');
        self::assertNull($this->system->get('packages.test-ext'));
        self::assertNull($this->system->get('packages.other-ext'));

        // One snapshot each: a single one holding whichever package came first
        // would leave the other unrecoverable.
        self::assertCount(2, $this->store()->list());
    }

    public function testAPackageTheInstallationDoesNotHaveIsReportedRatherThanPassedOver(): void
    {
        // A name that is merely misspelled would otherwise read as a package
        // successfully removed, and the operator would go looking for the effect.
        $failure = $this->refusal(fn () => $this->uninstall(['pagekit/absent']));

        self::assertStringContainsString('pagekit/absent', $failure->getMessage());
        self::assertFileExists($this->packages . '/pagekit/test-ext/composer.json');
        self::assertSame('1.0.0', $this->system->get('packages.test-ext'));
    }

    /**
     * Runs the command the way the console runs it, against a container holding
     * what the installation offers it.
     *
     * @param array<int, string> $packages    as they are named on the command line
     * @param bool               $snapshotted whether this installation keeps snapshots
     */
    private function uninstall(array $packages, bool $snapshotted = false): CommandTester
    {
        $tester = new CommandTester(new UninstallCommand($this->container($snapshotted)));
        $tester->execute(['packages' => $packages]);

        return $tester;
    }

    private function container(bool $snapshotted): Application
    {
        $app = new Application();

        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($this->system);

        $app->set('config', $config);
        $app->set('package', $this->factory());
        $app->set('file', new Filesystem());
        $app->set('log', new NullLogger());

        $app->set('path.temp', $this->workspace . '/tmp/temp');
        $app->set('path.cache', $this->workspace . '/tmp/cache');
        $app->set('path.vendor', $this->workspace . '/app/vendor');
        $app->set('path.artifact', $this->workspace . '/tmp/packages');
        $app->set('path.packages', $this->packages);
        $app->set('system.api', 'https://example.test');

        if ($snapshotted) {
            $files = new Filesystem();

            $app->set('snapshotter', new PackageSnapshotter(
                new SnapshotStore($this->snapshots, $files),
                new DatabaseDumper($this->openDatabase()),
                $files,
                new NullLogger(),
                $this->packages,
            ));
        }

        return $app;
    }

    /**
     * Puts a package on disk, the way the marketplace leaves one behind.
     */
    private function plant(string $module): void
    {
        $tree = $this->packages . '/pagekit/' . $module;

        mkdir($tree, 0755, true);

        file_put_contents($tree . '/composer.json', (string) json_encode([
            'name' => 'pagekit/' . $module,
            'type' => 'pagekit-extension',
            'version' => '1.0.0',
        ]));
    }

    /**
     * The packages the installation knows about, as the factory read them off
     * disk.
     */
    private function factory(): PackageFactory
    {
        $factory = new PackageFactory();

        foreach ($this->entries($this->packages . '/pagekit') as $module) {
            $package = new Package([
                'name' => 'pagekit/' . $module,
                'type' => 'pagekit-extension',
                'module' => $module,
                'title' => ucfirst($module),
                'version' => '1.0.0',
                'path' => $this->packages . '/pagekit/' . $module,
            ]);

            $factory[$package->getName()] = $package;
        }

        return $factory;
    }

    private function store(): SnapshotStore
    {
        return new SnapshotStore($this->snapshots, new Filesystem());
    }

    /**
     * Runs a removal that has to be refused, and hands back what it refused
     * with. Captured rather than asserted on inside the catch, because a failed
     * assertion is itself a RuntimeException.
     */
    private function refusal(callable $call): \RuntimeException
    {
        try {
            $call();
        } catch (\RuntimeException $e) {
            return $e;
        }

        self::fail('The removal was expected to be refused.');
    }

    /**
     * @return array<int, string>
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
            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}
