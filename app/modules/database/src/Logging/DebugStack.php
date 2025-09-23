<?php

namespace Pagekit\Database\Logging;

use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @deprecated Since DBAL 3.x migration. Use Pagekit\Debug\Middleware\DebugMiddleware instead.
 * 
 * This class is kept for backward compatibility but is no longer used.
 * DBAL 3.x uses a middleware-based approach for SQL logging.
 */
class DebugStack
{
    protected ?string $callstack = null;
    protected ?Stopwatch $stopwatch = null;
    public bool $enabled = true;
    public array $queries = [];
    protected ?int $currentQuery = null;

    public function __construct(Stopwatch $stopwatch = null)
    {
        $this->stopwatch = $stopwatch;
        trigger_error('DebugStack is deprecated. Use Pagekit\Debug\Middleware\DebugMiddleware instead.', E_USER_DEPRECATED);
    }

    /**
     * @deprecated
     */
    public function startQuery($sql, array $params = null, array $types = null): void
    {
        // No-op for compatibility
    }

    /**
     * @deprecated
     */
    public function stopQuery(): void
    {
        // No-op for compatibility
    }
}
