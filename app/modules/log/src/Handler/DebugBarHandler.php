<?php

namespace Pagekit\Log\Handler;

use DebugBar\DataCollector\DataCollectorInterface;
use Monolog\Handler\AbstractHandler;
use Monolog\LogRecord;

class DebugBarHandler extends AbstractHandler implements DataCollectorInterface
{
    protected array $records = [];

    /**
     * {@inheritdoc}
     */
    public function handle($record): bool
    {
        // Support both Monolog 2.x (array) and 3.x (LogRecord)
        if ($record instanceof LogRecord) {
            // Monolog 3.x
            if ($record->level->value < $this->level->value) {
                return false;
            }
            
            $this->records[] = [
                'message' => $record->message,
                'level' => $record->level->value,
                'level_name' => $record->level->name,
                'channel' => $record->channel
            ];
        } else {
            // Monolog 2.x fallback
            if ($record['level'] < $this->level) {
                return false;
            }
            
            $keys = [
                'message',
                'level',
                'level_name',
                'channel'
            ];
            
            $this->records[] = array_intersect_key($record, array_flip($keys));
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function collect(): array
    {
        return ['records' => $this->records];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'log';
    }
}
