<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Monolog\Handler\TestHandler;
use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Level;
use Pagekit\Database\Connection;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Controller\SnapshotController;
use Pagekit\Installer\Package\Package;
use Pagekit\Installer\Package\Snapshot\DatabaseDumper;
use Pagekit\Installer\Package\Snapshot\DatabaseRestorer;
use Pagekit\Installer\Package\Snapshot\DumpFormat;
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Installer\Package\Snapshot\SnapshotStore;
use Pagekit\Log\Logger;
use Pagekit\Module\Module;
use Pagekit\Module\ModuleManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The end of the snapshots an administrator actually touches.
 *
 * A snapshot is two things at once, and both of them decide what this end may
 * hand back. It is the way back from a removal, so choosing one has to be
 * possible: the page lists what there is, how much disk each one holds and when
 * it may be reclaimed. And it is the site's whole database as it stood - every
 * password hash on it included - so none of that listing may be a line of the
 * dump.
 *
 * The id is the one part of a snapshot's address a request gets to choose. What
 * is asserted here is that it is never a path: an id that reads as a traversal,
 * as an absolute path or as a name with a null byte in it is a refused request,
 * and the directory such an id points at is still there afterwards.
 *
 * The two operations differ in what they leave behind, and the page has to be
 * told the difference. A restore replaces the configuration the panel itself was
 * built from, so what the installation had cached is cleared; a purge changes
 * nothing the installation loads, so nothing is. Either of them can also simply
 * not happen, and then the administrator is told which snapshot and where the
 * rest of it is - never what refused, which is a path on the disk or a table in
 * the database and belongs in the log.
 *
 * The store is a real directory and the database a real one, so the snapshots
 * acted on here are the snapshots a removal leaves.
 */
final class SnapshotControllerTest extends TestCase
{
    use SnapshotDatabase;

    /**
     * Something in the database that has no business being handed to a browser.
     * It is in the dump because the dump is of everything, which is exactly why
     * the page may not carry it.
     */
    private const SECRET = '$2y$10$notarealhashbutlonganddistinctive';

    private const DAY = 86400;

    private string $workspace;

    private string $snapshots;

    private string $packages;

    /**
     * The package on disk, in the vendor/name shape packages/ gives it.
     */
    private string $tree;

    /**
     * A directory beside the store, holding something worth keeping. Nothing an
     * id can say may reach it.
     */
    private string $outside;

    /**
     * What the installation had cached, and whether an operation cleared it.
     */
    private RecordedCacheClears $cache;

    /**
     * What the administrator is not told, as the log receives it.
     */
    private TestHandler $records;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_snapshot_admin_'.getmypid().'_'.uniqid();
        $this->snapshots = $this->workspace.'/tmp/snapshots';
        $this->packages = $this->workspace.'/packages';
        $this->tree = $this->packages.'/pagekit/test-ext';
        $this->outside = $this->workspace.'/outside';
        $this->cache = new RecordedCacheClears();
        $this->records = new TestHandler();

        mkdir($this->tree.'/views', 0755, true);
        mkdir($this->outside, 0755, true);

        file_put_contents($this->tree.'/composer.json', (string) json_encode([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'version' => '1.4.2',
        ]));
        file_put_contents($this->tree.'/views/extension.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->outside.'/keep-me.txt', 'Not part of any snapshot.');
    }

    protected function tearDown(): void
    {
        $this->closeDatabases();
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // What the page is given
    // ------------------------------------------------------------------

    public function testThePageIsGivenTheSnapshotsToChooseFromNewestFirst(): void
    {
        $connection = $this->installation();
        $older = $this->place('20260101-000000-old-ext-a1b2c3d4', time() - 2 * self::DAY, 'old-ext');
        $newest = $this->take($connection);

        $data = $this->page($this->controller($this->snapshotter($connection)));

        // A list rather than a map: the order is what the page renders, and an
        // object keyed by id would arrive in whatever order it was encoded in.
        self::assertSame([0, 1], array_keys($data['snapshots']));
        self::assertSame([$newest, $older], array_column($data['snapshots'], 'id'));

        // What choosing one takes: which package it was, which version of it,
        // when it was taken, what it costs to keep and when it may go.
        self::assertSame('pagekit/test-ext', $data['snapshots'][0]['package']);
        self::assertSame('1.4.2', $data['snapshots'][0]['version']);
        self::assertGreaterThan(0, $data['snapshots'][0]['created']);
        self::assertGreaterThan(0, $data['snapshots'][0]['size']);
        self::assertSame(
            $data['snapshots'][0]['created'] + SnapshotStore::DEFAULT_RETENTION_DAYS * self::DAY,
            $data['snapshots'][0]['expires'],
        );
    }

    public function testThePageIsGivenNothingThatWasInADumpItLists(): void
    {
        $connection = $this->installation();
        $id = $this->take($connection);

        $data = $this->page($this->controller($this->snapshotter($connection)));

        // The dump is the whole database, so it is what a listing has to stop
        // at: whoever is looking at a list of snapshots is choosing one, not
        // reading one.
        self::assertStringContainsString(
            self::SECRET,
            (string) file_get_contents($this->file($id, SnapshotStore::DUMP_FILE)),
            'The dump holds the secret, which is what makes its absence below worth asserting',
        );
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($data));
    }

