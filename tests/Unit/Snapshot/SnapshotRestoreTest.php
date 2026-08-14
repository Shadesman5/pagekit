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
 * Putting a removed package back, which is the whole reason the removal before it
 * was a move rather than a deletion.
 *
 * A restore is not the reverse of the file copy alone. What an administrator asks
 * for is the extension they had: its files under packages/, its version key, its
 * place in the enabled list, the nodes its pages hang off. All but the first of
 * those live in the database, so the way back is the archived tree plus the dump -
 * and because the dump is of the whole installation, everything else in the
 * database goes back with it. Rows written since the removal are not in it and are
 * gone. That is the price of a one-click way back, and it is asserted here so that
 * nothing later reports a restore as a smaller operation than it is.
 *
 * The order is what keeps the installation readable in between: files first, then
 * the database. A package tree nothing enables is one the panel lists as not
 * installed, while a database naming an enabled extension whose files are missing
 * is a boot that fails. So a restore that gets halfway leaves the safer of the two
 * halves, reports the failure, and leaves the snapshot to be run again.
 *
 * The database is a real one and the files are real files, because what is
 * asserted is that a package actually comes back.
 */
final class SnapshotRestoreTest extends TestCase
{
    use SnapshotDatabase;

    /**
     * What the archived manifest says, which is what the tree on disk has to say
     * again once it is back.
     */
    private const MANIFEST = '{"name":"pagekit/test-ext","type":"pagekit-extension","version":"1.4.2"}';

    /**
     * The configuration as it stands with the extension installed: the version
     * key the panel reads it as installed from, and the list a boot runs it from.
     * It lives in the database, so the dump is the only way it comes back.
     */
    private const INSTALLED = '{"packages":{"test-ext":"1.4.2"},"extensions":["test-ext"]}';

    /**
     * The same configuration once the removal has been through it.
     */
    private const REMOVED = '{"packages":{},"extensions":[]}';

    /**
     * Composer's record as it lies under packages/, naming the package that was
     * removed.
     */
    private const BOOKKEEPING = '[{"name":"pagekit/test-ext","version":"1.4.2","type":"pagekit-extension"}]';

    private string $workspace;

    private string $snapshots;

    /**
     * Where runtime-installed packages live, which is where a restored tree has
     * to land.
     */
    private string $packages;

    /**
     * The package on disk, in the vendor/name shape packages/ gives it.
     */
    private string $tree;

    /**
     * The trail the operation under test leaves. The snapshot each test starts
     * from is taken with a log of its own, so what is read back here is what the
     * restore said and nothing else.
     */
    private SnapshotAudit $log;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_snapshot_restore_'.getmypid().'_'.uniqid();
        $this->snapshots = $this->workspace.'/snapshots';
        $this->packages = $this->workspace.'/packages';
        $this->tree = $this->packages.'/pagekit/test-ext';
        $this->log = new SnapshotAudit();

        mkdir($this->tree.'/views', 0755, true);

