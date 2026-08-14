<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Helper\Composer;
use Pagekit\Installer\Package\Package;
use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\Installer\Package\Snapshot\DatabaseDumper;
use Pagekit\Installer\Package\Snapshot\DatabaseRestorer;
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Installer\Package\Snapshot\SnapshotStore;
use Pagekit\Tests\Unit\Snapshot\SnapshotDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Taking a package's files out of the live installation, which is the last half
 * of a removal that has become a move.
 *
 * The copy in the snapshot is what the package is retained as from the moment the
 * snapshot is taken, so this step is no longer deleting the last copy of anything
 * - but it does have to leave nothing behind. A tree still under packages/ is one
 * the factory goes on globbing up and the panel goes on offering, as a package
 * that is merely not installed, while its hooks have already run and its content
 * is in the trash. Half-removed like that, it is worse than either state.
 *
 * So the outcome is read rather than assumed. The filesystem service says whether
 * it could take the tree away and Composer says nothing at all, which is why a
 * package Composer installed is judged by what is left on the disk. Either way a
 * tree that stayed is reported to the administrator instead of being reported as
 * done - with the path in the log, where whoever finishes the job by hand will
 * look, and out of the message, which is streamed to a browser.
 *
 * Composer is told last, after the archive is written, because it takes the tree
 * away itself: told first, it would delete the very files the snapshot is the copy
 * of.
 */
final class PackageTreeRemovalTest extends TestCase
{
    use SnapshotDatabase;

    /**
     * Composer's record as it lies under packages/, naming the package that is
     * about to be removed. Its presence is what makes a package one Composer
     * installed, for the removal and for the snapshot alike.
     */
    private const BOOKKEEPING = '[{"name":"pagekit/test-ext","version":"1.0.0","type":"pagekit-extension"}]';

    private string $workspace;

    private string $snapshots;

    private string $packages;

    /**
     * The package on disk, in the vendor/name shape packages/ gives it.
     */
    private string $tree;

    /**
     * What the next boot reads: which packages are installed, and which
     * extensions it runs.
     */
    private Config $system;

    private BufferedOutput $output;

    private RemovalLog $log;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_tree_removal_' . getmypid() . '_' . uniqid();
        $this->snapshots = $this->workspace . '/tmp/snapshots';
        $this->packages = $this->workspace . '/packages';
        $this->tree = $this->packages . '/pagekit/test-ext';

        $this->plant('test-ext');

        $this->system = new Config();
        $this->system->set('packages.test-ext', '1.0.0');
        $this->system->set('extensions', ['test-ext']);

