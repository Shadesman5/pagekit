<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Pagekit\Database\Connection;
use Pagekit\Installer\Package\Snapshot\DatabaseDumper;
use Pagekit\Installer\Package\Snapshot\DumpFormat;
use PHPUnit\Framework\TestCase;

/**
 * Writing the database of an installation to a file, which is the part of a
 * snapshot that has to be finished before a package may be taken away.
 *
 * Two properties carry the weight here. The dump is scoped: it holds the tables
 * the installation's prefix names and nothing else, because a database is a place
 * other installations share and a restore drops every table its dump lists. And
 * the file at the name a restore reads is either a whole dump or absent - a dump
 * broken off by a full disk or a server that stopped answering must not be left
 * somewhere it can be found and replayed, since by then it is the only copy of a
 * database nobody can compare it against.
 *
 * The database is a real one, so what lands in the file is the schema an engine
 * renders and the values a driver hands back rather than a fixture's idea of
 * either.
 */
final class DatabaseDumperTest extends TestCase
{
    use SnapshotDatabase;

    /**
     * What the dump is called while it is still being written. A restore reads
     * the other name, so a leftover under this one is disk nobody reclaims -
     * and disk holding every password hash on the site.
     */
    private const STAGING = '.part';

    /**
     * A column only the second table carries, which is where the database is
     * made to stop answering: by then the header and a whole table are written.
     */
    private const TRIPWIRE = 'tripwire';

    /**
     * What the one fully filled-in row is recognised by. A dump carries rows in
     * whatever order the database hands them over, which is the driver's choice
     * and not something a test may read as a position.
     */
    private const TITLE = 'Überschrift ’zwei’';

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_dump_'.getmypid().'_'.uniqid();

        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->closeDatabases();
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // What a dump holds
    // ------------------------------------------------------------------

    public function testADumpHoldsTheTablesTheInstallationOwnsAndNoOthers(): void
    {
        // A Pagekit database is not always Pagekit's alone - a prefix is what
        // makes room for a second installation, or for another application, in
        // one database. Everything a dump lists is dropped when it is replayed.
        $connection = $this->installation();

        $summary = (new DatabaseDumper($connection))->dump($this->target());

        self::assertSame(['pk_items', 'pk_meta'], $this->tablesIn($this->target()));
        self::assertSame(['tables' => 2, 'rows' => 3], $summary);
        self::assertStringNotContainsString('other_items', $this->contents($this->target()));
    }

    public function testAnInstallationWithNoPrefixOfItsOwnOwnsEveryTableInItsDatabase(): void
    {
        // A prefix is optional, and where there is none the installation is the
        // only thing in the database - so leaving the unprefixed tables out
        // would dump nothing at all.
        $connection = $this->installation('');

        (new DatabaseDumper($connection))->dump($this->target());

        self::assertSame(['items', 'meta', 'other_items'], $this->tablesIn($this->target()));
    }

    public function testADumpSaysWhichDatabaseItCameOutOfAndInWhichLayoutItIsWritten(): void
    {
        $connection = $this->installation();
        $taken = time();

        (new DatabaseDumper($connection))->dump($this->target());

        $header = $this->recordsIn($this->target())[0];

        // Every one of these is read back before a restore runs: the layout so a
        // file written by a version that arranged records differently is refused
        // rather than misread, the database so a dump only ever goes back into
        // the kind it came from, the prefix so it may only name its own tables.
        self::assertSame(DumpFormat::HEADER, $header['type']);
        self::assertSame(DumpFormat::VERSION, $header['format']);
        self::assertSame($connection->getParams()['driver'], $header['driver']);
        self::assertSame($this->isSqlite($connection) ? DumpFormat::SQLITE : DumpFormat::MYSQL, $header['platform']);
        self::assertSame('pk_', $header['prefix']);
        self::assertGreaterThanOrEqual($taken, $header['created']);
    }

    public function testEveryTableComesWithTheStatementsThatRecreateItAndTheColumnsItsRowsAreWrittenAgainst(): void
    {
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->target());

        $table = $this->recordFor($this->target(), 'pk_items');

