<?php

namespace Pagekit\Debug\DataCollector;

use DebugBar\DataCollector\AssetProvider;
use DebugBar\DataCollector\DataCollector;
use DebugBar\DataCollector\Renderable;
use Pagekit\Database\Connection;
use Pagekit\Debug\Middleware\DebugLogger;

class DatabaseDataCollector extends DataCollector implements Renderable, AssetProvider
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

        if ($this->logger !== null && !empty($this->logger->queries)) {
            foreach ($this->logger->queries as $q) {
                $params = $q['params'] ?? [];
                $queries[] = [
                    'sql' => $q['sql'],
                    'params' => (object) $this->formatParameters($params),
                    'duration' => $q['executionMS'] ?? 0,
                    'duration_str' => $this->formatQueryDuration($q['executionMS'] ?? 0),
                ];
                $totalTime += $q['executionMS'] ?? 0;
            }
        }

        return [
            'nb_statements' => count($queries),
            'accumulated_duration' => $totalTime,
            'accumulated_duration_str' => $this->formatQueryDuration($totalTime),
            'statements' => $queries,
        ];
    }

    /**
     * Format parameters for display
     *
     * @param array $params
     * @return array
     */
    protected function formatParameters(array $params): array
    {
        return array_map(function ($param) {
            if (is_string($param)) {
                return htmlentities($param, ENT_QUOTES, 'UTF-8', false);
            } elseif (is_array($param)) {
                return '[' . implode(', ', $this->formatParameters($param)) . ']';
            } elseif (is_numeric($param)) {
                return (string) $param;
            } elseif ($param instanceof \DateTimeInterface) {
                return $param->format('Y-m-d H:i:s');
            } elseif (is_object($param)) {
                return json_encode($param);
            }

            return $param ?: '';
        }, $params);
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
                'icon' => 'inbox',
                'widget' => 'PhpDebugBar.Widgets.SQLQueriesWidget',
                'map' => 'database',
                'default' => '[]',
            ],
            'database:badge' => [
                'map' => 'database.nb_statements',
                'default' => 0,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getAssets(): array
    {
        return [
            'css' => 'widgets/sqlqueries/widget.css',
            'js' => 'widgets/sqlqueries/widget.js',
        ];
    }

    /**
     * Format query duration in milliseconds to a readable string.
     *
     * @param float $ms Duration in milliseconds
     * @return string Formatted duration string
     */
    protected function formatQueryDuration(float $ms): string
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
