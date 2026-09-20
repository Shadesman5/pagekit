<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Pagekit\Database\Connection;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Package\Package;
use Pagekit\Installer\Package\Snapshot\DatabaseDumper;
use Pagekit\Installer\Package\Snapshot\DatabaseRestorer;
use Pagekit\Installer\Package\Snapshot\DumpFormat;
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Installer\Package\Snapshot\SnapshotStore;
use PHPUnit\Framework\TestCase;

/**
 * How long a removal stays undoable, and what stops the snapshots it leaves
 * behind from filling the disk.
 *
 * Every removal writes a copy of a package and of the whole database it was
 * installed in, and nothing about a site says when the administrator has stopped
 * wanting it back. So a window is configured, and once it has run out on a
 * snapshot that snapshot may be reclaimed - which is the same irreversible
 * operation as a purge somebody asked for, reached without anybody asking.
 *
 * Nothing runs it on a timer. It runs when the next snapshot is taken, so the
 * operation that makes the store grow is the one that prunes it, and it runs when
 * an administrator asks. An installation that removes no packages and asks for
 * nothing therefore keeps everything, which is the direction to err in for
 * something nobody can undo.
 *
 * Two things follow from being destructive and unattended. Only what the window
 * has actually run out on may go, because everything else is a package somebody
 * can still get back. And every reclaimed snapshot has to be on the record, since
 * that line is all that is left of it - while disk that could not be reclaimed
 * must not cost the removal that was in the middle of writing a new way back.
 *
 * The store is a real directory and the snapshots in it are real snapshots, so
 * what is reclaimed here is what an installation actually reclaims.
 */
final class SnapshotRetentionTest extends TestCase
{
    use SnapshotDatabase;

    private const DAY = 86400;

    /**
     * Something in the database that only the database and its dump should ever
     * hold, so a listing that leaks the way back's contents can be told from one
     * that describes it.
     */
    private const SECRET = 'a-hash-only-the-database-holds';

    private string $workspace;

    /**
     * Where the snapshots go. Not created in setUp(): an installation that never
     * removed a package has no such directory, and asking it to reclaim disk is
     * no reason to grow one.
     */
    private string $snapshots;

    private string $packages;

    /**
     * The package on disk, in the vendor/name shape packages/ gives it.
     */
    private string $tree;

    /**
     * The trail the operations under test leave. Reclaiming a snapshot is as
     * final as purging one, so it is accounted for the same way.
     */
    private SnapshotAudit $log;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_snapshot_retention_'.getmypid().'_'.uniqid();
        $this->snapshots = $this->workspace.'/snapshots';
        $this->packages = $this->workspace.'/packages';
        $this->tree = $this->packages.'/pagekit/test-ext';
        $this->log = new SnapshotAudit();

        mkdir($this->tree.'/views', 0755, true);

