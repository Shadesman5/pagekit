<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Pagekit\Database\Connection;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Installer\Helper\Composer;
use Pagekit\Installer\Package\Package;
use Pagekit\Installer\Package\Snapshot\DatabaseDumper;
use Pagekit\Installer\Package\Snapshot\DumpFormat;
use Pagekit\Installer\Package\Snapshot\PackageSnapshotter;
use Pagekit\Installer\Package\Snapshot\SnapshotStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Putting aside everything a removal is about to take away.
 *
 * This is the one thing a removal asks for before it destroys anything, so the
 * answer has to be honest in one direction: a snapshot that is reported as taken
 * must hold the whole of the way back, and anything less must be reported as no
 * snapshot at all - with nothing left on disk that a later restore would read as
 * one. Half a snapshot is worse than none, because it is offered to an
 * administrator as a package that can be brought back.
 *
 * What that whole is: the package's own files in the shape they have on disk, a
 * complete dump of the database they were installed in, and a description of
 * both - plus Composer's record of what it had installed, where Composer is what
 * installed the package.
 *
 * The database is a real one and the files are real files, so what lands in the
 * snapshot is what a driver and a filesystem actually produce.
 */
final class PackageSnapshotterTest extends TestCase
{
    use SnapshotDatabase;

    /**
     * A column only the second table carries, which is where the database is
     * made to stop answering: by then the header and a whole table of the dump
     * are written, so what is asserted is the clean-up of a half-written
     * snapshot rather than of an empty one.
     */
    private const TRIPWIRE = 'tripwire';

    /**
     * Composer's record as it lies under packages/, naming the package that is
     * about to be removed.
     */
    private const BOOKKEEPING = '[{"name":"pagekit/test-ext","version":"1.4.2","type":"pagekit-extension"}]';

    private string $workspace;

    /**
     * Where the snapshots go. Not created here: an installation that never
     * removed a package has no such directory, and the first snapshot has to
     * land regardless.
     */
    private string $snapshots;

    /**
     * Where runtime-installed packages live, which is also where Composer keeps
     * its record of them.
     */
    private string $packages;

    /**
     * The package on disk, in the vendor/name shape packages/ gives it.
     */
    private string $tree;

    private SnapshotAudit $log;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_snapshotter_'.getmypid().'_'.uniqid();
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
    // What a snapshot holds
    // ------------------------------------------------------------------

    public function testASnapshotHoldsThePackageTheDatabaseAndADescriptionOfBoth(): void
    {
        $connection = $this->installation();

        $id = $this->snapshotter($connection)->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        $snapshot = $this->store()->get($id);

        self::assertNotNull($snapshot, 'A snapshot that was taken is one the store hands back');
        self::assertSame('pagekit/test-ext', $snapshot['package']);
        self::assertSame('test-ext', $snapshot['module']);
        self::assertSame('Test Extension', $snapshot['title']);
        self::assertSame('pagekit-extension', $snapshot['type']);
        self::assertSame('1.4.2', $snapshot['version']);
        self::assertSame(PackageSnapshotter::REASON_UNINSTALL, $snapshot['reason']);

        // Read back before a restore runs: the layout the dump is written in,
        // and the database it may be replayed into.
        self::assertSame(DumpFormat::VERSION, $snapshot['format']);
        self::assertSame($connection->getParams()['driver'], $snapshot['database']['driver']);
        self::assertSame($this->isSqlite($connection) ? DumpFormat::SQLITE : DumpFormat::MYSQL, $snapshot['database']['platform']);
        self::assertSame('pk_', $snapshot['database']['prefix']);
    }

    public function testTheDatabaseInASnapshotIsAWholeDumpOfTheInstallation(): void
    {
        $id = $this->snapshotter($this->installation())->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        $records = $this->records($this->file($id, SnapshotStore::DUMP_FILE));

        // The last record is what tells a whole dump from one a full disk cut
        // short, and the tables are the installation's own rather than the
        // package's alone: a package's rows are spread across the site's
        // content, configuration and permissions.
        self::assertSame(DumpFormat::HEADER, $records[0]['type']);
        self::assertSame(DumpFormat::END, $records[count($records) - 1]['type']);
        self::assertSame(['pk_system_config', 'pk_test_ext_items'], $this->tablesIn($records));
    }

