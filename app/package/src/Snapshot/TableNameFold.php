<?php

declare(strict_types=1);

namespace Pagekit\Package\Snapshot;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Pagekit\Database\Connection;

/**
 * Whether two table names are one table here, and whether a name belongs to a prefix.
 *
 * Selecting an installation's tables, checking that a dump only names those, and
 * spotting a restore's own copies have to fold the same way or a folding server
 * counts a table in one place and misses it in another.
 */
final class TableNameFold
{
    /**
     * Whether the server matches table names without regard to case, once it has
     * been asked. Set when the server starts, so one dump or restore sees one answer.
     */
    private ?bool $folds = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * A table name as a comparison against another one has to read it.
     */
    public function comparable(string $name): string
    {
        return $this->folds() ? self::folded($name) : $name;
    }

    /**
     * Whether $name is a table of the installation that names its tables with $prefix.
     *
     * An empty prefix names every table.
     */
    public function prefixed(string $name, string $prefix): bool
    {
        // The same bytes are the same table whether or not the server folds, so
        // this does not ask. A dump's names are checked before a restore takes
        // its lock.
        if (str_starts_with($name, $prefix)) {
            return true;
        }

        // Folded forms that still do not match are not this prefix on any server.
        if (!str_starts_with(self::folded($name), self::folded($prefix))) {
            return false;
        }

        return $this->folds();
    }

    private function folds(): bool
    {
        if ($this->folds === null) {
            $this->folds = $this->serverFoldsNames();
        }

        return $this->folds;
    }

    /**
     * Whether MySQL matches table names without regard to case.
     */
    private function serverFoldsNames(): bool
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return false;
        }

        // Globally, because the variable has no session value, and through
        // SHOW rather than as @@lower_case_table_names: the connection
        // substitutes its table prefix for an @-led name outside quotes.
        $row = $this->connection->fetchAssociative("SHOW GLOBAL VARIABLES LIKE 'lower_case_table_names'");
        $value = $row === false ? null : ($row['Value'] ?? null);

        // 1 stores names folded and 2 stores them as given but matches them
        // folded. Anything else, and a server that does not say, matches a
        // name as it is written.
        return in_array($value, ['1', '2', 1, 2], true);
    }

    private static function folded(string $name): string
    {
        return mb_strtolower($name, 'UTF-8');
    }
}
