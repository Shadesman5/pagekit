<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Package;

use Pagekit\Application;
use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Package\Package;
use Pagekit\Installer\Package\PackageFactory;
use Pagekit\Installer\Package\PackageManager;
use Pagekit\Installer\Package\Snapshot\DatabaseDumper;
use Pagekit\Installer\Package\Snapshot\DatabaseRestorer;
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Installer\Package\Snapshot\SnapshotStore;
use Pagekit\Tests\Unit\Snapshot\SnapshotDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Removing a package is the one operation that cannot be undone by running it
 * again, so the first thing it does is put the installation aside.
 *
 * That ordering is the whole contract. The snapshot is taken before the package
 * is switched off and long before its folder is touched, because everything after
 * that line is what the snapshot exists to reverse - and because a snapshot that
 * could not be taken has to stop the removal while there is still nothing to
 * regret. An administrator told that a package is restorable when it is not would
 * find that out at the moment they needed it back.
 *
 * The one environment that removes a package unsnapshotted is the one that never
 * had a way back to offer: no snapshot store at all, which is how the installer
 * runs before there is a database. Refusing there would leave such an
 * installation unable to remove a package for good, so the removal goes ahead and
 * says so in the log - the only thing that will later tell that package from one
 * that was put aside first.
 *
 * The snapshotter here is the real one, over a real database and a real package
 * tree, because what is asserted is that a removal actually leaves something
 * behind to come back to.
 */
final class PackageSnapshotGateTest extends TestCase
{
    use SnapshotDatabase;

    private string $workspace;

    /**
     * Where the snapshots go. Not created in setUp(): an installation that never
     * removed a package has no such directory.
     */
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

    private GateLog $log;

    private GateEvents $events;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap.php';

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/') . '/pk_snapshot_gate_' . getmypid() . '_' . uniqid();
        $this->snapshots = $this->workspace . '/tmp/snapshots';
        $this->packages = $this->workspace . '/packages';
        $this->tree = $this->packages . '/pagekit/test-ext';

        $this->plant('test-ext');

        $this->system = new Config();
        $this->system->set('packages.test-ext', '1.0.0');
        $this->system->set('extensions', ['test-ext']);

