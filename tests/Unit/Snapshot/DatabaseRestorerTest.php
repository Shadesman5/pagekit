<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Exception as DatabaseFailure;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Pagekit\Database\Connection;
use Pagekit\Installer\Package\Snapshot\DatabaseDumper;
use Pagekit\Installer\Package\Snapshot\DatabaseRestorer;
use Pagekit\Installer\Package\Snapshot\DumpFormat;
use Pagekit\Installer\Package\Snapshot\RestoreTableNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Putting a database back the way a dump found it, which is the half of a
 * snapshot that destroys something.
 *
 * Every table the dump names ends up holding what the dump holds for it, so what
 * was written to those tables since it was taken is gone. That is the point - the
 * state of the installation before a package was removed is what is being asked
 * for - and it is also why the dump has to be judged before a single statement
 * runs. It is the only copy of what is about to be replaced: a file a full disk
 * cut short, one from another installation, or one written in a layout this
 * version does not know has to be refused while the database it would have
 * replaced is still there.
 *
 * A restore that failed leaves the installation it found, on either engine and by
 * two routes: SQLite replaces the tables where they stand and takes the whole of a
 * failure back with the transaction it ran in, while MySQL writes nothing live at
 * all - the dump goes into copies of the tables, and one statement swaps those in.
 * What only a server can say, how it matches names and which table points at
 * which, is answered by a stand-in on a run with no server behind it and by the
 * server itself on a run with one.
 */
final class DatabaseRestorerTest extends TestCase
{
    use SnapshotDatabase;

    /**
     * What the fully filled-in row is recognised by, since a dump carries rows
     * in whatever order the database hands them over.
     */
    private const TITLE = 'Überschrift ’zwei’';