        file_put_contents($this->tree.'/composer.json', (string) json_encode([
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'version' => '1.4.2',
        ]));
        file_put_contents($this->tree.'/views/extension.php', "<?php\n\nreturn [];\n");
    }

    protected function tearDown(): void
    {
        $this->closeDatabases();
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // What the window reclaims, and when
    // ------------------------------------------------------------------

    public function testTakingASnapshotReclaimsTheOnesTheWindowHasRunOutOn(): void
    {
        // The store grows by one snapshot per removal and nothing else ever
        // looks at it, so the removal is where the disk of the snapshots nobody
        // can use any more is handed back.
        $expired = '20260101-000000-old-ext-a1b2c3d4';
        $kept = '20260102-000000-other-ext-b2c3d4e5';

        $this->place($expired, time() - 31 * self::DAY, 'old-ext');
        $this->place($kept, time() - 2 * self::DAY, 'other-ext');

        $snapshotter = $this->snapshotter($this->installation(), 30);
        $id = $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        self::assertDirectoryDoesNotExist($this->snapshots.'/'.$expired);
        self::assertSame([$id, $kept], array_keys($snapshotter->list()));

        // Still a way back, in full: a window that is not up yet is a package an
        // administrator can still ask for.
        self::assertFileExists($this->file($kept, SnapshotStore::DUMP_FILE));

        // The disk goes back before the new snapshot needs it, which is the
        // whole reason the prune sits in this operation rather than after it.
        self::assertCount(2, $this->log->records);
        self::assertStringContainsString($expired, $this->log->records[0]['message']);
        self::assertStringContainsString($id, $this->log->records[1]['message']);
    }

    public function testASnapshotIsReclaimedTheMomentItsWindowIsUpAndNotBefore(): void
    {
        // Where the line falls is a documented rule rather than a detail: a
        // snapshot is reclaimable as soon as the window has passed, not on the
        // following day, so what an administrator was shown as its expiry is
        // when it can actually go.
        $upToTheMoment = '20260101-000000-blog-a1b2c3d4';
        $aWhileToGo = '20260102-000000-pages-b2c3d4e5';

        $this->place($upToTheMoment, time() - 14 * self::DAY, 'blog');
        $this->place($aWhileToGo, time() - 14 * self::DAY + 300, 'pages');

        $purged = $this->snapshotter($this->installation(), 14)->purgeExpired();

        self::assertSame([$upToTheMoment], $purged);
        self::assertDirectoryExists($this->snapshots.'/'.$aWhileToGo);
    }

    public function testAnInstallationThatKeepsEverySnapshotReclaimsNone(): void
    {
        // Retention turned off is an administrator saying that nothing is to be
        // deleted behind their back. A prune that ran anyway would destroy
        // restorable data against an explicit instruction.
        $ancient = '20200101-000000-old-ext-a1b2c3d4';

        $this->place($ancient, time() - 3650 * self::DAY, 'old-ext');

        $snapshotter = $this->snapshotter($this->installation(), 0);

        self::assertSame([], $snapshotter->purgeExpired());

        $id = $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);
        $snapshots = $snapshotter->list();

        self::assertSame([$id, $ancient], array_keys($snapshots));
        self::assertNull($snapshots[$ancient]['expires'], 'A snapshot with no window has no date to show');
        self::assertCount(1, $this->log->records, 'The only thing that happened is the snapshot that was taken');
    }

    public function testAnAdministratorCanReclaimTheExpiredSnapshotsWithoutRemovingAPackage(): void
    {
        // The other half of enforcing a window without a scheduler: an
        // installation that has stopped removing packages would otherwise sit on
        // every snapshot it ever took.
        $expired = '20260101-000000-old-ext-a1b2c3d4';
        $kept = '20260102-000000-other-ext-b2c3d4e5';

        $this->place($expired, time() - 31 * self::DAY, 'old-ext');
        $this->place($kept, time() - 2 * self::DAY, 'other-ext');

        $purged = $this->snapshotter($this->installation(), 30)->purgeExpired();

        self::assertSame([$expired], $purged);
        self::assertSame([$kept], array_keys($this->store()->list()));
    }

    public function testAnInstallationWithNothingToReclaimIsLeftAsItWas(): void
    {
        // Asked of an installation that has never removed a package. Answering
        // it must not fail and must not create the store either: an
        // administrator looking at an empty page is not a reason to leave a
        // directory behind on the disk.
        $snapshotter = $this->snapshotter($this->installation(), 30);

        self::assertSame([], $snapshotter->purgeExpired());
        self::assertSame([], $snapshotter->list());
        self::assertDirectoryDoesNotExist($this->snapshots);
        self::assertSame([], $this->log->records);
    }

    // ------------------------------------------------------------------
    // The record of what was reclaimed
    // ------------------------------------------------------------------

    public function testWhatTheWindowReclaimedGoesOnRecordAsSomethingNobodyCanGetBack(): void
    {
        $id = '20260101-000000-test-ext-a1b2c3d4';

        $this->place($id, time() - 31 * self::DAY, 'test-ext');

        $this->snapshotter($this->installation(), 30)->purgeExpired();

        self::assertCount(1, $this->log->records);

        $record = $this->log->records[0];

        // Weeks later this line is the only thing left of the package, and it
        // has to say that nobody asked for this: an administrator who finds a
        // snapshot gone needs to know whether they or the window took it.
        self::assertStringContainsString($id, $record['message']);
        self::assertStringContainsString('pagekit/test-ext', $record['message']);
        self::assertStringContainsString('retention window', $record['message']);
        self::assertStringContainsString('not recoverable', $record['message']);
        self::assertSame($id, $record['context']['snapshot'] ?? null);
        self::assertSame('test-ext', $record['context']['package'] ?? null);
        self::assertSame('retention', $record['context']['trigger'] ?? null);
    }

    public function testEverySnapshotTheWindowReclaimedIsAccountedForOnItsOwn(): void
    {
        // A prune can reclaim a whole run of removals at once, and each of them
        // was a package somebody may come looking for. One line for the lot
        // would leave most of them with nothing to find.
        $now = time();

        $this->place('20260101-000000-blog-a1b2c3d4', $now - 31 * self::DAY, 'blog');
        $this->place('20260102-000000-pages-b2c3d4e5', $now - 40 * self::DAY, 'pages');
        $this->place('20260103-000000-forum-c3d4e5f6', $now - 90 * self::DAY, 'forum');

        $purged = $this->snapshotter($this->installation(), 30)->purgeExpired();
        sort($purged);

        self::assertSame([
            '20260101-000000-blog-a1b2c3d4',
            '20260102-000000-pages-b2c3d4e5',
            '20260103-000000-forum-c3d4e5f6',
        ], $purged);

        $reported = array_map(
            static fn (array $record): string => (string) ($record['context']['snapshot'] ?? ''),
            $this->log->records,
        );
        sort($reported);

        self::assertSame($purged, $reported);
        self::assertSame([], $this->store()->list());
    }

    // ------------------------------------------------------------------
    // Disk the window could not reclaim
    // ------------------------------------------------------------------

    public function testASnapshotThatWillNotGoIsReportedAndLeftWhereItIs(): void
    {
        // Expired, still on the disk, and no longer a way back to anything: the
        // mark comes off before the removal walks the tree, so a removal that
        // failed after that leaves a directory nothing restores from. The disk
        // it holds is not handed back either, and an operator has to be told -
        // because reclaiming it is now something only they can do.
        $id = '20260101-000000-old-ext-a1b2c3d4';

        $this->place($id, time() - 31 * self::DAY, 'old-ext');

        $snapshotter = $this->snapshotter($this->installation(), 30, new ASnapshotThatWillNotGo());

        self::assertSame([], $snapshotter->purgeExpired(), 'Nothing was reclaimed, so nothing is reported as reclaimed');

        $left = $this->store()->list();

        self::assertArrayHasKey($id, $left, 'The disk it holds is still spent, so it is still on the inventory');
        self::assertFalse($left[$id]['complete'], 'What a failed removal left is not offered as a package that can be brought back');
        self::assertFileExists($this->file($id, SnapshotStore::DUMP_FILE));

        self::assertCount(1, $this->log->records);

        $record = $this->log->records[0];

        self::assertSame('warning', $record['level']);
        self::assertStringContainsString($id, $record['message']);
        self::assertStringContainsString('not reclaimed', $record['message']);
        self::assertSame('retention', $record['context']['trigger'] ?? null);
    }

    public function testDiskThatCouldNotBeReclaimedDoesNotCostTheSnapshotBeingTaken(): void
    {
        // The prune runs in the middle of a removal, which is writing the only
        // copy of a package. Failing that removal over disk that could not be
        // handed back would trade a way back for a number on a mount.
        $stubborn = '20260101-000000-old-ext-a1b2c3d4';

        $this->place($stubborn, time() - 31 * self::DAY, 'old-ext');

        $snapshotter = $this->snapshotter($this->installation(), 30, new ASnapshotThatWillNotGo());
        $id = $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        // The whole of the new way back, marked as one, rather than a directory
        // that was opened and then abandoned over the prune.
        self::assertFileExists($this->file($id, SnapshotStore::METADATA_FILE));
        self::assertFileExists($this->file($id, SnapshotStore::DUMP_FILE));
        self::assertFileExists($this->file($id, SnapshotStore::FILES_DIR).'/pagekit/test-ext/composer.json');
        self::assertFileExists($this->file($id, SnapshotStore::COMPLETE_FILE));

        self::assertDirectoryExists($this->snapshots.'/'.$stubborn);
        self::assertSame('warning', $this->log->records[0]['level']);
        self::assertStringContainsString($id, $this->log->records[1]['message']);
    }

    public function testASnapshotSomethingElseReclaimedFirstIsNotAccountedForTwice(): void
    {
        // Two removals can be running at once, and the second one lists a
        // snapshot the first is already destroying. Reporting it as reclaimed
        // here would put a package on the record twice and count disk this call
        // never handed back.
        $this->place('20260101-000000-blog-a1b2c3d4', time() - 31 * self::DAY, 'blog');
        $this->place('20260102-000000-pages-b2c3d4e5', time() - 40 * self::DAY, 'pages');

        $snapshotter = $this->snapshotter(
            $this->installation(),
            30,
            new APurgeSomebodyElseGotToFirst($this->snapshots),
        );

        $purged = $snapshotter->purgeExpired();

        self::assertCount(1, $purged, 'What this call destroyed is what it accounts for');
        self::assertCount(1, $this->log->records);
        self::assertStringContainsString($purged[0], $this->log->records[0]['message']);
        self::assertSame([], $this->store()->list(), 'Between the two of them the store is empty');
    }

    // ------------------------------------------------------------------
    // What an operator watching the store has to go on
    // ------------------------------------------------------------------

    public function testTheListSaysHowMuchDiskEachSnapshotHoldsAndWhenItMayBeReclaimed(): void
    {
        // Nothing caps the store, so this pair is the whole of what tells an
        // operator that a retention window is too long for the disk they have.
        $older = '20260101-000000-other-ext-a1b2c3d4';

        $this->place($older, time() - 2 * self::DAY, 'other-ext');

        $snapshotter = $this->snapshotter($this->installation(), 7);
        $id = $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        $snapshots = $snapshotter->list();

        self::assertSame([$id, $older], array_keys($snapshots), 'Newest first: the one to undo is the one just taken');

        foreach ($snapshots as $snapshot) {
            self::assertSame($snapshot['created'] + 7 * self::DAY, $snapshot['expires']);
            self::assertGreaterThan(0, $snapshot['size']);
        }
    }

    public function testNoPartOfTheDatabaseInASnapshotIsHandedOutWithTheListOfThem(): void
    {
        // The dump is the site's whole database, password hashes included, and
        // whoever is looking at a list of snapshots is choosing one rather than
        // reading one.
        $snapshotter = $this->snapshotter($this->installation(), 30);
        $id = $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        self::assertStringContainsString(
            self::SECRET,
            (string) file_get_contents($this->file($id, SnapshotStore::DUMP_FILE)),
            'The dump is what holds the database, so the leak this guards against is a real one',
        );
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($snapshotter->list()));
    }

    // ------------------------------------------------------------------
    // The installation a window is enforced against
    // ------------------------------------------------------------------

    /**
     * The snapshotter as the installer builds it, over a real store and a real
     * database.
     *
     * @param int             $days  how long this installation keeps a snapshot
     * @param Filesystem|null $files the filesystem as the test needs it to
     *                               behave, where that is what is under test
     */
    private function snapshotter(Connection $connection, int $days, ?Filesystem $files = null): PackageSnapshotter
    {
        $files ??= new Filesystem();

        return new PackageSnapshotter(
            new SnapshotStore($this->snapshots, $files, $days),
            new DatabaseDumper($connection),
            new DatabaseRestorer($connection),
            $files,
            $this->log,
            $this->packages,
        );
    }

    /**
     * A database with something in it worth putting aside, so the snapshots a
     * window acts on hold a dump the way real ones do.
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
            'value' => '{"packages":{"test-ext":"1.4.2"},"secret":"'.self::SECRET.'"}',
        ]);

        return $connection;
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
     * Puts a finished snapshot in the store that was taken at a given moment.
     * Every snapshot a window acts on arrives that way: written by the removal
     * that took it, marked as a way back by the last step of that write, and
     * read back by whatever reclaims it weeks later.
     */
    private function place(string $id, int $taken, string $module): void
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

        // The disk the snapshot is holding, which is what a window exists to
        // hand back.
        file_put_contents($directory.'/'.SnapshotStore::DUMP_FILE, str_repeat('x', 1024));

        // Written last by the removal that took it, which is what makes this a
        // snapshot somebody could still restore rather than the leftovers of an
        // interrupted write.
        file_put_contents($directory.'/'.SnapshotStore::COMPLETE_FILE, gmdate('c')."\n");
    }

    private function store(int $days = SnapshotStore::DEFAULT_RETENTION_DAYS): SnapshotStore
    {
        return new SnapshotStore($this->snapshots, new Filesystem(), $days);
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
 * A store a second hand is already clearing out: the moment one snapshot is
 * being destroyed, the others listed with it are gone.
 *
 * Which is what two removals running at once look like from inside either of
 * them - the list a prune works from is read before the first snapshot on it is
 * destroyed, and by the time it reaches the last one somebody else may have had
 * it.
 */
final class APurgeSomebodyElseGotToFirst extends Filesystem
{
    private bool $raced = false;

    /**
     * @param string $store the directory the snapshots live in
     */
    public function __construct(private readonly string $store)
    {
    }

    /**
     * @param string|array<int, string> $files
     */
    public function delete($files): bool
    {
        if (!$this->raced) {
            $this->raced = true;

            // Everything but what is being destroyed here, so that this call
            // still destroys the one snapshot it was asked about.
            foreach (glob($this->store.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
                if (!is_string($files) || basename($directory) !== basename($files)) {
                    parent::delete($directory);
                }
            }
        }

        return parent::delete($files);
    }
}
