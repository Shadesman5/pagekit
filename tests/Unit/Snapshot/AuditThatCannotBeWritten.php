<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Snapshot;

use Psr\Log\AbstractLogger;

/**
 * A log that is itself broken, as one writing to a full disk or into a directory
 * that went away is.
 */
final class AuditThatCannotBeWritten extends AbstractLogger
{
    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('The log could not be written.');
    }
}
