<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Result;
use Pagekit\Database\Connection;

/**
 * A database that answers until it is asked for one particular table, and then
 * does not.
 *
 * A dump is taken table by table out of a live installation, so it is read over
 * a stretch of time in which a server can be restarted, a connection dropped or
 * a table locked. What matters is not which of those happened but that a dump
 * broken off halfway is never left where a restore would find it, so the failure
 * is provoked at a point where part of the dump is already written.
 */
final class ConnectionThatStopsAnswering extends Connection
{
    /**
     * A fragment of the query to refuse - the name of a column only the table
     * the dump should break off at carries, so introspection and the tables
     * before it are answered as usual.
     */
    public string $refuse = '';

    /**
     * What to note down at the moment the database stops answering. It is the
     * only point from which a dump can be seen part written, and what it is
     * called while it is in that state is the whole reason a restore never finds
     * one.
     *
     * @var (\Closure(): void)|null
     */
    public ?\Closure $observe = null;

    public function executeQuery($sql, array $params = [], $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        if ($this->refuse !== '' && str_contains($sql, $this->refuse)) {

            if ($this->observe !== null) {
                ($this->observe)();
            }

            throw new \RuntimeException('The database stopped answering.');
        }

        return parent::executeQuery($sql, $params, $types, $qcp);
    }
}
