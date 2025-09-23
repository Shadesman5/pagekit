<?php

namespace Pagekit\Debug\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Logger implementation that collects SQL queries for the debug bar.
 * Compatible with PSR-3 and DBAL 3.x.
 */
class DebugLogger implements LoggerInterface
{
    /**
     * Collected queries.
     */
    public array $queries = [];

    /**
     * Current query being executed.
     */
    protected ?int $currentQuery = null;

    /**
     * Start time of current query.
     */
    protected ?float $start = null;

    /**
     * Stopwatch for performance profiling.
     */
    protected ?Stopwatch $stopwatch = null;

    /**
     * Whether logging is enabled.
     */
    public bool $enabled = true;

    /**
     * Call stack for debugging.
     */
    protected ?string $callstack = null;

    public function __construct(?Stopwatch $stopwatch = null)
    {
        $this->stopwatch = $stopwatch;
    }

    /**
     * Logs with an arbitrary level.
     */
    public function log($level, $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        // Handle SQL query logging
        if ($level === LogLevel::DEBUG && isset($context['sql'])) {
            $this->startQuery($context['sql'], $context['params'] ?? null, $context['types'] ?? null);
        }

        // Handle query completion
        if ($level === LogLevel::DEBUG && isset($context['elapsed'])) {
            $this->stopQuery($context['elapsed']);
        }
    }

    /**
     * Start logging a query.
     */
    public function startQuery(string $sql, ?array $params = null, ?array $types = null): void
    {
        if (!$this->enabled) {
            return;
        }

        // Capture call stack for debugging
        $e = new \Exception;
        $this->callstack = $e->getTraceAsString();

        if ($this->stopwatch !== null) {
            $this->stopwatch->start('doctrine');
        }

        $this->start = microtime(true);
        $this->queries[] = [
            'sql' => $sql,
            'params' => $params,
            'types' => $types,
            'executionMS' => 0,
            'callstack' => $this->callstack
        ];
        $this->currentQuery = array_key_last($this->queries);
    }

    /**
     * Stop logging the current query.
     */
    public function stopQuery(?float $elapsed = null): void
    {
        if (!$this->enabled || $this->currentQuery === null) {
            return;
        }

        if ($this->stopwatch !== null) {
            $this->stopwatch->stop('doctrine');
        }

        if ($elapsed === null && $this->start !== null) {
            $elapsed = microtime(true) - $this->start;
        }

        $this->queries[$this->currentQuery]['executionMS'] = $elapsed * 1000;
        $this->currentQuery = null;
        $this->start = null;
    }

    // PSR-3 LoggerInterface methods
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}