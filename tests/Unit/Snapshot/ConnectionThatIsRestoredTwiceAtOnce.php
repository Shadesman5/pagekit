<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Pagekit\Database\Connection;
use Pagekit\Installer\Package\Snapshot\DatabaseRestorer;

/**
 * A database that has a second restore asked of it while it is running one.
 *
 * Two restores at once are two requests, and neither process knows the other is
 * there. Both fill copies named after the tables they replace, so the second would
 * be writing into the first one's copies and each would swap in whatever happened
 * to be in them - which is why one restore of an installation at a time is the
 * server's to keep rather than the application's.
 *
 * That it is kept can only be seen from a second restore arriving while the first
 * is holding the lock, and nothing outside a restore can arrange for that. So it is
 * arranged from inside: the second one is asked for on a session of its own as the
 * first creates its first copy, and what it was told is left here to be read.
 */
final class ConnectionThatIsRestoredTwiceAtOnce extends Connection
{
    /**
     * The restore to ask for while this one is running, or nothing where the test
     * has none to ask for yet - the installation's own tables are created through
     * this connection as well, and a restore starting during that would be a
     * restore of nothing.
     */
    public ?DatabaseRestorer $second = null;

    /**
     * The dump the second restore is asked for, which is the first one's dump: two
     * requests to put one snapshot back is what an operator clicking twice sends.
     */
    public string $dump = '';

    /**
     * What the second restore was told, or nothing where it was not turned away at
     * all.
     */
    public ?\Throwable $refusal = null;

    public function executeStatement($sql, array $params = [], array $types = []): int
    {
        // As the first copy is created: the lock is held by then, and the restore
        // has not yet written anything the site reads - the whole of which is a
        // stretch no second restore may be running in.
        if ($this->second !== null && str_starts_with($sql, 'CREATE TABLE')) {
            $second = $this->second;

            // Once. The restore that is running creates a copy of every table it
            // puts back, and the second one is answered the first time it asks.
            $this->second = null;

            try {
                $second->restore($this->dump);
            } catch (\Throwable $e) {
                $this->refusal = $e;
            }
        }

        return parent::executeStatement($sql, $params, $types);
    }
}
