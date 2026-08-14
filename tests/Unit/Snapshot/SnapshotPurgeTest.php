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
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Installer\Package\Snapshot\SnapshotStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Destroying a snapshot, which is the only step of a removal that cannot be taken
 * back.
 *
 * Everything before this point is reversible: a package taken out of the
 * installation is put aside rather than deleted, and it can be brought back for as
 * long as what was put aside is still there. Purging is what ends that - the
 * files, the dump of the database they were removed from and the description of
 * both go, and after it nobody can bring that package back.
 *
 * Two things follow from being irreversible. It has to be honest about whether it
 * happened: a snapshot reported as purged while part of it is still on disk is a
 * directory holding a whole database that an operator believes is gone, and one
 * reported as purged that never existed sends them looking for what they asked
 * about. And it has to be on the record, because weeks later that line is the only
 * thing left of the package.
 *
 * The store is a real directory and the snapshot in it a real one, so what is
 * destroyed here is what a purge actually destroys.
 */
final class SnapshotPurgeTest extends TestCase
{
    use SnapshotDatabase;

    private string $workspace;

    private string $snapshots;

    private string $packages;

    /**
     * The package on disk, in the vendor/name shape packages/ gives it.
     */
    private string $tree;

    /**
     * The trail the operation under test leaves. The snapshots each test starts
     * from are taken with a log of their own, so what is read back here is what
     * the purge said and nothing else.
     */
    private SnapshotAudit $log;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_snapshot_purge_'.getmypid().'_'.uniqid();
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
    // What a purge destroys
    // ------------------------------------------------------------------

    public function testPurgingASnapshotDestroysEverythingThatWasInIt(): void
    {
        $connection = $this->installation();
        $id = $this->take($connection, 'test-ext');

        $this->snapshotter($connection)->purge($id);

        // Not merely off the list: the dump in it holds every password hash on
        // the site, and a directory left behind is that dump left behind.
        self::assertDirectoryDoesNotExist($this->snapshots.'/'.$id);
        self::assertNull($this->store()->get($id));
        self::assertSame([], $this->store()->list());
    }

    public function testPurgingOneSnapshotLeavesTheOthersWhereTheyAre(): void
    {
        // An administrator reclaiming the disk one snapshot at a time is asking
        // about that one. The rest are other packages' ways back.
        $connection = $this->installation();
        $kept = $this->take($connection, 'test-ext');
        $purged = $this->take($connection, 'other-ext');

        $this->snapshotter($connection)->purge($purged);

        self::assertSame([$kept], array_keys($this->store()->list()));
        self::assertFileExists($this->file($kept, SnapshotStore::DUMP_FILE));
    }

    public function testAPurgedSnapshotIsNoLongerSomethingAPackageCanBeRestoredFrom(): void
    {
        // The point of the whole operation, from the other end: this is the line
        // after which the removal it was taken for is as final as a deletion.
        $connection = $this->installation();
        $id = $this->take($connection, 'test-ext');

        $snapshotter = $this->snapshotter($connection);
        $snapshotter->purge($id);

        $thrown = null;

        try {
            $snapshotter->restore($id);
        } catch (\InvalidArgumentException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\InvalidArgumentException::class, $thrown);
    }

    // ------------------------------------------------------------------
    // A purge that did not happen
    // ------------------------------------------------------------------