        $this->output = new BufferedOutput();
        $this->log = new RemovalLog();
    }

    protected function tearDown(): void
    {
        $this->closeDatabases();
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // The tree goes, and the copy in the snapshot stays
    // ------------------------------------------------------------------

    public function testAPackageComposerInstalledIsArchivedBeforeComposerIsToldToTakeItAway(): void
    {
        // Composer removes the tree itself, so the order is the whole of what
        // keeps the snapshot from being a snapshot of nothing.
        $this->writeBookkeeping();

        $app = $this->container($this->snapshotter());
        $composer = new ComposerThatTakesTheTreeAway($this->composerPaths(), $this->output);
        $composer->tree = $this->tree;

        $archived = [];
        $composer->observe = function () use (&$archived): void {
            $archived = $this->archivedTrees();
        };

        $this->manager($app, $composer)->uninstall('pagekit/test-ext');

        self::assertCount(1, $archived, 'The files were in the snapshot before Composer was told about them');
        self::assertSame(['pagekit/test-ext'], $composer->uninstalled);

        // And afterwards: gone from the live tree, still in the snapshot.
        self::assertDirectoryDoesNotExist($this->tree);
        self::assertCount(1, $this->archivedTrees());
        self::assertNull($this->system->get('packages.test-ext'));
    }

    public function testTheVendorDirectoryGoesWithTheLastPackageInIt(): void
    {
        // An empty vendor directory is not harmful, it is just not something a
        // removal should leave behind for every package ever installed.
        $this->manager($this->container($this->snapshotter()))->uninstall('pagekit/test-ext');

        self::assertDirectoryDoesNotExist($this->tree);
        self::assertDirectoryDoesNotExist($this->packages . '/pagekit');
    }

    public function testTheVendorDirectoryStaysWhereItHoldsAnotherPackage(): void
    {
        // Two extensions from one vendor is the ordinary case, and taking the
        // directory with the first of them would take the second with it.
        $this->plant('other-ext');

        $app = $this->container($this->snapshotter(), $this->package('test-ext'), $this->package('other-ext'));

        $this->manager($app)->uninstall('pagekit/test-ext');

        self::assertDirectoryDoesNotExist($this->tree);
        self::assertFileExists($this->packages . '/pagekit/other-ext/composer.json');
    }

    // ------------------------------------------------------------------
    // A tree that stayed behind
    // ------------------------------------------------------------------

    public function testATreeThatWillNotGoIsReportedRatherThanCountedAsRemoved(): void
    {
        // A file held open, a permission the process does not have, a mount that
        // has gone read-only. What it was does not matter; that the removal did
        // not finish does, because everything else about the package is undone by
        // now and the rest has to be done by hand.
        $app = $this->container($this->snapshotter());
        $app->set('file', new ATreeThatStaysBehind());

        $failure = $this->refusal(fn () => $this->manager($app)->uninstall('pagekit/test-ext'));

        self::assertStringContainsString('Test Extension', $failure->getMessage());
        self::assertStringContainsString('could not be taken off the disk', $failure->getMessage());
        self::assertStringContainsString('restored', $failure->getMessage(), 'The way out is the snapshot, and it has to be named');

        // Streamed straight to a browser, so what is on the disk stays in the
        // log rather than being read out to whoever asked.
        self::assertStringNotContainsString($this->workspace, $failure->getMessage());

        // The half that did happen: the package is out of the configuration and
        // its way back is in the store, which is what the message points at.
        self::assertNull($this->system->get('packages.test-ext'));
        self::assertCount(1, $this->archivedTrees());
    }

    public function testWhereTheTreeStayedIsInTheLogForWhoeverHasToFinishByHand(): void
    {
        $app = $this->container($this->snapshotter());
        $app->set('file', new ATreeThatStaysBehind());

        $this->refusal(fn () => $this->manager($app)->uninstall('pagekit/test-ext'));

        self::assertCount(1, $this->log->records);
        self::assertSame('error', $this->log->records[0]['level']);
        self::assertStringContainsString('pagekit/test-ext', $this->log->records[0]['message']);
        self::assertStringContainsString($this->tree, $this->log->records[0]['message']);
        self::assertSame('test-ext', $this->log->records[0]['context']['package'] ?? null);
    }

    public function testATreeComposerCouldNotTakeAwayIsReportedTheSameWay(): void
    {
        // Composer reports nothing about what it removed that can be read back,
        // so a run that failed halfway through - or a plugin that left the files
        // where they were - is only visible on the disk.
        $this->writeBookkeeping();

        $app = $this->container($this->snapshotter());

        $composer = new ComposerThatLeavesTheTreeBehind($this->composerPaths(), $this->output);

        $failure = $this->refusal(fn () => $this->manager($app, $composer)->uninstall('pagekit/test-ext'));

        self::assertStringContainsString('could not be taken off the disk', $failure->getMessage());
        self::assertFileExists($this->tree . '/composer.json');
        self::assertCount(1, $this->log->records);
        self::assertStringContainsString($this->tree, $this->log->records[0]['message']);
    }

    public function testAPackageThatGaveItselfNoTitleIsNamedByItsPackageName(): void
    {
        // The message is what an administrator reads, and a package that filled
        // in no title has to be recognisable in it all the same.
        $app = $this->container($this->snapshotter(), $this->package('test-ext', ['title' => null]));
        $app->set('file', new ATreeThatStaysBehind());

        $failure = $this->refusal(fn () => $this->manager($app)->uninstall('pagekit/test-ext'));

        self::assertStringContainsString('pagekit/test-ext', $failure->getMessage());
    }

    public function testAPackageThatNamesNoPathIsRefusedBeforeAnythingIsDeleted(): void
    {
        // An empty path is not a tree at the root of the disk, it is the
        // directory the process happens to be running in - so it is refused as a
        // path rather than passed to a deletion. Asserted in the installation
        // that keeps no snapshots, because that is the one where a package gets
        // this far without its files having been read first.
        $app = $this->container(null, $this->package('test-ext', ['path' => '']));

        $failure = $this->refusal(fn () => $this->manager($app)->uninstall('pagekit/test-ext'));

        self::assertStringContainsString('Package path is missing', $failure->getMessage());

        // The version key is already gone, which is what says the refusal came
        // from the removal of the files rather than from something before it.
        self::assertNull($this->system->get('packages.test-ext'));
        self::assertFileExists($this->tree . '/composer.json');
    }

    // ------------------------------------------------------------------
    // The stage finishes even where the package objects to it
    // ------------------------------------------------------------------

    #[DataProvider('provideHooksAPackageCanFailIn')]
    public function testAStageAHookThrewInStillEndsWithThePackageArchivedAndGone(string $hook): void
    {
        // Removing a package is how an administrator gets out from under a broken
        // one, so the package gets its say and no veto - and the stage that
        // follows has to be the whole stage. A hook that threw may not leave the
        // tree on disk with the content it owns already trashed.
        $this->writeLifecycle($hook, "throw new \\RuntimeException('Broken on the way out');");

        $app = $this->container($this->snapshotter(), $this->package('test-ext', ['extra' => ['scripts' => 'scripts.php']]));

        $this->manager($app)->uninstall('pagekit/test-ext');

        self::assertDirectoryDoesNotExist($this->tree);
        self::assertNull($this->system->get('packages.test-ext'));
        self::assertSame([], (array) $this->system->get('extensions'));

        // Archived before any of it ran, which is what makes the package
        // restorable from the state it was removed in.
        self::assertCount(1, $this->archivedTrees());
        self::assertCount(1, $this->store()->list());

        // What the failure costs is the log line naming the hook.
        self::assertCount(1, $this->log->records);
        self::assertStringContainsString($hook . ' hook', $this->log->records[0]['message']);
        self::assertStringContainsString('pagekit/test-ext', $this->log->records[0]['message']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideHooksAPackageCanFailIn(): array
    {
        return [
            'a package that throws on its way out of use' => ['disable'],
            'a package that throws on its way off the installation' => ['uninstall'],
        ];
    }

    // ------------------------------------------------------------------
    // The installation a removal runs against
    // ------------------------------------------------------------------

    /**
     * The container the manager is built against.
     *
     * @param object|null $snapshotter what is registered as the way back, or null
     *                                 in an installation that has none at all
     * @param Package     ...$known    the packages the installation knows about,
     *                                 the one on disk by default
     */
    private function container(?object $snapshotter, Package ...$known): Application
    {
        $app = new Application();

        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($this->system);

        $app->set('config', $config);
        $app->set('package', $this->factory($known === [] ? [$this->package('test-ext')] : $known));
        $app->set('file', new Filesystem());
        $app->set('log', $this->log);

        $app->set('path.temp', $this->workspace . '/tmp/temp');
        $app->set('path.cache', $this->workspace . '/tmp/cache');
        $app->set('path.vendor', $this->workspace . '/app/vendor');
        $app->set('path.artifact', $this->workspace . '/tmp/packages');
        $app->set('path.packages', $this->packages);
        $app->set('system.api', 'https://example.test');

        if ($snapshotter !== null) {
            $app->set('snapshotter', $snapshotter);
        }

        return $app;
    }

    /**
     * The manager as the panel and the console build it.
     *
     * @param Composer|null $composer the helper for a package Composer installed,
     *                                whose real one resolves packages against a
     *                                marketplace
     */
    private function manager(Application $app, ?Composer $composer = null): PackageManager
    {
        if ($composer === null) {
            return new PackageManager($app, $this->output);
        }

        return new ManagerWithComposer($app, $this->output, $composer);
    }

    /**
     * The paths the Composer helper works in, as the manager reads them off the
     * container to build its own.
     *
     * @return array<string, string>
     */
    private function composerPaths(): array
    {
        return [
            'path.packages' => $this->packages,
            'path.artifact' => $this->workspace . '/tmp/packages',
            'system.api' => 'https://example.test',
        ];
    }

    /**
     * The snapshotter as the installer builds it, over a real store, a real
     * database and real files.
     */
    private function snapshotter(): PackageSnapshotter
    {
        $files = new Filesystem();
        $connection = $this->openDatabase();

        return new PackageSnapshotter(
            new SnapshotStore($this->snapshots, $files),
            new DatabaseDumper($connection),
            new DatabaseRestorer($connection),
            $files,
            new NullLogger(),
            $this->packages,
        );
    }

    /**
     * Puts a package on disk, the way the marketplace leaves one behind.
     */
    private function plant(string $module): void
    {
        $tree = $this->packages . '/pagekit/' . $module;

        mkdir($tree . '/views', 0755, true);

        file_put_contents($tree . '/composer.json', (string) json_encode([
            'name' => 'pagekit/' . $module,
            'type' => 'pagekit-extension',
            'version' => '1.0.0',
        ]));
        file_put_contents($tree . '/views/extension.php', "<?php\n\nreturn [];\n");
    }

    /**
     * The package as the factory reads it out of a manifest on disk.
     *
     * @param array<string, mixed> $overrides
     */
    private function package(string $module, array $overrides = []): Package
    {
        return new Package(array_replace([
            'name' => 'pagekit/' . $module,
            'type' => 'pagekit-extension',
            'module' => $module,
            'title' => $module === 'test-ext' ? 'Test Extension' : 'Other Extension',
            'version' => '1.0.0',
            'path' => $this->packages . '/pagekit/' . $module,
        ], $overrides));
    }

    /**
     * @param array<int, Package> $packages
     */
    private function factory(array $packages): PackageFactory
    {
        $factory = new PackageFactory();

        foreach ($packages as $package) {
            $factory[$package->getName()] = $package;
        }

        return $factory;
    }

    /**
     * The package's lifecycle file, implementing the one hook a test drives.
     */
    private function writeLifecycle(string $hook, string $body): void
    {
        file_put_contents(
            $this->tree . '/scripts.php',
            str_replace(['{HOOK}', '{BODY}'], [$hook, $body], <<<'PHP'
                <?php

                declare(strict_types=1);

                use Pagekit\Installer\Package\Lifecycle\PackageLifecycle;
                use Psr\Container\ContainerInterface;

                return new class () extends PackageLifecycle {
                    public function {HOOK}(ContainerInterface $app): void
                    {
                        {BODY}
                    }
                };
                PHP),
        );
    }

    private function writeBookkeeping(): void
    {
        mkdir($this->packages . '/composer', 0755, true);

        file_put_contents($this->packages . '/composer/installed.json', self::BOOKKEEPING);
    }

    // ------------------------------------------------------------------
    // Reading back what a removal left behind
    // ------------------------------------------------------------------

    private function store(): SnapshotStore
    {
        return new SnapshotStore($this->snapshots, new Filesystem());
    }

    /**
     * The archived copies of the package tree, wherever in the store they are.
     * Read by globbing rather than through the store, so it can be asked while a
     * removal is still running and the id it is running under is not known here.
     *
     * @return array<int, string>
     */
    private function archivedTrees(): array
    {
        return glob(
            $this->snapshots . '/*/' . SnapshotStore::FILES_DIR . '/pagekit/test-ext/composer.json'
        ) ?: [];
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

/**
 * The manager with the Composer helper a test hands it.
 *
 * The helper is built in the constructor out of the container, and the real one
 * resolves and downloads packages off a marketplace, so standing in for it is the
 * only way a removal through Composer can be asserted at all.
 */
final class ManagerWithComposer extends PackageManager
{
    public function __construct(ContainerInterface $app, OutputInterface $output, Composer $composer)
    {
        parent::__construct($app, $output);

        $this->composer = $composer;
    }
}

/**
 * Composer as the removal path has it: told about a package it installed, it
 * takes the tree off the disk itself and reports nothing back about having done
 * so. What is left out here is the run that resolves and downloads packages; the
 * registry write around it is the helper's own.
 */
final class ComposerThatTakesTheTreeAway extends Composer
{
    /**
     * The packages it was told to remove.
     *
     * @var array<int, string>
     */
    public array $uninstalled = [];

    /**
     * The tree it takes off the disk, as Composer does for what it installed.
     */
    public string $tree = '';

    /**
     * What to note down at the moment Composer is told. It is the only point from
     * which the order can be seen, and the order is what keeps the snapshot from
     * being taken of files that are already gone.
     *
     * @var (\Closure(): void)|null
     */
    public ?\Closure $observe = null;

    /**
     * @param array<int, string>|bool $updates
     * @param array<int, mixed>       $refresh
     */
    protected function composerUpdate(array|bool $updates = false, array $refresh = [], bool $packagist = false, bool $preferSource = false): void
    {
        $this->uninstalled = is_array($updates) ? array_values($updates) : [];

        if ($this->observe !== null) {
            ($this->observe)();
        }

        if ($this->tree !== '') {
            (new Filesystem())->delete($this->tree);
        }
    }
}

/**
 * Composer that was told and left the tree where it was: a file it could not
 * unlink, a permission it does not have, a run that failed halfway through. It
 * says nothing about that either, which is why the disk is what the removal reads.
 */
final class ComposerThatLeavesTheTreeBehind extends Composer
{
    /**
     * @param array<int, string>|bool $updates
     * @param array<int, mixed>       $refresh
     */
    protected function composerUpdate(array|bool $updates = false, array $refresh = [], bool $packagist = false, bool $preferSource = false): void
    {
    }
}

/**
 * The filesystem service a package folder is removed through, where the folder
 * will not go.
 */
final class ATreeThatStaysBehind extends Filesystem
{
    /**
     * @param string|array<int, string> $files
     */
    public function delete($files): bool
    {
        return false;
    }
}

/**
 * What the removal reported, with the context that names the package.
 */
final class RemovalLog extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
