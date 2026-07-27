<?php

declare(strict_types=1);

namespace Pagekit\Debug\DataCollector;

use DebugBar\DataCollector\DataCollectorInterface;
use Monolog\Handler\AbstractHandler;
use Monolog\LogRecord;

class LogDataCollector extends AbstractHandler implements DataCollectorInterface
{
    /** @var array<int, array<string, mixed>> */
    protected array $messages = [];

    /**
     * {@inheritdoc}
     */
    public function handle(LogRecord $record): bool
    {
        if ($record->level->value < $this->level->value) {
            return false;
        }

        $this->messages[] = [
            'message' => $record->message,
            'level' => $record->level->value,
            'level_name' => $record->level->name,
            'channel' => $record->channel,
        ];

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @return array{messages: array<int, array<string, mixed>>}
     */
    public function collect(): array
    {
        return ['messages' => $this->messages];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'log';
    }
}
