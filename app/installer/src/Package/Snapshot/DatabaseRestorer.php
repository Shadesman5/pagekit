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
 */
final class DatabaseRestorer
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Replaces the database with what the dump holds.
     *
     * @param  string                        $file a dump as {@see DatabaseDumper} wrote it
     * @return array{tables: int, rows: int} what was put back
     * @throws \RuntimeException             where the dump cannot be read, does not belong
     *                                      to this installation, or could not be applied
     */
    public function restore(string $file): array
    {
        $summary = $this->inspect($file);

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

        return $summary;
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
     * @return array{tables: int, rows: int} what the dump holds
     * @throws \RuntimeException             where the dump is unreadable, incomplete,
     *                                      or not this installation's
     */
    private function inspect(string $file): array
    {
        $records = $this->read($file);

        // Reading it is the checking; taking the records and doing nothing with
        // them is what makes this the pass that only judges the file.
        foreach ($records as $ignored) {
        }

        return $records->getReturn();
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
}