        // The schema is what the engine itself renders, so a restore recreates
        // the table the database had rather than one assembled out of column
        // names. The columns are named once here because the rows that follow
        // carry values alone.
        self::assertNotSame([], $table['ddl']);
        self::assertStringContainsString('pk_items', $table['ddl'][0]);
        self::assertStringContainsStringIgnoringCase('create table', $table['ddl'][0]);
        self::assertSame(['id', 'title', 'body', 'status', 'created', 'score', 'thumb'], $table['columns']);
    }

    public function testEveryRowOfATableFollowsItWithOneValuePerColumn(): void
    {
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->target());

        $rows = $this->rowsFor($this->target(), 'pk_items');

        self::assertCount(2, $rows);

        foreach ($rows as $values) {
            self::assertCount(7, $values, 'A row is read back positionally against the columns of its table');
        }
    }

    public function testBytesAColumnHeldAreDumpedAsBytesRatherThanAsWhateverTextTheyResembled(): void
    {
        // What is in this column is a thumbnail in the real thing. Carried as
        // text it comes back out of a restore as replacement characters, which
        // is a snapshot that restores a broken site rather than the site.
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->target());

        self::assertSame(['b64' => base64_encode(self::thumbnail())], $this->filledRow($this->target())[6]);
    }

    public function testTextAColumnHeldIsDumpedAsTextRatherThanAsSomethingOnlyAMachineReads(): void
    {
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->target());

        // An operator looking at a dump to decide whether to replay it can only
        // do that while the values in it are the values.
        self::assertSame(self::TITLE, $this->filledRow($this->target())[1]);
        self::assertSame("line one\nline two", $this->filledRow($this->target())[2]);
    }

    public function testTablesGoIntoADumpInAnOrderOfItsOwnRatherThanTheOneTheyWereHandedOverIn(): void
    {
        // Which order introspection lists tables in is settled by a catalogue
        // query inside the driver, and the two engines do not use the same one.
        // Left as it arrived, two snapshots of one installation could not be
        // compared - and a dump could not be read looking for a table.
        $connection = $this->installation('pk_', ConnectionThatListsTablesBackwards::class);

        (new DatabaseDumper($connection))->dump($this->target());

        self::assertSame(['pk_items', 'pk_meta'], $this->tablesIn($this->target()));
    }

    public function testTwoDumpsOfOneUnchangedDatabaseAreTheSameDump(): void
    {
        // Beyond the order of the tables: the rows, the values in them and the
        // schema statements are all things a dump takes from somewhere else and
        // has to write down the same way twice, or no two snapshots of one
        // installation can be told apart.
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->target());
        (new DatabaseDumper($connection))->dump($this->target('second.dump'));

        self::assertSame(
            $this->contentsWithoutHeader($this->target()),
            $this->contentsWithoutHeader($this->target('second.dump')),
            'Only the line saying when a dump was taken may differ between two of them',
        );
    }

    public function testADumpEndsWithWhatItHolds(): void
    {
        // The last line is written only once everything before it is on disk, so
        // its presence is what tells a restore that a file is a whole dump - and
        // its counts are what tell it the file was not shortened afterwards.
        $connection = $this->installation();

        $summary = (new DatabaseDumper($connection))->dump($this->target());

        $records = $this->recordsIn($this->target());
        $end = end($records);

        self::assertSame(DumpFormat::END, $end['type']);
        self::assertSame($summary['tables'], $end['tables']);
        self::assertSame($summary['rows'], $end['rows']);
    }

    // ------------------------------------------------------------------
    // The file, and what is never left of one
    // ------------------------------------------------------------------

    public function testAFinishedDumpIsAllThatIsLeftBehind(): void
    {
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->target());

        self::assertFileExists($this->target());
        self::assertFileDoesNotExist($this->target().self::STAGING);
        self::assertSame(['db.dump'], $this->entries($this->workspace));
    }

    public function testADumpIsReadableOnlyToTheAccountThatTookIt(): void
    {
        $this->requirePermissionBits();

        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->target());

        // Every row of the database is in this file, session data and password
        // hashes among them, and it stays there for as long as the snapshot is
        // kept. On a shared host the account next door is what this keeps out.
        self::assertSame(0, fileperms($this->target()) & 0077);
    }

    public function testTheNameARestoreReadsIsNotThereUntilAWholeDumpIsUnderIt(): void
    {
        $connection = $this->installation('pk_', ConnectionThatStopsAnswering::class);

        self::assertInstanceOf(ConnectionThatStopsAnswering::class, $connection);

        $halfway = [];

        $connection->refuse = self::TRIPWIRE;
        $connection->observe = function () use (&$halfway): void {
            $halfway = $this->entries($this->workspace);
        };

        try {
            (new DatabaseDumper($connection))->dump($this->target());
        } catch (\RuntimeException) {
            // What the file was called while it was being written is the point.
        }

        // Part of the dump is on disk at this moment, which is what makes the
        // name it is under the thing that matters: a restore reads the other
        // one, and moving a finished dump onto it happens all at once.
        self::assertSame(['db.dump'.self::STAGING], $halfway);
    }

    public function testADatabaseThatStopsAnsweringPartwayThroughLeavesNoDumpAtAll(): void
    {
        $connection = $this->installation('pk_', ConnectionThatStopsAnswering::class);

        self::assertInstanceOf(ConnectionThatStopsAnswering::class, $connection);

        // By the time this bites, the header and the whole first table are
        // written. A file with those in it is a dump that restores an
        // installation missing most of its data.
        $connection->refuse = self::TRIPWIRE;

        try {
            (new DatabaseDumper($connection))->dump($this->target());

            self::fail('A dump that broke off must not be reported as taken');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Failed to dump the database', $e->getMessage());
            self::assertStringContainsString('stopped answering', (string) $e->getPrevious()?->getMessage());
        }

        // Not merely "no dump at the name a restore reads": nothing at all, so
        // the snapshot directory cannot be inventoried as holding one.
        self::assertSame([], $this->entries($this->workspace));
    }

    public function testADumpThatNeverReachedTheNameARestoreReadsIsReportedRatherThanLeftUnderItsWorkingOne(): void
    {
        // Moving the finished file onto the name a restore reads is the last
        // thing a dump does and the last thing that can fail. By then the whole
        // database is written, which is exactly why it may not be reported as
        // taken: nothing would ever read it.
        $connection = $this->installation();

        mkdir($this->target().'/something-in-the-way', 0755, true);

        try {
            (new DatabaseDumper($connection))->dump($this->target());

            self::fail('A dump that never reached the name a restore reads must not be reported as taken');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Failed to dump the database', $e->getMessage());
            self::assertStringContainsString('Failed to move the finished database dump into place', (string) $e->getPrevious()?->getMessage());
        }

        // Cleaned up even here, where what was under the working name was a
        // whole dump: nothing reads that name, so leaving it is every row of
        // the database sitting on disk for as long as the snapshot directory is.
        self::assertSame(['db.dump'], $this->entries($this->workspace));
        self::assertDirectoryExists($this->target());
    }

    public function testADumpThatCannotBeOpenedIsReportedRatherThanCountedAsEmpty(): void
    {
        // A read-only mount, a full disk or a directory that was never created:
        // the caller has to hear this, because it is the answer to whether a
        // package may be removed.
        $connection = $this->installation();

        try {
            (new DatabaseDumper($connection))->dump($this->workspace.'/no-such-directory/db.dump');

            self::fail('A dump that was never opened must not be reported as taken');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Failed to dump the database', $e->getMessage());
            self::assertStringContainsString('Failed to open the database dump', (string) $e->getPrevious()?->getMessage());
        }

        self::assertSame([], $this->entries($this->workspace));
    }

    public function testAnInstallationOnADatabaseNoDumpFitsIsRefusedBeforeAnyFileIsMade(): void
    {
        // The platform is checked first so that an installation which cannot be
        // snapshotted at all does not leave a file behind on every attempt.
        $connection = $this->openDatabase('pk_', ConnectionOnAnUnsupportedDatabase::class);

        try {
            (new DatabaseDumper($connection))->dump($this->target());

            self::fail('A database no dump can be taken of must not produce one');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('is not one a snapshot can be taken of', $e->getMessage());
        }

        self::assertSame([], $this->entries($this->workspace));
    }

    public function testADumperSaysWhichDatabaseItWouldWriteBeforeThereIsADumpToRead(): void
    {
        // A snapshot records what it is of before its dump exists, and the
        // dumper is what knows: the description in the metadata and the header
        // in the dump have to be the same reading of the same installation.
        $connection = $this->installation();

        $description = (new DatabaseDumper($connection))->describe();

        (new DatabaseDumper($connection))->dump($this->target());

        $header = $this->recordsIn($this->target())[0];

        self::assertSame($description['driver'], $header['driver']);
        self::assertSame($description['platform'], $header['platform']);
        self::assertSame($description['prefix'], $header['prefix']);
    }

    // ------------------------------------------------------------------
    // The installation a dump is taken of
    // ------------------------------------------------------------------

    /**
     * A database with something in it worth getting back: values of every kind a
     * column holds, a second table of the installation's own, and one table that
     * belongs to whatever else shares the database.
     *
     * @param class-string<Connection> $wrapper
     */
    private function installation(string $prefix = 'pk_', string $wrapper = Connection::class): Connection
    {
        $connection = $this->openDatabase($prefix, $wrapper);
        $manager = $connection->createSchemaManager();

        // Created out of alphabetical order, because the order a dump reads in
        // is one it decides rather than one it is handed.
        $meta = new Table($prefix.'meta');
        $meta->addColumn(self::TRIPWIRE, Types::STRING, ['length' => 64]);
        $meta->addColumn('value', Types::TEXT, ['notnull' => false]);
        $meta->setPrimaryKey([self::TRIPWIRE]);
        $manager->createTable($meta);

        $items = new Table($prefix.'items');
        $items->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $items->addColumn('title', Types::STRING, ['length' => 191]);
        $items->addColumn('body', Types::TEXT, ['notnull' => false]);
        $items->addColumn('status', Types::BOOLEAN, ['default' => false]);
        $items->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $items->addColumn('score', Types::FLOAT, ['notnull' => false]);
        $items->addColumn('thumb', Types::BLOB, ['notnull' => false]);
        $items->setPrimaryKey(['id']);
        $manager->createTable($items);

        // Not the installation's, whatever the prefix is - the name carries no
        // prefix at all, which is what a neighbouring application's table looks
        // like from here.
        $other = new Table('other_items');
        $other->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $other->addColumn('title', Types::STRING, ['length' => 32]);
        $other->setPrimaryKey(['id']);
        $manager->createTable($other);

        $connection->insert($prefix.'items', [
            'title' => self::TITLE,
            'body' => "line one\nline two",
            'status' => 1,
            'created' => '2026-02-03 04:05:06',
            'score' => 1.5,
            'thumb' => self::thumbnail(),
        ], ['thumb' => ParameterType::BINARY]);

        $connection->insert($prefix.'items', [
            'title' => 'nothing else filled in',
            'body' => null,
            'status' => 0,
            'created' => null,
            'score' => null,
            'thumb' => null,
        ]);

        $connection->insert($prefix.'meta', [self::TRIPWIRE => 'version', 'value' => '1.4.2']);
        $connection->insert('other_items', ['title' => 'not this installation to lose']);

        return $connection;
    }

    /**
     * Bytes a column held that are no text: the opening of a PNG, which is what
     * a stored thumbnail begins with.
     */
    private static function thumbnail(): string
    {
        return "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\xff\xfe";
    }

    // ------------------------------------------------------------------
    // Reading a dump back as a file, rather than through the restore
    // ------------------------------------------------------------------

    private function target(string $name = 'db.dump'): string
    {
        return $this->workspace.'/'.$name;
    }

    private function contents(string $file): string
    {
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /**
     * A dump without the one record that differs between two of them, so the
     * rest can be compared.
     */
    private function contentsWithoutHeader(string $file): string
    {
        return implode("\n", array_slice(explode("\n", $this->contents($file)), 1));
    }

    /**
     * Every record of a dump, read the way the format says they are written -
     * one JSON object per line - rather than through the restore, so what is on
     * disk is what is asserted.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recordsIn(string $file): array
    {
        $records = [];

        foreach (explode("\n", trim($this->contents($file))) as $line) {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            self::assertIsArray($record);

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @return array<int, string>
     */
    private function tablesIn(string $file): array
    {
        $names = [];

        foreach ($this->recordsIn($file) as $record) {
            if ($record['type'] === DumpFormat::TABLE) {
                self::assertIsString($record['name']);

                $names[] = $record['name'];
            }
        }

        return $names;
    }

    /**
     * @return array{name: string, ddl: array<int, string>, columns: array<int, string>}
     */
    private function recordFor(string $file, string $table): array
    {
        foreach ($this->recordsIn($file) as $record) {
            if ($record['type'] === DumpFormat::TABLE && $record['name'] === $table) {
                /** @var array{name: string, ddl: array<int, string>, columns: array<int, string>} $record */
                return $record;
            }
        }

        self::fail(sprintf('The dump holds no table "%s".', $table));
    }

    /**
     * The rows of one table, which are the records between its own and the next
     * table's.
     *
     * @return array<int, array<int, mixed>>
     */
    private function rowsFor(string $file, string $table): array
    {
        $rows = [];
        $reading = false;

        foreach ($this->recordsIn($file) as $record) {

            if ($record['type'] === DumpFormat::TABLE) {
                $reading = $record['name'] === $table;

                continue;
            }

            if ($reading && $record['type'] === DumpFormat::ROW) {
                self::assertIsArray($record['values']);

                $rows[] = $record['values'];
            }
        }

        return $rows;
    }

    /**
     * The row every column of which was filled in, found by its title rather
     * than by where it happens to lie in the dump.
     *
     * @return array<int, mixed>
     */
    private function filledRow(string $file): array
    {
        foreach ($this->rowsFor($file, 'pk_items') as $values) {
            if (($values[1] ?? null) === self::TITLE) {
                return $values;
            }
        }

        self::fail('The dump holds no row for the item that was written with every column filled in.');
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

    /**
     * Skips where the filesystem carries no POSIX permission bits to assert on
     * (Windows reports the same mode for everything).
     */
    private function requirePermissionBits(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('This platform keeps no POSIX permission bits');
        }
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
