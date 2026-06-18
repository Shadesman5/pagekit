<?php

declare(strict_types=1);

namespace Pagekit\Database\Logging;

use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @deprecated Since DBAL 3.x migration. Use Pagekit\Debug\Middleware\DebugMiddleware instead.
 *
 * This class is kept for backward compatibility but is no longer used.
 * DBAL 3.x uses a middleware-based approach for SQL logging.
 *
 * TODO: Must be refactored in Step 2.1.6 (PHPStan Level 7→8 — Strict Typing) — delete this dead
 * shim (zero consumers; replaced by Pagekit\Debug\Middleware\DebugMiddleware) per Aggressive
 * Rule 4 (Delete over Wrap).
 */
class DebugStack
{
    protected ?string $callstack = null;
    protected ?Stopwatch $stopwatch = null;
    public bool $enabled = true;
    /** @var array<int, array<string, mixed>> */
    public array $queries = [];
    protected ?int $currentQuery = null;

    public function __construct(Stopwatch $stopwatch = null)
    {
        $this->stopwatch = $stopwatch;
        trigger_error('DebugStack is deprecated. Use Pagekit\Debug\Middleware\DebugMiddleware instead.', E_USER_DEPRECATED);
    }

    /**
     * @deprecated
     *
     * @param array<int|string, mixed>|null $params
     * @param array<int|string, mixed>|null $types
     */
    public function startQuery(string $sql, array $params = null, array $types = null): void
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
