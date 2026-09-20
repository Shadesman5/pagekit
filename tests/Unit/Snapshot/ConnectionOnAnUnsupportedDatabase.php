<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Pagekit\Database\Connection;

/**
 * An installation on a database neither half of a snapshot is written for.
 *
 * Pagekit is configured for two engines and a dump carries the schema one of
 * them renders, so a third is not a dump that comes out slightly wrong - it is
 * an installation that cannot be snapshotted at all, and has to be told so
 * before a removal is started on the strength of one.
 */
final class ConnectionOnAnUnsupportedDatabase extends Connection
{
    public function getDatabasePlatform(): AbstractPlatform
    {
        return new PostgreSQLPlatform();
    }
}
