<?php

namespace Pagekit\Debug\DataCollector;

use DebugBar\DataCollector\DataCollector;
use DebugBar\DataCollector\Renderable;
use Pagekit\Database\Connection;
use Pagekit\Debug\Middleware\DebugLogger;

class DatabaseDataCollector extends DataCollector implements Renderable
{
    protected Connection $connection;
    protected ?DebugLogger $logger;

    /**
     * Constructor.
     *
     * @param Connection $connection
     * @param DebugLogger|null $logger
     */
    public function __construct(Connection $connection, ?DebugLogger $logger = null)
    {
        $this->connection = $connection;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function collect(): array
    {
        $queries = [];
        $totalTime = 0;

        if ($this->logger !== null) {
            foreach ($this->logger->queries as $query) {
                $queries[] = [
                    'sql' => $query['sql'],
                    'params' => $query['params'] ?? [],
                    'duration' => $query['executionMS'] ?? 0,
                    'duration_str' => $this->formatDuration($query['executionMS'] ?? 0),
                    'memory' => 0,
                    'memory_str' => '0B',
                    'is_success' => true,
                    'error_code' => null,
                    'error_message' => null
                ];
                $totalTime += $query['executionMS'] ?? 0;
            }
        }

        $driver = $this->connection->getDriver()->getName();

        return [
            'nb_statements' => count($queries),
            'nb_failed_statements' => 0,
            'accumulated_duration' => $totalTime,
            'accumulated_duration_str' => $this->formatDuration($totalTime),
            'memory_usage' => 0,
            'memory_usage_str' => '0B',
            'statements' => $queries,
            'driver' => $driver
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'database';
    }

    /**
     * {@inheritdoc}
     */
    public function getWidgets(): array
    {
        return [
            'database' => [
                'icon' => 'database',
                'widget' => 'PhpDebugBar.Widgets.SQLQueriesWidget',
                'map' => 'database',
                'default' => '[]'
            ],
            'database:badge' => [
                'map' => 'database.nb_statements',
                'default' => 0
            ]
        ];
    }

    /**
     * Format duration in milliseconds to a readable string.
     */
    public function formatDuration(float $ms): string
    {
        if ($ms < 1) {
            return round($ms * 1000) . 'μs';
        }
        if ($ms < 1000) {
            return round($ms, 2) . 'ms';
        }
        return round($ms / 1000, 2) . 's';
    }
}