    public function testThePackageFilesInASnapshotAreTheTreeAsItStandsUnderPackages(): void
    {
        $id = $this->snapshotter($this->installation())->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        $archive = $this->file($id, SnapshotStore::FILES_DIR).'/pagekit/test-ext';

        // The same vendor/name shape packages/ gives it, so putting it back is
        // a copy rather than a reconstruction - and everything below it, not
        // just the manifest at the top.
        self::assertFileExists($archive.'/composer.json');
        self::assertSame(
            (string) file_get_contents($this->tree.'/composer.json'),
            (string) file_get_contents($archive.'/composer.json'),
        );
        self::assertFileExists($archive.'/views/extension.php');
    }

    #[DataProvider('provideNamesAPackageGivesItself')]
    public function testTheArchivedTreeIsShapedByWhereTheFilesAreAndNotByWhatTheManifestSays(string $name): void
    {
        // A manifest is a package's own text, and text out of a package has no
        // business naming a path inside the store: one that reads as a traversal
        // would archive the files somewhere else entirely.
        $id = $this->snapshotter($this->installation())->create(
            $this->package(['name' => $name]),
            PackageSnapshotter::REASON_UNINSTALL,
        );

        self::assertSame(['pagekit'], $this->entries($this->file($id, SnapshotStore::FILES_DIR)));
        self::assertFileExists($this->file($id, SnapshotStore::FILES_DIR).'/pagekit/test-ext/composer.json');

        // Nothing was written beside the snapshot, in the store or above it.
        self::assertSame([$id], $this->entries($this->snapshots));
        self::assertSame(['packages', 'snapshots'], $this->entries($this->workspace));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideNamesAPackageGivesItself(): array
    {
        return [
            'a name that is not where the files are' => ['somewhere/else'],
            'a name that reads as a way out of the store' => ['../../escaped'],
            'a name that reads as a path of its own' => ['/etc/passwd'],
            'no name at all' => [''],
        ];
    }

    // ------------------------------------------------------------------
    // Composer's bookkeeping
    // ------------------------------------------------------------------

    #[DataProvider('provideComposerRecords')]
    public function testWhetherComposerInstalledThePackageIsReadTheWayTheRemovalReadsIt(?string $record, bool $composer): void
    {
        // The removal asks Composer's record whether Composer has to be told
        // about the package it is taking away, and the snapshot asks it whether
        // there is bookkeeping to capture. Two answers about one package would
        // leave a snapshot that is missing half of what putting it back needs.
        if ($record !== null) {
            $this->writeBookkeeping($record);
        }

        $id = $this->snapshotter($this->installation())->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        $snapshot = $this->store()->get($id);

        self::assertNotNull($snapshot);
        self::assertSame($composer, $snapshot['composer']);
        self::assertSame($composer, $this->composer()->isInstalled('pagekit/test-ext'));
        self::assertSame($composer, is_file($this->file($id, SnapshotStore::INSTALLED_FILE)));
    }

    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function provideComposerRecords(): array
    {
        return [
            'a record naming the package' => [self::BOOKKEEPING, true],
            'a record naming another package' => ['[{"name":"pagekit/other","version":"1.0.0"}]', false],
            'an empty record' => ['[]', false],
            'a record cut off mid-write' => ['[{"name":"pagekit/test-e', false],
            'a record that is no record' => ['not json at all', false],
            'no record at all' => [null, false],
        ];
    }

    public function testTheCapturedBookkeepingIsComposersOwnFileRatherThanAReadingOfIt(): void
    {
        $this->writeBookkeeping(self::BOOKKEEPING);

        $id = $this->snapshotter($this->installation())->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        // Kept beside the archived tree rather than in it: it describes the
        // whole installation, and what to do with it on a restore is a decision
        // somebody makes about Composer's bookkeeping.
        self::assertSame(self::BOOKKEEPING, (string) file_get_contents($this->file($id, SnapshotStore::INSTALLED_FILE)));
    }

    public function testBookkeepingThatCannotBeCapturedCostsTheWholeSnapshot(): void
    {
        // Without it a Composer-installed package comes back with its files and
        // without Composer knowing it is there, which is a restore that reports
        // success and leaves the installation inconsistent.
        $this->writeBookkeeping(self::BOOKKEEPING);

        $snapshotter = $this->snapshotter($this->installation(), new BookkeepingThatCannotBeCopied());

        $failure = $this->refusal(fn () => $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL));

        self::assertStringContainsString('pagekit/test-ext', $failure->getMessage());
        self::assertStringContainsString(
            'Composer\'s record',
            (string) $failure->getPrevious()?->getMessage(),
            'The caller logs what actually went wrong',
        );
        self::assertSame([], $this->store()->list());
    }

