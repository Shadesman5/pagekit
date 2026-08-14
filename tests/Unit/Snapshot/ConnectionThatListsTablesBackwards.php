<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Result;
use Pagekit\Database\Connection;

/**
 * A database that hands its tables over in the opposite order to the usual one.
 *
 * Which order introspection lists tables in is the driver's business: the two
 * engines ask two different catalogues for them, and neither promises an order
 * that will not change with a version. A dump that came out in whatever order it
 * was handed could not be compared with another dump of the same installation, so
 * the order is one a dump decides - and this is what that decision is worth
 * anything against.
 */
final class ConnectionThatListsTablesBackwards extends Connection
{
    /**
     * How each driver's own catalogue query ends, which is the one place the
     * order tables arrive in is settled.
     */
    private const ORDERINGS = ['ORDER BY name', 'ORDER BY TABLE_NAME'];

    public function executeQuery($sql, array $params = [], $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        foreach (self::ORDERINGS as $ordering) {
            if (str_ends_with($sql, $ordering)) {
                $sql .= ' DESC';

                break;
            }
        }

        return parent::executeQuery($sql, $params, $types, $qcp);
    }
}
