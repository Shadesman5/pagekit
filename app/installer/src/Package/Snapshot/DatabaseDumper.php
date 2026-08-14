<?php

declare(strict_types=1);

namespace Pagekit\Installer\Package\Snapshot;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Pagekit\Database\Connection;

/**
 * Writes the database a package was installed in to a file, so a removal can be
 * undone.
 *
 * The dump is taken through the connection the application already has, without
 * an external tool: an installation that could only be snapshotted where mysqldump
 * happens to be on the PATH would be one where removing a package is safe on some
 * hosts and not on others. The schema comes out of the driver's own introspection
 * and goes back in as the DDL that platform renders; a row is written the moment
 * it is read, so no copy of the database is ever assembled here. What the driver
 * keeps is the driver's business - PDO buffers a MySQL result set client-side
 * unless it is told otherwise - and is not something this class can promise away.
 *
 * What is captured is the tables the installation owns - the ones its table prefix
 * names, or every table where it is configured without one. Views, triggers and
 * stored routines are not, and neither is anything under another prefix sharing
 * the same database: a restore is meant to put this installation back, not to
 * reach into a neighbour's tables.
 *
 * Nothing here reports a partial dump as a dump. The file is written under a name
 * of its own and only renamed to the one a restore reads once the last record is
 * on disk, so a write that runs out of disk, or a database that stops answering
 * halfway through a table, leaves no file for a restore to find - and leaves the
 * caller an exception saying the snapshot was not taken.
 *
 * @phpstan-import-type Description from DumpFormat
 * @phpstan-type DumpedTable array{name: string, ddl: list<string>, columns: list<string>}
 */
final class DatabaseDumper
{
    /**
     * What the dump is called while it is still being written. It sits in the
     * target's own directory so that the move onto the final name is a rename
     * within one filesystem, which is the part that happens all at once.
     */
    private const STAGING_SUFFIX = '.part';

    /**
     * Owner-only. A dump holds every row of the database, password hashes and
     * session data among them, and it is kept for as long as the snapshot is.
     */
    private const FILE_MODE = 0600;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Which database a dump would be taken from, for whoever has to record what
     * a snapshot is of before the dump itself exists.
     *
     * @return Description
     * @throws \RuntimeException where the platform is not one that can be dumped
     */
    public function describe(): array
    {
        return DumpFormat::describe($this->connection);
    }

    /**
     * Writes the database to a file, which exists only once it is complete.
     *
     * @param  string                        $file where the finished dump belongs
     * @return array{tables: int, rows: int} what was written
     * @throws \RuntimeException             where the database could not be read or the
     *                                      file could not be written; there is then no
     *                                      dump, and the caller has no snapshot to
     *                                      remove a package against
     */
    public function dump(string $file): array
    {
        // Both of these run before anything is opened: a platform that cannot be
        // dumped, or a schema that cannot be read, leaves no file behind at all.
        $description = $this->describe();
        $tables = $this->schema($description['prefix']);

        $staging = $file.self::STAGING_SUFFIX;

        try {
            $summary = $this->stage($staging, $description, $tables);

            if (!@rename($staging, $file)) {
                throw new \RuntimeException(sprintf('Failed to move the finished database dump into place ("%s").', $file));
            }
        } catch (\Throwable $e) {
            @unlink($staging);

            throw new \RuntimeException(sprintf('Failed to dump the database to "%s".', $file), 0, $e);
        }

        return $summary;
    }

    /**
     * Writes the dump, from its header to the line that closes it, and says
     * what went into it.
     *
     * @param  string                        $file the name it is written under, which is not yet the one a restore reads
     * @param  Description                   $description
     * @param  list<DumpedTable>             $tables
     * @return array{tables: int, rows: int}
     */
    private function stage(string $file, array $description, array $tables): array
    {
        $handle = @fopen($file, 'wb');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to open the database dump for writing ("%s").', $file));
        }

