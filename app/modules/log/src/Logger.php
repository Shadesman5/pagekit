<?php

declare(strict_types=1);

namespace Pagekit\Log;

use Monolog\Logger as BaseLogger;

class Logger extends BaseLogger
{
    /**
     * Log shortcut.
     *
     * @see log()
     *
     * @param array<string, mixed> $context
     */
    public function __invoke(int|string $level, string|\Stringable $message, array $context = []): void
    {
        $this->log($level, $message, $context);
    }
}