        $this->output = new BufferedOutput();
        $this->log = new GateLog();
        $this->events = new GateEvents($this->snapshots);
    }

    protected function tearDown(): void
    {
        $this->closeDatabases();

        // A test that provoked a store nothing can be written to hands it back,
        // so the workspace can be removed again.
        if (is_dir($this->snapshots)) {
            chmod($this->snapshots, 0755);
        }

        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // The snapshot comes first
    // ------------------------------------------------------------------

    public function testTheInstallationIsPutAsideBeforeAnythingIsTakenOutOfIt(): void
    {
        $app = $this->container($this->snapshotter());

        $this->manager($app)->uninstall('pagekit/test-ext');

        $snapshots = $this->store()->list();
        $id = (string) array_key_first($snapshots);

        self::assertCount(1, $snapshots);
        self::assertSame(PackageSnapshotter::REASON_UNINSTALL, $snapshots[$id]['reason']);

        // The archived tree is what says the copy was taken in time: the live
        // one is gone by now, so there was nothing left to copy afterwards.
        self::assertFileExists($this->file($id, SnapshotStore::FILES_DIR) . '/pagekit/test-ext/composer.json');
        self::assertFileExists($this->file($id, SnapshotStore::FILES_DIR) . '/pagekit/test-ext/views/extension.php');
        self::assertFileExists($this->file($id, SnapshotStore::DUMP_FILE));

        // The removal itself happened, and the id is on the transcript an
        // administrator is watching, because it is what a restore is asked for.
        self::assertDirectoryDoesNotExist($this->tree);
        self::assertNull($this->system->get('packages.test-ext'));
        self::assertSame([], (array) $this->system->get('extensions'));
        self::assertStringContainsString('Snapshot ' . $id . ' taken.', $this->output->fetch());
    }

    public function testTheSnapshotIsInPlaceBeforeTheRemovalAnnouncesItselfToAnybody(): void
    {
        // The listeners on these two are where other modules part with the
        // content the extension owns. A snapshot taken after them would be one
        // of an installation the removal had already started on.
        $app = $this->container($this->snapshotter());

        $this->manager($app)->uninstall('pagekit/test-ext');

        self::assertSame([
            ['event' => 'package.disable', 'snapshots' => 1],
            ['event' => 'package.uninstall', 'snapshots' => 1],
        ], $this->events->fired);
    }

    public function testEveryPackageInOneRemovalIsPutAsideOnItsOwnBeforeItGoes(): void
    {
        // A dependency cleanup removes several packages in one call, and each of
        // them is restorable on its own: one snapshot holding whichever package
        // happened to be first would leave the rest unrecoverable.
        $this->plant('other-ext');
        $this->system->set('packages.other-ext', '2.0.0');
        $this->system->set('extensions', ['test-ext', 'other-ext']);

        $app = $this->container($this->snapshotter());

        $this->manager($app)->uninstall(['pagekit/test-ext', 'pagekit/other-ext']);

        self::assertSame([
            ['event' => 'package.disable', 'snapshots' => 1],
            ['event' => 'package.uninstall', 'snapshots' => 1],
            ['event' => 'package.disable', 'snapshots' => 2],
            ['event' => 'package.uninstall', 'snapshots' => 2],
        ], $this->events->fired);

        $modules = array_column($this->store()->list(), 'module');
        sort($modules);

        self::assertSame(['other-ext', 'test-ext'], $modules);
        self::assertSame([], (array) $this->system->get('extensions'));
    }

    // ------------------------------------------------------------------
    // A snapshot that could not be taken is a removal that does not happen
    // ------------------------------------------------------------------

    public function testASnapshotThatCannotBeTakenLeavesThePackageExactlyWhereItWas(): void
    {
        // A full disk, a read-only mount, a store on a volume that is not
        // mounted: what it is does not matter, only that the administrator is
        // left with the package they had rather than with half a removal.
        $app = $this->container($this->snapshotter($this->storeNothingCanBeWrittenTo()));

        $failure = $this->refusal(fn () => $this->manager($app)->uninstall('pagekit/test-ext'));

        self::assertStringContainsString('Test Extension', $failure->getMessage());
        self::assertStringContainsString('nothing was removed', $failure->getMessage());
        self::assertNotNull($failure->getPrevious(), 'What went wrong is carried for the log');

        // The message is streamed straight to a browser, so what refused the
        // write stays in the log rather than being read out to whoever asked.
        self::assertStringNotContainsString($this->workspace, $failure->getMessage());

        self::assertFileExists($this->tree . '/composer.json');
        self::assertSame('1.0.0', $this->system->get('packages.test-ext'));
        self::assertSame(['test-ext'], (array) $this->system->get('extensions'));
        self::assertSame([], $this->events->fired, 'Nothing was announced, because nothing was removed');
    }

    public function testAStoreWithNoRoomLeftInItLeavesThePackageExactlyWhereItWas(): void
    {
        // The store an installation has been keeping snapshots in all along,
        // which has stopped accepting them: it is there, it is where the boot
        // says it is, and a write into it no longer lands. The removal has to
        // stop on that as squarely as on a store that was never there - and it
        // may not leave a directory behind in a store an administrator reads,
        // because every directory in one is offered as a package to restore.
        mkdir($this->snapshots, 0755, true);
        chmod($this->snapshots, 0555);

        $this->requireUnwritable($this->snapshots);

        $app = $this->container($this->snapshotter());

        $failure = $this->refusal(fn () => $this->manager($app)->uninstall('pagekit/test-ext'));

        self::assertStringContainsString('nothing was removed', $failure->getMessage());
        self::assertSame([], $this->entries($this->snapshots));

        self::assertFileExists($this->tree . '/composer.json');
        self::assertSame('1.0.0', $this->system->get('packages.test-ext'));
        self::assertSame(['test-ext'], (array) $this->system->get('extensions'));
        self::assertSame([], $this->events->fired, 'Nothing was announced, because nothing was removed');
    }

    public function testWhatRefusedTheSnapshotIsReportedWhereItCanBeReadBack(): void
    {
        $app = $this->container($this->snapshotter($this->storeNothingCanBeWrittenTo()));

        $this->refusal(fn () => $this->manager($app)->uninstall('pagekit/test-ext'));

        self::assertCount(1, $this->log->records);
        self::assertSame('error', $this->log->records[0]['level']);
        self::assertStringContainsString('pagekit/test-ext', $this->log->records[0]['message']);
        self::assertStringContainsString('nothing was removed', $this->log->records[0]['message']);
        self::assertInstanceOf(\Throwable::class, $this->log->records[0]['context']['exception'] ?? null);
        self::assertSame('test-ext', $this->log->records[0]['context']['package'] ?? null);
    }

    public function testSomethingThatIsNoSnapshotterCannotStandInForOne(): void
    {
        // The container answers what is registered under an id, not what that
        // thing turns out to be. Taken on trust it would be called and the
        // removal would run on the strength of a snapshot nobody took.
        $app = $this->container(new \stdClass());

        $failure = $this->refusal(fn () => $this->manager($app)->uninstall('pagekit/test-ext'));

        self::assertStringContainsString('nothing was removed', $failure->getMessage());
        self::assertStringContainsString('cannot take a snapshot', $this->log->records[0]['message'] ?? '');
        self::assertFileExists($this->tree . '/composer.json');
        self::assertSame('1.0.0', $this->system->get('packages.test-ext'));
    }

    public function testASnapshotterThatCannotEvenBeBuiltAbortsTheRemoval(): void
    {
        // A service that fails to resolve is an installation that meant to keep
        // snapshots and cannot - which is the opposite of one that never kept
        // any, and has to stop the removal rather than wave it through.
        $app = $this->container(fn () => throw new \RuntimeException('nothing to build a snapshotter from'));

        $failure = $this->refusal(fn () => $this->manager($app)->uninstall('pagekit/test-ext'));

        self::assertStringContainsString('nothing was removed', $failure->getMessage());
        self::assertFileExists($this->tree . '/composer.json');
        self::assertSame('1.0.0', $this->system->get('packages.test-ext'));
        self::assertSame([], $this->events->fired);
    }

    // ------------------------------------------------------------------
    // The installation that never had a way back
    // ------------------------------------------------------------------

    public function testAnInstallationThatKeepsNoSnapshotsRemovesThePackageAndSaysSo(): void
    {
        $app = $this->container(null);

        $this->manager($app)->uninstall('pagekit/test-ext');

        self::assertDirectoryDoesNotExist($this->tree);
        self::assertNull($this->system->get('packages.test-ext'));
        self::assertSame([], $this->entries($this->snapshots));

        // One line, and it has to be enough on its own weeks later: which
        // package went, and that it cannot be brought back.
        self::assertCount(1, $this->log->records);
        self::assertSame('warning', $this->log->records[0]['level']);
        self::assertStringContainsString('pagekit/test-ext', $this->log->records[0]['message']);
        self::assertStringContainsString('without a snapshot', $this->log->records[0]['message']);
        self::assertStringContainsString('cannot be undone', $this->log->records[0]['message']);
        self::assertSame('test-ext', $this->log->records[0]['context']['package'] ?? null);
    }

    public function testNothingLeftToTakeThatReportIsNoReasonToRefuseTheRemoval(): void
    {
        // Getting out from under a package is what this operation is for, and a
        // log writing to a full disk is not a reason to withhold it.
        $app = $this->container(null);
        $app->set('log', new GateLogThatFails());

        $this->manager($app)->uninstall('pagekit/test-ext');

        self::assertDirectoryDoesNotExist($this->tree);
        self::assertNull($this->system->get('packages.test-ext'));
    }

    // ------------------------------------------------------------------
    // The installation a removal runs against
    // ------------------------------------------------------------------

    /**
     * The container the manager is built against.
     *
     * @param object|null $snapshotter what is registered as the way back, or null
     *                                 in an installation that has none at all
     */
    private function container(?object $snapshotter): Application
    {
        $app = new Application();

        $config = $this->createMock(ConfigManager::class);
        $config->method('__invoke')->willReturn($this->system);

        $app->set('config', $config);
        $app->set('package', $this->factory());
        $app->set('file', new Filesystem());
        $app->set('events', $this->events);
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

    private function manager(Application $app): PackageManager
    {
        return new PackageManager($app, $this->output);
    }

    /**
     * The snapshotter as the installer builds it, over a real store and a real
     * database.
     *
     * @param string|null $store where the snapshots go, for a test that needs
     *                           that to be somewhere they cannot
     */
    private function snapshotter(?string $store = null): PackageSnapshotter
    {
        $files = new Filesystem();
        $connection = $this->openDatabase();

        return new PackageSnapshotter(
            new SnapshotStore($store ?? $this->snapshots, $files),
            new DatabaseDumper($connection),
            new DatabaseRestorer($connection),
            $files,
            new NullLogger(),
            $this->packages,
        );
    }

    /**
     * A snapshot store that cannot be created, because its path is occupied by
     * something that is not a directory.
     */
    private function storeNothingCanBeWrittenTo(): string
    {
        $path = $this->workspace . '/blocked';

        file_put_contents($path, 'not a directory');

        return $path;
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
                'title' => $module === 'test-ext' ? 'Test Extension' : 'Other Extension',
                'version' => '1.0.0',
                'path' => $this->packages . '/pagekit/' . $module,
            ]);

            $factory[$package->getName()] = $package;
        }

        return $factory;
    }

    // ------------------------------------------------------------------
    // Reading back what a removal left behind
    // ------------------------------------------------------------------

    private function store(): SnapshotStore
    {
        return new SnapshotStore($this->snapshots, new Filesystem());
    }

    private function file(string $id, string $name): string
    {
        return $this->snapshots . '/' . $id . '/' . $name;
    }

    /**
     * Skips where a file can still be created in a directory whose permission
     * bits refuse one: root ignores them, and a platform that answers a
     * read-only directory with a flag rather than a refusal (Windows) lets the
     * write happen as well.
     */
    private function requireUnwritable(string $dir): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root writes into a read-only directory regardless of its permission bits');
        }

        $probe = $dir . '/probe-writable';

        if (@file_put_contents($probe, '') !== false) {
            unlink($probe);

            self::markTestSkipped('This host writes into a read-only directory, where a failed write cannot be provoked');
        }
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

/**
 * The lifecycle events other modules act on, each with what the snapshot store
 * held at the moment it was announced.
 */
final class GateEvents
{
    /** @var array<int, array{event: string, snapshots: int}> */
    public array $fired = [];

    public function __construct(private readonly string $snapshots)
    {
    }

    /**
     * @param array<int, mixed> $params
     */
    public function trigger(string $event, array $params = []): void
    {
        $this->fired[] = [
            'event' => $event,
            'snapshots' => count(glob($this->snapshots . '/*', GLOB_ONLYDIR) ?: []),
        ];
    }
}

/**
 * What the removal reported, with the context that carries the throwable.
 */
final class GateLog extends AbstractLogger
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

/**
 * A log that is itself broken, as one writing to a full disk or into a directory
 * that went away is.
 */
final class GateLogThatFails extends AbstractLogger
{
    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('The log could not be written.');
    }
}