        file_put_contents($this->tree.'/composer.json', self::MANIFEST);
        file_put_contents($this->tree.'/views/extension.php', "<?php\n\nreturn [];\n");
    }

    protected function tearDown(): void
    {
        $this->closeDatabases();
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // What comes back
    // ------------------------------------------------------------------

    public function testAPackageAndTheInstallationItWasRemovedFromComeBackTogether(): void
    {
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);

        $this->snapshotter($connection)->restore($id);

        // The files, in the shape packages/ gives them and everything below the
        // top of the tree.
        self::assertSame(self::MANIFEST, (string) file_get_contents($this->tree.'/composer.json'));
        self::assertFileExists($this->tree.'/views/extension.php');

        // And the half of the extension that was never on disk: the version key
        // that says it is installed and the list that says it runs. Both are in
        // the configuration, which is a table, which is in the dump.
        self::assertSame(self::INSTALLED, $this->configuration($connection));
    }

    public function testEverythingWrittenSinceTheSnapshotWasTakenIsGoneWithTheRestore(): void
    {
        // The dump is of the whole installation, so this is what a restore
        // actually is - and why it may never be offered as a casual default. A
        // page written after the removal is not in the snapshot and does not
        // survive being put back.
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);
        $connection->insert('pk_test_ext_items', ['title' => 'written after the package went']);

        $this->snapshotter($connection)->restore($id);

        self::assertSame(['an item the extension owns'], $this->items($connection));
    }

    public function testAPackageThatWasPutBackIsExactlyTheOneThatWasArchived(): void
    {
        // A package reinstalled since the snapshot has files this one never had,
        // and a newer manifest. Copying over it would leave a tree that is
        // neither version - half of one release and half of another, which is
        // worse than either.
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);
        $this->plantAnotherVersion();

        $this->snapshotter($connection)->restore($id);

        self::assertSame(self::MANIFEST, (string) file_get_contents($this->tree.'/composer.json'));
        self::assertFileDoesNotExist($this->tree.'/leftover.php');
    }

    public function testWhereTheFilesGoIsDecidedByTheArchiveAndNotByWhatTheMetadataSays(): void
    {
        // The metadata is a file in a directory an administrator can reach, and
        // most of what it holds came out of a package manifest to begin with. A
        // name in it that reads as a way out of packages/ may not become the
        // path a restore writes to.
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);
        $this->rewriteMetadata($id, ['package' => '../../escaped', 'module' => '../../escaped']);

        $this->snapshotter($connection)->restore($id);

        self::assertFileExists($this->tree.'/composer.json');
        self::assertSame(['pagekit'], $this->entries($this->packages));
        self::assertSame(['packages', 'snapshots'], $this->entries($this->workspace));
    }

    public function testComposersRecordOfWhatItHadInstalledIsNotWrittenBack(): void
    {
        // The captured copy describes the installation as it was and the live one
        // describes it as it is. Overwriting the second with the first would take
        // every package installed since the snapshot off Composer's books.
        $this->writeBookkeeping(self::BOOKKEEPING);

        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);

        $since = '[{"name":"pagekit/installed-since","version":"1.0.0"}]';
        $this->writeBookkeeping($since);

        $this->snapshotter($connection)->restore($id);

        self::assertSame($since, (string) file_get_contents($this->packages.'/composer/installed.json'));
        self::assertSame(
            self::BOOKKEEPING,
            (string) file_get_contents($this->file($id, SnapshotStore::INSTALLED_FILE)),
            'The captured record stays in the snapshot for whoever reconciles the two',
        );
    }

    public function testASnapshotThatWasRestoredIsStillOneAndCanBeRestoredAgain(): void
    {
        // Nothing about a restore destroys the snapshot it came out of, so an
        // administrator who restores, works on the site and wants that moment
        // back again has it until they purge it.
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);

        $snapshotter = $this->snapshotter($connection);
        $snapshotter->restore($id);

        $this->removePackage($connection);
        $snapshotter->restore($id);

        self::assertFileExists($this->tree.'/composer.json');
        self::assertSame(self::INSTALLED, $this->configuration($connection));
        self::assertSame([$id], array_keys($this->store()->list()));
    }

    // ------------------------------------------------------------------
    // A restore that cannot be finished
    // ------------------------------------------------------------------

    public function testARestoreThatCannotApplyTheDumpLeavesTheFilesBackAndTheSnapshotWhereItIs(): void
    {
        // A dump a full disk cut short is refused while the database it would
        // have replaced is still there. By then the files are back, which is the
        // half of the restore that leaves a readable installation - a package
        // tree nothing enables - and running the restore again is what finishes
        // the job.
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);
        $this->cutTheDumpShort($id);

        $failure = $this->refusal(fn () => $this->snapshotter($connection)->restore($id));

        self::assertStringContainsString('incomplete', $failure->getMessage());
        self::assertFileExists($this->tree.'/composer.json');
        self::assertSame(self::REMOVED, $this->configuration($connection));

        // Still a snapshot, with everything in it that was there before, so the
        // administrator has not lost the way back over a failed attempt at it.
        self::assertFileExists($this->file($id, SnapshotStore::FILES_DIR).'/pagekit/test-ext/composer.json');
        self::assertSame([$id], array_keys($this->store()->list()));
        self::assertSame([], $this->log->records, 'Nothing that did not happen goes on the record as done');
    }

    public function testFilesThatCouldNotBePutBackAreReportedRatherThanHalfRestored(): void
    {
        // The database is the destructive half, so a restore that could not even
        // put the files back may not go on to replace it: that would leave the
        // installation naming an enabled extension that is not on disk.
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);

        $failure = $this->refusal(
            fn () => $this->snapshotter($connection, new FilesThatCannotBePutBack())->restore($id),
        );

        self::assertStringContainsString('pagekit/test-ext', $failure->getMessage());
        self::assertStringContainsString($id, $failure->getMessage());
        self::assertSame(self::REMOVED, $this->configuration($connection));
        self::assertSame([], $this->log->records);
    }

    public function testATreeThatWillNotGoLeavesTheSnapshotToBeRunAgain(): void
    {
        // Where a package was reinstalled since, the tree on disk has to go
        // before the archived one lands. One that will not go is a restore that
        // has not happened - and the snapshot is untouched, so the retry is the
        // same call over again.
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);
        $this->plantAnotherVersion();

        $failure = $this->refusal(
            fn () => $this->snapshotter($connection, new ATreeThatWillNotGo())->restore($id),
        );

        self::assertStringContainsString('pagekit/test-ext', $failure->getMessage());
        self::assertStringContainsString($id, $failure->getMessage());
        self::assertSame(self::REMOVED, $this->configuration($connection));

        // The same restore, once whatever was holding the files has let go.
        $this->snapshotter($connection)->restore($id);

        self::assertSame(self::MANIFEST, (string) file_get_contents($this->tree.'/composer.json'));
        self::assertSame(self::INSTALLED, $this->configuration($connection));
    }

    #[DataProvider('provideSnapshotsWithNothingToPutBack')]
    public function testASnapshotWithNothingInItToPutBackIsRefused(string $part, string $expected): void
    {
        // Half a snapshot is what an interrupted removal or a reclaimed
        // directory leaves behind. It is listed like any other, so this is where
        // it is told from one a package can come back out of - and the refusal
        // has to come before anything is dropped on the strength of it.
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);
        $this->discard($id, $part);

        $failure = $this->refusal(fn () => $this->snapshotter($connection)->restore($id));

        self::assertStringContainsString($expected, $failure->getMessage());
        self::assertDirectoryDoesNotExist($this->tree);
        self::assertSame(self::REMOVED, $this->configuration($connection));
        self::assertSame([], $this->log->records);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideSnapshotsWithNothingToPutBack(): array
    {
        return [
            'a dump that never arrived' => ['dump', 'holds no database dump'],
            'an archive that never arrived' => ['files', 'holds no package files'],
            'an archive with nothing in it' => ['vendor', 'holds no package files'],
            'a vendor directory with no package under it' => ['tree', 'holds no package files'],
        ];
    }

    #[DataProvider('provideIdsThatNameNoSnapshot')]
    public function testAnIdThatNamesNoSnapshotIsRefusedWithoutRepeatingIt(string $id): void
    {
        // An id is what a restore is asked for, so it comes in from a request:
        // one that reads as a traversal, an absolute path or a name with a null
        // byte in it is refused as a name rather than looked for as a directory.
        // And the value stays out of the message, which is read back by whoever
        // sent it.
        $connection = $this->installation();
        $kept = $this->take($connection);

        $this->removePackage($connection);

        $snapshotter = $this->snapshotter($connection);
        $thrown = null;

        try {
            $snapshotter->restore($id);
        } catch (\InvalidArgumentException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\InvalidArgumentException::class, $thrown);
        self::assertStringNotContainsString($id, $thrown->getMessage());

        // Nothing was put back and nothing was reached for: the snapshot the
        // store does hold is the one an id like this is not.
        self::assertDirectoryDoesNotExist($this->tree);
        self::assertSame(self::REMOVED, $this->configuration($connection));
        self::assertSame([$kept], array_keys($this->store()->list()));
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

    // ------------------------------------------------------------------
    // The trail a destructive operation leaves
    // ------------------------------------------------------------------

    public function testWhatARestoreDidIsOnRecordWithWhatItPutBack(): void
    {
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);
        $this->snapshotter($connection)->restore($id);

        self::assertCount(1, $this->log->records);

        $record = $this->log->records[0];

        // Weeks later this line is what says the site was rolled back at all,
        // which package it was rolled back for, and how much of the database
        // went with it - the part an administrator did not ask for by name.
        self::assertStringContainsString($id, $record['message']);
        self::assertStringContainsString('pagekit/test-ext', $record['message']);
        self::assertStringContainsString('restored', $record['message']);
        self::assertSame($id, $record['context']['snapshot'] ?? null);
        self::assertSame('test-ext', $record['context']['package'] ?? null);
        self::assertSame(2, $record['context']['tables'] ?? null);
        self::assertSame(2, $record['context']['rows'] ?? null);
    }

    public function testNothingLeftToTakeTheRecordDoesNotCostTheRestore(): void
    {
        // The restore is what the administrator asked for and it has already
        // happened by then. Refusing it over a log line would be refusing the
        // way back for the reason the way back was needed.
        $connection = $this->installation();
        $id = $this->take($connection);

        $this->removePackage($connection);

        $this->snapshotter($connection, null, new AuditThatCannotBeWritten())->restore($id);

        self::assertFileExists($this->tree.'/composer.json');
        self::assertSame(self::INSTALLED, $this->configuration($connection));
    }

    // ------------------------------------------------------------------
    // The installation a restore runs against
    // ------------------------------------------------------------------

    /**
     * The snapshotter as the installer builds it, over a real store, a real
     * database and real files.
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
     * The snapshot a restore is run against, taken the way a removal takes one.
     *
     * Its own log, so that every assertion about the trail is about the
     * operation under test.
     */
    private function take(Connection $connection): string
    {
        return $this->snapshotter($connection, null, new NullLogger())
            ->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);
    }

    /**
     * The removal the snapshot was taken for, as it leaves the installation: the
     * package's files are out of the live tree and the configuration no longer
     * says it is installed or enabled. The tables it brought with it stay, which
     * is what a soft uninstall leaves behind.
     */
    private function removePackage(Connection $connection): void
    {
        (new Filesystem())->delete($this->tree);

        $connection->update('pk_system_config', ['value' => self::REMOVED], ['name' => 'system']);
    }

    /**
     * Another release of the same package, on disk where the archived one goes.
     */
    private function plantAnotherVersion(): void
    {
        mkdir($this->tree, 0755, true);

        file_put_contents($this->tree.'/composer.json', '{"name":"pagekit/test-ext","version":"2.0.0"}');
        file_put_contents($this->tree.'/leftover.php', "<?php\n\nreturn [];\n");
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

    private function writeBookkeeping(string $record): void
    {
        if (!is_dir($this->packages.'/composer')) {
            mkdir($this->packages.'/composer', 0755, true);
        }

        file_put_contents($this->packages.'/composer/installed.json', $record);
    }

    /**
     * A database with something in it worth getting back: the configuration
     * every module's settings live in, and a table the package brought with it.
     */
    private function installation(): Connection
    {
        $connection = $this->openDatabase();
        $manager = $connection->createSchemaManager();

        $config = new Table('pk_system_config');
        $config->addColumn('name', Types::STRING, ['length' => 64]);
        $config->addColumn('value', Types::TEXT, ['notnull' => false]);
        $config->setPrimaryKey(['name']);
        $manager->createTable($config);

        $items = new Table('pk_test_ext_items');
        $items->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $items->addColumn('title', Types::STRING, ['length' => 191]);
        $items->setPrimaryKey(['id']);
        $manager->createTable($items);

        $connection->insert('pk_system_config', ['name' => 'system', 'value' => self::INSTALLED]);
        $connection->insert('pk_test_ext_items', ['title' => 'an item the extension owns']);

        return $connection;
    }

    // ------------------------------------------------------------------
    // Reading the installation back
    // ------------------------------------------------------------------

    /**
     * What the next boot would read: which packages are installed, and which
     * extensions it runs.
     */
    private function configuration(Connection $connection): string
    {
        return (string) $connection->fetchOne('SELECT value FROM pk_system_config WHERE name = ?', ['system']);
    }

    /**
     * @return array<int, string>
     */
    private function items(Connection $connection): array
    {
        $titles = [];

        foreach ($connection->fetchFirstColumn('SELECT title FROM pk_test_ext_items ORDER BY title') as $title) {
            $titles[] = (string) $title;
        }

        return $titles;
    }

    private function store(): SnapshotStore
    {
        return new SnapshotStore($this->snapshots, new Filesystem());
    }

    private function file(string $id, string $name): string
    {
        return $this->snapshots.'/'.$id.'/'.$name;
    }

    // ------------------------------------------------------------------
    // Taking a snapshot apart
    // ------------------------------------------------------------------

    /**
     * Leaves a snapshot without one of the things a restore needs, the way an
     * interrupted write or a half-reclaimed directory does.
     */
    private function discard(string $id, string $part): void
    {
        $files = new Filesystem();
        $archive = $this->file($id, SnapshotStore::FILES_DIR);

        match ($part) {
            'dump' => unlink($this->file($id, SnapshotStore::DUMP_FILE)),
            'files' => $files->delete($archive),
            'vendor' => $files->delete($archive.'/pagekit'),
            'tree' => $files->delete($archive.'/pagekit/test-ext'),
        };
    }

    /**
     * Cuts the last line off the dump, which is the line that says it was
     * written in full - what a disk filling up mid-write leaves behind.
     */
    private function cutTheDumpShort(string $id): void
    {
        $file = $this->file($id, SnapshotStore::DUMP_FILE);
        $lines = explode("\n", rtrim((string) file_get_contents($file), "\n"));

        array_pop($lines);

        file_put_contents($file, implode("\n", $lines)."\n");
    }

    /**
     * Rewrites what a snapshot says about itself, the way anything with write
     * access to the store could.
     *
     * @param array<string, mixed> $overrides
     */
    private function rewriteMetadata(string $id, array $overrides): void
    {
        $file = $this->file($id, SnapshotStore::METADATA_FILE);
        $metadata = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($metadata);

        file_put_contents($file, (string) json_encode($overrides + $metadata));
    }

    /**
     * Runs a restore that has to be refused, and hands back what it refused
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

        self::fail('The restore was expected to be refused.');
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
            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}

/**
 * A filesystem that will not copy a directory - a disk with no room left on it,
 * as far as the caller can tell.
 */
final class FilesThatCannotBePutBack extends Filesystem
{
    public function copyDir(string $source, string $target): bool
    {
        return false;
    }
}

/**
 * A filesystem that cannot remove what is in the way: a file held open, a
 * permission the process does not have, a mount that has gone read-only.
 */
final class ATreeThatWillNotGo extends Filesystem
{
    /**
     * @param string|array<int, string> $files
     */
    public function delete($files): bool
    {
        return false;
    }
}
