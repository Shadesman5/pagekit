<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package\Snapshot;

use Doctrine\DBAL\Statement;
use Pagekit\Database\Connection;

/**
 * Puts a database back the way a dump found it.
 *
 * This is the destructive half of a snapshot. Every table the dump names is
 * dropped and written again from what the dump holds, so the database ends up at
 * the moment the dump was taken and everything written to those tables since is
 * gone. Tables that came into existence after the dump are not in it and are left
 * alone - the restore reaches exactly as far as the dump does, and no further.
 *
 * The dump is read end to end before a single statement runs. It is the only copy
 * of what is about to be dropped, so a file that was truncated by a full disk, a
 * dump from another installation, or one written in a layout this version does not
 * know has to be refused while the database it would have replaced is still there.
 *
 * Foreign keys are not enforced while the tables are being replaced, because they
 * are dropped and recreated one at a time and each is briefly missing the ones it
 * points at. How much of the rest is undoable is a property of the platform, not a
 * promise this class can make: SQLite keeps schema changes inside the transaction,
 * so a restore that fails there leaves the database as it was, while MySQL commits
 * on every schema statement, so a restore that fails there leaves it partly
 * replaced. The snapshot is still on disk either way, and running the restore
 * again is what puts it right.
 *
 * @phpstan-import-type Description from DumpFormat
 * @phpstan-type Value array{0: mixed, 1: int}
 * @phpstan-type Record array{type: DumpFormat::TABLE, name: string, ddl: list<string>, columns: list<string>}|array{type: DumpFormat::ROW, values: list<Value>}
 * @phpstan-type Inspection array{tables: int, rows: int, names: list<string>}
 */
final class DatabaseRestorer
{
    /**
     * How long a table name MySQL allows. The number rather than the platform's
     * own answer, which is the 63 characters DBAL reports for every platform
     * that does not say otherwise and would refuse names MySQL takes.
     */
    private const MYSQL_NAME_LIMIT = 64;

    /**
     * Whether the server matches table names without regard to case, once it has
     * been asked. Fixed when the server starts, so it cannot change under a
     * restore that is already running.
     */
    private ?bool $folds = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Replaces the database with what the dump holds.
     *
     * @param  string                        $file a dump as {@see DatabaseDumper} wrote it
     * @return array{tables: int, rows: int} what was put back
     * @throws \RuntimeException             where the dump cannot be read, does not belong to
     *                                      this installation, could not be carried through on
     *                                      it, or could not be applied
     */
    public function restore(string $file): array
    {
        $mysql = $this->platform() === DumpFormat::MYSQL;

        // Before the dump is opened, because this one is about the installation
        // rather than the file, and no dump changes the answer.
        if ($mysql) {
            $this->refuseWithoutPrefix();
        }

        $dump = $this->inspect($file);

        if ($mysql) {
            $this->preflight($dump['names']);
        }

        $enforced = $this->foreignKeys();

        // SQLite ignores this inside a transaction, so it is turned off before
        // one is opened and put back after it has closed.
        $this->setForeignKeys(false);

        try {
            $this->replace($file);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Failed to restore the database from "%s".', $file), 0, $e);
        } finally {
            try {
                $this->setForeignKeys($enforced);
            } catch (\Throwable) {
                // Whatever went wrong restoring the setting, the restore itself
                // is the outcome the caller has to act on.
            }
        }

        return ['tables' => $dump['tables'], 'rows' => $dump['rows']];
    }

    /**
     * Refuses a MySQL restore of an installation whose tables carry no prefix.
     *
     * A restore there fills copies of the tables and swaps them in, and both
     * halves of that have to know which tables are the installation's. Without a
     * prefix every table in the database reads as one, whatever else shares it
     * included, so there is no swap to make that is only this installation's.
     */
    private function refuseWithoutPrefix(): void
    {
        if ($this->prefix() !== '') {
            return;
        }

        throw new \RuntimeException('The tables of this installation carry no name prefix, so a restore cannot tell them from the rest of the database while it swaps them. Reinstalling with a table prefix - "pk_" is the default - is what makes a restore possible.');
    }