    #[DataProvider('provideIdsThatNameNoSnapshot')]
    public function testAnIdThatNamesNoSnapshotIsRefusedWithoutRepeatingIt(string $id): void
    {
        // An id is what a purge is asked for, so it comes in from a request: one
        // that reads as a traversal, an absolute path or a name with a null byte
        // in it is refused as a name rather than deleted as a directory. And the
        // value stays out of the message, which is read back by whoever sent it.
        $connection = $this->installation();
        $kept = $this->take($connection, 'test-ext');

        $snapshotter = $this->snapshotter($connection);
        $thrown = null;

        try {
            $snapshotter->purge($id);
        } catch (\InvalidArgumentException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\InvalidArgumentException::class, $thrown);
        self::assertStringNotContainsString($id, $thrown->getMessage());
        self::assertSame([$kept], array_keys($this->store()->list()));
        self::assertSame([], $this->log->records, 'Nothing was destroyed, so there is nothing to account for');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideIdsThatNameNoSnapshot(): array
    {
        return [
            'an id nothing in the store goes by' => ['20200101-000000-test-ext-c0ffee00'],
            'an id that reads as a way out of the store' => ['../../etc'],
            'an id that reads as a path of its own' => ['/etc/passwd'],
            'an id with a null byte in it' => ["20200101-000000-test-ext-c0ffee00\0"],
        ];
    }

    public function testASnapshotThatCouldNotBeRemovedIsReportedRatherThanCountedAsGone(): void
    {
        // A purge that got part of the way leaves a directory that is no longer
        // something a package can be restored from, and the administrator has to
        // hear that: the way back is gone either way, and what is left of it
        // still has to be cleared by hand.
        $connection = $this->installation();
        $id = $this->take($connection, 'test-ext');

        $snapshotter = $this->snapshotter($connection, new ASnapshotThatWillNotGo());
        $thrown = null;

        try {
            $snapshotter->purge($id);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $thrown);
        self::assertStringContainsString($id, $thrown->getMessage());
        self::assertStringContainsString('restored from', $thrown->getMessage());
        self::assertSame([], $this->log->records, 'A snapshot that is still there is not one that was destroyed');
    }

    // ------------------------------------------------------------------
    // The trail the irreversible step leaves
    // ------------------------------------------------------------------

    public function testWhatWasDestroyedAndWhatAskedForItGoesOnRecord(): void
    {
        $connection = $this->installation();
        $id = $this->take($connection, 'test-ext');

        $this->snapshotter($connection)->purge($id);

        self::assertCount(1, $this->log->records);

        $record = $this->log->records[0];

        // Weeks later this line is the only thing left of the package, so it has
        // to say which snapshot went, which package it held, and that nothing of
        // it can be recovered.
        self::assertStringContainsString($id, $record['message']);
        self::assertStringContainsString('pagekit/test-ext', $record['message']);
        self::assertStringContainsString('not recoverable', $record['message']);
        self::assertSame($id, $record['context']['snapshot'] ?? null);
        self::assertSame('test-ext', $record['context']['package'] ?? null);
        self::assertSame('request', $record['context']['trigger'] ?? null);
    }

    public function testNothingLeftToTakeTheRecordDoesNotCostThePurge(): void
    {
        // Reclaiming the disk is what was asked for, and by the time the line is
        // written the snapshot is already gone. A log that cannot take it would
        // otherwise report a failure for an operation that succeeded.
        $connection = $this->installation();
        $id = $this->take($connection, 'test-ext');

        $this->snapshotter($connection, null, new AuditThatCannotBeWritten())->purge($id);

        self::assertSame([], $this->store()->list());
    }

    // ------------------------------------------------------------------
    // The store a purge runs against
    // ------------------------------------------------------------------

    /**
     * The snapshotter as the installer builds it, over a real store and a real
     * database.
     *
     * @param Filesystem|null     $files the filesystem as the test needs it to
     *                                   behave, where that is what is under test
     * @param AbstractLogger|null $log   where the trail goes, for the test about
     *                                   what happens when it cannot be written
     */
    private function snapshotter(Connection $connection, ?Filesystem $files = null, ?AbstractLogger $log = null): PackageSnapshotter
    {
        $files ??= new Filesystem();

        return new PackageSnapshotter(
            new SnapshotStore($this->snapshots, $files),
            new DatabaseDumper($connection),
            new DatabaseRestorer($connection),
            $files,
            $log ?? $this->log,
            $this->packages,
        );
    }

    /**
     * A snapshot to destroy, taken the way a removal takes one.
     *
     * Its own log, so that every assertion about the trail is about the
     * operation under test.
     */
    private function take(Connection $connection, string $module): string
    {
        return $this->snapshotter($connection, null, new NullLogger())
            ->create($this->package($module), PackageSnapshotter::REASON_UNINSTALL);
    }

    /**
     * The package as the factory reads it out of a manifest on disk. Every
     * module's files are the one tree in the workspace: which package a snapshot
     * was taken of is what is asserted here, not what is archived in it.
     */
    private function package(string $module): Package
    {
        return new Package([
            'name' => 'pagekit/'.$module,
            'type' => 'pagekit-extension',
            'module' => $module,
            'title' => 'Test Extension',
            'version' => '1.4.2',
            'path' => $this->tree,
        ]);
    }

    /**
     * A database with something in it worth putting aside, so the snapshot a
     * purge destroys holds a dump the way a real one does.
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
            'value' => '{"packages":{"test-ext":"1.4.2"},"extensions":["test-ext"]}',
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