    public function testThePageSaysHowLongThisInstallationKeepsASnapshot(): void
    {
        // The one thing about snapshots an installation configures, and the page
        // has to say it: the dates it shows are only meaningful beside the
        // window they were worked out from.
        $connection = $this->installation();
        $this->take($connection);

        $data = $this->page($this->controller($this->snapshotter($connection), retention: 7));

        self::assertSame(7, $data['retention']);
    }

    public function testThePageOfAnInstallationWithNoWindowOfItsOwnSaysTheShippedOne(): void
    {
        // Module configuration comes out of the database, and the installer
        // module is not loaded in every environment that can render this page.
        // Either way the window is the one the installation ships with rather
        // than none at all.
        $data = $this->page($this->controller($this->snapshotter($this->installation()), retention: null));

        self::assertSame(SnapshotStore::DEFAULT_RETENTION_DAYS, $data['retention']);
    }

    public function testThePageOfAnInstallationThatKeepsNoSnapshotsIsEmptyRatherThanBroken(): void
    {
        // The installer runs before there is a database to dump, so an
        // environment with no snapshotter is a real one. Asking it for the page
        // has to answer, because the menu entry that leads here is rendered
        // from the same access as the page.
        $data = $this->page($this->controller(null));

        self::assertSame([], $data['snapshots']);
        self::assertSame(SnapshotStore::DEFAULT_RETENTION_DAYS, $data['retention']);
    }

    public function testThePageThatIsRenderedIsTheOneTheInstallationShips(): void
    {
        $view = $this->controller($this->snapshotter($this->installation()))->indexAction()['$view'];

        self::assertIsArray($view);
        self::assertSame('installer:views/snapshots.php', $view['name']);
        self::assertFileExists(strtr(dirname(__DIR__, 3), '\\', '/').'/app/installer/views/snapshots.php');
    }

    // ------------------------------------------------------------------
    // Putting a snapshot back
    // ------------------------------------------------------------------

    public function testRestoringPutsThePackageBackAndClearsWhatTheInstallationHadCached(): void
    {
        $connection = $this->installation();
        $id = $this->take($connection);
        $controller = $this->controller($this->snapshotter($connection));

        // The removal the snapshot was taken for: the files are out of the live
        // tree and the configuration no longer says the extension is installed.
        (new Filesystem())->delete($this->tree);
        $connection->update('pk_system_config', ['value' => '{"packages":{}}'], ['name' => 'system']);

        self::assertSame(['message' => 'success'], $controller->restoreAction($id));

        self::assertFileExists($this->tree.'/composer.json');
        self::assertStringContainsString(
            '"test-ext":"1.4.2"',
            (string) $connection->fetchOne('SELECT value FROM pk_system_config WHERE name = ?', ['system']),
        );

        // What the panel and the site load is cached, and this replaced the
        // configuration both of them were built from - down to which extensions
        // are enabled and which theme the site is on.
        self::assertSame(1, $this->cache->clears);
    }

    public function testARestoredSnapshotIsKeptSoItCanBeRunAgain(): void
    {
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->controller($this->snapshotter($connection))->restoreAction($id);

        self::assertArrayHasKey($id, $this->store()->list());
    }

