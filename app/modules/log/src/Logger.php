<?php

declare(strict_types=1);

namespace Pagekit\Log;

use Monolog\Level;
use Monolog\Logger as BaseLogger;

class Logger extends BaseLogger
{
    /**
     * Log shortcut.
     *
     * @see log()
     *
     * @param 'alert'|'critical'|'debug'|'emergency'|'error'|'info'|'notice'|'warning'|Level $level
     * @param array<string, mixed>                                                            $context
     */
    public function __invoke(string|Level $level, string|\Stringable $message, array $context = []): void
    {
        $this->log($level, $message, $context);
    }
}
