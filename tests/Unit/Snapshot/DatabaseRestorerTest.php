<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Pagekit\Database\Connection;
use Pagekit\Installer\Package\Snapshot\DatabaseDumper;
use Pagekit\Installer\Package\Snapshot\DatabaseRestorer;
use Pagekit\Installer\Package\Snapshot\DumpFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Putting a database back the way a dump found it, which is the half of a
 * snapshot that destroys something.
 *
 * Every table the dump names is dropped and written again, so what was in those
 * tables since the dump was taken is gone. That is the point - the state of the
 * installation before a package was removed is what is being asked for - and it
 * is also why the dump has to be judged before a single statement runs. It is the
 * only copy of what is about to be dropped: a file a full disk cut short, one
 * from another installation, or one written in a layout this version does not
 * know has to be refused while the database it would have replaced is still
 * there.
 *
 * How much of a failed restore is undone is a property of the database and not a
 * promise this class can make, so the two engines are asserted separately where
 * they genuinely differ, and the recovery that works on both - running the
 * restore again - is asserted for both.
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
    // A restore that fails while it is running
    // ------------------------------------------------------------------

    public function testARestoreThatFailedPartwayIsPutRightByRunningItAgain(): void
    {
        // How much of a half-applied restore is undone depends on the engine, so
        // what is promised on both is this: the snapshot is still on disk, and
        // replaying it is what finishes the job.
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

    public function testOnSqliteARestoreThatFailedPartwayLeavesTheDatabaseAsItWas(): void
    {
        $connection = $this->installation();

        if (!$this->isSqlite($connection)) {
            // MySQL commits on every schema statement, so a restore that fails
            // there leaves the database partly replaced. That is documented
            // rather than worked around, and asserting it as recovery is the
            // test above.
            self::markTestSkipped('Only SQLite keeps schema changes inside the transaction a restore opens');
        }

        $connection->executeStatement('DELETE FROM pk_items');
        $connection->insert('pk_items', ['title' => 'written after the snapshot', 'status' => 0]);

        try {
            (new DatabaseRestorer($connection))->restore($this->brokenDump($connection));

            self::fail('A restore that could not be applied must not be reported as done');
        } catch (\RuntimeException) {
            // The state left behind is what this is about.
        }

        // The dump had replaced the first table before it failed on the second,
        // and none of that is left: the row written after the snapshot is still
        // the only one there.
        self::assertSame(1, $this->countItems($connection));
        self::assertSame('written after the snapshot', $connection->fetchOne('SELECT title FROM pk_items'));
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
    // The installation a dump is replayed into
    // ------------------------------------------------------------------

    /**
     * A database holding what an installation holds: two tables of its own with
     * values of every kind in them, and one table belonging to whatever else
     * shares the database.
     */
    private function installation(): Connection
    {
        $connection = $this->openDatabase();
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
        return $connection->fetchAllAssociative('SELECT id, title, body, status, created, score, thumb FROM pk_items ORDER BY id');
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
        return (int) $connection->fetchOne('SELECT COUNT(*) FROM pk_items');
    }

    private function countMeta(Connection $connection): int
    {
        return (int) $connection->fetchOne('SELECT COUNT(*) FROM pk_meta');
    }

    // ------------------------------------------------------------------
    // Dumps a restore is handed
    // ------------------------------------------------------------------

    private function dump(): string
    {
        return $this->workspace.'/db.dump';
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