        // The snapshot directory is already owner-only, which is what actually
        // keeps a dump private; this narrows the file itself to match, for the
        // moment it is moved or copied somewhere less careful.
        @chmod($file, self::FILE_MODE);

        try {
            $rows = 0;

            $this->write($handle, [
                'type' => DumpFormat::HEADER,
                'format' => DumpFormat::VERSION,
                'created' => time(),
                'driver' => $description['driver'],
                'platform' => $description['platform'],
                'prefix' => $description['prefix'],
            ]);

            foreach ($tables as $table) {
                $this->write($handle, [
                    'type' => DumpFormat::TABLE,
                    'name' => $table['name'],
                    'ddl' => $table['ddl'],
                    'columns' => $table['columns'],
                ]);

                $rows += $this->rows($handle, $table['name'], $table['columns']);
            }

            $this->write($handle, ['type' => DumpFormat::END, 'tables' => count($tables), 'rows' => $rows]);

            // The last records are still in a buffer at this point, and a disk
            // that is full says so here rather than at any of the writes above.
            if (!fflush($handle)) {
                throw new \RuntimeException('Failed to flush the database dump to disk.');
            }

            return ['tables' => count($tables), 'rows' => $rows];

        } finally {
            fclose($handle);
        }
    }

    /**
     * The tables the installation owns, each with the statements that recreate
     * it and the columns its rows are written against.
     *
     * Introspected and rendered by the platform the connection is on, so that
     * what goes into the dump is the schema that database engine writes rather
     * than SQL assembled here.
     *
     * @param  string            $prefix what the installation's own tables are named
     *                                  with; empty where it owns the whole database
     * @return list<DumpedTable> in name order, so that two dumps of one database read the same
     */
    private function schema(string $prefix): array
    {
        $manager = $this->connection->createSchemaManager();
        $platform = $this->connection->getDatabasePlatform();

        $names = [];

        foreach ($manager->listTableNames() as $name) {
            if (str_starts_with($name, $prefix)) {
                $names[] = $name;
            }
        }

        sort($names);

        $tables = [];

        foreach ($names as $name) {
            $table = $manager->introspectTable($name);

            $tables[] = [
                'name' => $name,
                'ddl' => $platform->getCreateTableSQL(
                    $table,
                    AbstractPlatform::CREATE_INDEXES | AbstractPlatform::CREATE_FOREIGNKEYS,
                ),
                'columns' => array_map(
                    static fn (Column $column): string => $column->getName(),
                    array_values($table->getColumns()),
                ),
            ];
        }

        return $tables;
    }

    /**
     * Writes every row of one table.
     *
     * The columns are named in the query rather than selected with a star, so
     * that the values arrive in the order the table record already declared and
     * a row carries nothing but its values.
     *
     * @param  resource     $handle
     * @param  list<string> $columns
     * @return int          how many rows were written
     */
    private function rows($handle, string $table, array $columns): int
    {
        $platform = $this->connection->getDatabasePlatform();

        $result = $this->connection->executeQuery(sprintf(
            'SELECT %s FROM %s',
            implode(', ', array_map(static fn (string $column): string => $platform->quoteIdentifier($column), $columns)),
            $platform->quoteIdentifier($table),
        ));

        $rows = 0;

        while (($row = $result->fetchNumeric()) !== false) {
            $this->write($handle, ['type' => DumpFormat::ROW, 'values' => array_map(DumpFormat::encode(...), $row)]);
            $rows++;
        }

        return $rows;
    }

    /**
     * @param  resource             $handle
     * @param  array<string, mixed> $record
     * @throws \RuntimeException    where the record did not reach the disk whole,
     *                             a full disk being the way that usually happens
     */
    private function write($handle, array $record): void
    {
        $line = DumpFormat::line($record);

        if (@fwrite($handle, $line) !== strlen($line)) {
            throw new \RuntimeException('Failed to write a record to the database dump.');
        }
    }
}
