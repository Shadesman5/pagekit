<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Pagekit\Filesystem\Filesystem;

/**
 * A store nothing can be taken out of: a file held open, a permission the
 * process does not have, a mount that has gone read-only.
 *
 * Shared by the two things that destroy a snapshot - an administrator asking for
 * one and a retention window running out on one - because the disk that will not
 * be reclaimed is the same disk either way, and only what is done about it
 * differs.
 */
final class ASnapshotThatWillNotGo extends Filesystem
{
    /**
     * @param string|array<int, string> $files
     */
    public function delete($files): bool
    {
        return false;
    }
}
