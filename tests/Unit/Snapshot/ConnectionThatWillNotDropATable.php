<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Pagekit\Database\Connection;

/**
 * A database that answers everything except a table being dropped.
 *
 * A fill that fails clears away the copies it had made, and that clearing away
 * can fail as well - a server that has gone away, a table another session is
 * holding open, a permission a restore does not have. Which of the two failures
 * then reaches the caller, and how much of the cleanup happens around the one that
 * went wrong, cannot be asserted against a database where both go right.
 */
final class ConnectionThatWillNotDropATable extends Connection
{
    /**
     * Which table will not go, or nothing for none of them going. One table is
     * what tells a cleanup that gave up at the first refusal from one that tried
     * the rest of them anyway.
     */
    public string $keeps = '';

    public function executeStatement($sql, array $params = [], array $types = []): int
    {
        if (str_starts_with($sql, 'DROP TABLE') && ($this->keeps === '' || str_contains($sql, $this->keeps))) {
            throw new \RuntimeException('The table could not be dropped.');
        }

        return parent::executeStatement($sql, $params, $types);
    }
}