    public function testARestoreThatDidNotHappenIsReportedAndTheSnapshotIsLeftWhereItIs(): void
    {
        // A store that has lost the dump out of a snapshot, as an interrupted
        // write or a hand in the directory leaves it. There is nothing to put
        // back, and the administrator has to hear that rather than be told the
        // installation was restored.
        $connection = $this->installation();
        $id = $this->take($connection);

        unlink($this->file($id, SnapshotStore::DUMP_FILE));

        $answer = $this->controller($this->snapshotter($connection))->restoreAction($id);

        self::assertTrue($answer['error']);
        self::assertStringContainsString('Test Extension', (string) $answer['message']);
        self::assertStringContainsString('error log', (string) $answer['message']);

        // Nothing was replaced, so nothing the installation loads changed - and
        // the snapshot is still there for whoever looks at the log line.
        self::assertSame(0, $this->cache->clears);
        self::assertArrayHasKey($id, $this->store()->list());
        self::assertTrue($this->reported($id, 'could not be restored'));
    }

    // ------------------------------------------------------------------
    // Destroying a snapshot
    // ------------------------------------------------------------------

    public function testPurgingDestroysTheSnapshotAndLeavesWhatTheInstallationLoadsAlone(): void
    {
        $connection = $this->installation();
        $id = $this->take($connection);

        self::assertSame(['message' => 'success'], $this->controller($this->snapshotter($connection))->purgeAction($id));

        self::assertDirectoryDoesNotExist($this->snapshots.'/'.$id);
        self::assertSame([], $this->store()->list());

        // Nothing that was in that directory was ever loaded by the
        // installation, so there is nothing cached to rebuild - unlike a
        // restore, which rewrites what the panel was built from.
        self::assertSame(0, $this->cache->clears);
    }

    public function testASnapshotThatWouldNotGoIsReportedWithoutSayingWhatRefused(): void
    {
        $connection = $this->installation();
        $id = $this->take($connection);

        $answer = $this->controller($this->snapshotter($connection, new ASnapshotThatWillNotGo()))->purgeAction($id);

        // Which snapshot, what it was for, and where the rest of it is. What
        // actually refused is a path or a permission, and that is read
        // deliberately in the log rather than shown to a browser.
        self::assertTrue($answer['error']);
        self::assertStringContainsString('Test Extension', (string) $answer['message']);
        self::assertStringContainsString('error log', (string) $answer['message']);
        self::assertStringNotContainsString('restored from', (string) $answer['message']);
        self::assertStringNotContainsString($this->snapshots, (string) $answer['message']);

        self::assertTrue($this->reported($id, 'could not be purged'));
    }

    public function testNothingLeftToTakeTheLineDoesNotStopTheAdministratorBeingTold(): void
    {
        // The operation did not do what it was asked, and a log that cannot take
        // the line does not get to turn that into a page that says nothing at
        // all: an administrator staring at a snapshot that is still listed needs
        // the message either way.
        $connection = $this->installation();
        $id = $this->take($connection);

        $controller = new SnapshotController(
            $this->modules(),
            new ALogThatCannotBeWritten(),
            $this->snapshotter($connection, new ASnapshotThatWillNotGo()),
        );

        $answer = $controller->purgeAction($id);

        self::assertTrue($answer['error']);
        self::assertStringContainsString('Test Extension', (string) $answer['message']);
        self::assertArrayHasKey($id, $this->store()->list());
    }

    // ------------------------------------------------------------------
    // The window, on demand
    // ------------------------------------------------------------------

    public function testReclaimingTheExpiredSnapshotsAnswersWithTheOnesThatAreGone(): void
    {
        // The page takes the answer at its word and drops those rows, so an id
        // in it that is still on the disk would leave an administrator believing
        // a database dump was destroyed.
        // The snapshot to keep is taken first: taking one prunes the store on its
        // way in, so an expired snapshot placed before it would be gone before
        // the administrator ever asked.
        $connection = $this->installation();
        $kept = $this->take($connection);
        $expired = $this->place('20260101-000000-old-ext-a1b2c3d4', time() - 31 * self::DAY, 'old-ext');

        $answer = $this->controller($this->snapshotter($connection))->purgeExpiredAction();

        self::assertSame('success', $answer['message']);
        self::assertSame([$expired], $answer['purged']);
        self::assertSame([$kept], array_keys($this->store()->list()));
    }

    // ------------------------------------------------------------------
    // An id that names no snapshot
    // ------------------------------------------------------------------

