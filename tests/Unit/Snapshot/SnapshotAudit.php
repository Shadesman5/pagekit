<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Psr\Log\AbstractLogger;

/**
 * The trail a snapshot leaves, as a test reads it back.
 *
 * Every operation on a snapshot writes one line - taken, put back, destroyed -
 * because every one of them is something an administrator will later have to
 * account for. Shared by the tests of all three, so that what is asserted about
 * the trail is asserted against one reading of it.
 */
final class SnapshotAudit extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
