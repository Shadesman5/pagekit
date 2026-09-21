<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

/**
 * A MySQL server that will not take back the lock a restore gives up on its way
 * out.
 *
 * A session that has lost the server hands nothing back to it, and giving the lock
 * up is the last thing a restore does - after whatever the operator is waiting to
 * be told about has already been decided. Raised from there it would be the
 * sentence they are shown instead, over a lock the server drops of its own accord
 * as soon as the session goes.
 */
final class ConnectionThatWillNotTakeTheLockBack extends ConnectionThatAnswersForAMysqlServer
{
    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        if (str_contains($query, 'RELEASE_LOCK')) {
            // Noted as asked before it fails: a restore that never tried to give
            // the lock up is a different thing from one the server would not hear.
            parent::fetchOne($query, $params, $types);

            throw new \RuntimeException('The lock could not be given up.');
        }

        return parent::fetchOne($query, $params, $types);
    }
}