    #[DataProvider('provideIdsThatNameNoSnapshot')]
    public function testAnIdThatNamesNoSnapshotIsARefusedRequestRatherThanAPath(string $id): void
    {
        $connection = $this->installation();
        $kept = $this->take($connection);
        $controller = $this->controller($this->snapshotter($connection));

        // An id arrives in a request, so a traversal, an absolute path or a name
        // with a null byte in it is not a snapshot that cannot be found but a
        // name that is refused - before anything reads the disk on its behalf.
        $this->refused(static fn () => $controller->restoreAction($id), $id);
        $this->refused(static fn () => $controller->purgeAction($id), $id);

        self::assertSame([$kept], array_keys($this->store()->list()));
        self::assertSame(0, $this->cache->clears);

        // What the id was pointing at, still there: the store resolves only the
        // snapshots it lists, so nothing beside it is reachable by name.
        self::assertFileExists($this->outside.'/keep-me.txt');
        self::assertFileExists($this->tree.'/composer.json');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideIdsThatNameNoSnapshot(): array
    {
        return [
            'an id nothing in the store goes by' => ['20200101-000000-test-ext-c0ffee00'],
            'an id that reads as a way out of the store' => ['../outside'],
            'an id that reads deeper out of it' => ['../../packages/pagekit/test-ext'],
            'an id that reads as a path of its own' => ['/etc/passwd'],
            'an id with a null byte in it' => ["20200101-000000-test-ext-c0ffee00\0"],
            'an id that is nothing but a dot segment' => ['..'],
        ];
    }

    public function testAnOperationAskedForNoSnapshotAtAllIsRefused(): void
    {
        // What arrives when the page posts nothing under that name. It is the
        // default the route carries, so it reaches the action as a request for a
        // snapshot with no id - which is no snapshot.
        $connection = $this->installation();
        $controller = $this->controller($this->snapshotter($connection));

        $this->refused(static fn () => $controller->restoreAction());
        $this->refused(static fn () => $controller->purgeAction());

        self::assertSame(0, $this->cache->clears);
    }

    public function testAnInstallationThatKeepsNoSnapshotsRefusesToActOnOne(): void
    {
        // Nothing to act on rather than nothing done: an environment with no
        // snapshot store answers that it keeps none, instead of reporting a
        // restore or a purge that never happened.
        $controller = $this->controller(null);

        $this->refused(static fn () => $controller->restoreAction('20200101-000000-test-ext-c0ffee00'));
        $this->refused(static fn () => $controller->purgeAction('20200101-000000-test-ext-c0ffee00'));
        $this->refused(static fn () => $controller->purgeExpiredAction());

        self::assertSame(0, $this->cache->clears);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Runs an operation that must not go through, and asserts how it is refused.
     *
     * A bad request rather than a failure: the installation is fine, the id is
     * not one of its snapshots. And the value stays out of the answer, which is
     * read back by whoever sent it.
     */
    private function refused(callable $operation, ?string $id = null): void
    {
        $thrown = null;

        try {
            $operation();
        } catch (BadRequestHttpException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(BadRequestHttpException::class, $thrown, 'An id that names no snapshot is a refused request');
        self::assertSame(400, $thrown->getStatusCode());

        if ($id !== null) {
            self::assertStringNotContainsString($id, $thrown->getMessage());
        }
    }

    /**
     * The controller as the panel builds it.
     *
     * @param int|null $retention the window this installation configures, or null
     *                            where the installer module is not one this
     *                            environment loaded
     */
    private function controller(?PackageSnapshotter $snapshotter, ?int $retention = null): SnapshotController
    {
        return new SnapshotController(
            $this->modules($retention),
            new Logger('test', [$this->records]),
            $snapshotter,
        );
    }

    /**
     * What the page was handed to render itself from.
     *
     * @return array<string, mixed>
     */
    private function page(SnapshotController $controller): array
    {
        $data = $controller->indexAction()['$data'];

        self::assertIsArray($data);

        return $data;
    }

    /**
     * The modules of the installation the controller runs in: the cache it
     * clears, and the module that says how long a snapshot is kept.
     *
     * @param int|null $retention the configured window, or null for an
     *                            installation that has not configured one
     */
    private function modules(?int $retention = null): ModuleManager
    {
        $installer = $retention === null ? null : new Module([
            'name' => 'installer',
            'path' => $this->workspace,
            'config' => ['snapshots' => ['retention_days' => $retention]],
        ]);

        $modules = $this->createMock(ModuleManager::class);
        $modules->method('get')->willReturnCallback(fn (string $name): mixed => match ($name) {
            'system/cache' => $this->cache,
            'installer' => $installer,
            default => null,
        });

        return $modules;
    }

    /**
     * The snapshotter as the installer builds it, over a real store and a real
     * database.
     *
     * @param Filesystem|null $files the filesystem as the test needs it to
     *                               behave, where that is what is under test
     */
    private function snapshotter(Connection $connection, ?Filesystem $files = null): PackageSnapshotter
    {
        $files ??= new Filesystem();

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
     * A snapshot to act on, taken the way a removal takes one.
     */
    private function take(Connection $connection): string
    {
        return $this->snapshotter($connection)->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);
    }

    /**
     * Puts a finished snapshot in the store that was taken at a given moment,
     * the way one an administrator finds weeks later arrived.
     */
    private function place(string $id, int $taken, string $module): string
    {
        $directory = $this->snapshots.'/'.$id;

        mkdir($directory, 0700, true);

        file_put_contents($directory.'/'.SnapshotStore::METADATA_FILE, (string) json_encode([
            'id' => $id,
            'created' => $taken,
            'package' => 'pagekit/'.$module,
            'module' => $module,
            'title' => 'Test Extension',
            'type' => 'pagekit-extension',
            'version' => '1.4.2',
            'composer' => false,
            'reason' => PackageSnapshotter::REASON_UNINSTALL,
            'format' => DumpFormat::VERSION,
            'database' => ['driver' => 'pdo_sqlite', 'platform' => 'sqlite', 'prefix' => 'pk_'],
        ]));

        file_put_contents($directory.'/'.SnapshotStore::DUMP_FILE, str_repeat('x', 1024));

        // The last thing the removal that took it wrote, and the whole of what
        // says the rest of the directory is all there.
        file_put_contents($directory.'/'.SnapshotStore::COMPLETE_FILE, gmdate('c')."\n");

        return $id;
    }

    /**
     * The package as the factory reads it out of a manifest on disk.
     */
    private function package(): Package
    {
        return new Package([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'module' => 'test-ext',
            'title' => 'Test Extension',
            'version' => '1.4.2',
            'path' => $this->tree,
        ]);
    }

    /**
     * Whether what the answer withheld reached the log, with the throwable that
     * carries the rest of it.
     */
    private function reported(string $id, string $context): bool
    {
        foreach ($this->records->getRecords() as $record) {
            if (str_contains($record->message, $id) && str_contains($record->message, $context)) {
                return ($record->context['exception'] ?? null) instanceof \Throwable;
            }
        }

        return false;
    }

    /**
     * A database with something in it worth putting aside, and something in it
     * that may not leave it.
     */
    private function installation(): Connection
    {
        $connection = $this->openDatabase();

        $config = new Table('pk_system_config');
        $config->addColumn('name', Types::STRING, ['length' => 64]);
        $config->addColumn('value', Types::TEXT, ['notnull' => false]);
        $config->setPrimaryKey(['name']);

        $connection->createSchemaManager()->createTable($config);
        $connection->insert('pk_system_config', [
            'name' => 'system',
            'value' => '{"packages":{"test-ext":"1.4.2"},"extensions":["test-ext"],"secret":"'.self::SECRET.'"}',
        ]);

        return $connection;
    }

    private function store(): SnapshotStore
    {
        return new SnapshotStore($this->snapshots, new Filesystem());
    }

    private function file(string $id, string $name): string
    {
        return $this->snapshots.'/'.$id.'/'.$name;
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

/**
 * What the installation had cached, and whether an operation asked for it to be
 * rebuilt.
 *
 * A recorder rather than a mock: the operations under test run inside a catch
 * for anything they raise, and a mock reporting a violated expectation would
 * throw where that catch reads it as the operation's own failure.
 */
final class RecordedCacheClears
{
    public int $clears = 0;

    /**
     * @param array<string, mixed> $options
     */
    public function clearCache(array $options = []): void
    {
        $this->clears += 1;
    }
}

/**
 * A log that is itself broken, as one writing to a full disk or into a directory
 * that went away is.
 *
 * Broken at the one point every level and every shortcut goes through, so it is
 * a log that cannot take a line rather than one that refuses the single method
 * the caller happens to use today.
 */
final class ALogThatCannotBeWritten extends Logger
{
    public function __construct()
    {
        parent::__construct('test');
    }

    /**
     * @param array<string, mixed> $context
     */
    public function addRecord(int|Level $level, string $message, array $context = [], ?JsonSerializableDateTimeImmutable $datetime = null): bool
    {
        throw new \RuntimeException('The log could not be written.');
    }
}