    // ------------------------------------------------------------------
    // A snapshot that could not be taken
    // ------------------------------------------------------------------

    public function testAPackageWhoseFilesAreNotWhereItSaysIsNotSnapshotted(): void
    {
        // The removal that follows would delete a path this one could not even
        // read, and the snapshot it was told it has would hold no package.
        $snapshotter = $this->snapshotter($this->installation());

        $failure = $this->refusal(fn () => $snapshotter->create(
            $this->package(['path' => $this->packages.'/pagekit/gone']),
            PackageSnapshotter::REASON_UNINSTALL,
        ));

        self::assertStringContainsString('pagekit/test-ext', $failure->getMessage());
        self::assertSame([], $this->store()->list(), 'Nothing of a failed snapshot is left to be restored from');
    }

    public function testAnInstallationOnADatabaseNoDumpCanBeTakenOfIsNotSnapshotted(): void
    {
        // Answered before a directory is made, because the answer costs nothing
        // and a removal is about to be started on the strength of it.
        $snapshotter = $this->snapshotter($this->openDatabase('pk_', ConnectionOnAnUnsupportedDatabase::class));

        $failure = $this->refusal(fn () => $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL));

        self::assertStringContainsString('not one a snapshot can be taken of', $failure->getMessage());
        self::assertDirectoryDoesNotExist($this->snapshots);
    }

    public function testADumpThatBreaksOffPartWrittenTakesTheSnapshotWithIt(): void
    {
        // A dump is read out of a live database over a stretch of time in which
        // a server can be restarted or a table locked. What is left behind then
        // is a directory a restore would list as a package it can bring back.
        $connection = $this->installation('pk_', ConnectionThatStopsAnswering::class);

        self::assertInstanceOf(ConnectionThatStopsAnswering::class, $connection);

        // By the time this bites, the header and the whole first table are in
        // the snapshot directory.
        $connection->refuse = self::TRIPWIRE;

        $snapshotter = $this->snapshotter($connection);

        $failure = $this->refusal(fn () => $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL));

        self::assertStringContainsString('pagekit/test-ext', $failure->getMessage());
        self::assertNotNull($failure->getPrevious());
        self::assertSame([], $this->store()->list());
        self::assertSame([], $this->entries($this->snapshots));
    }

    public function testASnapshotThatWasNotTakenIsNotOnRecordAsTaken(): void
    {
        $snapshotter = $this->snapshotter($this->installation());

        $this->refusal(fn () => $snapshotter->create(
            $this->package(['path' => $this->packages.'/pagekit/gone']),
            PackageSnapshotter::REASON_UNINSTALL,
        ));

        // The trail an administrator reads weeks later says which snapshots
        // exist. A line about one that does not would send them looking for it.
        self::assertSame([], $this->log->records);
    }

    // ------------------------------------------------------------------
    // The trail a destructive operation leaves
    // ------------------------------------------------------------------

    public function testTakingASnapshotIsOnRecordWithWhatItIsOfAndWhatAskedForIt(): void
    {
        $id = $this->snapshotter($this->installation())->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        self::assertCount(1, $this->log->records);

        $record = $this->log->records[0];

        self::assertStringContainsString($id, $record['message']);
        self::assertStringContainsString('pagekit/test-ext', $record['message']);
        self::assertStringContainsString(PackageSnapshotter::REASON_UNINSTALL, $record['message']);
        self::assertSame($id, $record['context']['snapshot'] ?? null);
        self::assertSame('test-ext', $record['context']['package'] ?? null);
        self::assertSame(PackageSnapshotter::REASON_UNINSTALL, $record['context']['reason'] ?? null);
    }

    public function testNothingLeftToTakeTheRecordDoesNotCostTheSnapshot(): void
    {
        // The store's own inventory is what says a snapshot exists, so refusing
        // here would refuse the removal the snapshot was taken for over a line
        // in a log.
        $snapshotter = $this->snapshotter($this->installation(), null, new AuditThatCannotBeWritten());

        $id = $snapshotter->create($this->package(), PackageSnapshotter::REASON_UNINSTALL);

        self::assertNotNull($this->store()->get($id));
    }

    // ------------------------------------------------------------------
    // The snapshotter as the application builds it
    // ------------------------------------------------------------------

    private function snapshotter(Connection $connection, ?Filesystem $files = null, ?AbstractLogger $log = null): PackageSnapshotter
    {
        $files ??= new Filesystem();

        return new PackageSnapshotter(
            new SnapshotStore($this->snapshots, $files),
            new DatabaseDumper($connection),
            $files,
            $log ?? $this->log,
            $this->packages,
        );
    }

    /**
     * The package as the factory reads it out of a manifest on disk.
     *
     * @param array<string, mixed> $overrides
     */
    private function package(array $overrides = []): Package
    {
        return new Package($overrides + [
            'name' => 'pagekit/test-ext',
            'type' => 'pagekit-extension',
            'module' => 'test-ext',
            'title' => 'Test Extension',
            'version' => '1.4.2',
            'path' => $this->tree,
        ]);
    }

    /**
     * The reader the removal path asks whether Composer installed a package,
     * which the snapshot has to agree with about one package.
     */
    private function composer(): Composer
    {
        return new Composer([
            'path.packages' => $this->packages,
            'path.artifact' => $this->workspace.'/artifact',
            'system.api' => 'https://example.test',
        ]);
    }

    private function writeBookkeeping(string $record): void
    {
        mkdir($this->packages.'/composer', 0755, true);
        file_put_contents($this->packages.'/composer/installed.json', $record);
    }

    /**
     * A database with something in it worth getting back: the configuration
     * every module's settings live in, and a table the package brought with it.
     *
     * @param class-string<Connection> $wrapper
     */
    private function installation(string $prefix = 'pk_', string $wrapper = Connection::class): Connection
    {
        $connection = $this->openDatabase($prefix, $wrapper);
        $manager = $connection->createSchemaManager();

        $config = new Table($prefix.'system_config');
        $config->addColumn('name', Types::STRING, ['length' => 64]);
        $config->addColumn('value', Types::TEXT, ['notnull' => false]);
        $config->setPrimaryKey(['name']);
        $manager->createTable($config);

        $items = new Table($prefix.'test_ext_items');
        $items->addColumn(self::TRIPWIRE, Types::INTEGER, ['autoincrement' => true]);
        $items->addColumn('title', Types::STRING, ['length' => 191]);
        $items->setPrimaryKey([self::TRIPWIRE]);
        $manager->createTable($items);

        $connection->insert($prefix.'system_config', [
            'name' => 'system',
            'value' => '{"packages":{"test-ext":"1.4.2"},"extensions":["test-ext"]}',
        ]);
        $connection->insert($prefix.'test_ext_items', ['title' => 'an item the extension owns']);

        return $connection;
    }

    // ------------------------------------------------------------------
    // Reading a snapshot back off the disk
    // ------------------------------------------------------------------

    private function store(): SnapshotStore
    {
        return new SnapshotStore($this->snapshots, new Filesystem());
    }

    private function file(string $id, string $name): string
    {
        return $this->snapshots.'/'.$id.'/'.$name;
    }

    /**
     * The records of a dump, read the way the format says they are written -
     * one JSON object per line - rather than through a restore.
     *
     * @return array<int, array<string, mixed>>
     */
    private function records(string $file): array
    {
        self::assertFileExists($file);

        $records = [];

        foreach (explode("\n", trim((string) file_get_contents($file))) as $line) {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            self::assertIsArray($record);

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param  array<int, array<string, mixed>> $records
     * @return array<int, string>
     */
    private function tablesIn(array $records): array
    {
        $names = [];

        foreach ($records as $record) {
            if ($record['type'] === DumpFormat::TABLE) {
                self::assertIsString($record['name']);

                $names[] = $record['name'];
            }
        }

        return $names;
    }

    /**
     * Runs a call that has to refuse to take a snapshot, and hands back what it
     * refused with. Captured rather than asserted on inside the catch, because a
     * failed assertion is itself a RuntimeException.
     */
    private function refusal(callable $call): \RuntimeException
    {
        try {
            $call();
        } catch (\RuntimeException $e) {
            return $e;
        }

        self::fail('Taking the snapshot was expected to be refused.');
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
 * The trail a snapshot leaves, as a test reads it back.
 */
final class SnapshotAudit extends AbstractLogger
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
final class AuditThatCannotBeWritten extends AbstractLogger
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

/**
 * A filesystem that copies the package tree and then loses Composer's record of
 * it - a disk that fills up between the two, as far as the caller can tell.
 */
final class BookkeepingThatCannotBeCopied extends Filesystem
{
    public function copy(string $source, string $target): bool
    {
        return !str_ends_with($target, SnapshotStore::INSTALLED_FILE) && parent::copy($source, $target);
    }
}
