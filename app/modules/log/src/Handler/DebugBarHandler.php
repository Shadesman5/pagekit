<?php

declare(strict_types=1);

namespace Pagekit\Log\Handler;

use DebugBar\DataCollector\DataCollectorInterface;
use Monolog\Handler\AbstractHandler;
use Monolog\LogRecord;

class DebugBarHandler extends AbstractHandler implements DataCollectorInterface
{
    /** @var list<array{message: string, level: int, level_name: string, channel: string}> */
    protected array $records = [];

    /**
     * {@inheritdoc}
     */
    public function handle(LogRecord $record): bool
    {
        if ($record->level->value < $this->level->value) {
            return false;
        }

        $this->records[] = [
            'message' => $record->message,
            'level' => $record->level->value,
            'level_name' => $record->level->name,
            'channel' => $record->channel,
        ];

        return true;
    }

    /**
     * @return array{records: list<array{message: string, level: int, level_name: string, channel: string}>}
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