    /**
     * Refuses a MySQL restore that could not be carried through to the end.
     *
     * Every one of these is asked after the dump has been read and before
     * anything is created, so what cannot finish comes back as a refusal with
     * the installation exactly as it was and not as a database left between two
     * states.
     *
     * @param  list<string>      $dumped every table the dump carries
     * @throws \RuntimeException naming what has to change before a restore can run
     */
    private function preflight(array $dumped): void
    {
        $this->refuseNamesThatWillNotFit($dumped);
        $this->refuseNamesAlreadyInUse($dumped);
        $this->refuseInboundReferences($dumped);
    }

    /**
     * Refuses a table whose name leaves no room for the copies a restore makes
     * of it.
     *
     * Both copies are the live name behind a marker of the same length
     * ({@see RestoreTableNames}), so measuring one of them answers for both.
     *
     * @param list<string> $dumped
     */
    private function refuseNamesThatWillNotFit(array $dumped): void
    {
        foreach ($dumped as $name) {
            $copy = RestoreTableNames::shadow($name);
            $length = mb_strlen($copy, 'UTF-8');

            if ($length <= self::MYSQL_NAME_LIMIT) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'The table "%s" cannot be restored on MySQL: the copy a restore fills first would be called "%s", which is %d characters where MySQL allows %d. The table has to be named something shorter before a snapshot holding it can be put back.',
                $name,
                $copy,
                $length,
                self::MYSQL_NAME_LIMIT,
            ));
        }
    }

    /**
     * Refuses where the database already holds a table under a name the restore
     * has to make for itself.
     *
     * Copies from a restore that did not finish are refused whether or not this
     * dump needs their names: they are tables nobody asked for, and a restore
     * that wrote over them would lose the one thing that says how far the last
     * one got. A reserved name whose remainder is not this installation's was
     * written by something else, so it is refused where the restore needs that
     * very name and otherwise left alone like any other table that is not this
     * installation's.
     *
     * @param  list<string>      $dumped
     * @throws \RuntimeException naming the tables in the way
     */
    private function refuseNamesAlreadyInUse(array $dumped): void
    {
        /** @var array<string, array{0: string, 1: string}> $needed the copy as the server would match it => the table and the copy's name */
        $needed = [];

        foreach ($dumped as $name) {
            foreach ([RestoreTableNames::shadow($name), RestoreTableNames::backup($name)] as $copy) {
                $needed[$this->comparable($copy)] = [$name, $copy];
            }
        }

        $prefix = $this->comparable($this->prefix());
        $leftovers = [];

        foreach ($this->connection->createSchemaManager()->listTableNames() as $held) {
            $comparable = $this->comparable($held);
            $live = RestoreTableNames::live($comparable);

            if ($live === null) {
                continue;
            }

            if (str_starts_with($live, $prefix)) {
                $leftovers[] = $held;

                continue;
            }

            if (isset($needed[$comparable])) {
                [$table, $copy] = $needed[$comparable];

                throw new \RuntimeException(sprintf(
                    'A restore of the table "%s" fills a copy called "%s" first, and the database already holds a table of that name that is no part of this installation. Rename or drop "%s" and run the restore again.',
                    $table,
                    $copy,
                    $held,
                ));
            }
        }

        if ($leftovers === []) {
            return;
        }

        // In an order of its own: what the server lists them in is the server's
        // business, and this reads as a list an operator can work through.
        sort($leftovers);

        throw new \RuntimeException(sprintf(
            'A restore that did not finish left copies of this installation\'s tables in the database (%s). Drop them and run the restore again.',
            implode(', ', $leftovers),
        ));
    }

    /**
     * Refuses where a table the dump does not carry points at one it does.
     *
     * The swap a restore ends with renames the live table aside, and MySQL takes
     * a foreign key with the table it points at rather than leaving it on the
     * name: a reference from outside the dump would end up on the copy that is on
     * its way out, which leaves the restore unable to remove that copy and the
     * table holding the reference pointing at something nothing maintains.
     * References from tables the dump does carry are no trouble - they are
     * swapped in the same statement.
     *
     * @param  list<string>      $dumped
     * @throws \RuntimeException naming each reference into the dump
     */
    private function refuseInboundReferences(array $dumped): void
    {
        $carried = [];

        foreach ($dumped as $name) {
            $carried[$this->comparable($name)] = true;
        }

        $inbound = [];

        foreach ($this->references() as $reference) {
            if (!isset($carried[$this->comparable($reference['parent'])]) || isset($carried[$this->comparable($reference['child'])])) {
                continue;
            }

            $inbound[] = sprintf('"%s" points at "%s" (%s)', $reference['child'], $reference['parent'], $reference['constraint']);
        }

        if ($inbound === []) {
            return;
        }

        sort($inbound);

        throw new \RuntimeException(sprintf(
            'Tables the database dump does not hold point at tables it does: %s. A restore swaps the tables it holds for copies of them and MySQL would take those references along to the ones being set aside, so they have to be dropped before a restore can run.',
            implode('; ', $inbound),
        ));
    }

    /**
     * Every foreign key in this database, as the constraint and the two tables it
     * ties together.
     *
     * Read out of information_schema rather than through the schema manager,
     * which would introspect every table in the database to arrive at the same
     * list. Both ends are held to the schema the installation is in: a referenced
     * name on its own does not say which schema it is in, and a reference from
     * another one is not something a rename here moves.
     *
     * @return list<array{constraint: string, child: string, parent: string}>
     */
    private function references(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT r.CONSTRAINT_NAME AS name, r.TABLE_NAME AS child, k.REFERENCED_TABLE_NAME AS parent'
            .' FROM information_schema.REFERENTIAL_CONSTRAINTS r'
            .' INNER JOIN information_schema.KEY_COLUMN_USAGE k'
            .' ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME AND k.TABLE_NAME = r.TABLE_NAME'
            .' WHERE r.CONSTRAINT_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_SCHEMA = DATABASE()',
        );

        $references = [];

        foreach ($rows as $row) {
            $constraint = $row['name'] ?? null;
            $child = $row['child'] ?? null;
            $parent = $row['parent'] ?? null;

            if (!is_string($constraint) || !is_string($child) || !is_string($parent)) {
                continue;
            }

            $references[] = ['constraint' => $constraint, 'child' => $child, 'parent' => $parent];
        }

        return $references;
    }

    /**
     * Applies the dump, as far as the platform lets that be one step.
     */
    private function replace(string $file): void
    {
        if ($this->platform() !== DumpFormat::SQLITE) {
            $this->apply($file);

            return;
        }

        $this->connection->beginTransaction();

        try {
            $this->apply($file);
            $this->connection->commit();
        } catch (\Throwable $e) {
            try {
                $this->connection->rollBack();
            } catch (\Throwable) {
                // The restore has already failed; what the rollback then ran
                // into would only hide the failure worth reporting.
            }

            throw $e;
        }
    }

    /**
     * Drops and rewrites every table the dump names.
     */
    private function apply(string $file): void
    {
        $statement = null;

        foreach ($this->read($file) as $record) {

            if ($record['type'] === DumpFormat::TABLE) {
                $this->recreate($record['name'], $record['ddl']);

                // Prepared once per table and given a row at a time, because a
                // table's rows are the same statement over and over.
                $statement = $this->connection->prepare($this->insert($record['name'], $record['columns']));

                continue;
            }

            if (!$statement instanceof Statement) {
                throw new \RuntimeException('The database dump holds a row before the table it belongs to.');
            }

            foreach ($record['values'] as $position => [$value, $type]) {
                $statement->bindValue($position + 1, $value, $type);
            }

            $statement->executeStatement();
        }
    }

    /**
     * Reads the dump through without touching the database, which is how it is
     * judged before anything is destroyed on the strength of it.
     *
     * @return Inspection        what the dump holds, and which tables it names -
     *                          the list every refusal that follows is measured against
     * @throws \RuntimeException where the dump is unreadable, incomplete,
     *                          or not this installation's
     */
    private function inspect(string $file): array
    {
        $records = $this->read($file);
        $names = [];

        // Reading it is the checking; the names are the one thing kept, because
        // what a restore can and cannot do to this database follows from which
        // tables the dump carries.
        foreach ($records as $record) {
            if ($record['type'] === DumpFormat::TABLE) {
                $names[] = $record['name'];
            }
        }

        return $records->getReturn() + ['names' => $names];
    }

    /**
     * Reads the dump from beginning to end, checking as it goes.
     *
     * A record is handed over only once it has been checked, and there is no
     * other way to get at one - so a dump can never be applied by a route that
     * skipped a check. Records come one at a time, because a dump is as large as
     * the database it was taken from and neither pass over it may hold more than
     * the record it is on.
     *
     * @return \Generator<int, Record, void, array{tables: int, rows: int}> each record of the dump, and what the whole
     *                                                                     of it held once it has all been read
     * @throws \RuntimeException                                            where the dump is unreadable, incomplete,
     *                                                                     or not this installation's
     */
    private function read(string $file): \Generator
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to open the database dump ("%s").', $file));
        }

        try {
            $expected = DumpFormat::describe($this->connection);

            $this->header($this->record($handle), $expected);

            $tables = 0;
            $rows = 0;
            $columns = null;
            $end = null;

            while (($record = $this->record($handle)) !== null) {

                if ($end !== null) {
                    throw new \RuntimeException('The database dump carries records past the line that ends it.');
                }

                $type = $record['type'] ?? null;

                if (!is_string($type)) {
                    throw new \RuntimeException('The database dump holds a record that does not say what it is.');
                }

                switch ($type) {

                    case DumpFormat::TABLE:
                        $name = $this->name($record, $expected['prefix']);
                        $ddl = $this->strings($record['ddl'] ?? null, 'schema');
                        $columns = $this->strings($record['columns'] ?? null, 'column names');
                        $tables++;

                        yield ['type' => DumpFormat::TABLE, 'name' => $name, 'ddl' => $ddl, 'columns' => $columns];

                        break;

                    case DumpFormat::ROW:
                        if ($columns === null) {
                            throw new \RuntimeException('The database dump holds a row before the table it belongs to.');
                        }

                        $values = $this->values($record, count($columns));
                        $rows++;

                        yield ['type' => DumpFormat::ROW, 'values' => $values];

                        break;

                    case DumpFormat::END:
                        $end = $record;

                        break;

                    default:
                        throw new \RuntimeException('The database dump holds a record of a kind this format does not define.');
                }
            }

            $this->end($end, $tables, $rows);

            return ['tables' => $tables, 'rows' => $rows];

        } finally {
            fclose($handle);
        }
    }

    /**
     * The next record in the dump, or nothing once the file is done.
     *
     * @param  resource                     $handle
     * @return array<array-key, mixed>|null
     */
    private function record($handle): ?array
    {
        while (($line = fgets($handle)) !== false) {

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException('The database dump holds a line that cannot be read.', 0, $e);
            }

            if (!is_array($record)) {
                throw new \RuntimeException('The database dump holds a line that is not a record.');
            }

            return $record;
        }

        return null;
    }

    /**
     * Checks that the dump belongs to this installation at all.
     *
     * @param  array<array-key, mixed>|null $record
     * @param  Description                  $expected
     * @throws \RuntimeException            naming what does not match, because every one
     *                                     of these is an operator decision rather than
     *                                     something a retry would fix
     */
    private function header(?array $record, array $expected): void
    {
        if ($record === null || ($record['type'] ?? null) !== DumpFormat::HEADER) {
            throw new \RuntimeException('The file is not a database dump.');
        }

        $format = $record['format'] ?? null;

        if ($format !== DumpFormat::VERSION) {
            throw new \RuntimeException(sprintf(
                'The database dump is written in format %s, and this installation reads format %d.',
                is_int($format) ? (string) $format : 'an unstated version',
                DumpFormat::VERSION,
            ));
        }

        $platform = $record['platform'] ?? null;

        if ($platform !== $expected['platform']) {
            throw new \RuntimeException(sprintf(
                'The database dump was taken from %s and this installation runs %s. A dump holds the schema of the database it came from and can only go back into that kind of database.',
                is_string($platform) && $platform !== '' ? $platform : 'an unnamed database',
                $expected['platform'],
            ));
        }

        $prefix = $record['prefix'] ?? null;

        if ($prefix !== $expected['prefix']) {
            throw new \RuntimeException(sprintf(
                'The database dump names its tables with the prefix "%s" and this installation reads tables named with "%s".',
                is_string($prefix) ? $prefix : '',
                $expected['prefix'],
            ));
        }
    }

    /**
     * Checks that the dump is the whole dump.
     *
     * The last line is written only once everything before it is on disk, and it
     * says how much that was. A file without it was cut short while it was being
     * written, and one whose counts disagree with what was just read is not the
     * file it claims to be - both would restore a database that is missing rows
     * nobody would notice were gone.
     *
     * @param array<array-key, mixed>|null $end
     */
    private function end(?array $end, int $tables, int $rows): void
    {
        if ($end === null) {
            throw new \RuntimeException('The database dump is incomplete: it does not end with the line that says it was written in full.');
        }

        if (($end['tables'] ?? null) !== $tables || ($end['rows'] ?? null) !== $rows) {
            throw new \RuntimeException('The database dump does not hold as much as it says it holds.');
        }

        if ($tables === 0) {
            throw new \RuntimeException('The database dump holds no tables, so there is nothing in it to restore.');
        }
    }

    /**
     * The name of a table the dump wants recreated.
     *
     * @param  array<array-key, mixed> $record
     * @param  string                  $prefix what this installation's tables are named with
     * @throws \RuntimeException       where the dump names a table that is not this
     *                                installation's, which a restore may not drop
     */
    private function name(array $record, string $prefix): string
    {
        $name = $record['name'] ?? null;

        if (!is_string($name) || $name === '') {
            throw new \RuntimeException('A table in the database dump carries no name.');
        }

        // A name a restore makes for itself is never a table an installation
        // holds, so a dump carrying one is a dump of a restore that was
        // interrupted rather than of an installation - and replaying it would
        // have this restore make a copy of a copy.
        if (RestoreTableNames::isReserved($name)) {
            throw new \RuntimeException(sprintf('The database dump names a table ("%s") that a restore makes for itself rather than one this installation holds.', $name));
        }

        // A restore drops every table the dump names, so the dump does not get
        // to name one outside what the installation owns.
        if (!str_starts_with($name, $prefix)) {
            throw new \RuntimeException(sprintf('The database dump names a table ("%s") that is not part of this installation.', $name));
        }

        return $name;
    }

    /**
     * @param  string       $field what the list is, for the message when there is none
     * @return list<string>
     */
    private function strings(mixed $value, string $field): array
    {
        if (!is_array($value) || $value === []) {
            throw new \RuntimeException(sprintf('A table in the database dump carries no %s.', $field));
        }

        $strings = [];

        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new \RuntimeException(sprintf('A table in the database dump carries an entry in its %s that is not text.', $field));
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * One row, decoded and ready to be bound.
     *
     * @param  array<array-key, mixed> $record
     * @param  int                     $columns how many values the table it belongs to takes
     * @return list<Value>
     */
    private function values(array $record, int $columns): array
    {
        $values = $record['values'] ?? null;

        if (!is_array($values) || count($values) !== $columns) {
            throw new \RuntimeException('A row in the database dump does not carry one value per column of its table.');
        }

        $decoded = [];

        foreach ($values as $value) {
            $decoded[] = DumpFormat::decode($value);
        }

        return $decoded;
    }

    /**
     * Puts one table back as an empty table of the right shape.
     *
     * @param list<string> $ddl as the platform rendered it when the dump was taken
     */
    private function recreate(string $table, array $ddl): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $platform->quoteIdentifier($table)));

        foreach ($ddl as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    /**
     * The statement every row of one table goes in through, prepared once and
     * given a row at a time.
     *
     * @param list<string> $columns
     */
    private function insert(string $table, array $columns): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $platform->quoteIdentifier($table),
            implode(', ', array_map(static fn (string $column): string => $platform->quoteIdentifier($column), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        );
    }

    /**
     * Whether the connection is enforcing foreign keys as things stand.
     *
     * Read rather than assumed, because the two platforms disagree about it:
     * MySQL enforces them unless told otherwise, SQLite does not enforce them
     * unless asked. Leaving the connection on the wrong one would change what
     * every statement after the restore is allowed to do.
     */
    private function foreignKeys(): bool
    {
        if ($this->platform() === DumpFormat::SQLITE) {
            $value = $this->connection->fetchOne('PRAGMA foreign_keys');

            return $value === 1 || $value === '1';
        }

        // Read through SHOW rather than as @@foreign_key_checks: the connection
        // substitutes its table prefix for @name anywhere outside a quoted
        // string, which would take the variable with it.
        $row = $this->connection->fetchAssociative("SHOW SESSION VARIABLES LIKE 'foreign_key_checks'");
        $value = $row === false ? null : ($row['Value'] ?? null);

        return $value === 'ON' || $value === 1 || $value === '1';
    }

    private function setForeignKeys(bool $enforced): void
    {
        $this->connection->executeStatement(
            $this->platform() === DumpFormat::SQLITE
                ? 'PRAGMA foreign_keys = '.($enforced ? 'ON' : 'OFF')
                : 'SET FOREIGN_KEY_CHECKS = '.($enforced ? '1' : '0'),
        );
    }

    /**
     * The family of database this installation is on.
     *
     * @throws \RuntimeException where it is one no dump can go back into
     */
    private function platform(): string
    {
        return DumpFormat::platform($this->connection->getDatabasePlatform());
    }

    /**
     * What this installation's tables are named with.
     */
    private function prefix(): string
    {
        return $this->connection->getPrefix() ?? '';
    }

    /**
     * A table name as a comparison against another one has to read it.
     *
     * Whether two names are the same table is the server's rule and not PHP's:
     * asked to fold them, MySQL matches names without regard to case, and a
     * comparison that did not fold would look straight past the table it was
     * checking for - and then create, or drop, the wrong one.
     */
    private function comparable(string $name): string
    {
        return $this->foldsNames() ? mb_strtolower($name, 'UTF-8') : $name;
    }

    /**
     * Whether the server matches table names without regard to case.
     */
    private function foldsNames(): bool
    {
        if ($this->folds === null) {
            // Globally, because the variable has no session value, and through
            // SHOW rather than as @@lower_case_table_names: the connection
            // substitutes its table prefix for an @-led name outside quotes.
            $row = $this->connection->fetchAssociative("SHOW GLOBAL VARIABLES LIKE 'lower_case_table_names'");
            $value = $row === false ? null : ($row['Value'] ?? null);

            // 1 stores names folded and 2 stores them as given but matches them
            // folded. 0 - and a server that does not say - is a name matched as
            // it is written.
            $this->folds = in_array($value, ['1', '2', 1, 2], true);
        }

        return $this->folds;
    }
}