    /**
     * Stands in a written-out dump for the database the run is against. Which
     * one that is cannot be asked until a connection is open, and the dumps a
     * data provider composes are built before there is one.
     */
    private const DATABASE_UNDER_TEST = '{the database under test}';

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_restore_'.getmypid().'_'.uniqid();

        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->closeDatabases();
        $this->removeTree($this->workspace);
    }

    // ------------------------------------------------------------------
    // The round trip
    // ------------------------------------------------------------------

    public function testEveryValueADatabaseHeldComesBackOutOfItsDumpUnchanged(): void
    {
        // The columns cover what a Pagekit table actually holds: keys, titles in
        // whatever script the site is written in, bodies with line breaks, flags,
        // timestamps, numbers with a fraction, stored bytes, and columns nobody
        // filled in. Read back out of the database rather than compared against
        // what was inserted, so what is asserted is a round trip and not the
        // test's own idea of how a driver stores a value.
        $connection = $this->installation();
        $before = $this->items($connection);

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->executeStatement('DELETE FROM pk_items');

        $summary = (new DatabaseRestorer($connection))->restore($this->dump());

        self::assertSame($before, $this->items($connection));
        self::assertSame(['tables' => 2, 'rows' => 3], $summary);
    }

    public function testTheBytesInAColumnSurviveTheWholeWayRoundRatherThanComingBackAsQuestionMarks(): void
    {
        // Read one column on its own as well, because a thumbnail or a hash that
        // came back mangled would still compare equal to itself in the row above
        // if both halves had gone through the same lossy step.
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->executeStatement('UPDATE pk_items SET thumb = NULL');
        (new DatabaseRestorer($connection))->restore($this->dump());

        self::assertSame(
            self::thumbnail(),
            $this->readColumn($connection, 'thumb'),
        );
    }

    public function testANumberWithAFractionComesBackWithTheDigitsItWentInWith(): void
    {
        // Written through the database rather than through the insert helper, so
        // the value in the column is the one with digits past what a cast to
        // text would keep.
        $connection = $this->installation();
        $connection->executeStatement('UPDATE pk_items SET score = 0.30000000000000004 WHERE title = ?', [self::TITLE]);

        $before = (float) $this->readColumn($connection, 'score');

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->executeStatement('UPDATE pk_items SET score = 1');
        (new DatabaseRestorer($connection))->restore($this->dump());

        self::assertSame(0.30000000000000004, $before, 'The column held every digit before the dump was taken');
        self::assertSame($before, (float) $this->readColumn($connection, 'score'));
    }

    public function testWhatWasWrittenAfterTheDumpWasTakenIsGone(): void
    {
        // This is what a restore is, and the reason it is never a casual
        // default: the database goes back to the moment the snapshot was taken,
        // so everything written to those tables since is lost with it.
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->insert('pk_items', ['title' => 'written after the snapshot', 'status' => 0]);

        (new DatabaseRestorer($connection))->restore($this->dump());

        self::assertSame([], $connection->fetchAllAssociative('SELECT id FROM pk_items WHERE title = ?', ['written after the snapshot']));
    }

    public function testATableTheDumpDoesNotNameIsLeftAsItIs(): void
    {
        // A prefix is what makes room for something else in the same database.
        // A restore reaching a table outside it would take a neighbouring
        // application back in time along with this one.
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->insert('other_items', ['title' => 'written after the snapshot']);

        (new DatabaseRestorer($connection))->restore($this->dump());

        self::assertCount(2, $connection->fetchAllAssociative('SELECT id FROM other_items'));
    }

    public function testATableThatCameIntoExistenceAfterTheDumpIsLeftAlone(): void
    {
        // A restore reaches exactly as far as the dump does. A table an update
        // added since is not in it, and dropping it would leave the installation
        // with a schema no version of it matches.
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->dump());

        $later = new Table('pk_later');
        $later->addColumn('id', Types::INTEGER, ['notnull' => true]);
        $later->setPrimaryKey(['id']);
        $connection->createSchemaManager()->createTable($later);
        $connection->insert('pk_later', ['id' => 7]);

        (new DatabaseRestorer($connection))->restore($this->dump());

        self::assertSame([['id' => 7]], $connection->fetchAllAssociative('SELECT id FROM pk_later'));
    }

    public function testATableThatIsGoneAltogetherIsPutBackByTheRestore(): void
    {
        // The case a snapshot exists for: a removal took a table with it, and
        // what has to come back is the table and the rows, not the rows into a
        // table that is no longer there.
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->executeStatement('DROP TABLE pk_meta');

        (new DatabaseRestorer($connection))->restore($this->dump());

        self::assertSame([['name' => 'version', 'value' => '1.4.2']], $connection->fetchAllAssociative('SELECT name, value FROM pk_meta'));
    }

    // ------------------------------------------------------------------
    // Foreign keys, which cannot be enforced while tables are being swapped
    // ------------------------------------------------------------------

    public function testATableIsPutBackBeforeTheOneItPointsAtWithoutTheReferenceRefusingIt(): void
    {
        // Tables are replaced one at a time and in name order, so a table that
        // references another is regularly recreated - and filled - while the one
        // it points at has not been put back yet. Enforced, that is a restore
        // that fails on any installation whose tables reference each other.
        $connection = $this->related();
        $this->enforceReferences($connection, true);

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->executeStatement('DELETE FROM pk_a_child');
        $connection->executeStatement('DELETE FROM pk_b_parent');

        $summary = (new DatabaseRestorer($connection))->restore($this->dump());

        self::assertSame(['tables' => 2, 'rows' => 2], $summary);
        self::assertSame([['id' => 1, 'parent_id' => 1]], $connection->fetchAllAssociative('SELECT id, parent_id FROM pk_a_child'));
    }

    public function testADatabaseThatEnforcedReferencesBeforeARestoreEnforcesThemAfterIt(): void
    {
        // Suspending enforcement is something the restore does to itself and not
        // a setting it may leave the connection on: every statement the request
        // runs after it would be allowed to write rows nothing points at.
        $connection = $this->related();
        $this->enforceReferences($connection, true);

        (new DatabaseDumper($connection))->dump($this->dump());
        (new DatabaseRestorer($connection))->restore($this->dump());

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $connection->insert('pk_a_child', ['id' => 2, 'parent_id' => 99]);
    }

    public function testADatabaseThatDidNotEnforceReferencesIsNotLeftEnforcingThemByARestore(): void
    {
        // The two engines disagree about the default, which is why the setting is
        // read before it is changed rather than assumed - putting back the wrong
        // one would change what the rest of the installation is allowed to do.
        $connection = $this->related();
        $this->enforceReferences($connection, false);

        (new DatabaseDumper($connection))->dump($this->dump());
        (new DatabaseRestorer($connection))->restore($this->dump());

        $connection->insert('pk_a_child', ['id' => 2, 'parent_id' => 99]);

        self::assertSame([['id' => 2, 'parent_id' => 99]], $connection->fetchAllAssociative('SELECT id, parent_id FROM pk_a_child WHERE id = 2'));
    }

    // ------------------------------------------------------------------
    // A dump that may not be replayed
    // ------------------------------------------------------------------

    public function testADumpFromAnotherKindOfDatabaseIsRefused(): void
    {
        // What is in a dump is the schema one engine renders and the table names
        // one installation writes under. A snapshot is how an installation is put
        // back, not how a site is moved between database engines - and a restore
        // that tried would drop the tables first and fail on the schema after.
        $connection = $this->installation();
        $foreign = $this->isSqlite($connection) ? DumpFormat::MYSQL : DumpFormat::SQLITE;

        $this->writeDump($connection, [
            ['type' => DumpFormat::HEADER, 'format' => DumpFormat::VERSION, 'platform' => $foreign, 'prefix' => 'pk_'],
            ['type' => DumpFormat::TABLE, 'name' => 'pk_items', 'ddl' => ['CREATE TABLE pk_items (id INTEGER)'], 'columns' => ['id']],
            ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 0],
        ]);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A dump from another kind of database must not be replayed');
        } catch (\RuntimeException $e) {
            // Both named, because this is an operator decision and not something
            // a retry would change.
            self::assertStringContainsString($foreign, $e->getMessage());
            self::assertStringContainsString($this->platformOf($connection), $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
    }

    public function testADumpThatNamesATableOutsideTheInstallationIsRefusedWithoutDroppingIt(): void
    {
        // A snapshot directory is a file tree on disk, so what a dump names is
        // not necessarily what this installation wrote. Every table it names is
        // dropped, which makes the prefix a boundary rather than a convention.
        $connection = $this->installation();

        $this->writeDump($connection, [
            self::header(),
            ['type' => DumpFormat::TABLE, 'name' => 'other_items', 'ddl' => ['CREATE TABLE other_items (id INTEGER)'], 'columns' => ['id']],
            ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 0],
        ]);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A dump naming a table outside the installation must not be replayed');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not part of this installation', $e->getMessage());
        }

        self::assertCount(1, $connection->fetchAllAssociative('SELECT id FROM other_items'));
    }

    public function testADumpNamingACopyARestoreMakesForItselfIsRefusedRatherThanCopiedAgain(): void
    {
        // A dump holding one of those names was taken while a restore was stuck
        // partway - nothing else ever makes such a table - so replaying it would
        // have this restore fill a copy of a copy, under a name it then reads as
        // a leftover of its own.
        $connection = $this->installation();

        $this->dumpNaming($connection, [RestoreTableNames::shadow('pk_items')]);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A dump naming a table a restore makes for itself must not be replayed');
        } catch (\RuntimeException $e) {
            // The name lies outside the prefix as well, and it is this reading
            // that has to answer first: where an installation has no prefix, the
            // other one has nothing to say about any name at all.
            self::assertStringContainsString('that a restore makes for itself', $e->getMessage());
            self::assertStringNotContainsString('not part of this installation', $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
    }

    public function testADumpNamingACopyIsRefusedEvenWhereTheInstallationOwnsEveryTableInTheDatabase(): void
    {
        $connection = $this->installation('');

        if (!$this->isSqlite($connection)) {
            // A MySQL restore of an installation whose tables carry no prefix is
            // refused before the dump is opened at all, which is asserted where
            // that refusal is.
            self::markTestSkipped('Only SQLite restores an installation whose tables carry no prefix');
        }

        $this->dumpNaming($connection, [RestoreTableNames::shadow('pk_items')]);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A dump naming a table a restore makes for itself must not be replayed');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('that a restore makes for itself', $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
    }

    public function testAFileThatIsNotThereIsRefusedRatherThanReadAsAnEmptyDatabase(): void
    {
        $connection = $this->installation();

        try {
            (new DatabaseRestorer($connection))->restore($this->workspace.'/never-written.dump');

            self::fail('A dump that is not there must not be replayed');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Failed to open the database dump', $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
    }

    #[DataProvider('provideDumpsThatMayNotBeReplayed')]
    public function testADumpThatCannotBeTrustedIsRefusedWhileTheDatabaseItWouldReplaceIsStillThere(string $content, string $refusal): void
    {
        // Every one of these is a file that would restore a database missing
        // something nobody would notice was gone. The whole file is read before
        // a statement runs, which is what makes the refusal free of cost.
        $connection = $this->installation();

        file_put_contents($this->dump(), str_replace(self::DATABASE_UNDER_TEST, $this->platformOf($connection), $content));

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A dump that cannot be trusted must not be replayed');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($refusal, $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
        self::assertSame(1, $this->countMeta($connection));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideDumpsThatMayNotBeReplayed(): array
    {
        $header = self::compose([self::header()]);
        $table = self::compose([['type' => DumpFormat::TABLE, 'name' => 'pk_items', 'ddl' => ['CREATE TABLE pk_items (id INTEGER)'], 'columns' => ['id']]]);

        return [
            'a file with nothing in it' => ['', 'not a database dump'],
            'a file that is not a dump at all' => ["a note to self\n", 'holds a line that cannot be read'],
            'a dump whose first record is not the header' => [$table, 'not a database dump'],
            'a dump written in a layout this version does not know' => [
                self::compose([self::header(['format' => 99])]),
                'this installation reads format',
            ],
            'a dump that does not say which layout it is in' => [
                self::compose([['type' => DumpFormat::HEADER, 'platform' => self::DATABASE_UNDER_TEST, 'prefix' => 'pk_']]),
                'an unstated version',
            ],
            'a dump from an installation whose tables are named differently' => [
                self::compose([self::header(['prefix' => 'wp_'])]),
                'names its tables with the prefix "wp_"',
            ],
            'a dump cut short by a full disk' => [$header.$table, 'is incomplete'],
            'a dump shortened after it was written' => [
                $header.$table.self::compose([['type' => DumpFormat::END, 'tables' => 1, 'rows' => 7]]),
                'does not hold as much as it says it holds',
            ],
            'a dump with something appended after the line that ends it' => [
                $header.$table.self::compose([
                    ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 0],
                    ['type' => DumpFormat::ROW, 'values' => [1]],
                ]),
                'past the line that ends it',
            ],
            'a dump of no tables, which restores nothing' => [
                $header.self::compose([['type' => DumpFormat::END, 'tables' => 0, 'rows' => 0]]),
                'holds no tables',
            ],
            'a table with no name' => [
                $header.self::compose([
                    ['type' => DumpFormat::TABLE, 'ddl' => ['CREATE TABLE pk_items (id INTEGER)'], 'columns' => ['id']],
                    ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 0],
                ]),
                'carries no name',
            ],
            'a table with nothing that recreates it' => [
                $header.self::compose([
                    ['type' => DumpFormat::TABLE, 'name' => 'pk_items', 'ddl' => [], 'columns' => ['id']],
                    ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 0],
                ]),
                'carries no schema',
            ],
            'a table whose columns are not named' => [
                $header.self::compose([
                    ['type' => DumpFormat::TABLE, 'name' => 'pk_items', 'ddl' => ['CREATE TABLE pk_items (id INTEGER)'], 'columns' => []],
                    ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 0],
                ]),
                'carries no column names',
            ],
            // Every entry in a table's schema is run as a statement, so this is
            // the line between a file on disk and the database: whatever a dump
            // holds there has to be text before it is anything else.
            'a table whose schema holds something that is no statement' => [
                $header.self::compose([
                    ['type' => DumpFormat::TABLE, 'name' => 'pk_items', 'ddl' => [42], 'columns' => ['id']],
                    ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 0],
                ]),
                'entry in its schema that is not text',
            ],
            'a table one of whose columns is not named' => [
                $header.self::compose([
                    ['type' => DumpFormat::TABLE, 'name' => 'pk_items', 'ddl' => ['CREATE TABLE pk_items (id INTEGER)'], 'columns' => ['id', null]],
                    ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 0],
                ]),
                'entry in its column names that is not text',
            ],
            'a row before the table it belongs to' => [
                $header.self::compose([
                    ['type' => DumpFormat::ROW, 'values' => [1]],
                    ['type' => DumpFormat::END, 'tables' => 0, 'rows' => 1],
                ]),
                'holds a row before the table it belongs to',
            ],
            'a row that does not carry one value per column' => [
                $header.$table.self::compose([
                    ['type' => DumpFormat::ROW, 'values' => [1, 'one too many']],
                    ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 1],
                ]),
                'one value per column',
            ],
            'a row carrying bytes that cannot be read back' => [
                $header.$table.self::compose([
                    ['type' => DumpFormat::ROW, 'values' => [['b64' => '!! not base64 !!']]],
                    ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 1],
                ]),
                'binary column value in the dump cannot be read',
            ],
            'a record of a kind this format does not define' => [
                $header.self::compose([
                    ['type' => 'trigger', 'name' => 'pk_items_after_insert'],
                    ['type' => DumpFormat::END, 'tables' => 0, 'rows' => 0],
                ]),
                'a record of a kind this format does not define',
            ],
            'a record that does not say what it is' => [
                $header.self::compose([
                    ['name' => 'pk_items'],
                    ['type' => DumpFormat::END, 'tables' => 0, 'rows' => 0],
                ]),
                'a record that does not say what it is',
            ],
            'a line that is a value rather than a record' => [$header."42\n", 'a line that is not a record'],
        ];
    }

    // ------------------------------------------------------------------
    // What a MySQL restore refuses while the installation is still whole
    // ------------------------------------------------------------------

    public function testARestoreOfAnInstallationWhoseTablesCarryNoPrefixIsRefusedBeforeTheDumpIsEvenOpened(): void
    {
        // A MySQL restore fills copies of the tables and swaps them in, and both
        // halves of that have to know which tables are this installation's.
        // Without a prefix every table in the database reads as one. That is a
        // fact about the installation rather than about any file, so the file is
        // not so much as looked for.
        $connection = $this->mysqlInstallation('');

        try {
            (new DatabaseRestorer($connection))->restore($this->workspace.'/never-written.dump');

            self::fail('A MySQL restore of an installation whose tables carry no prefix must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('carry no name prefix', $e->getMessage());
            self::assertStringNotContainsString('Failed to open the database dump', $e->getMessage());

            // And what to do about it: this reaches an operator through the
            // snapshots panel, where the only way on is a decision of theirs.
            self::assertStringContainsString('"pk_"', $e->getMessage());
        }

        self::assertSame([], $connection->asked, 'The refusal came before the server was asked anything');
        self::assertSame(2, $this->countItems($connection));
    }

    public function testAnInstallationWhoseTablesCarryNoPrefixIsStillRestoredOnSqlite(): void
    {
        // The refusal above is MySQL's alone, because copies are what needs
        // telling apart from the tables they were made of. SQLite replaces the
        // tables where they stand, inside a transaction, so the installation
        // nobody gave a prefix is one it still puts back - and there the dump is
        // the whole database, the table a neighbour would have owned included.
        $connection = $this->installation('');

        if (!$this->isSqlite($connection)) {
            self::markTestSkipped('A MySQL restore of an installation whose tables carry no prefix is refused rather than run');
        }

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->insert('other_items', ['title' => 'written after the snapshot']);

        $summary = (new DatabaseRestorer($connection))->restore($this->dump());

        self::assertSame(['tables' => 3, 'rows' => 4], $summary);
        self::assertCount(1, $connection->fetchAllAssociative('SELECT id FROM other_items'));
    }

    public function testATableWhoseCopyWouldBeTooLongForMysqlIsRefusedRatherThanRenamedIntoOne(): void
    {
        // A copy is the live name behind a marker, so a table named nearly as
        // long as MySQL allows is one no copy can be made for. Nothing has to be
        // dropped to find that out, and the name of the table that has to change
        // is no use to an operator without the two lengths beside it.
        $connection = $this->mysqlInstallation();

        $table = 'pk_'.str_repeat('a', 59);

        $this->dumpNaming($connection, [$table]);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A table whose copy would be longer than MySQL allows must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(RestoreTableNames::shadow($table), $e->getMessage());
            self::assertMatchesRegularExpression('/65.+64/', $e->getMessage());
        }

        self::assertSame([], $connection->asked, 'The refusal came before the server was asked anything');
        self::assertSame(2, $this->countItems($connection));
    }

    public function testATableWhoseCopyIsExactlyAsLongAsMysqlAllowsIsNotTurnedAwayForItsLength(): void
    {
        // 64 characters is what MySQL takes. The platform's own answer is 63, and
        // a restore measuring by that would turn away a table the server holds
        // quite happily - so what stops this one is the leftover copy in the
        // database, which is the next refusal along.
        $connection = $this->mysqlInstallation();

        $table = 'pk_'.str_repeat('a', 58);

        self::assertSame(64, strlen(RestoreTableNames::shadow($table)), 'The copy of this table is exactly as long as MySQL allows');

        $this->tableNamed($connection, RestoreTableNames::backup('pk_meta'));
        $this->dumpNaming($connection, [$table]);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A restore must be refused while a copy of one of these tables is still in the database');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(RestoreTableNames::backup('pk_meta'), $e->getMessage());
            self::assertStringNotContainsString($table, $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
    }

    public function testATableNamedInMoreThanAsciiIsMeasuredInTheCharactersMysqlCounts(): void
    {
        // What MySQL allows 64 of is characters, so a site whose tables are named
        // in a script that takes more than one byte to a character would have
        // every restore refused if the copy were measured in bytes - and this
        // name is not near the limit in characters at all.
        $connection = $this->mysqlInstallation();

        $table = 'pk_'.str_repeat('ü', 40);

        $this->tableNamed($connection, RestoreTableNames::backup('pk_meta'));
        $this->dumpNaming($connection, [$table]);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A restore must be refused while a copy of one of these tables is still in the database');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(RestoreTableNames::backup('pk_meta'), $e->getMessage());
            self::assertStringNotContainsString($table, $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
    }

    public function testCopiesLeftBehindByARestoreThatDidNotFinishAreRefusedRatherThanWrittenOver(): void
    {
        // They are the only record of how far the last restore got, and filling
        // them again would write over it. Named rather than counted, and in an
        // order of the restore's own, because working through them by hand is
        // what an operator does next.
        $connection = $this->mysqlInstallation();

        $this->tableNamed($connection, RestoreTableNames::shadow('pk_items'));
        $this->tableNamed($connection, RestoreTableNames::backup('pk_meta'));

        $this->dumpNaming($connection, ['pk_items', 'pk_meta']);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A restore must be refused while copies of its tables are still in the database');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('_b_pk_meta, _r_pk_items', $e->getMessage());
        }

        // Refused, not cleared away: what the last restore left is still there
        // for an operator to look at, and the installation still holds its rows.
        self::assertSame(['_b_pk_meta', '_r_pk_items'], $this->copiesIn($connection));
        self::assertSame(2, $this->countItems($connection));
        self::assertSame(1, $this->countMeta($connection));
    }

    public function testACopyLeftBehindIsRefusedEvenWhereThisDumpHasNoUseForItsName(): void
    {
        // The name being free is not the point. The table is one nobody asked
        // for, holding as much of a table as a restore had written when it
        // stopped, and the installation it was made for is this one - so it is
        // this installation's problem whether or not this dump wants the name.
        $connection = $this->mysqlInstallation();

        $this->tableNamed($connection, RestoreTableNames::shadow('pk_gone'));

        $this->dumpNaming($connection, ['pk_items']);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A restore must be refused while a copy of one of this installation\'s tables is still in the database');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('_r_pk_gone', $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function provideWhatAServerSaysAboutFoldingNames(): array
    {
        return [
            'a server that matches names as they are written' => ['0'],
            'a server that stores them folded' => ['1'],
            'a server that stores them as given and matches them folded' => ['2'],
            'a server that does not say' => [null],
        ];
    }

    #[DataProvider('provideWhatAServerSaysAboutFoldingNames')]
    public function testACopyOfATableThisInstallationDoesNotOwnIsLeftToWhoeverMadeIt(?string $folding): void
    {
        // A database is a place installations share, so a name that reads as a
        // copy is not necessarily a copy of anything here: the table it was made
        // for says whose it is. One made for a table this installation does not
        // own is a table like any other - not this restore's to drop, and not its
        // business to refuse over either, however the server matches names.
        $connection = $this->mysqlInstallation();
        $connection->folding = $folding;

        $this->tableNamed($connection, RestoreTableNames::shadow('wp_items'));
        $this->tableNamed($connection, RestoreTableNames::backup('pk_meta'));

        $this->dumpNaming($connection, ['pk_items', 'pk_meta']);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A restore must be refused while a copy of one of this installation\'s tables is still in the database');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('_b_pk_meta', $e->getMessage());
            self::assertStringNotContainsString('_r_wp_items', $e->getMessage());
        }

        self::assertContains('_r_wp_items', $this->copiesIn($connection));
    }

    public function testAMarkerInACaseNoRestoreWritesIsAnotherTableWhereTheServerMatchesNamesAsTheyAreWritten(): void
    {
        // A restore writes its markers in one case, so on a server that hands
        // names back as they were given, a marker in another case was written by
        // something else and names a table of its own.
        $connection = $this->mysqlInstallation();

        $this->tableNamed($connection, '_R_pk_items');
        $this->tableNamed($connection, RestoreTableNames::backup('pk_meta'));

        $this->requireNamesKeptAsGiven($connection, '_R_pk_items');

        $this->dumpNaming($connection, ['pk_items', 'pk_meta']);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A restore must be refused while a copy of one of this installation\'s tables is still in the database');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('_b_pk_meta', $e->getMessage());
            self::assertStringNotContainsStringIgnoringCase('_r_pk_items', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideServersThatMatchNamesFolded(): array
    {
        return [
            'a server that stores names folded' => ['1'],
            'a server that stores them as given and matches them folded' => ['2'],
        ];
    }

    #[DataProvider('provideServersThatMatchNamesFolded')]
    public function testACopyIsRecognisedThroughItsCaseWhereTheServerMatchesNamesFolded(string $folding): void
    {
        // Whether two names are one table is the server's rule rather than PHP's.
        // Asked to match names folded, it hands back the table that is there for
        // any spelling of it - so a restore comparing as written would look
        // straight past the copy it was checking for, and then make, and later
        // drop, a table it never accounted for.
        $connection = $this->mysqlInstallation();
        $connection->folding = $folding;

        $this->tableNamed($connection, '_R_pk_items');

        $this->dumpNaming($connection, ['pk_items']);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A restore must be refused while a copy of one of its tables is still in the database');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('_r_pk_items', $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
    }

    public function testATableTheDumpDoesNotHoldThatPointsAtOneItDoesIsRefusedByName(): void
    {
        // The swap a restore ends with renames the live table aside, and MySQL
        // takes a foreign key along to the table it points at rather than leaving
        // it on the name: a reference from outside the dump would end up on the
        // copy that is on its way out, which the restore then cannot remove.
        // References between tables the dump holds are swapped in the same
        // statement, and one pointing out of the dump is not moved at all.
        $connection = $this->mysqlInstallation();
        $connection->references = [
            ['name' => 'fk_from_a_neighbour', 'child' => 'other_items', 'parent' => 'pk_items'],
            ['name' => 'fk_between_two_dumped_tables', 'child' => 'pk_meta', 'parent' => 'pk_items'],
            ['name' => 'fk_out_of_the_dump', 'child' => 'pk_items', 'parent' => 'other_items'],
        ];

        $this->dumpNaming($connection, ['pk_items', 'pk_meta']);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A table outside the dump pointing at one inside it must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('fk_from_a_neighbour', $e->getMessage());
            self::assertStringContainsString('other_items', $e->getMessage());
            self::assertStringNotContainsString('fk_between_two_dumped_tables', $e->getMessage());
            self::assertStringNotContainsString('fk_out_of_the_dump', $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
    }

    public function testAReferenceIsReadAgainstTheDumpTheWayTheServerMatchesNames(): void
    {
        // Which end of a reference is in the dump is the same question as which
        // table a copy was made for, and it is answered the same way: where names
        // are matched folded, a catalogue naming the table in another case is
        // naming one of the tables about to be swapped.
        $connection = $this->mysqlInstallation();
        $connection->folding = '1';
        $connection->references = [
            ['name' => 'fk_from_a_neighbour', 'child' => 'OTHER_ITEMS', 'parent' => 'PK_ITEMS'],
        ];

        $this->dumpNaming($connection, ['pk_items']);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A table outside the dump pointing at one inside it must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('fk_from_a_neighbour', $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
    }

    public function testATableOutsideTheDumpPointingIntoItIsRefusedByWhatTheServerItselfReports(): void
    {
        $connection = $this->installation();

        if ($this->isSqlite($connection)) {
            // The catalogue the references are read out of is MySQL's, and so is
            // the swap that would take them along.
            self::markTestSkipped('Only a MySQL restore has references to be refused over');
        }

        (new DatabaseDumper($connection))->dump($this->dump());

        $neighbour = new Table('other_links');
        $neighbour->addColumn('id', Types::INTEGER, ['notnull' => true]);
        $neighbour->addColumn('item_id', Types::INTEGER, ['notnull' => true]);
        $neighbour->setPrimaryKey(['id']);
        $neighbour->addForeignKeyConstraint('pk_items', ['item_id'], ['id'], [], 'fk_other_links_pk_items');
        $connection->createSchemaManager()->createTable($neighbour);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A table outside the dump pointing at one inside it must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('other_links', $e->getMessage());
            self::assertStringContainsString('pk_items', $e->getMessage());
            self::assertStringContainsString('fk_other_links_pk_items', $e->getMessage());
        }

        self::assertSame(2, $this->countItems($connection));
    }

    public function testCopiesARestoreLeftBehindAreNoObstacleToASqliteRestore(): void
    {
        // What they stand in the way of is a swap, and SQLite makes none. Refusing
        // over them there would leave an installation that can no longer be
        // restored at all over tables no restore of its own would ever have made.
        $connection = $this->installation();

        if (!$this->isSqlite($connection)) {
            self::markTestSkipped('A MySQL restore is refused while copies of its tables are still in the database');
        }

        $this->tableNamed($connection, RestoreTableNames::shadow('pk_items'));

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->executeStatement('DELETE FROM pk_items');

        $summary = (new DatabaseRestorer($connection))->restore($this->dump());

        self::assertSame(['tables' => 2, 'rows' => 3], $summary);
        self::assertSame(2, $this->countItems($connection));
        self::assertSame(['_r_pk_items'], $this->copiesIn($connection));
    }

    // ------------------------------------------------------------------
    // A restore that fails while it is running
    // ------------------------------------------------------------------

    public function testARestoreThatFailedPartwayIsPutRightByRunningItAgain(): void
    {
        // A restore that could not be carried through is one that did not happen,
        // and the snapshot it was reading is still on disk: replaying it is what
        // finishes the job.
        $connection = $this->installation();

        (new DatabaseDumper($connection))->dump($this->dump());

        $restorer = new DatabaseRestorer($connection);

        try {
            $restorer->restore($this->brokenDump($connection));

            self::fail('A restore that could not be applied must not be reported as done');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Failed to restore the database', $e->getMessage());
        }

        self::assertSame(['tables' => 2, 'rows' => 3], $restorer->restore($this->dump()));
        self::assertSame(2, $this->countItems($connection));
        self::assertSame(self::TITLE, $this->readColumn($connection, 'title'));
    }

    public function testARestoreThatFailedPartwayLeavesTheInstallationTheWayItFoundIt(): void
    {
        // Neither engine leaves half of an installation the snapshot and the other
        // half what the site had. SQLite replaces the tables where they stand and
        // the transaction takes the whole of a failure back; on MySQL the dump had
        // gone into copies, so nothing the site reads was written at all and what
        // the failure costs is the copies.
        $connection = $this->installation();

        $connection->executeStatement('DELETE FROM pk_items');
        $connection->insert('pk_items', ['title' => 'written after the snapshot', 'status' => 0]);

        try {
            (new DatabaseRestorer($connection))->restore($this->brokenDump($connection));

            self::fail('A restore that could not be applied must not be reported as done');
        } catch (\RuntimeException) {
            // The state left behind is what this is about.
        }

        // The dump names two tables and failed on the second: the row written
        // after the snapshot is still the only one in the first, and the second is
        // as it was as well.
        self::assertSame(1, $this->countItems($connection));
        self::assertSame('written after the snapshot', $connection->fetchOne('SELECT title FROM pk_items'));
        self::assertSame(1, $this->countMeta($connection));

        // And nothing a restore gave a name of its own is standing in the database
        // for somebody to work out what it was.
        self::assertSame([], $this->copiesIn($connection));
    }

    public function testAnInstallationOnADatabaseNoDumpFitsIsRefusedBeforeAnythingIsDropped(): void
    {
        $connection = $this->openDatabase('pk_', ConnectionOnAnUnsupportedDatabase::class);

        $this->writeDump($connection, [
            self::header(),
            ['type' => DumpFormat::TABLE, 'name' => 'pk_items', 'ddl' => ['CREATE TABLE pk_items (id INTEGER)'], 'columns' => ['id']],
            ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 0],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not one a snapshot can be taken of or restored into');

        (new DatabaseRestorer($connection))->restore($this->dump());
    }

    // ------------------------------------------------------------------
    // The copies a restore fills before it touches anything live
    // ------------------------------------------------------------------

    public function testACopyHoldsWhatTheDumpHeldWhileTheTableItWasMadeFromGoesOnBeingRead(): void
    {
        // This is the half of a restore that can be thrown away. The dump goes into
        // copies of the tables, and until those are swapped in the installation is
        // still serving out of the tables it was serving out of - rows written
        // since the snapshot was taken included.
        $connection = $this->installation();
        $snapshotted = $this->items($connection);

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->insert('pk_items', ['title' => 'written after the snapshot', 'status' => 0]);
        $live = $this->items($connection);

        $copies = (new DatabaseRestorer($connection))->fill($this->dump(), ['pk_items', 'pk_meta']);

        // One copy per table the dump holds, named after the table it was made
        // for, and left standing for whoever swaps them in.
        self::assertSame(['_r_pk_items', '_r_pk_meta'], $copies);
        self::assertSame($copies, $this->copiesIn($connection));

        // Every value of every row, read the way the round trip above reads it:
        // what a copy is worth is that it holds the database the dump holds.
        self::assertSame($snapshotted, $this->itemsIn($connection, '_r_pk_items'));
        self::assertSame(1, $this->countIn($connection, '_r_pk_meta'));

        self::assertSame($live, $this->items($connection));
    }

    public function testACopyIsMadeOutOfTheSchemaTheDumpHoldsRatherThanOutOfTheTableAsItStandsNow(): void
    {
        // What the live table looks like now is not what the snapshot holds - an
        // update since has added a column, a removal has dropped one - so a copy
        // made in the shape of the live table is one the dumped rows do not fit.
        $connection = $this->installation();

        $this->writeDump($connection, [
            self::header(),
            ['type' => DumpFormat::TABLE, 'name' => 'pk_meta', 'ddl' => ['CREATE TABLE pk_meta (name VARCHAR(64) NOT NULL, PRIMARY KEY(name))'], 'columns' => ['name']],
            ['type' => DumpFormat::ROW, 'values' => ['version']],
            ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 1],
        ]);

        (new DatabaseRestorer($connection))->fill($this->dump(), ['pk_meta']);

        self::assertSame(['name'], $this->columnsOf($connection, '_r_pk_meta'));
        self::assertSame(1, $this->countIn($connection, '_r_pk_meta'));

        // And the table it was made from is the one the site is still reading.
        self::assertSame(['name', 'value'], $this->columnsOf($connection, 'pk_meta'));
    }

    public function testAReferenceInACopyPointsAtTheCopyOfWhatItPointedAt(): void
    {
        // The copies are swapped in together, so among them the references have to
        // point at copies as well: one left pointing at a live table is a restored
        // table tied to the table it replaced. Which tables are being swapped is
        // told to the fill rather than read out of the dump, because the dump is
        // walked a record at a time and the table a reference names can lie further
        // along it - as it does here, where the copy holding the reference is
        // created before the copy it points at exists. Which is also why a fill
        // belongs inside the window where references are not enforced.
        $connection = $this->relatedWithoutAnIndexOfItsOwn();
        $this->enforceReferences($connection, false);

        (new DatabaseDumper($connection))->dump($this->dump());

        (new DatabaseRestorer($connection))->fill($this->dump(), ['pk_a_child', 'pk_b_parent']);

        self::assertSame(['_r_pk_b_parent'], $this->referencesOf($connection, '_r_pk_a_child'));
        self::assertSame(1, $this->countIn($connection, '_r_pk_a_child'));

        self::assertSame(['pk_b_parent'], $this->referencesOf($connection, 'pk_a_child'));
    }

    public function testAFillThatCannotCreateOneCopyLeavesNoneOfThemBehind(): void
    {
        // A copy left standing is a table nobody declared, holding as much of a
        // table as the fill had written - and the next restore refuses while it is
        // there. So a fill that cannot be carried through costs the copies and
        // nothing else: the installation is the one it was before the dump was
        // opened.
        $connection = $this->installation();

        try {
            (new DatabaseRestorer($connection))->fill($this->brokenDump($connection), ['pk_items', 'pk_meta']);

            self::fail('A fill that could not create a copy must not be reported as done');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('"pk_meta"', $e->getMessage());
        }

        self::assertSame([], $this->copiesIn($connection));

        // The first table's copy had already been filled when the second table's
        // schema was refused, and the table it was a copy of never heard about any
        // of it.
        self::assertSame(2, $this->countItems($connection));
        self::assertSame(self::TITLE, $this->readColumn($connection, 'title'));
        self::assertSame(1, $this->countMeta($connection));
    }

    public function testACopyThatWasOnlyPartlyCreatedIsDroppedWithTheRest(): void
    {
        // A copy is a copy from the first statement of its schema onwards: the
        // table is created and then the index belonging to it will not go on. What
        // is cleaned up is therefore every copy the fill began rather than every
        // copy it finished.
        $connection = $this->installation();

        $this->writeDump($connection, [
            self::header(),
            ['type' => DumpFormat::TABLE, 'name' => 'pk_items', 'ddl' => [
                'CREATE TABLE pk_items (id INTEGER NOT NULL, PRIMARY KEY(id))',
                'CREATE INDEX IDX_NOTHING ON pk_items (a_column_no_table_has)',
            ], 'columns' => ['id']],
            ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 0],
        ]);

        try {
            (new DatabaseRestorer($connection))->fill($this->dump(), ['pk_items']);

            self::fail('A fill whose copy could not be finished must not be reported as done');
        } catch (DatabaseFailure) {
            // Which statement the server refused is the server's business; what
            // was left behind is this test's.
        }

        self::assertSame([], $this->copiesIn($connection));
        self::assertSame(2, $this->countItems($connection));
    }

    public function testARowThatWillNotGoIntoACopyTakesEveryCopyWithIt(): void
    {
        // A disk that fills, a connection that goes away and a row that does not
        // fit come to the same thing as far as the installation is concerned: the
        // copies go, the tables stay, and the snapshot is still on disk to try
        // again from.
        $connection = $this->installation();

        $this->writeDump($connection, [
            self::header(),
            ['type' => DumpFormat::TABLE, 'name' => 'pk_meta', 'ddl' => ['CREATE TABLE pk_meta (name VARCHAR(64) NOT NULL, PRIMARY KEY(name))'], 'columns' => ['name']],
            ['type' => DumpFormat::ROW, 'values' => ['version']],
            ['type' => DumpFormat::ROW, 'values' => ['version']],
            ['type' => DumpFormat::END, 'tables' => 1, 'rows' => 2],
        ]);

        try {
            (new DatabaseRestorer($connection))->fill($this->dump(), ['pk_meta']);

            self::fail('A fill that could not write a row must not be reported as done');
        } catch (DatabaseFailure) {
            // As above: the row the server would not take is its to name.
        }

        self::assertSame([], $this->copiesIn($connection));
        self::assertSame(1, $this->countMeta($connection));
    }

    public function testAFillThatCannotCleanUpAfterItselfReportsTheFailureThatCausedIt(): void
    {
        // A copy that will not drop is a refusal for whoever runs the next restore,
        // which is where it stands in the way. Reported here it would stand in
        // front of the failure that actually has to be acted on - and the copy is
        // named to an operator either way.
        $connection = $this->installationThatWillNotDrop();

        try {
            (new DatabaseRestorer($connection))->fill($this->brokenDump($connection), ['pk_items', 'pk_meta']);

            self::fail('A fill that could not create a copy must not be reported as done');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('"pk_meta"', $e->getMessage());
            self::assertStringNotContainsString('could not be dropped', $e->getMessage());
        }

        self::assertSame(['_r_pk_items'], $this->copiesIn($connection));
        self::assertSame(2, $this->countItems($connection));
    }

    public function testACopyThatWillNotDropDoesNotLeaveTheOtherCopiesStanding(): void
    {
        // Every copy is tried before anything is reported. Given up at the first
        // one that will not go, the rest would be left behind as well - tables
        // nobody declared, and every one of them a refusal the next restore raises
        // and somebody has to clear by hand.
        $connection = $this->installationThatWillNotDrop();
        $connection->keeps = RestoreTableNames::shadow('pk_items');

        $this->writeDump($connection, [
            self::header(),
            ['type' => DumpFormat::TABLE, 'name' => 'pk_items', 'ddl' => ['CREATE TABLE pk_items (id INTEGER NOT NULL, PRIMARY KEY(id))'], 'columns' => ['id']],
            ['type' => DumpFormat::TABLE, 'name' => 'pk_meta', 'ddl' => ['CREATE TABLE pk_meta (name VARCHAR(64) NOT NULL, PRIMARY KEY(name))'], 'columns' => ['name']],
            ['type' => DumpFormat::TABLE, 'name' => 'pk_later', 'ddl' => ['NOT A STATEMENT ANY DATABASE RUNS'], 'columns' => ['id']],
            ['type' => DumpFormat::END, 'tables' => 3, 'rows' => 0],
        ]);

        try {
            (new DatabaseRestorer($connection))->fill($this->dump(), ['pk_items', 'pk_meta', 'pk_later']);

            self::fail('A fill that could not create a copy must not be reported as done');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('"pk_later"', $e->getMessage());
        }

        // The copy of the first table is the one that would not go, and the copy of
        // the second was tried after it all the same.
        self::assertSame(['_r_pk_items'], $this->copiesIn($connection));
    }

    // ------------------------------------------------------------------
    // The one statement the copies are swapped in with
    // ------------------------------------------------------------------

    public function testEveryCopyIsSwappedInByOneStatementThatSetsTheTableItReplacesAsideFirst(): void
    {
        // The whole of a restore is spent beside the installation so that this is
        // the only moment what the site reads changes, and a rename of several
        // tables either moves every name in it or none of them. A statement per
        // table would undo that: MySQL takes a foreign key along with the table it
        // points at, so a table renamed aside on its own leaves the tables not yet
        // renamed pointing at the copies on their way out. Every name in it is
        // written out quoted, and none in the @-led spelling the connection
        // substitutes a table prefix for, which would arrive as another name again.
        $connection = $this->mysqlInstallationThatWillNotSwap();

        $this->dumpNaming($connection, ['pk_items', 'pk_meta']);

        $this->refusedSwap($connection);

        self::assertSame(
            ['RENAME TABLE `pk_items` TO `_b_pk_items`, `_r_pk_items` TO `pk_items`, `pk_meta` TO `_b_pk_meta`, `_r_pk_meta` TO `pk_meta`'],
            $connection->swaps,
        );
    }

    public function testATableTheInstallationNoLongerHoldsIsSwappedInWithNothingSetAside(): void
    {
        // A dump can hold a table the installation has since lost - the snapshot was
        // taken before something dropped it - and then there is nothing to set
        // aside, only a name standing free for the copy to move into.
        $connection = $this->mysqlInstallationThatWillNotSwap();

        $this->dumpNaming($connection, ['pk_items', 'pk_gone']);

        $this->refusedSwap($connection);

        self::assertSame(
            ['RENAME TABLE `pk_items` TO `_b_pk_items`, `_r_pk_items` TO `pk_items`, `_r_pk_gone` TO `pk_gone`'],
            $connection->swaps,
        );
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function provideHowAServerReadsTheNameOfTheTableToSetAside(): array
    {
        $aside = 'RENAME TABLE `pk_items` TO `_b_pk_items`, `_r_pk_items` TO `pk_items`';
        $free = 'RENAME TABLE `_r_pk_items` TO `pk_items`';

        return [
            'a server that matches names as they are written' => ['0', $free],
            'a server that does not say' => [null, $free],
            'a server that stores names folded' => ['1', $aside],
            'a server that stores them as given and matches them folded' => ['2', $aside],
        ];
    }

    #[DataProvider('provideHowAServerReadsTheNameOfTheTableToSetAside')]
    public function testWhetherThereIsATableToSetAsideIsReadTheWayTheServerMatchesNames(?string $folding, string $swap): void
    {
        // Whether the installation still holds the table a copy was made for is the
        // same question as whether two names are one table, and that is the server's
        // rule rather than PHP's. Where names are matched folded, the table spelled
        // in another case is the one the copy replaces and it has to be moved out of
        // the way first; where they are not, it is a table of its own and the name
        // the copy wants is free.
        $connection = $this->databaseThatWillNotSwap();
        $connection->folding = $folding;

        $this->tableNamed($connection, 'PK_ITEMS');
        $this->requireNamesKeptAsGiven($connection, 'PK_ITEMS');

        $this->dumpNaming($connection, ['pk_items']);

        $this->refusedSwap($connection);

        self::assertSame([$swap], $connection->swaps);
    }

    public function testASwapThatDidNotGoThroughLeavesTheInstallationTheWayItFoundIt(): void
    {
        // Up to the swap a restore has written nothing the site reads, so a swap the
        // server would not carry out costs the copies and nothing else. What the
        // caller is told is the server's own failure, as a restore that did not
        // happen - the snapshot is still on disk and running it again is what
        // finishes the job.
        $connection = $this->mysqlInstallationThatWillNotSwap();
        $live = $this->items($connection);

        $this->dumpNaming($connection, ['pk_items', 'pk_meta']);

        $failure = $this->refusedSwap($connection);
        $cause = $failure->getPrevious();

        self::assertStringContainsString('Failed to restore the database from', $failure->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $cause);
        self::assertSame('The tables could not be swapped.', $cause->getMessage());

        self::assertSame([], $this->copiesIn($connection));
        self::assertSame($live, $this->items($connection));
        self::assertSame(1, $this->countMeta($connection));
    }

    public function testACopyTheDatabaseWillNotGiveUpDoesNotStandInFrontOfTheSwapThatFailed(): void
    {
        // Clearing the copies away is what a refused swap does next, and that can
        // fail as well. The failure to act on is still the swap: the copy left
        // behind is a name the next restore refuses over, which is where an operator
        // meets it.
        $connection = $this->mysqlInstallationThatWillNotSwap();
        $connection->keeps = RestoreTableNames::shadow('pk_items');

        $this->dumpNaming($connection, ['pk_items', 'pk_meta']);

        $failure = $this->refusedSwap($connection);
        $cause = $failure->getPrevious();

        self::assertInstanceOf(\RuntimeException::class, $cause);
        self::assertSame('The tables could not be swapped.', $cause->getMessage());

        // The copy that would not go is still there and the other one was cleared
        // away regardless, while the tables the site reads never heard about any of
        // it.
        self::assertSame(['_r_pk_items'], $this->copiesIn($connection));
        self::assertSame(2, $this->countItems($connection));
        self::assertSame(1, $this->countMeta($connection));
    }

    public function testWhatARestoreRefusesIsPutToAnOperatorInItsOwnWords(): void
    {
        // A refusal names something somebody has to decide about - a table to
        // rename, a copy to clear away - and it reaches them through the snapshots
        // panel. Reported as a restore that failed, the sentence saying what to do
        // next would be the one thing the panel does not show.
        $connection = $this->mysqlInstallation();

        $this->tableNamed($connection, RestoreTableNames::shadow('pk_items'));
        $this->dumpNaming($connection, ['pk_items']);

        try {
            (new DatabaseRestorer($connection))->restore($this->dump());

            self::fail('A restore must be refused while a copy of one of its tables is still in the database');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Drop them and run the restore again', $e->getMessage());
            self::assertStringNotContainsString('Failed to restore the database', $e->getMessage());
        }
    }

    public function testARestoreThatWentThroughIsLeftWithNoTablesOfItsOwn(): void
    {
        // The tables a restore sets aside hold what the site was reading until the
        // swap, and nothing reaches them afterwards - so they go in the same window,
        // and a database somebody looks at later holds the installation and nothing a
        // restore invented. What is put back reaches exactly as far as the dump: a
        // table it holds that the installation has since lost comes back, and one
        // that arrived after it is left where it is.
        $connection = $this->installation();
        $snapshotted = $this->items($connection);

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->executeStatement('DELETE FROM pk_items');
        $connection->executeStatement('DROP TABLE pk_meta');

        $this->tableNamed($connection, 'pk_later');
        $connection->insert('pk_later', ['id' => 7]);

        $summary = (new DatabaseRestorer($connection))->restore($this->dump());

        self::assertSame(['tables' => 2, 'rows' => 3], $summary);
        self::assertSame([], $this->copiesIn($connection));
        self::assertSame($snapshotted, $this->items($connection));
        self::assertSame([['name' => 'version', 'value' => '1.4.2']], $connection->fetchAllAssociative('SELECT name, value FROM pk_meta'));
        self::assertSame([['id' => 7]], $connection->fetchAllAssociative('SELECT id FROM pk_later'));
    }

    public function testATableThatCouldNotBeSetAsideDoesNotUndoARestoreThatWentThrough(): void
    {
        // Past the swap the site is reading what the dump held, and the table it
        // replaced is one nothing reaches. A database that will not let that table go
        // has cost the disk it sits on and no more than that, so the restore stands
        // and an operator gets a line saying where the table came from; the next
        // restore is what clears the name away.
        $connection = $this->installationThatWillNotDrop();

        $this->requireTablesSetAside($connection);

        $connection->keeps = RestoreTableNames::backup('pk_items');

        $snapshotted = $this->items($connection);
        $audit = new SnapshotAudit();

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->executeStatement('DELETE FROM pk_items');

        $summary = (new DatabaseRestorer($connection, $audit))->restore($this->dump());

        self::assertSame(['tables' => 2, 'rows' => 3], $summary);
        self::assertSame($snapshotted, $this->items($connection));

        // The one table that would not go, and the line about it: what it is called,
        // that it could not be dropped, and that the restore happened regardless.
        self::assertSame(['_b_pk_items'], $this->copiesIn($connection));
        self::assertCount(1, $audit->records);
        self::assertSame('warning', $audit->records[0]['level']);
        self::assertStringContainsString('_b_pk_items', $audit->records[0]['message']);
        self::assertStringContainsString('could not be dropped', $audit->records[0]['message']);
        self::assertStringContainsString('was restored', $audit->records[0]['message']);
    }

    public function testALogThatCannotTakeTheLineDoesNotUndoARestoreThatWentThrough(): void
    {
        // The line is how an operator finds out about a table nothing reads any
        // more, and a log on a full disk is one more thing to look into - not a
        // reason to report a site that is serving the snapshot as a site that is not.
        $connection = $this->installationThatWillNotDrop();

        $this->requireTablesSetAside($connection);

        $connection->keeps = RestoreTableNames::backup('pk_items');

        $snapshotted = $this->items($connection);

        (new DatabaseDumper($connection))->dump($this->dump());

        $connection->executeStatement('DELETE FROM pk_items');

        $summary = (new DatabaseRestorer($connection, new AuditThatCannotBeWritten()))->restore($this->dump());

        self::assertSame(['tables' => 2, 'rows' => 3], $summary);
        self::assertSame($snapshotted, $this->items($connection));
    }

    // ------------------------------------------------------------------
    // The installation a dump is replayed into
    // ------------------------------------------------------------------

    /**
     * A database holding what an installation holds: two tables of its own with
     * values of every kind in them, and one table belonging to whatever else
     * shares the database.
     *
     * The prefix is the connection's - what the installation claims as its own -
     * and the three tables are the same either way: given no prefix, the table a
     * neighbour would have owned is the installation's too.
     *
     * @param class-string<Connection> $wrapper the connection as the test needs it
     *                                         to answer
     */
    private function installation(string $prefix = 'pk_', string $wrapper = Connection::class): Connection
    {
        $connection = $this->openDatabase($prefix, $wrapper);
        $manager = $connection->createSchemaManager();

        $items = new Table('pk_items');
        $items->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $items->addColumn('title', Types::STRING, ['length' => 191]);
        $items->addColumn('body', Types::TEXT, ['notnull' => false]);
        $items->addColumn('status', Types::BOOLEAN, ['default' => false]);
        $items->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $items->addColumn('score', Types::FLOAT, ['notnull' => false]);
        $items->addColumn('thumb', Types::BLOB, ['notnull' => false]);
        $items->setPrimaryKey(['id']);
        $manager->createTable($items);

        $meta = new Table('pk_meta');
        $meta->addColumn('name', Types::STRING, ['length' => 64]);
        $meta->addColumn('value', Types::TEXT, ['notnull' => false]);
        $meta->setPrimaryKey(['name']);
        $manager->createTable($meta);

        $other = new Table('other_items');
        $other->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $other->addColumn('title', Types::STRING, ['length' => 32]);
        $other->setPrimaryKey(['id']);
        $manager->createTable($other);

        $connection->insert('pk_items', [
            'title' => self::TITLE,
            'body' => "line one\nline two",
            'status' => 1,
            'created' => '2026-02-03 04:05:06',
            'score' => 1.5,
            'thumb' => self::thumbnail(),
        ], ['thumb' => ParameterType::BINARY]);

        $connection->insert('pk_items', [
            'title' => 'nothing else filled in',
            'body' => null,
            'status' => 0,
            'created' => null,
            'score' => null,
            'thumb' => null,
        ]);

        $connection->insert('pk_meta', ['name' => 'version', 'value' => '1.4.2']);
        $connection->insert('other_items', ['title' => 'not this installation to lose']);

        return $connection;
    }

    /**
     * The same installation, reading as one on MySQL: what a restore refuses
     * there it refuses before it has made anything, and a run with no MySQL
     * server can still be asked all of it.
     */
    private function mysqlInstallation(string $prefix = 'pk_'): ConnectionThatAnswersForAMysqlServer
    {
        $connection = $this->installation($prefix, ConnectionThatAnswersForAMysqlServer::class);

        self::assertInstanceOf(ConnectionThatAnswersForAMysqlServer::class, $connection);

        return $connection;
    }

    /**
     * The same installation on a database that will not let a table go, which is
     * the one failure a fill has to clear up around rather than report.
     */
    private function installationThatWillNotDrop(): ConnectionThatWillNotDropATable
    {
        $connection = $this->installation('pk_', ConnectionThatWillNotDropATable::class);

        self::assertInstanceOf(ConnectionThatWillNotDropATable::class, $connection);

        return $connection;
    }

    /**
     * The same installation on a MySQL server that will not carry out the statement
     * its copies are swapped in with, which is where that statement can be read off
     * on a run with no server behind it.
     */
    private function mysqlInstallationThatWillNotSwap(): ConnectionThatWillNotSwapTables
    {
        $connection = $this->installation('pk_', ConnectionThatWillNotSwapTables::class);

        self::assertInstanceOf(ConnectionThatWillNotSwapTables::class, $connection);

        return $connection;
    }

    /**
     * The same server on a database holding nothing yet, for the cases where which
     * tables the installation still holds is what the swap turns on.
     */
    private function databaseThatWillNotSwap(): ConnectionThatWillNotSwapTables
    {
        $connection = $this->openDatabase('pk_', ConnectionThatWillNotSwapTables::class);

        self::assertInstanceOf(ConnectionThatWillNotSwapTables::class, $connection);

        return $connection;
    }

    /**
     * A restore of the dump the test wrote, into a database that will not swap the
     * copies in, and what it was reported as.
     */
    private function refusedSwap(ConnectionThatWillNotSwapTables $connection): \RuntimeException
    {
        try {
            (new DatabaseRestorer($connection))->restore($this->dump());
        } catch (\RuntimeException $e) {
            return $e;
        }

        self::fail('A restore whose swap the server would not carry out must not be reported as done');
    }

    /**
     * Skips where the run is on SQLite, which replaces the tables where they stand
     * and sets none of them aside - so what a restore does with the table it
     * replaced is a question only a MySQL run answers.
     */
    private function requireTablesSetAside(Connection $connection): void
    {
        if ($this->isSqlite($connection)) {
            self::markTestSkipped('Only a MySQL restore sets the tables it replaces aside');
        }
    }

    /**
     * One more table in the database, under a name the test chooses - a copy a
     * restore left behind, or a neighbour's table that only reads like one.
     */
    private function tableNamed(Connection $connection, string $name): void
    {
        $table = new Table($name);
        $table->addColumn('id', Types::INTEGER, ['notnull' => true]);
        $table->setPrimaryKey(['id']);

        $connection->createSchemaManager()->createTable($table);
    }

    /**
     * Skips where the server cannot hold the name at all, which is the one thing
     * a test about how a name is read cannot work around: a server that stores
     * table names folded has no way to be handed one in another case.
     */
    private function requireNamesKeptAsGiven(Connection $connection, string $name): void
    {
        if (!in_array($name, $connection->createSchemaManager()->listTableNames(), true)) {
            self::markTestSkipped(sprintf('This server stores table names folded, so it cannot hold a table called "%s"', $name));
        }
    }

    /**
     * Every table in the database that reads as a copy a restore made, in an
     * order of the test's own.
     *
     * @return array<int, string>
     */
    private function copiesIn(Connection $connection): array
    {
        $copies = array_values(array_filter(
            $connection->createSchemaManager()->listTableNames(),
            static fn (string $name): bool => RestoreTableNames::isReserved($name),
        ));

        sort($copies);

        return $copies;
    }

    /**
     * An installation whose tables point at each other, named so that the one
     * holding the reference is replaced first - which is the order that needs
     * enforcement suspended.
     */
    private function related(): Connection
    {
        $connection = $this->openDatabase();
        $manager = $connection->createSchemaManager();

        $parent = new Table('pk_b_parent');
        $parent->addColumn('id', Types::INTEGER, ['notnull' => true]);
        $parent->addColumn('title', Types::STRING, ['length' => 64]);
        $parent->setPrimaryKey(['id']);
        $manager->createTable($parent);

        $child = new Table('pk_a_child');
        $child->addColumn('id', Types::INTEGER, ['notnull' => true]);
        $child->addColumn('parent_id', Types::INTEGER, ['notnull' => true]);
        $child->setPrimaryKey(['id']);
        $child->addForeignKeyConstraint('pk_b_parent', ['parent_id'], ['id']);
        $manager->createTable($child);

        $connection->insert('pk_b_parent', ['id' => 1, 'title' => 'the one pointed at']);
        $connection->insert('pk_a_child', ['id' => 1, 'parent_id' => 1]);

        return $connection;
    }

    /**
     * The same two tables, created as SQL rather than through the schema tools.
     *
     * The tools put an index of their own beside every foreign key, and SQLite
     * keeps index names per database where MySQL keeps them per table - so a copy
     * created out of a dump holding one would be asking SQLite for the name the
     * live table's index already has. That is a property of the fixture and not of
     * a restore: the copies are what a restore fills on MySQL, where an index name
     * is the table's own.
     */
    private function relatedWithoutAnIndexOfItsOwn(): Connection
    {
        $connection = $this->openDatabase();

        $connection->executeStatement('CREATE TABLE pk_b_parent (id INTEGER NOT NULL, title VARCHAR(64) NOT NULL, PRIMARY KEY(id))');
        $connection->executeStatement(
            'CREATE TABLE pk_a_child (id INTEGER NOT NULL, parent_id INTEGER NOT NULL, PRIMARY KEY(id),'
            .' CONSTRAINT fk_a_child_parent FOREIGN KEY (parent_id) REFERENCES pk_b_parent (id))',
        );

        $connection->insert('pk_b_parent', ['id' => 1, 'title' => 'the one pointed at']);
        $connection->insert('pk_a_child', ['id' => 1, 'parent_id' => 1]);

        return $connection;
    }

    /**
     * Puts the connection on one side of the disagreement between the engines
     * about whether references are enforced, so that what a restore leaves it on
     * can be read off behaviour rather than off a setting.
     */
    private function enforceReferences(Connection $connection, bool $enforced): void
    {
        $connection->executeStatement(
            $this->isSqlite($connection)
                ? 'PRAGMA foreign_keys = '.($enforced ? 'ON' : 'OFF')
                : 'SET FOREIGN_KEY_CHECKS = '.($enforced ? '1' : '0'),
        );
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
    // Reading the database back
    // ------------------------------------------------------------------

    /**
     * Every row of the table under test, in an order of the test's own so two
     * readings of one database can be compared.
     *
     * @return array<int, array<string, mixed>>
     */
    private function items(Connection $connection): array
    {
        return $this->itemsIn($connection, 'pk_items');
    }

    /**
     * The same reading against a table under a name of the test's own - a copy a
     * fill made - so that what a copy holds is compared with what the table it was
     * made from held rather than with a count of its rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itemsIn(Connection $connection, string $table): array
    {
        return $connection->fetchAllAssociative(sprintf(
            'SELECT id, title, body, status, created, score, thumb FROM %s ORDER BY id',
            $connection->getDatabasePlatform()->quoteIdentifier($table),
        ));
    }

    /**
     * The columns one table was created with, as the server reports them - which
     * is how the shape of a copy is compared with the shape of the table beside
     * it.
     *
     * @return array<int, string>
     */
    private function columnsOf(Connection $connection, string $table): array
    {
        return array_map(
            static fn (Column $column): string => $column->getName(),
            array_values($connection->createSchemaManager()->introspectTable($table)->getColumns()),
        );
    }

    /**
     * Which tables one table's foreign keys point at, in an order of the test's
     * own.
     *
     * @return array<int, string>
     */
    private function referencesOf(Connection $connection, string $table): array
    {
        $targets = array_map(
            static fn (ForeignKeyConstraint $key): string => $key->getForeignTableName(),
            array_values($connection->createSchemaManager()->introspectTable($table)->getForeignKeys()),
        );

        sort($targets);

        return $targets;
    }

    /**
     * One column of the fully filled-in row, read as the driver hands it over.
     */
    private function readColumn(Connection $connection, string $column): mixed
    {
        $value = $connection->fetchOne(sprintf('SELECT %s FROM pk_items WHERE title = ?', $column), [self::TITLE]);

        // A driver that streams large columns hands back a handle rather than
        // the bytes, and what is being compared here is the bytes.
        return is_resource($value) ? stream_get_contents($value) : $value;
    }

    private function countItems(Connection $connection): int
    {
        return $this->countIn($connection, 'pk_items');
    }

    private function countMeta(Connection $connection): int
    {
        return $this->countIn($connection, 'pk_meta');
    }

    /**
     * How many rows one table holds, under whatever name the test asks about.
     */
    private function countIn(Connection $connection, string $table): int
    {
        return (int) $connection->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $connection->getDatabasePlatform()->quoteIdentifier($table),
        ));
    }

    // ------------------------------------------------------------------
    // Dumps a restore is handed
    // ------------------------------------------------------------------

    private function dump(): string
    {
        return $this->workspace.'/db.dump';
    }

    /**
     * A dump this installation accepts, holding the tables it names and no rows.
     * Which tables a dump names is what every refusal that comes before the first
     * statement is measured against, and none of them reads any further into it.
     *
     * @param array<int, string> $tables
     */
    private function dumpNaming(Connection $connection, array $tables): void
    {
        $records = [self::header(['prefix' => $connection->getPrefix() ?? ''])];

        foreach ($tables as $table) {
            $records[] = [
                'type' => DumpFormat::TABLE,
                'name' => $table,
                'ddl' => [sprintf('CREATE TABLE %s (id INTEGER)', $table)],
                'columns' => ['id'],
            ];
        }

        $records[] = ['type' => DumpFormat::END, 'tables' => count($tables), 'rows' => 0];

        $this->writeDump($connection, $records);
    }

    /**
     * A dump that passes every check a restore makes on it and then cannot be
     * applied: its second table is recreated by a statement no database runs.
     * The first table is already replaced by then, which is where the engines
     * part company.
     */
    private function brokenDump(Connection $connection): string
    {
        $file = $this->workspace.'/broken.dump';

        $this->write($connection, $file, [
            self::header(),
            ['type' => DumpFormat::TABLE, 'name' => 'pk_items', 'ddl' => ['CREATE TABLE pk_items (id INTEGER NOT NULL, title VARCHAR(191) NOT NULL, PRIMARY KEY (id))'], 'columns' => ['id', 'title']],
            ['type' => DumpFormat::ROW, 'values' => [1, 'put back by a restore that then failed']],
            ['type' => DumpFormat::TABLE, 'name' => 'pk_meta', 'ddl' => ['NOT A STATEMENT ANY DATABASE RUNS'], 'columns' => ['name']],
            ['type' => DumpFormat::END, 'tables' => 2, 'rows' => 1],
        ]);

        return $file;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function writeDump(Connection $connection, array $records): void
    {
        $this->write($connection, $this->dump(), $records);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function write(Connection $connection, string $file, array $records): void
    {
        file_put_contents($file, str_replace(self::DATABASE_UNDER_TEST, $this->platformOf($connection), self::compose($records)));
    }

    /**
     * A header naming the database the run is against, so a dump composed here
     * is one this installation would accept but for whatever the test broke.
     *
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function header(array $overrides = []): array
    {
        return $overrides + [
            'type' => DumpFormat::HEADER,
            'format' => DumpFormat::VERSION,
            'platform' => self::DATABASE_UNDER_TEST,
            'prefix' => 'pk_',
        ];
    }

    private function platformOf(Connection $connection): string
    {
        return $this->isSqlite($connection) ? DumpFormat::SQLITE : DumpFormat::MYSQL;
    }

    /**
     * Records as they lie in a file, which is the only way a restore ever reads
     * one.
     *
     * @param array<int, array<string, mixed>> $records
     */
    private static function compose(array $records): string
    {
        $lines = '';

        foreach ($records as $record) {
            $lines .= DumpFormat::line($record);
        }

        return $lines;
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
