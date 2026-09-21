<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * A MySQL server that answers a restore up to the statement its copies are swapped
 * in with, and will not carry that one out.
 *
 * What that statement is worth is the server's own: a rename of several tables is
 * one step there, and no session reads the database between two of the names in it.
 * Neither can be asked of a stand-in - the run against a real server is what
 * answers them - but the statement a restore composes for it can be read off here,
 * on a run with no server behind it, and so can what is left in the database once
 * the swap has been refused.
 *
 * Everything on either side of the swap happens for real against the database the
 * test opened: the copies are created and filled by the statements the restore
 * composes and cleared away by the statement it composes for that, so what a test
 * reads back afterwards is the database rather than a recording of it.
 */
final class ConnectionThatWillNotSwapTables extends ConnectionThatAnswersForAMysqlServer
{
    /**
     * A copy this database will not give up either, or nothing for a clearing away
     * that goes through. A refused swap clears the copies next, and what the caller
     * is told about the swap may not turn into what that ran into.
     */
    public string $keeps = '';

    /**
     * Every statement the restore handed the server to swap tables with, as it
     * handed it over. How many arrived is as much the point as what they say, one
     * rename of every table being what MySQL carries out as a single step.
     *
     * @var list<string>
     */
    public array $swaps = [];

    /**
     * Whether references are enforced, which is the other thing only a server can
     * say and the one a restore asks once it is past the refusals. MySQL enforces
     * them unless it has been told not to.
     *
     * @param  array<int|string, mixed>   $params
     * @param  array<int|string, mixed>   $types
     * @return array<string, mixed>|false
     */
    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        if (str_contains($query, 'foreign_key_checks')) {
            return ['Variable_name' => 'foreign_key_checks', 'Value' => 'ON'];
        }

        return parent::fetchAssociative($query, $params, $types);
    }

    public function executeStatement($sql, array $params = [], array $types = []): int
    {
        if (str_starts_with($sql, 'RENAME TABLE')) {
            $this->swaps[] = $sql;

            throw new \RuntimeException('The tables could not be swapped.');
        }

        if ($this->keeps !== '' && str_starts_with($sql, 'DROP TABLE') && str_contains($sql, $this->keeps)) {
            throw new \RuntimeException('The table could not be dropped.');
        }

        // Suspending enforcement is spelled the way MySQL spells it: a run with a
        // server behind it runs that statement, and one on SQLite has no such
        // statement to run. What a restore leaves the setting on is read off the
        // database the restore was talking to rather than off this.
        if (str_starts_with($sql, 'SET FOREIGN_KEY_CHECKS') && !($this->platformUnderneath() instanceof AbstractMySQLPlatform)) {
            return 0;
        }

        return parent::executeStatement($sql, $params, $types);
    }
}
