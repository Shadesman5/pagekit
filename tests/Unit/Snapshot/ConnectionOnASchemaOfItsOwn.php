<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

/**
 * An installation the server says is on a schema of the test's naming.
 *
 * A server keeps the lock one restore runs under for the whole of itself, and two
 * installations on one server are told apart by the schema each is in as much as
 * by the prefix its tables carry: the same prefix in two schemas is two sites that
 * share nothing. Which schema a connection is on is the server's own answer, and a
 * run with no server behind it is on the single database it opened - so a second
 * schema is the one thing such a run cannot arrange for itself.
 */
final class ConnectionOnASchemaOfItsOwn extends ConnectionThatAnswersForAMysqlServer
{
    /**
     * The schema the server says this connection is on.
     */
    public string $schema = '';

    public function getDatabase(): ?string
    {
        return $this->schema;
    }
}
